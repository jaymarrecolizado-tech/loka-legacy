<?php
/**
 * LOKA - Navigation Search Index
 * Builds a permission-aware list of searchable nav items for the current user.
 * Used by the top-bar search + Ctrl+K command palette. Client stores
 * recent/frequent in localStorage per user id.
 */

function getNavSearchItems(): array
{
    $items = [];

    // Live badge counts (cheap, cached where possible) to surface in search
    $badgeMap = [];
    try {
        if (isApprover()) {
            if (isMotorpool()) {
                $badgeMap['/?page=approvals'] = (int) db()->count('requests', "status = 'pending_motorpool'");
            } else {
                $uid = currentUser()->department_id ?? null;
                if ($uid) $badgeMap['/?page=approvals'] = (int) db()->count('requests', "status = 'pending' AND department_id = ?", [$uid]);
            }
        }
        if (function_exists('canAccessGasVouchers') && canAccessGasVouchers()) {
            if (function_exists('badgeCountPendingGasVouchers')) $badgeMap['/?page=gas-vouchers'] = (int) badgeCountPendingGasVouchers();
        }
        if (isApprover()) {
            $badgeMap['/?page=maintenance'] = (int) db()->count('maintenance_requests', "status IN (?, ?) AND deleted_at IS NULL", [MAINTENANCE_STATUS_PENDING, MAINTENANCE_STATUS_SCHEDULED]);
        }
    } catch (Throwable $e) { /* badges are best-effort */ }

    // Helper to push item - href must be absolute (APP_URL + query) so it works from subfolder install
    $add = function (string $label, string $path, string $icon, string $section, string $keywords = '', ?int $badge = null) use (&$items, $badgeMap) {
        // $path is like '/?page=drivers' - prefix with APP_URL
        $href = rtrim(APP_URL, '/') . $path;
        // badgeMap is keyed by path, also try href
        $resolvedBadge = $badge ?? ($badgeMap[$path] ?? ($badgeMap[$href] ?? null));
        $items[] = [
            'label' => $label,
            'href' => $href,
            'icon' => $icon,
            'section' => $section,
            'keywords' => strtolower($keywords ?: $label),
            'badge' => $resolvedBadge,
        ];
    };

    // Main
    $add('Dashboard', '/?page=dashboard', 'bi-speedometer2', 'Main', 'dashboard home overview');
    $add('Requests', '/?page=requests', 'bi-file-earmark-text', 'Main', 'requests trips list my requests');
    $add('New Request', '/?page=requests&action=create', 'bi-plus-lg', 'Main', 'new request create trip apply');
    if (isDriver()) {
        $add('My Trips', '/?page=my-trips', 'bi-truck', 'Main', 'my trips driver trips');
    }
    $add('Completed Trips', '/?page=completed-trips', 'bi-check-all', 'Main', 'completed trips history');
    if (function_exists('canAccessGasVouchers') && canAccessGasVouchers()) {
        $add('Gas Vouchers', '/?page=gas-vouchers', 'bi-fuel-pump', 'Main', 'gas vouchers fuel');
    }
    $add('Availability', '/?page=schedule&action=calendar', 'bi-calendar3', 'Main', 'availability schedule calendar planning');

    if (isApprover()) {
        $add('My Trip Tickets', '/?page=my-trip-tickets', 'bi-file-earmark-text', 'Main', 'my trip tickets');
    }
    if (isGuard()) {
        $add('Guard Dashboard', '/?page=guard', 'bi-shield-check', 'Main', 'guard dispatch arrival');
        $add('Trip Tickets', '/?page=trip-tickets', 'bi-file-earmark-text', 'Main', 'trip tickets guard');
    }
    if (isMotorpool() || isAdmin()) {
        $add('Review Trip Tickets', '/?page=trip-tickets', 'bi-clipboard-check', 'Main', 'review trip tickets motorpool');
    }
    if (isApprover()) {
        $add('Approvals', '/?page=approvals', 'bi-check-circle', 'Main', 'approvals pending motorpool department');
    }

    // Fleet Management
    if (isApprover()) {
        $add('Vehicles', '/?page=vehicles', 'bi-car-front', 'Fleet Management', 'vehicles fleet cars');
        $add('Drivers', '/?page=drivers', 'bi-person-badge', 'Fleet Management', 'drivers driver list');
        if (isAdmin() || isMotorpool() || isApprover()) {
            $add('Vehicle Types', '/?page=vehicle_types', 'bi-car-front', 'Fleet Management', 'vehicle types categories');
        }
        $add('Maintenance', '/?page=maintenance', 'bi-wrench', 'Fleet Management', 'maintenance repair');
        $add('Maintenance Schedule', '/?page=maintenance&action=schedule', 'bi-calendar-check', 'Fleet Management', 'maintenance schedule calendar');
        if (function_exists('canManageCareAssignments') && canManageCareAssignments()) {
            $add('Care Assignments', '/?page=maintenance&action=care-assign', 'bi-heart-pulse', 'Fleet Management', 'care assignments vehicle care pms');
        }
        if (canAccessSystemControl()) {
            $add('Odometers', '/?page=security&action=odometer', 'bi-speedometer', 'Fleet Management', 'odometers mileage broken');
        }
    }

    // Reports
    if (canAccessReports()) {
        $add('Reports', '/?page=reports', 'bi-bar-chart', 'Reports', 'reports analytics');
        $add('Driver Rankings', '/?page=reports&action=driver-rankings', 'bi-trophy', 'Reports', 'driver rankings evaluation rating anonymous');
        $add('Evaluations', '/?page=evaluations', 'bi-star-half', 'Reports', 'evaluations driver rating feedback anonymous');
        if (isApprover()) {
            $add('Vehicle History', '/?page=reports&action=vehicle-history', 'bi-clock-history', 'Reports', 'vehicle history trips');
            $add('Driver Report', '/?page=reports&action=driver', 'bi-person-badge', 'Reports', 'driver report trips');
            $add('Trip Requests Report', '/?page=reports&action=trips', 'bi-journal-text', 'Reports', 'trip requests report');
        }
    }

    // Administration
    if (isMotorpool() || isAdmin()) {
        $add('Users', '/?page=users', 'bi-people', 'Administration', 'users accounts employees');
        $add('Departments', '/?page=departments', 'bi-building', 'Administration', 'departments organization');
    }
    if (isAdmin() || canAccessSystemControl()) {
        $add('Gas Stations', '/?page=gas-stations', 'bi-fuel-pump-fill', 'Administration', 'gas stations fuel pump stations partners vendors');
    }
    if (isAdmin()) {
        $add('Audit Logs', '/?page=audit', 'bi-journal-text', 'Administration', 'audit logs history');
        $add('Request Rollback', '/?page=rollback', 'bi-arrow-counterclockwise', 'Administration', 'request rollback admin revert');
        $add('Settings', '/?page=settings', 'bi-gear', 'Administration', 'settings booking rules travel order confirmations');
    }

    // System Control (All Father)
    if (canAccessSystemControl()) {
        $add('Lockouts', '/?page=security&action=rate-limits', 'bi-unlock', 'System Control', 'lockouts rate limits security');
        $add('Security Summary', '/?page=security&action=summary', 'bi-bar-chart-line', 'System Control', 'security summary');
        $add('SMS', '/?page=security&action=sms', 'bi-phone', 'System Control', 'sms notifications gateway');
        $add('Email', '/?page=security&action=email', 'bi-envelope', 'System Control', 'email queue delivery');
    }

    // User
    $add('Profile', '/?page=profile', 'bi-person', 'User', 'profile account');
    $add('Notifications', '/?page=notifications', 'bi-bell', 'User', 'notifications inbox unread');
    $add('Patch Notes', '/?page=patch-notes', 'bi-journal-text', 'User', 'patch notes changelog version');

    // Quick jumps (not in sidebar but useful for power users)
    $add('View Request by ID', '/?page=requests', 'bi-hash', 'Quick Jump', 'request id goto view #', null);
    $add('View Vehicle by Plate', '/?page=vehicles', 'bi-car-front', 'Quick Jump', 'vehicle plate goto view', null);

    return $items;
}
