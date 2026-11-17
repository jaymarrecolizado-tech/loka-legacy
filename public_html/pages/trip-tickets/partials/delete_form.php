<?php
/** Expects int $ticketId. Motorpool / admin only. */
if (!empty($ticketId) && (isAdmin() || isMotorpool())): ?>
<form method="POST" action="<?= APP_URL ?>/?page=trip-tickets&action=delete" class="d-inline">
    <?= csrfField() ?>
    <input type="hidden" name="id" value="<?= (int) $ticketId ?>">
    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete trip ticket"
        data-confirm="Delete this trip ticket? The completed trip stays; only this ticket is removed.">
        <i class="bi bi-trash"></i>
    </button>
</form>
<?php endif; ?>
