<?php
/**
 * LOKA - Global Search API (for nav search live results)
 * GET ?page=api&action=global_search&q=XXX
 * Returns JSON {success:true, items:[{label, href, icon, section, badge}]}
 * Permission-aware: only returns entities current user can access.
 */
requireAuth();

$q = trim(get('q', get('query', get('search', ''))));
if ($q === '' || mb_strlen($q) < 2) {
    jsonResponse(true, ['items' => []]);
}

$like = '%' . $q . '%';
$items = [];
$limitPerType = 3;

// 1. Requests: numeric ID exact or destination/purpose like (own unless admin)
$isAdmin = isAdmin();
if (ctype_digit($q)) {
    $id = (int) $q;
    $where = $isAdmin ? 'r.id = ? AND r.deleted_at IS NULL' : 'r.id = ? AND r.user_id = ? AND r.deleted_at IS NULL';
    $params = $isAdmin ? [$id] : [$id, userId()];
    // also allow approver to see requests they can view? loosen: if isApprover, allow any pending they could approve
    // For simplicity, if not found with owner check, try broader if approver/motorpool
    $row = db()->fetch("SELECT r.id, r.destination, r.status FROM requests r WHERE {$where} LIMIT 1", $params);
    if (!$row && (isApprover() || isMotorpool())) {
        $row = db()->fetch("SELECT id, destination, status FROM requests WHERE id = ? AND deleted_at IS NULL LIMIT 1", [$id]);
    }
    if ($row) {
        $items[] = [
            'label' => "Request #{$row->id} — " . mb_substr($row->destination ?: 'View', 0, 40),
            'href' => APP_URL . '/?page=requests&action=view&id=' . $row->id,
            'icon' => 'bi-file-earmark-text',
            'section' => 'Request',
            'keywords' => 'request ' . $row->id,
        ];
    }
}
// Text search on requests (destination/purpose)
if (mb_strlen($q) >= 2) {
    $where = $isAdmin ? 'r.deleted_at IS NULL AND (r.destination LIKE ? OR r.purpose LIKE ?)' : 'r.deleted_at IS NULL AND r.user_id = ? AND (r.destination LIKE ? OR r.purpose LIKE ?)';
    $params = $isAdmin ? [$like, $like] : [userId(), $like, $like];
    // Approvers also see pending department requests; include them when search
    $rows = db()->fetchAll("SELECT r.id, r.destination, r.status FROM requests r WHERE {$where} ORDER BY r.updated_at DESC LIMIT {$limitPerType}", $params);
    // If approver and not admin and got zero results, broaden to approvable
    if (empty($rows) && isApprover() && !$isAdmin) {
        $rows = db()->fetchAll("SELECT r.id, r.destination, r.status FROM requests r WHERE r.deleted_at IS NULL AND r.status = 'pending' AND r.department_id = ? AND (r.destination LIKE ? OR r.purpose LIKE ?) ORDER BY r.updated_at DESC LIMIT {$limitPerType}", [currentUser()->department_id ?? 0, $like, $like]);
    }
    foreach ($rows as $r) {
        // avoid duplicate if numeric exact already added
        if (!empty($items) && $items[0]['href'] === APP_URL . '/?page=requests&action=view&id=' . $r->id) continue;
        $items[] = [
            'label' => "Request #{$r->id} — " . mb_substr($r->destination ?: 'View', 0, 40),
            'href' => APP_URL . '/?page=requests&action=view&id=' . $r->id,
            'icon' => 'bi-file-earmark-text',
            'section' => 'Request',
            'keywords' => 'request destination',
        ];
    }
}

// 2. Vehicles (approver+)
if (isApprover()) {
    $rows = db()->fetchAll("SELECT v.id, v.plate_number, v.make, v.model FROM vehicles v JOIN vehicle_types vt ON v.vehicle_type_id = vt.id WHERE v.deleted_at IS NULL AND (v.plate_number LIKE ? OR v.make LIKE ? OR v.model LIKE ? OR vt.name LIKE ?) ORDER BY v.plate_number LIMIT {$limitPerType}", [$like,$like,$like,$like]);
    foreach ($rows as $v) {
        $items[] = [
            'label' => $v->plate_number . ' — ' . $v->make . ' ' . $v->model,
            'href' => APP_URL . '/?page=vehicles&action=view&id=' . $v->id,
            'icon' => 'bi-car-front',
            'section' => 'Vehicle',
            'keywords' => 'vehicle plate ' . $v->plate_number,
        ];
    }
}

// 3. Drivers (approver+)
if (isApprover()) {
    $rows = db()->fetchAll("SELECT d.id, u.name FROM drivers d JOIN users u ON d.user_id = u.id WHERE d.deleted_at IS NULL AND u.deleted_at IS NULL AND (u.name LIKE ? OR d.license_number LIKE ?) ORDER BY u.name LIMIT {$limitPerType}", [$like,$like]);
    foreach ($rows as $d) {
        $items[] = [
            'label' => $d->name,
            'href' => APP_URL . '/?page=drivers&search=' . urlencode($q),
            'icon' => 'bi-person-badge',
            'section' => 'Driver',
            'keywords' => 'driver ' . $d->name,
        ];
    }
}

// 4. Users (motorpool+)
if (isMotorpool()) {
    $rows = db()->fetchAll("SELECT id, name, email FROM users WHERE deleted_at IS NULL AND (name LIKE ? OR email LIKE ?) ORDER BY name LIMIT {$limitPerType}", [$like,$like]);
    foreach ($rows as $u) {
        $items[] = [
            'label' => $u->name . ' — ' . $u->email,
            'href' => APP_URL . '/?page=users&search=' . urlencode($q),
            'icon' => 'bi-people',
            'section' => 'User',
            'keywords' => 'user ' . $u->name,
        ];
    }
}

jsonResponse(true, ['items' => $items]);
