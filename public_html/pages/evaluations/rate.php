<?php
/**
 * LOKA - "Rate now" handler (nag banner action)
 *
 * Route: ?page=evaluations&action=rate&id={request_id}
 * Re-issues the invite token for the current user's pending evaluation of a
 * trip and forwards to the public token form. Auth required here (the submit
 * page itself is public — the token is the capability there).
 */

requireAuth();

$requestId = getInt('id', 0);
$eval = $requestId > 0 ? db()->fetch(
    "SELECT de.id, de.created_at, de.submitted_at
     FROM driver_evaluations de
     JOIN requests r ON r.id = de.request_id AND r.deleted_at IS NULL
     WHERE de.request_id = ? AND de.evaluator_user_id = ? AND r.status = ?",
    [$requestId, userId(), STATUS_COMPLETED]
) : null;

if (!$eval || $eval->submitted_at !== null) {
    redirectWith('/?page=dashboard', 'warning', 'No pending driver evaluation found for that trip.');
}

$expiryDays = driverEvaluationExpiryDays();
if (strtotime($eval->created_at) < time() - ($expiryDays * 86400)) {
    redirectWith(
        '/?page=dashboard',
        'warning',
        'This evaluation link has expired (' . $expiryDays . ' days limit). '
        . 'Please contact the motorpool if you still wish to provide feedback.'
    );
}

// Re-issue the token — previously emailed links stop working (by design)
$rawToken = bin2hex(random_bytes(32));
$affected = db()->update(
    'driver_evaluations',
    ['token_hash' => hash('sha256', $rawToken)],
    'id = ? AND submitted_at IS NULL',
    [$eval->id]
);
if ($affected === 0) {
    redirectWith('/?page=dashboard', 'info', 'This evaluation was already submitted. Thank you!');
}

redirect('/?page=evaluations&action=submit&token=' . urlencode($rawToken));
