<?php
/**
 * OB Pass Slip participants — users on the same Official Business.
 * No extra signatures.
 * Printed employee line: J.Recolizado / D.Abad (slash, Plan #26).
 * CoA certify sentence: S.Admin and J.Recolizado (short names, Plan #26).
 */

if (defined('OB_PARTICIPANTS_LOADED')) {
    return;
}
define('OB_PARTICIPANTS_LOADED', 1);

/**
 * First initial + last surname. "Jaymar Recolizado" → "J.Recolizado".
 */
function obShortPrintedName(string $fullName): string
{
    $fullName = trim(preg_replace('/\s+/u', ' ', $fullName) ?? '');
    if ($fullName === '') {
        return '';
    }
    $parts = explode(' ', $fullName);
    $skip = ['jr', 'jr.', 'sr', 'sr.', 'ii', 'iii', 'iv'];
    while (count($parts) > 1 && in_array(mb_strtolower((string) end($parts)), $skip, true)) {
        array_pop($parts);
    }
    $first = $parts[0];
    $last = $parts[count($parts) - 1];
    $initial = mb_strtoupper(mb_substr($first, 0, 1));
    if (count($parts) === 1) {
        return $last;
    }
    return $initial . '.' . $last;
}

/**
 * Employee signature line: short names joined with " / " (Plan #26).
 * "Jaymar Recolizado, Daryl Abad" -> "J.Recolizado / D.Abad".
 *
 * @param list<string> $fullNames
 */
function obPrintedEmployeeLine(array $fullNames): string
{
    $shorts = [];
    foreach ($fullNames as $name) {
        $short = obShortPrintedName((string) $name);
        if ($short !== '') {
            $shorts[] = $short;
        }
    }
    return implode(' / ', $shorts);
}

/**
 * Oxford-and join of the SHORT names ("S.Admin and J.Recolizado") — used by
 * the CoA certify sentence so the "latter part" of the certificate stays
 * compact. Full names live in the Personnel block of the printed slip.
 *
 * @param list<string> $fullNames
 */
function obJoinShortNames(array $fullNames): string
{
    $shorts = [];
    foreach ($fullNames as $name) {
        $short = obShortPrintedName((string) $name);
        if ($short !== '') {
            $shorts[] = $short;
        }
    }
    return obJoinNames($shorts);
}

/**
 * Non-empty names in order.
 *
 * @param list<string> $fullNames
 * @return list<string>
 */
function obNameList(array $fullNames): array
{
    $names = [];
    foreach ($fullNames as $name) {
        $name = trim((string) $name);
        if ($name !== '') {
            $names[] = $name;
        }
    }
    return $names;
}

/**
 * "Jaymar Recolizado"; "Jaymar Recolizado and Daryl Abad";
 * "Jaymar Recolizado, Daryl Abad, and Nora Usman".
 *
 * @param list<string> $fullNames
 */
function obJoinNames(array $fullNames): string
{
    $names = obNameList($fullNames);
    $n = count($names);
    if ($n === 0) {
        return '';
    }
    if ($n === 1) {
        return $names[0];
    }
    if ($n === 2) {
        return $names[0] . ' and ' . $names[1];
    }
    $last = array_pop($names);
    return implode(', ', $names) . ', and ' . $last;
}

/**
 * Certificate of Appearance body. Grammar follows 1 vs 2+ names.
 * Names are the SHORT form (Plan #26): "… that J.Recolizado has appeared …"
 * / "… the following personnel have appeared …: S.Admin and J.Recolizado."
 * Full names print in the Personnel block of the slip.
 *
 * @param list<string> $fullNames
 */
function obCoaAppearanceLine(array $fullNames, string $onDate): string
{
    $names = obNameList($fullNames);
    $who = obJoinShortNames($names);
    if ($who === '') {
        $who = 'the employee';
    }
    $n = count($names);
    if ($n <= 1) {
        return 'I hereby certify that ' . $who . ' has appeared in this office/establishment on ' . $onDate . '.';
    }
    return 'I hereby certify that the following personnel have appeared in this office/establishment on '
        . $onDate . ': ' . $who . '.';
}

