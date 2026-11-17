<?php
/**
 * LOKA - Soft-delete a trip ticket (motorpool / admin)
 */

requireAnyRole([ROLE_MOTORPOOL, ROLE_ADMIN]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectWith('/?page=trip-tickets', 'danger', 'Invalid request.');
}

requireCsrf();

$ticketId = (int) post('id');
if ($ticketId <= 0) {
    redirectWith('/?page=trip-tickets', 'danger', 'Ticket ID is required.');
}

$ticket = db()->fetch(
    "SELECT * FROM trip_tickets WHERE id = ? AND deleted_at IS NULL",
    [$ticketId]
);

if (!$ticket) {
    redirectWith('/?page=trip-tickets', 'danger', 'Trip ticket not found.');
}

try {
    db()->beginTransaction();

    db()->softDelete('trip_tickets', 'id = ?', [$ticketId]);

    if (!empty($ticket->request_id)) {
        db()->update(
            'requests',
            ['trip_ticket_id' => null],
            'id = ? AND trip_ticket_id = ?',
            [(int) $ticket->request_id, $ticketId]
        );
    }

    auditLog(
        'trip_ticket_deleted',
        'trip_ticket',
        $ticketId,
        [
            'request_id' => $ticket->request_id,
            'status' => $ticket->status,
            'destination' => $ticket->destination ?? null,
        ]
    );

    db()->commit();
} catch (Exception $e) {
    if (db()->inTransaction()) {
        db()->rollback();
    }
    error_log('Trip ticket delete error: ' . $e->getMessage());
    redirectWith('/?page=trip-tickets', 'danger', 'An error occurred while deleting the trip ticket.');
}

redirectWith(
    '/?page=trip-tickets',
    'success',
    'Trip ticket TT-' . (int) $ticket->request_id . ' deleted.'
);
