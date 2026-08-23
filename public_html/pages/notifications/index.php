<?php
$pageTitle = 'Notifications';

$view = get('view', 'inbox');
$isArchive = $view === 'archive';
$isAll = $view === 'all';

$where = "user_id = ? AND deleted_at IS NULL";
$params = [userId()];

if ($isArchive) {
    $where .= " AND is_archived = 1";
} elseif (!$isAll) {
    $where .= " AND is_archived = 0 AND is_read = 0";
}

$notifications = db()->fetchAll(
    "SELECT * FROM notifications WHERE {$where} ORDER BY created_at DESC LIMIT 100",
    $params
);

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-12 col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header bg-white border-0 py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <h4 class="card-title mb-0">
                            <i class="bi bi-bell me-2"></i>Notifications
                        </h4>
                        <div class="btn-group" role="group">
                            <a href="<?= APP_URL ?>/?page=notifications&view=inbox" 
                               class="btn btn-sm <?= $view === 'inbox' ? 'btn-primary' : 'btn-outline-primary' ?>">
                                <i class="bi bi-inbox me-1"></i>Inbox
                            </a>
                            <a href="<?= APP_URL ?>/?page=notifications&view=all" 
                               class="btn btn-sm <?= $view === 'all' ? 'btn-primary' : 'btn-outline-primary' ?>">
                                <i class="bi bi-list-check me-1"></i>All
                            </a>
                            <a href="<?= APP_URL ?>/?page=notifications&view=archive" 
                               class="btn btn-sm <?= $view === 'archive' ? 'btn-primary' : 'btn-outline-primary' ?>">
                                <i class="bi bi-archive me-1"></i>Archive
                            </a>
                        </div>
                    </div>
                </div>

                <div class="card-body p-0">
                    <?php if (empty($notifications)): ?>
                    <div class="empty-state py-5">
                        <div class="text-center">
                            <i class="bi bi-bell-slash display-4 text-muted mb-3 d-block"></i>
                            <h5 class="text-muted">No notifications in <?= ucfirst($view) ?></h5>
                            <p class="text-muted">You're all caught up!</p>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="list-group list-group-flush" id="notificationsList">
                        <?php foreach ($notifications as $notif): ?>
                        <div class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?= $notif->is_read ? '' : 'bg-light border-start border-primary border-4' ?>">
                            <div class="d-flex align-items-start flex-grow-1">
                                <div class="p-2 me-3 bg-opacity-10 rounded-circle <?= $notif->is_read ? 'bg-secondary' : 'bg-primary' ?>">
                                    <i class="bi bi-bell <?= $notif->is_read ? 'text-secondary' : 'text-primary' ?>"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="d-flex justify-content-between mb-1">
                                        <h6 class="mb-0 <?= $notif->is_read ? '' : 'fw-bold' ?>">
                                            <a href="<?= APP_URL ?>/?page=notifications&action=read&id=<?= $notif->id ?>" 
                                               class="text-decoration-none text-dark">
                                                <?= e($notif->title) ?>
                                            </a>
                                        </h6>
                                        <span class="text-muted small"><?= formatDateTime($notif->created_at) ?></span>
                                    </div>
                                    <p class="mb-1 text-muted small"><?= e($notif->message) ?></p>
                                    <div class="d-flex gap-3">
                                        <?php if ($notif->link): ?>
                                        <a href="<?= APP_URL ?>/?page=notifications&action=read&id=<?= $notif->id ?>" 
                                           class="btn btn-link btn-sm p-0 text-decoration-none small">
                                            <i class="bi bi-box-arrow-up-right me-1"></i>View Details
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="ms-3 dropdown">
                                <button class="btn btn-sm btn-light border" type="button" data-bs-toggle="dropdown">
                                    <i class="bi bi-three-dots-vertical"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                    <li>
                                        <a class="dropdown-item"
                                           href="<?= APP_URL ?>/?page=notifications&action=archive&id=<?= $notif->id ?>&view=<?= $view ?>">
                                            <i class="bi <?= $isArchive ? 'bi-inbox' : 'bi-archive' ?> me-2"></i>
                                            <?= $isArchive ? 'Move to Inbox' : 'Archive' ?>
                                        </a>
                                    </li>
                                    <li>
                                        <form method="POST" action="<?= APP_URL ?>/?page=notifications&action=delete&view=<?= $view ?>" style="display:inline;">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="id" value="<?= $notif->id ?>">
                                            <button type="submit" class="dropdown-item text-danger" onclick="return confirm('Delete this notification?')">
                                                <i class="bi bi-trash me-2"></i>Delete
                                            </button>
                                        </form>
                                    </li>
                                </ul>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($notifications)): ?>
                <div class="card-footer bg-white border-0 py-3 d-flex justify-content-between">
                    <small class="text-muted"><?= count($notifications) ?> notification(s) displayed</small>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            Bulk Actions
                        </button>
                        <ul class="dropdown-menu">
                            <?php if ($view === 'inbox'): ?>
                            <li>
                                <a href="<?= APP_URL ?>/?page=notifications&action=read-all&view=<?= $view ?>" 
                                   class="dropdown-item">
                                    <i class="bi bi-check2-all me-2"></i>Mark All as Read
                                </a>
                            </li>
                            <?php endif; ?>
                            <li>
                                <a href="<?= APP_URL ?>/?page=notifications&action=archive-all&view=<?= $view ?>" 
                                   class="dropdown-item">
                                    <i class="bi bi-archive me-2"></i>Archive All
                                </a>
                            </li>
                            <li>
                                <a href="<?= APP_URL ?>/?page=notifications&action=delete-all&view=<?= $view ?>" 
                                   class="dropdown-item text-danger"
                                   onclick="return confirm('Delete all notifications in <?= $view ?>?')">
                                    <i class="bi bi-trash me-2"></i>Clear All
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php 
$pageScripts = <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function() {
    let refreshInterval;
    
    function refreshNotifications() {
        fetch('<?= APP_URL ?>/?page=notifications&action=refresh-ajax&view=<?= $view ?>', {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const notificationsList = document.getElementById('notificationsList');
                if (notificationsList) {
                    notificationsList.innerHTML = data.html;
                }
                
                const badge = document.querySelector('.badge.bg-danger');
                if (badge && data.unread !== undefined) {
                    if (data.unread > 0) {
                        badge.textContent = data.unread > 9 ? '9+' : data.unread;
                        badge.classList.remove('d-none');
                    } else {
                        badge.classList.add('d-none');
                    }
                }
            }
        })
        .catch(error => console.error('Refresh error:', error));
    }
    
    refreshInterval = setInterval(refreshNotifications, 30000);
});
</script>
HTML;

require_once INCLUDES_PATH . '/footer.php'; 
?>