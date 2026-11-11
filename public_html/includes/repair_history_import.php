<?php
/**
 * LOKA - Repair History workbook importer (Plan #38, experimental)
 *
 * Reads the DICT "Motor Vehicle Repair History" .xlsx workbooks shipped in
 * Reference/Repair History/ and turns each dated (date, nature) pair into a
 * vehicle_repair_entries row with its purchased-item lines.
 *
 * No PhpSpreadsheet dependency: an .xlsx is a zip of XML, so ext-zip +
 * SimpleXML is enough for the flat tables these workbooks use.
 */

if (!defined('REPAIR_HISTORY_IMPORT_LOADED')) {

    define('REPAIR_HISTORY_IMPORT_LOADED', 1);

    /** Workbook folder shipped with the repo. */
    function repairHistoryReferenceDir(): string
    {
        return dirname(BASE_PATH) . '/Reference/Repair History';
    }

    /**
     * First worksheet of an .xlsx as a list of rows keyed by column letter.
     *
     * @return list<array<string,string>>
     */
    function repairHistoryReadXlsx(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Could not open the workbook (not a readable .xlsx).');
        }

        $shared = [];
        if ($zip->locateName('xl/sharedStrings.xml') !== false) {
            $xml = simplexml_load_string((string) $zip->getFromName('xl/sharedStrings.xml'));
            if ($xml !== false) {
                foreach ($xml->si as $si) {
                    $text = '';
                    foreach ($si->t as $t) {          // plain <t> or rich-text runs <r><t>
                        $text .= (string) $t;
                    }
                    if ($text === '') {
                        $text = trim(strip_tags($si->asXML() ?: ''));
                    }
                    $shared[] = $text;
                }
            }
        }

        $sheetName = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', (string) $name)) {
                $sheetName = $name;
                break;
            }
        }
        if ($sheetName === null) {
            $zip->close();
            throw new RuntimeException('Workbook has no worksheet.');
        }

        $xml = simplexml_load_string((string) $zip->getFromName($sheetName));
        $zip->close();
        if ($xml === false) {
            throw new RuntimeException('Could not parse the worksheet XML.');
        }

        $rows = [];
        foreach ($xml->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $col = preg_replace('/\d+/', '', (string) $c['r']);
                $value = '';
                if ((string) $c['t'] === 's') {
                    $value = $shared[(int) $c->v] ?? '';
                } elseif ((string) $c['t'] === 'inlineStr') {
                    $value = (string) $c->is->t;
                } else {
                    $value = (string) $c->v;
                }
                $cells[$col] = trim($value);
            }
            $rows[] = $cells;
        }
        return $rows;
    }

    /**
     * Excel serial (1900 system) to Y-m-d. Unix epoch 1970-01-01 is serial 25569.
     */
    function repairHistoryExcelSerialToDate(float $serial): ?string
    {
        if ($serial < 20000 || $serial > 80000) {   // plausible 1954..2119 only
            return null;
        }
        $days = (int) floor($serial);
        return gmdate('Y-m-d', ($days - 25569) * 86400);
    }

    /**
     * Parse a workbook into a plate-keyed structure.
     *
     * @return array{plate:string, engine_no:string, brand_model:string, type:string,
     *               agency:string, province:string,
     *               entries:list<array{date:string, nature:string, items:list<array{description:string,unit:?string,quantity:float,amount:?float}>}>}
     */
    function repairHistoryParseWorkbook(string $path): array
    {
        $rows = repairHistoryReadXlsx($path);
        $out = [
            'plate' => '', 'engine_no' => '', 'brand_model' => '', 'type' => '',
            'agency' => '', 'province' => '', 'entries' => [],
        ];

        // ---- header block: "Label:" in any column A..D, value to its right ----
        // The sheets are not consistent: some put "Plate Number:" in A (value B),
        // others in C (value D).
        $labels = [
            'agency' => ['agency'], 'province' => ['province'],
            'type' => ['type'], 'brand_model' => ['brand/model', 'brand\\model'],
            'engine_no' => ['engine no', 'engine no.'],
            'plate' => ['plate number', 'plate no', 'plate no.'],
        ];
        $cols = range('A', 'H');
        foreach ($rows as $row) {
            // One row can hold two label/value pairs (e.g. "Engine No: x  Plate
            // Number: y"), so scan the whole row rather than stopping at the first.
            foreach ($cols as $ci => $col) {
                $cell = $row[$col] ?? '';
                if ($cell === '' || substr($cell, -1) !== ':') {
                    continue;
                }
                $key = strtolower(rtrim($cell, ': '));
                foreach ($labels as $field => $aliases) {
                    if (!in_array($key, $aliases, true) || $out[$field] !== '') {
                        continue;
                    }
                    for ($cj = $ci + 1, $n = count($cols); $cj < $n; $cj++) {
                        if (!empty($row[$cols[$cj]])) {
                            $out[$field] = $row[$cols[$cj]];
                            break;
                        }
                    }
                }
            }
        }

        // ---- data block: start after the "Date | Nature of Repair" banner ----
        $start = null;
        foreach ($rows as $i => $row) {
            if (strcasecmp($row['A'] ?? '', 'Date') === 0
                && stripos($row['B'] ?? '', 'Nature of Repair') !== false) {
                $start = $i + 1;
                break;
            }
        }
        if ($start === null) {
            throw new RuntimeException('Workbook has no "Date / Nature of Repair" header row.');
        }

        /** @var array<string, array{date:string,nature:string,items:list<array>}> $entries */
        $entries = [];
        $curDate = null;
        $curNature = null;
        $lastKey = null;

        for ($i = $start, $n = count($rows); $i < $n; $i++) {
            $row = $rows[$i];
            $cellA = $row['A'] ?? '';
            $cellB = $row['B'] ?? '';
            $cellC = $row['C'] ?? '';

            // Nothing left but the certification footer / blank spacers.
            if ($cellC === '' && $cellB === '' && !is_numeric($cellA)) {
                continue;
            }

            // The date may lead the row (common) or trail the nature (SNJ 8786
            // writes the nature first and the date on the next row), so read it
            // whenever column A carries a serial.
            if ($cellA !== '' && is_numeric($cellA)) {
                $curDate = repairHistoryExcelSerialToDate((float) $cellA) ?? $curDate;
            } elseif ($cellA !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $cellA)) {
                $curDate = $cellA;
            }

            // A new "Nature of Repair" starts a new entry. The workbook leaves the
            // date cell blank when two natures share one date.
            if ($cellB !== '') {
                $curNature = $cellB;
                $lastKey = null;
            }

            // Open the entry as soon as we know both halves. The date may arrive
            // on this row or on the next one (SNJ 8786 writes the nature first),
            // so re-key whenever the date changes under a carried nature.
            if ($cellC !== '' && $curDate !== null && $curNature !== null) {
                $key = $curDate . '|' . mb_strtolower($curNature);
                $entries[$key] ??= ['date' => $curDate, 'nature' => $curNature, 'items' => []];
                $lastKey = $key;
            }

            if ($lastKey === null || $cellC === '' || !isset($entries[$lastKey])) {
                continue;
            }

            $qty = isset($row['E']) && is_numeric($row['E']) ? (float) $row['E'] : 1.0;
            $qty = $qty > 0 ? $qty : 1.0;
            $amount = isset($row['F']) && is_numeric($row['F']) ? round((float) $row['F'], 2) : null;
            $unit = trim($row['D'] ?? '');

            $entries[$lastKey]['items'][] = [
                'description' => $cellC,
                'unit'        => $unit !== '' ? mb_substr($unit, 0, 30) : null,
                'quantity'    => $qty,
                'amount'      => $amount,
            ];
        }

        $out['entries'] = array_values($entries);
        return $out;
    }

    /**
     * All parseable workbooks under Reference/Repair History (recursively).
     *
     * @return list<array{path:string,rel:string,name:string,size:int}>
     */
    function repairHistoryScanWorkbooks(): array
    {
        $dir = repairHistoryReferenceDir();
        if (!is_dir($dir)) {
            return [];
        }
        $found = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            /** @var SplFileInfo $file */
            if (strtolower($file->getExtension()) !== 'xlsx') {
                continue;
            }
            if (stripos($file->getFilename(), 'repair history') === false) {
                continue;   // skip the pre/post-inspection Request 4 workbooks (out of scope)
            }
            $found[] = [
                'path' => $file->getPathname(),
                'rel'  => str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1)),
                'name' => $file->getFilename(),
                'size' => (int) $file->getSize(),
            ];
        }
        usort($found, static fn($a, $b) => strcmp($a['rel'], $b['rel']));
        return $found;
    }

    /**
     * Import one workbook. Entries are grouped per plate; unknown plates are
     * reported and skipped.
     *
     * @return array{plate:string, entries:int, items:int, total:float, skipped:string[]}
     */
    function repairHistoryImportWorkbook(string $path, bool $dryRun = false): array
    {
        $parsed = repairHistoryParseWorkbook($path);
        $result = ['plate' => $parsed['plate'], 'entries' => 0, 'items' => 0, 'total' => 0.0, 'skipped' => []];

        $vehicleId = $parsed['plate'] !== '' ? repairHistoryVehicleByPlate($parsed['plate']) : null;
        if ($vehicleId === null) {
            $result['skipped'][] = $parsed['plate'] !== ''
                ? 'plate ' . $parsed['plate'] . ' not found in the vehicle master'
                : 'workbook has no plate number';
            return $result;
        }

        if (!$dryRun) {
            foreach ($parsed['entries'] as $entry) {
                if ($entry['items'] === []) {
                    continue;
                }
                // Idempotency: skip (date, nature) pairs already imported.
                $dupe = db()->fetchColumn(
                    "SELECT id FROM vehicle_repair_entries
                     WHERE vehicle_id = ? AND repair_date = ? AND nature_of_repair = ?
                       AND source = 'import' AND deleted_at IS NULL",
                    [$vehicleId, $entry['date'], $entry['nature']]
                );
                if ($dupe) {
                    $result['skipped'][] = $entry['date'] . ' — ' . $entry['nature'] . ' (already imported)';
                    continue;
                }
                repairHistoryCreateEntry(
                    $vehicleId,
                    $entry['date'],
                    $entry['nature'],
                    $entry['items'],
                    'import'
                );
                $result['entries']++;
                $result['items'] += count($entry['items']);
                $result['total'] += repairHistoryItemsTotal($entry['items']);
            }

            if (!empty($parsed['engine_no'])) {
                db()->query(
                    "UPDATE vehicles SET engine_number = ?, updated_at = NOW()
                     WHERE id = ? AND (engine_number IS NULL OR engine_number = '')",
                    [mb_substr($parsed['engine_no'], 0, 50), $vehicleId]
                );
            }

            auditLog('repair_history_imported', 'vehicle', $vehicleId, null, [
                'workbook' => basename($path),
                'entries'  => $result['entries'],
                'items'    => $result['items'],
                'total'    => round($result['total'], 2),
                'skipped'  => count($result['skipped']),
            ]);
        } else {
            foreach ($parsed['entries'] as $entry) {
                $result['entries'] += $entry['items'] ? 1 : 0;
                $result['items'] += count($entry['items']);
                $result['total'] += repairHistoryItemsTotal($entry['items']);
            }
            $result['total'] = round($result['total'], 2);
        }

        return $result;
    }
}