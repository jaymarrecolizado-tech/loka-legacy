<?php
/**
 * LOKA - Vehicle Repair History helpers (Plan #38, experimental)
 *
 * Experimental module, ships OFF (`settings.repair_history_enabled`).
 * Every data route is gated on repairHistoryEnabled(); the All Father hub
 * page shows the Enable button when it is off.
 *
 * Consumers must require this file themselves (same rule as vehicle_care.php):
 *   require_once INCLUDES_PATH . '/repair_history.php';
 */

if (!defined('REPAIR_HISTORY_LOADED')) {

    define('REPAIR_HISTORY_LOADED', 1);

    define('REPAIR_SOURCES', ['manual', 'maintenance', 'care', 'import']);

    /** Experimental feature flag. */
    function repairHistoryEnabled(): bool
    {
        static $cached = null;
        if ($cached === null) {
            try {
                $row = db()->fetch("SELECT value FROM settings WHERE `key` = 'repair_history_enabled'");
                $cached = ($row && (string) $row->value === '1');
            } catch (Throwable $e) {
                error_log('repairHistoryEnabled: ' . $e->getMessage());
                $cached = false;
            }
        }
        return $cached;
    }

    /**
     * All Father toggle. Clears the static cache so the next call re-reads.
     */
    function repairHistorySetEnabled(bool $on): void
    {
        db()->query(
            "INSERT INTO settings (`key`, value, type, category, created_at, updated_at)
             VALUES ('repair_history_enabled', ?, 'boolean', 'experimental', NOW(), NOW())
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()",
            [$on ? '1' : '0']
        );
        auditLog('repair_history_toggled', 'settings', null, null, ['repair_history_enabled' => $on ? '1' : '0']);
    }

    /**
     * Roles that may see the per-vehicle repair history once the feature is on.
     * Plan #38 decision 2: Motorpool never gets the All Father nav item; they
     * only reach the history through maintenance/vehicle links.
     */
    function canViewRepairHistory(): bool
    {
        return isAdmin() || isMotorpool() || (isRealAllFather() && !isViewingAs());
    }

    /**
     * Roles that may create/edit/delete history entries.
     */
    function canManageRepairHistory(): bool
    {
        return isMotorpool() || isAdmin() || (isRealAllFather() && !isViewingAs());
    }

    function repairHistoryStatusBadge(string $source): string
    {
        $map = [
            'manual'      => ['Manual entry', 'secondary'],
            'maintenance' => ['Repair ticket', 'info'],
            'care'        => ['Care schedule', 'primary'],
            'import'      => ['Imported', 'warning'],
        ];
        [$label, $color] = $map[$source] ?? [$source, 'secondary'];
        return '<span class="badge bg-' . $color . '">' . e($label) . '</span>';
    }

    // -----------------------------------------------------------------
    // Read
    // -----------------------------------------------------------------

    /**
     * History entries for one vehicle (newest first) with their line items.
     *
     * @return list<array{entry:object, items:list<object>}>
     */
    function repairHistoryForVehicle(int $vehicleId): array
    {
        if (!repairHistoryEnabled()) {
            return [];
        }
        $entries = db()->fetchAll(
            "SELECT e.*, u.name AS created_by_name
             FROM vehicle_repair_entries e
             LEFT JOIN users u ON u.id = e.created_by
             WHERE e.vehicle_id = ? AND e.deleted_at IS NULL
             ORDER BY e.repair_date DESC, e.id DESC",
            [$vehicleId]
        );
        if ($entries === []) {
            return [];
        }
        $ids = array_map(static fn($e) => (int) $e->id, $entries);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $itemsByEntry = [];
        foreach (db()->fetchAll(
            "SELECT * FROM vehicle_repair_items WHERE entry_id IN ({$ph}) ORDER BY sort_order ASC, id ASC",
            $ids
        ) as $item) {
            $itemsByEntry[(int) $item->entry_id][] = $item;
        }
        $out = [];
        foreach ($entries as $entry) {
            $out[] = ['entry' => $entry, 'items' => $itemsByEntry[(int) $entry->id] ?? []];
        }
        return $out;
    }

    /** Vehicle id by plate (case/space insensitive), or null. */
    function repairHistoryVehicleByPlate(string $plate): ?int
    {
        $row = db()->fetch(
            "SELECT id FROM vehicles
             WHERE deleted_at IS NULL
               AND UPPER(REPLACE(REPLACE(plate_number, ' ', ''), '-', '')) = ?
             LIMIT 1",
            [strtoupper(str_replace([' ', '-'], '', trim($plate)))]
        );
        return $row ? (int) $row->id : null;
    }

    /** Per-vehicle totals for the hub table. */
    function repairHistoryVehicleSummary(): array
    {
        if (!repairHistoryEnabled()) {
            return [];
        }
        return db()->fetchAll(
            "SELECT v.id, v.plate_number, v.make, v.model, v.engine_number,
                    COUNT(e.id) AS entry_count,
                    COALESCE(SUM(e.total_amount), 0) AS total_amount,
                    MAX(e.repair_date) AS last_repair_date
             FROM vehicles v
             LEFT JOIN vehicle_repair_entries e ON e.vehicle_id = v.id AND e.deleted_at IS NULL
             WHERE v.deleted_at IS NULL
             GROUP BY v.id, v.plate_number, v.make, v.model, v.engine_number
             ORDER BY v.plate_number ASC"
        );
    }

    // -----------------------------------------------------------------
    // Write
    // -----------------------------------------------------------------

    /**
     * Normalize + validate raw line items coming from a form post.
     *
     * @param array<int,array{description:string,unit:string,quantity:mixed,amount:mixed}> $rows
     * @return array{0:list<array>,1:string[]} [items, errors]
     */
    function repairHistoryNormalizeItems(array $rows): array
    {
        $items = [];
        $errors = [];
        foreach ($rows as $i => $row) {
            $description = trim((string) ($row['description'] ?? ''));
            $unit = trim((string) ($row['unit'] ?? ''));
            $qtyRaw = trim((string) ($row['quantity'] ?? ''));
            $amtRaw = trim((string) ($row['amount'] ?? ''));

            if ($description === '') {
                continue; // blank line in the grid — skip
            }
            if (mb_strlen($description) > 255) {
                $errors[] = 'Line ' . ($i + 1) . ': description is longer than 255 characters.';
                continue;
            }
            $qty = $qtyRaw === '' ? 1.0 : (float) $qtyRaw;
            $amount = $amtRaw === '' ? null : (float) $amtRaw;
            if ($qty <= 0 || $qty > 100000) {
                $errors[] = 'Line ' . ($i + 1) . ': quantity must be greater than 0.';
                continue;
            }
            if ($amount !== null && ($amount < 0 || $amount > 99999999.99)) {
                $errors[] = 'Line ' . ($i + 1) . ': price is out of range.';
                continue;
            }
            $items[] = [
                'description' => $description,
                'unit'        => $unit !== '' ? mb_substr($unit, 0, 30) : null,
                'quantity'    => $qty,
                'amount'      => $amount,
                'sort_order'  => (int) $i,
            ];
        }
        return [$items, $errors];
    }

    /** Sum of the item line amounts. */
    function repairHistoryItemsTotal(array $items): float
    {
        $sum = 0.0;
        foreach ($items as $it) {
            $sum += (float) ($it['amount'] ?? 0);
        }
        return round($sum, 2);
    }

    /**
     * Replace an entry's line items and refresh the rolled-up total.
     */
    function repairHistoryReplaceItems(int $entryId, array $items): void
    {
        db()->query("DELETE FROM vehicle_repair_items WHERE entry_id = ?", [$entryId]);
        foreach ($items as $it) {
            db()->insert('vehicle_repair_items', ['entry_id' => $entryId] + $it);
        }
        db()->query(
            "UPDATE vehicle_repair_entries
             SET total_amount = ?, updated_at = NOW()
             WHERE id = ?",
            [repairHistoryItemsTotal($items), $entryId]
        );
    }

    /**
     * Create an entry + items.
     *
     * @return int new entry id
     */
    function repairHistoryCreateEntry(
        int $vehicleId,
        string $repairDate,
        string $nature,
        array $items,
        string $source = 'manual',
        ?int $maintenanceRequestId = null,
        ?int $careScheduleId = null,
        ?int $createdBy = null
    ): int {
        $entryId = db()->insert('vehicle_repair_entries', [
            'vehicle_id'            => $vehicleId,
            'repair_date'           => $repairDate,
            'nature_of_repair'      => $nature,
            'source'                => in_array($source, REPAIR_SOURCES, true) ? $source : 'manual',
            'maintenance_request_id' => $maintenanceRequestId,
            'care_schedule_id'      => $careScheduleId,
            'created_by'            => $createdBy ?? userId(),
            'total_amount'          => repairHistoryItemsTotal($items),
            'created_at'            => date(DATETIME_FORMAT),
        ]);
        repairHistoryReplaceItems($entryId, $items);
        return $entryId;
    }

    function repairHistorySoftDelete(int $entryId): void
    {
        db()->query(
            "UPDATE vehicle_repair_entries SET deleted_at = NOW(), updated_at = NOW() WHERE id = ?",
            [$entryId]
        );
    }

    /**
     * Auto-write from a completed repair ticket. No-op when the feature is off
     * (Plan #38 decision 1) or when the ticket already has an entry.
     */
    function repairHistoryUpsertFromMaintenance(object $maintenance, array $items = []): ?int
    {
        if (!repairHistoryEnabled()) {
            return null;
        }
        $existing = db()->fetchColumn(
            "SELECT id FROM vehicle_repair_entries
             WHERE maintenance_request_id = ? AND deleted_at IS NULL",
            [(int) $maintenance->id]
        );
        if ($existing) {
            return (int) $existing;
        }
        if ($items === [] && !empty($maintenance->actual_cost)) {
            $items = [[
                'description' => (string) ($maintenance->title ?: 'Repair'),
                'unit'        => 'lot',
                'quantity'    => 1.0,
                'amount'      => (float) $maintenance->actual_cost,
                'sort_order'  => 0,
            ]];
        }
        if ($items === []) {
            return null;
        }
        $date = $maintenance->completed_date ?: date('Y-m-d');
        return repairHistoryCreateEntry(
            (int) $maintenance->vehicle_id,
            $date,
            (string) ($maintenance->title ?: 'Repair'),
            $items,
            'maintenance',
            (int) $maintenance->id
        );
    }

    /**
     * Auto-write from a completed care schedule. No-op when the feature is off
     * or the care schedule carries no costing.
     */
    function repairHistoryUpsertFromCare(object $care, array $items = []): ?int
    {
        if (!repairHistoryEnabled()) {
            return null;
        }
        $existing = db()->fetchColumn(
            "SELECT id FROM vehicle_repair_entries
             WHERE care_schedule_id = ? AND deleted_at IS NULL",
            [(int) $care->id]
        );
        if ($existing) {
            return (int) $existing;
        }
        if ($items === []) {
            return null; // care without costing writes no history row
        }
        $date = date('Y-m-d', strtotime((string) $care->completed_at));
        return repairHistoryCreateEntry(
            (int) $care->vehicle_id,
            $date ?: date('Y-m-d'),
            (string) $care->title,
            $items,
            'care',
            null,
            (int) $care->id
        );
    }

    /**
     * Read `items[*][...]` arrays off a POST body (line-item grid).
     *
     * @return list<array{description:string,unit:string,quantity:string,amount:string}>
     */
    function repairHistoryItemsFromPost(string $prefix = 'item'): array
    {
        $raw = post($prefix, []);
        if (!is_array($raw)) {
            return [];
        }
        $rows = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = [
                'description' => trim((string) ($row['description'] ?? '')),
                'unit'        => trim((string) ($row['unit'] ?? '')),
                'quantity'    => trim((string) ($row['quantity'] ?? '')),
                'amount'      => trim((string) ($row['amount'] ?? '')),
            ];
        }
        return $rows;
    }
}