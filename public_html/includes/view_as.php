<?php
/**
 * All Father "View as" role — session-based effective role for testing.
 * Real identity stays All Father (System Control, audit actor user_id).
 */

const VIEW_AS_SESSION_KEY = 'view_as_role';

/**
 * Only All Father may use View-as.
 */
function canUseViewAs(): bool
{
    return isRealAllFather();
}

function isRealAdministrator(): bool
{
    return realUserRole() === ROLE_ADMIN;
}

/**
 * Roles All Father may view/act as.
 *
 * @return array<string, string> role => label
 */
function viewAsRoleOptions(): array
{
    return [
        ROLE_REQUESTER => 'Requester',
        ROLE_GUARD => 'Guard',
        ROLE_APPROVER => 'Approver',
        ROLE_MOTORPOOL => 'Motorpool Head',
        ROLE_CHIEF_ADMIN_FINANCE => 'Chief Admin & Finance',
        ROLE_OIC_CHIEF_ADMIN_FINANCE => 'OIC Chief Admin & Finance',
        ROLE_ADMIN => 'Administrator',
        'driver' => 'Driver',
    ];
}

function viewAsDefaultLabel(): string
{
    return 'All Father (default)';
}

function realUserRole(): ?string
{
    return $_SESSION['user_role'] ?? null;
}

function isRealAllFather(): bool
{
    return realUserRole() === ROLE_ALL_FATHER;
}

function isViewingAs(): bool
{
    return isRealAllFather() && getViewAsRole() !== null;
}

function getViewAsRole(): ?string
{
    if (!isRealAllFather()) {
        return null;
    }
    $role = $_SESSION[VIEW_AS_SESSION_KEY] ?? null;
    if ($role === null || $role === '') {
        return null;
    }
    $allowed = viewAsRoleOptions();
    return isset($allowed[$role]) ? (string) $role : null;
}

/**
 * Effective role for gates/UI (View-as when active).
 */
function effectiveUserRole(): ?string
{
    $viewAs = getViewAsRole();
    if ($viewAs !== null) {
        // Driver is not a users.role — treat as requester-level for hasRole hierarchy
        if ($viewAs === 'driver') {
            return ROLE_REQUESTER;
        }
        return $viewAs;
    }
    return realUserRole();
}

function viewAsTestDriverId(): ?int
{
    $fromEnv = (int) (getenv('VIEW_AS_TEST_DRIVER_ID') ?: 0);
    if ($fromEnv > 0) {
        $row = db()->fetch(
            "SELECT id FROM drivers WHERE id = ? AND deleted_at IS NULL LIMIT 1",
            [$fromEnv]
        );
        if ($row) {
            return (int) $row->id;
        }
    }

    static $fallback = false;
    if ($fallback === false) {
        $row = db()->fetch(
            "SELECT id FROM drivers WHERE deleted_at IS NULL AND status = 'available' ORDER BY id ASC LIMIT 1"
        );
        if (!$row) {
            $row = db()->fetch(
                "SELECT id FROM drivers WHERE deleted_at IS NULL ORDER BY id ASC LIMIT 1"
            );
        }
        $fallback = $row ? (int) $row->id : null;
    }

    return $fallback;
}

/**
 * Set or clear View-as. Returns false if not allowed / invalid role.
 */
function setViewAsRole(?string $role): bool
{
    if (!isRealAllFather()) {
        return false;
    }

    if ($role === null || $role === '' || $role === 'none' || $role === ROLE_ALL_FATHER) {
        $prev = getViewAsRole();
        unset($_SESSION[VIEW_AS_SESSION_KEY]);
        if ($prev !== null && function_exists('auditLog')) {
            auditLog('view_as_exit', 'session', userId(), ['role' => $prev], null);
        }
        return true;
    }

    if (!isset(viewAsRoleOptions()[$role])) {
        return false;
    }

    if ($role === 'driver' && viewAsTestDriverId() === null) {
        return false;
    }

    $_SESSION[VIEW_AS_SESSION_KEY] = $role;
    if (function_exists('auditLog')) {
        auditLog('view_as_enter', 'session', userId(), null, ['role' => $role]);
    }
    return true;
}

function viewAsBannerLabel(): ?string
{
    $role = getViewAsRole();
    if ($role === null) {
        return null;
    }
    return viewAsRoleOptions()[$role] ?? $role;
}
