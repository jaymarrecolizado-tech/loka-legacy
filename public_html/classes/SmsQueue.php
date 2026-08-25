<?php
/**
 * LOKA - SMS Queue (outbound notify-only)
 */

class SmsQueue
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Queue SMS for a user if enabled + allowlisted + valid phone.
     * Never throws to callers — logs and returns null on skip/failure.
     */
    public function queueForUser(
        int $userId,
        string $eventType,
        string $title,
        string $message,
        ?string $link = null,
        ?int $requestId = null
    ): ?int {
        try {
            if (!smsEnabled() || !smsEventAllowed($eventType)) {
                return null;
            }

            $user = $this->db->fetch(
                "SELECT id, phone, name FROM users WHERE id = ? AND deleted_at IS NULL AND status = 'active'",
                [$userId]
            );
            if (!$user || empty($user->phone)) {
                // Others with phones still get SMS; warn requester when we can
                if ($requestId && function_exists('smsNotifyRequesterMissingPhone')) {
                    smsNotifyRequesterMissingPhone(
                        $requestId,
                        $userId,
                        $user->name ?? ('User #' . $userId)
                    );
                }
                return null;
            }

            $phone = normalizePhoneE164((string) $user->phone);
            if ($phone === null) {
                error_log("SMS SKIP: Invalid phone for user #{$userId}");
                if ($requestId && function_exists('smsNotifyRequesterMissingPhone')) {
                    smsNotifyRequesterMissingPhone(
                        $requestId,
                        $userId,
                        $user->name ?? ('User #' . $userId)
                    );
                }
                return null;
            }

            $body = buildSmsMessage($eventType, $title, $message, $link, $requestId);
            if ($body === '') {
                return null;
            }

            $id = (int) $this->db->insert('sms_logs', [
                'user_id' => $userId,
                'request_id' => $requestId,
                'phone' => $phone,
                'event_type' => $eventType,
                'message' => $body,
                'status' => 'pending',
                'attempts' => 0,
                'created_at' => date(DATETIME_FORMAT),
            ]);

            // Queue only — never call the gateway during page requests.
            // Inline processOne() stacked curl timeouts (≈N × connect timeout) and
            // blew past max_execution_time on request create when the gateway was down.
            // Drain via cron, HTTP cron, or All Father → SMS → Process queue.

            return $id > 0 ? $id : null;
        } catch (Throwable $e) {
            error_log('SMS QUEUE ERROR: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Queue a one-off test SMS (All Father).
     */
    public function queueTest(string $phoneRaw, string $message, ?int $userId = null): ?int
    {
        $phone = normalizePhoneE164($phoneRaw);
        if ($phone === null) {
            throw new InvalidArgumentException('Invalid phone number.');
        }
        $max = (int) smsConfig('sms_max_length', '320');
        $message = trim($message);
        if ($message === '') {
            throw new InvalidArgumentException('Message is required.');
        }
        if (mb_strlen($message) > $max) {
            $message = mb_substr($message, 0, $max);
        }

        return $this->db->insert('sms_logs', [
            'user_id' => $userId,
            'request_id' => null,
            'phone' => $phone,
            'event_type' => 'test',
            'message' => $message,
            'status' => 'pending',
            'attempts' => 0,
            'created_at' => date(DATETIME_FORMAT),
        ]);
    }

    /**
     * Attempt to send a single pending SMS row immediately.
     */
    public function processOne(int $id): bool
    {
        if (!smsEnabled()) {
            return false;
        }

        $row = $this->db->fetch(
            "SELECT * FROM sms_logs WHERE id = ? AND status = 'pending' AND attempts < 5",
            [$id]
        );
        if (!$row) {
            return false;
        }

        $gateway = SmsGateway::fromConfig();
        if (!$gateway) {
            error_log('SMS PROCESS: Gateway not configured');
            return false;
        }

        $this->db->update(
            'sms_logs',
            [
                'status' => 'processing',
                'attempts' => ((int) $row->attempts) + 1,
            ],
            'id = ? AND status = ?',
            [$row->id, 'pending']
        );

        $claimed = $this->db->fetch(
            "SELECT id FROM sms_logs WHERE id = ? AND status = 'processing'",
            [$row->id]
        );
        if (!$claimed) {
            return false;
        }

        $send = $gateway->send((string) $row->phone, (string) $row->message);
        if ($send['ok']) {
            $this->db->update('sms_logs', [
                'status' => 'sent',
                'gateway_message_id' => $send['message_id'],
                'gateway_response' => $send['response'],
                'error_message' => null,
                'sent_at' => date(DATETIME_FORMAT),
            ], 'id = ?', [$row->id]);
            return true;
        }

        $attempts = ((int) $row->attempts) + 1;
        $this->db->update('sms_logs', [
            'status' => $attempts >= 5 ? 'failed' : 'pending',
            'gateway_response' => $send['response'],
            'error_message' => $send['error'],
        ], 'id = ?', [$row->id]);
        return false;
    }

    /**
     * @return array{sent:int,failed:int,skipped:int}
     */
    public function process(int $batchSize = 20): array
    {
        $results = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

        if (!smsEnabled()) {
            return $results;
        }

        $gateway = SmsGateway::fromConfig();
        if (!$gateway) {
            error_log('SMS PROCESS: Gateway not configured');
            return $results;
        }

        $limit = max(1, min(100, (int) $batchSize));
        $rows = $this->db->fetchAll(
            "SELECT * FROM sms_logs
             WHERE status = 'pending' AND attempts < 5
             ORDER BY id ASC
             LIMIT {$limit}"
        );

        foreach ($rows as $row) {
            if ($this->processOne((int) $row->id)) {
                $results['sent']++;
            } else {
                $still = $this->db->fetch("SELECT status FROM sms_logs WHERE id = ?", [$row->id]);
                if ($still && $still->status === 'pending') {
                    $results['failed']++;
                } elseif ($still && $still->status === 'failed') {
                    $results['failed']++;
                } else {
                    $results['skipped']++;
                }
            }
        }

        return $results;
    }

    /**
     * @return array{pending:int,sent:int,failed:int,processing:int}
     */
    public function getStats(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT status, COUNT(*) AS cnt FROM sms_logs GROUP BY status"
        );
        $stats = ['pending' => 0, 'sent' => 0, 'failed' => 0, 'processing' => 0];
        foreach ($rows as $row) {
            $key = (string) $row->status;
            if (isset($stats[$key])) {
                $stats[$key] = (int) $row->cnt;
            }
        }
        return $stats;
    }
}
