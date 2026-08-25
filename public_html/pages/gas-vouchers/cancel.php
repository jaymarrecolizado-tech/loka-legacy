<?php
/**
 * LOKA - Gas Voucher Cancel Handler
 */

$voucherId = (int) get('id', 0);
if (!$voucherId) {
    redirectWith('/?page=gas-vouchers', 'danger', 'Invalid voucher.');
}

$voucher = db()->fetch("SELECT * FROM gas_vouchers WHERE id = ? AND deleted_at IS NULL", [$voucherId]);

if (!$voucher) {
    redirectWith('/?page=gas-vouchers', 'danger', 'Voucher not found.');
}

if ($voucher->requested_by_user_id != userId() && !isAdmin() && !isChiefAdminFinance()) {
    redirectWith('/?page=gas-vouchers', 'danger', 'Access denied.');
}

if (!in_array($voucher->status, ['draft', 'pending_review'])) {
    redirectWith('/?page=gas-vouchers&action=view&id=' . $voucherId, 'warning', 'This voucher cannot be cancelled at this stage.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $isSelfCancel = ((int) $voucher->requested_by_user_id === (int) userId());
    $previousStatus = $voucher->status;

    db()->update('gas_vouchers', [
        'status'     => 'cancelled',
        'updated_at' => date(DATETIME_FORMAT),
    ], 'id = ?', [$voucherId]);

    auditLog('cancel', 'gas_voucher', $voucherId);

    $cancelledBy = currentUser()->name ?? 'An administrator';
    $link = '/?page=gas-vouchers&action=view&id=' . $voucherId;

    // Notify requester when cancelled by someone else (skip email/SMS echo on self-cancel)
    if (!$isSelfCancel && (int) $voucher->requested_by_user_id > 0) {
        notify(
            (int) $voucher->requested_by_user_id,
            'gas_voucher_cancelled',
            'Gas Voucher Cancelled',
            "Your gas voucher {$voucher->voucher_no} has been cancelled by {$cancelledBy}.",
            $link,
            $voucherId
        );
    } elseif ($isSelfCancel && (int) $voucher->requested_by_user_id > 0) {
        notify(
            (int) $voucher->requested_by_user_id,
            'gas_voucher_cancelled',
            'Gas Voucher Cancelled',
            "Your gas voucher {$voucher->voucher_no} has been cancelled.",
            $link,
            $voucherId,
            false
        );
    }

    // Notify approvers if it was pending
    if ($previousStatus === 'pending_review') {
        $approvers = db()->fetchAll(
            "SELECT id FROM users WHERE role IN (?, ?, ?, ?, ?) AND deleted_at IS NULL AND status = 'active'",
            [ROLE_APPROVER, ROLE_MOTORPOOL, ROLE_ADMIN, ROLE_CHIEF_ADMIN_FINANCE, ROLE_OIC_CHIEF_ADMIN_FINANCE]
        );
        foreach ($approvers as $approver) {
            if ((int) $approver->id === (int) userId()) {
                continue;
            }
            notify(
                (int) $approver->id,
                'gas_voucher_cancelled',
                'Gas Voucher Cancelled',
                "Gas voucher {$voucher->voucher_no} has been cancelled by {$cancelledBy}.",
                '/?page=gas-vouchers',
                $voucherId
            );
        }
    }

    redirectWith('/?page=gas-vouchers', 'success', 'Gas voucher cancelled.');
}

redirectWith('/?page=gas-vouchers&action=view&id=' . $voucherId, 'info', 'Nothing was changed.');

