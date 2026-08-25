<?php
/**
 * Shared helpers for ops reports (filters, export meta, date presets).
 */

/** @return array{start:string,end:string,preset:string} */
function reportResolveDateRange(?string $preset = null, ?string $start = null, ?string $end = null): array
{
    $preset = $preset ?? (string) get('preset', '');
    $start = $start ?? (string) get('start_date', '');
    $end = $end ?? (string) get('end_date', '');

    $today = date('Y-m-d');
    switch ($preset) {
        case 'mtd':
            return ['start' => date('Y-m-01'), 'end' => $today, 'preset' => 'mtd'];
        case 'last30':
            return [
                'start' => date('Y-m-d', strtotime('-29 days')),
                'end' => $today,
                'preset' => 'last30',
            ];
        case 'qtd':
            $m = (int) date('n');
            $qStartMonth = (int) (floor(($m - 1) / 3) * 3) + 1;
            return [
                'start' => date('Y-' . sprintf('%02d', $qStartMonth) . '-01'),
                'end' => $today,
                'preset' => 'qtd',
            ];
        default:
            if ($start === '' || !strtotime($start)) {
                $start = date('Y-m-01');
            }
            if ($end === '' || !strtotime($end)) {
                $end = date('Y-m-t');
            }
            return ['start' => $start, 'end' => $end, 'preset' => ''];
    }
}

/**
 * Search SQL fragment + params for trip request reports (expects joined aliases).
 *
 * @return array{0:string,1:list<string>}
 */
function tripsReportSearchClause(string $searchQuery): array
{
    if ($searchQuery === '') {
        return ['', []];
    }
    $like = '%' . $searchQuery . '%';
    $sql = " AND (
        CAST(r.id AS CHAR) LIKE ? OR
        r.destination LIKE ? OR
        r.purpose LIKE ? OR
        u.name LIKE ? OR
        dept.name LIKE ? OR
        v.plate_number LIKE ? OR
        dr_user.name LIKE ?
    )";
    return [$sql, [$like, $like, $like, $like, $like, $like, $like]];
}

/** Standard FROM/JOIN for trip request reports */
function tripsReportFromSql(): string
{
    return "FROM requests r
     JOIN users u ON r.user_id = u.id
     LEFT JOIN departments dept ON r.department_id = dept.id
     LEFT JOIN vehicles v ON r.vehicle_id = v.id
     LEFT JOIN drivers dr ON r.driver_id = dr.id
     LEFT JOIN users dr_user ON dr.user_id = dr_user.id";
}

/**
 * @param resource $output
 * @param list<string> $filterLines
 */
function reportCsvWriteMeta($output, string $title, array $filterLines, int $rowCount, int $cap): void
{
    fputcsv($output, ['# ' . $title]);
    fputcsv($output, ['# Generated', date('Y-m-d H:i:s')]);
    foreach ($filterLines as $line) {
        fputcsv($output, ['# Filter', $line]);
    }
    fputcsv($output, ['# Rows exported', (string) $rowCount]);
    if ($rowCount >= $cap) {
        fputcsv($output, ['# Notice', "Result capped at {$cap} rows — narrow filters for a full export."]);
    }
    fputcsv($output, []);
}

/**
 * @param list<string> $filterLines
 */
function reportPdfWriteMeta(TCPDF $pdf, string $title, array $filterLines, int $rowCount, int $cap): void
{
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 7, $title, 0, 1);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell(0, 5, 'Generated: ' . date('Y-m-d H:i:s') . ' | Rows: ' . $rowCount . ($rowCount >= $cap ? " (CAPPED at {$cap})" : ''), 0, 1);
    foreach ($filterLines as $line) {
        $pdf->MultiCell(0, 4, 'Filter: ' . $line, 0, 'L', false, 1);
    }
    if ($rowCount >= $cap) {
        $pdf->SetTextColor(180, 80, 0);
        $pdf->MultiCell(0, 5, "Notice: Export capped at {$cap} rows. Narrow your date range or filters for complete data.", 0, 'L', false, 1);
        $pdf->SetTextColor(0, 0, 0);
    }
    $pdf->Ln(2);
}

function reportPresetFieldHtml(string $currentPreset): string
{
    $opts = [
        '' => 'Custom range',
        'mtd' => 'This month',
        'last30' => 'Last 30 days',
        'qtd' => 'This quarter',
    ];
    $html = '<div class="min-w-[150px]"><label class="loka-form-label">Period</label>';
    $html .= '<select class="loka-form-input" name="preset" onchange="this.form.submit()">';
    foreach ($opts as $k => $label) {
        $sel = ($currentPreset === $k) ? ' selected' : '';
        $html .= '<option value="' . e($k) . '"' . $sel . '>' . e($label) . '</option>';
    }
    $html .= '</select></div>';
    return $html;
}
