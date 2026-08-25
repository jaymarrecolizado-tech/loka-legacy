<?php
/**
 * All Father — set / clear View-as role
 */

requireAuth();

if (!isRealAllFather()) {
    redirectWith('/?page=dashboard', 'danger', 'All Father access required.');
}

$role = getSafe('role', '', 40);
$redirect = getSafe('redirect', '', 500);
if ($redirect === '' || str_starts_with($redirect, 'http')) {
    $redirect = '/?page=dashboard';
}
// Only allow relative redirects
if ($redirect[0] !== '/') {
    $redirect = '/?page=dashboard';
}

if ($role === '' || $role === 'none') {
    setViewAsRole(null);
    redirectWith($redirect, 'success', 'View-as cleared. You are All Father again.');
}

if (!setViewAsRole($role)) {
    $msg = ($role === 'driver' && viewAsTestDriverId() === null)
        ? 'No test driver available. Set VIEW_AS_TEST_DRIVER_ID in .env or add a driver.'
        : 'Invalid View-as role.';
    redirectWith($redirect, 'danger', $msg);
}

$label = viewAsBannerLabel() ?? $role;
redirectWith($redirect, 'success', 'Now viewing as ' . $label . '.');
