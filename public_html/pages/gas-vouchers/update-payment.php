<?php
/**
 * LOKA - Gas Voucher Payment Status Update Handler (Admin only)
 */

requireAnyRole([ROLE_ADMIN, ROLE_CHIEF_ADMIN_FINANCE, ROLE_OIC_CHIEF_ADMIN_FINANCE]);

$voucherId = (int) get('id', 0);
if (!$voucherId) {
    redirectWith('/?page=gas-vouchers', 'danger', 'Invalid voucher.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $status = post('payment_status', '');
    if (!in_array($status, ['unpaid', 'paid', 'processed', 'cancelled'], true)) {
        redirectWith('/?page=gas-vouchers&action=view&id=' . $voucherId, 'danger', 'Invalid payment status.');
    }

    $voucher = db()->fetch(
        "SELECT id, voucher_no, payment_status, requested_by_user_id
         FROM gas_vouchers
         WHERE id = ? AND deleted_at IS NULL",
        [$voucherId]
    );
    if (!$voucher) {
        redirectWith('/?page=gas-vouchers', 'danger', 'Voucher not found.');
    }

    $oldStatus = (string) ($voucher->payment_status ?? '');
    if ($oldStatus === $status) {
        redirectWith('/?page=gas-vouchers&action=view&id=' . $voucherId, 'info', 'Payment status unchanged.');
    }

    db()->update('gas_vouchers', [
        'payment_status' => $status,
        'updated_at'     => date(DATETIME_FORMAT),
    ], 'id = ?', [$voucherId]);

    auditLog('update_payment', 'gas_voucher', $voucherId, ['payment_status' => $oldStatus], ['payment_status' => $status]);

    $requesterId = (int) ($voucher->requested_by_user_id ?? 0);
    if ($requesterId > 0) {
        $label = ucfirst($status);
        notify(
            $requesterId,
            'gas_voucher_payment_updated',
            'Gas Voucher Payment Updated',
            "Payment status for gas voucher {$voucher->voucher_no} is now: {$label}.",
            '/?page=gas-vouchers&action=view&id=' . $voucherId,
            $voucherId
        );
    }

    redirectWith('/?page=gas-vouchers&action=view&id=' . $voucherId, 'success', 'Payment status updated to: ' . ucfirst($status));
}

redirectWith('/?page=gas-vouchers&action=view&id=' . $voucherId, 'info', 'Nothing was changed.');