/**
 * Slip caption under the printed employee line.
 *
 * @param list<string> $fullNames
 */
function obEmployeePrintedHint(array $fullNames): string
{
    return count(obNameList($fullNames)) > 1
        ? '(Printed names of employees)'
        : '(Signature of employee over printed name)';
}

/**
 * Active users for the OB participant picker (includes the filer).
 *
 * @return list<object>{id:int,name:string,department_name:?string}
 */
function obListActiveUsers(): array
{
    return db()->fetchAll(
        "SELECT u.id, u.name, d.name AS department_name
         FROM users u
         LEFT JOIN departments d ON d.id = u.department_id
         WHERE u.status = 'active' AND u.deleted_at IS NULL
         ORDER BY u.name ASC"
    );
}

/**
 * Printed employee lines keyed by OB id (one query). Missing ids omitted.
 *
 * @param list<int> $obIds
 * @return array<int,string>
 */
function obPrintedLinesForIds(array $obIds): array
{
    $obIds = array_values(array_unique(array_filter(array_map('intval', $obIds), static fn($id) => $id > 0)));
    if ($obIds === []) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($obIds), '?'));
    $rows = db()->fetchAll(
        "SELECT p.ob_request_id, u.name
         FROM ob_request_participants p
         JOIN users u ON u.id = p.user_id
         WHERE p.ob_request_id IN ({$ph})
         ORDER BY p.ob_request_id ASC, p.sort_order ASC, p.id ASC",
        $obIds
    );
    $names = [];
    foreach ($rows as $row) {
        $names[(int) $row->ob_request_id][] = (string) $row->name;
    }
    $out = [];
    foreach ($names as $id => $list) {
        $line = obPrintedEmployeeLine($list);
        if ($line !== '') {
            $out[$id] = $line;
        }
    }
    return $out;
}

/**
 * Requester first, then posted extra user ids (active users only, unique).
 *
 * @param list<int|string> $postedIds
 * @return list<int>
 */
function obCollectParticipantIds(array $postedIds, int $requesterId): array
{
    $ids = [];
    $seen = [];
    $push = static function (int $id) use (&$ids, &$seen): void {
        if ($id < 1 || isset($seen[$id])) {
            return;
        }
        $seen[$id] = true;
        $ids[] = $id;
    };
    $push($requesterId);
    foreach ($postedIds as $raw) {
        $push((int) $raw);
    }
    return array_slice($ids, 0, 21);
}

/**
 * @return list<object>{user_id:int,name:string}
 */
function obListParticipants(int $obId): array
{
    return db()->fetchAll(
        "SELECT p.user_id, u.name
         FROM ob_request_participants p
         JOIN users u ON u.id = p.user_id
         WHERE p.ob_request_id = ?
         ORDER BY p.sort_order ASC, p.id ASC",
        [$obId]
    );
}

/**
 * Full names for the printed employee line (falls back to the filer).
 *
 * @return list<string>
 */
function obParticipantFullNames(int $obId, string $requesterName): array
{
    $names = [];
    foreach (obListParticipants($obId) as $row) {
        $names[] = (string) $row->name;
    }
    return $names !== [] ? $names : [$requesterName];
}

/**
 * @param list<int> $userIds
 */
function obSaveParticipants(int $obId, array $userIds): void
{
    db()->delete('ob_request_participants', 'ob_request_id = ?', [$obId]);
    $now = date(DATETIME_FORMAT);
    foreach (array_values($userIds) as $i => $userId) {
        db()->insert('ob_request_participants', [
            'ob_request_id' => $obId,
            'user_id' => $userId,
            'sort_order' => $i,
            'created_at' => $now,
        ]);
    }
}
