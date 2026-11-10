<?php
/**
 * AJAX: is this individual (login) email already registered?
 * Official/office email is never unique and is not accepted here.
 */
requireAuth();
requireRole(ROLE_ADMIN);

$email = normalizeOptionalEmail((string) get('email', ''));
$excludeId = (int) get('exclude_id', 0);

if ($email === '') {
    jsonResponse(true, ['taken' => false, 'valid' => false]);
}

$valid = (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
$taken = $valid && userIndividualEmailTaken($email, $excludeId ?: null);

jsonResponse(true, [
    'taken' => $taken,
    'valid' => $valid,
], $taken
    ? 'This individual email is already registered.'
    : ($valid ? '' : 'Invalid email format.'));
