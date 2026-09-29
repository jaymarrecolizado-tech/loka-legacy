<?php
/**
 * LOKA - Messenger Channel Queue (Plan #35: Telegram)
 *
 * Outbound notify-only queue over channel_logs, mirroring SmsQueue/sms_logs.
 * Queue-only: gateways are never called during the HTTP page request — drain
 * via cron/process_channel_queue.php, the HTTP cron action, or the System
 * Control "Process queue" button.
 */

class ChannelQueue
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Queue a channel message for a user if the channel is enabled + the
     * event is allowlisted + a binding exists. Never throws to callers.
     */
    public function queueForUser(
        int $userId,
        string $channel,
        string $eventType,
        string $title,
        string $message,
        ?string $link = null,
        ?int $requestId = null
    ): ?int {
        try {
            if (!in_array($channel, LOKA_CHANNELS, true)) {
                return null;
            }
            if (!function_exists('channelEnabled') || !channelEnabled($channel)) {
                return null;
            }
            if (!function_exists('channelEventAllowed') || !channelEventAllowed($channel, $eventType)) {
                return null;
            }
            $binding = function_exists('channelGetBinding') ? channelGetBinding($userId, $channel) : null;
            if (!$binding) {
                return null;
            }

            $body = function_exists('channelBuildMessage')
                ? channelBuildMessage($channel, $eventType, $title, $message, $link, $requestId)
                : trim($title);
            if ($body === '') {
                return null;
            }

            $id = (int) $this->db->insert('channel_logs', [
                'user_id'     => $userId,
                'channel'     => $channel,
                'chat_id'     => (string) $binding->chat_id,
                'request_id'  => $requestId,
                'event_type'  => $eventType,
                'message'     => $body,
                'status'      => 'pending',
                'attempts'    => 0,
                'created_at'  => date(DATETIME_FORMAT),
            ]);

            return $id > 0 ? $id : null;
        } catch (Throwable $e) {
            error_log('CHANNEL QUEUE ERROR: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Queue a one-off test message to an explicit chat id (All Father tools).
     * Bypasses binding/allowlist; still requires the channel to be enabled.
     */
    public function queueTest(string $channel, string $chatId, string $message, ?int $userId = null): ?int
    {
        if (!in_array($channel, LOKA_CHANNELS, true)) {
            throw new InvalidArgumentException('Unknown channel.');
        }
        $message = trim($message);
        if ($message === '') {
            throw new InvalidArgumentException('Message is required.');
        }
        if (!channelEnabled($channel)) {
            throw new RuntimeException(ucfirst($channel) . ' notifications are disabled.');
        }

        return (int) $this->db->insert('channel_logs', [
            'user_id'    => $userId,
            'channel'    => $channel,
            'chat_id'    => $chatId,
            'request_id' => null,
            'event_type' => 'test',
            'message'    => $message,
            'status'     => 'pending',
            'attempts'   => 0,
            'created_at' => date(DATETIME_FORMAT),
        ]);
    }

    /**
     * Attempt to send a single pending row immediately.
     */
    public function processOne(int $id): bool
    {
        $row = $this->db->fetch(
            "SELECT * FROM channel_logs WHERE id = ? AND status = 'pending' AND attempts < 5",
            [$id]
        );
        if (!$row) {
            return false;
        }

        $gateway = $this->gatewayFor((string) $row->channel);
        if (!$gateway) {
            error_log('CHANNEL PROCESS: ' . $row->channel . ' gateway not configured');
            return false;
        }

        $this->db->update(
            'channel_logs',
            [
                'status'   => 'processing',
                'attempts' => ((int) $row->attempts) + 1,
            ],
            'id = ? AND status = ?',
            [$row->id, 'pending']
        );

        $claimed = $this->db->fetch(
            "SELECT id FROM channel_logs WHERE id = ? AND status = 'processing'",
            [$row->id]
        );
        if (!$claimed) {
            return false;
        }

        $send = $gateway->send((string) $row->chat_id, (string) $row->message);
        if ($send['ok']) {
            $this->db->update('channel_logs', [
                'status'             => 'sent',
                'gateway_message_id' => $send['message_id'],
                'gateway_response'   => $send['response'],
                'error_message'      => null,
                'sent_at'            => date(DATETIME_FORMAT),
            ], 'id = ?', [$row->id]);
            return true;
        }

        $attempts = ((int) $row->attempts) + 1;
        $this->db->update('channel_logs', [
            'status'           => $attempts >= 5 ? 'failed' : 'pending',
            'gateway_response' => $send['response'],
            'error_message'    => $send['error'],
        ], 'id = ?', [$row->id]);
        return false;
    }

    /**
     * Drain pending rows for one channel.
     *
     * @return array{sent:int,failed:int,skipped:int}
     */
    public function process(string $channel, int $batchSize = 20): array
    {
        $results = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

        if (!in_array($channel, LOKA_CHANNELS, true)) {
            return $results;
        }
        if (!channelEnabled($channel)) {
            return $results;
        }
        if (!$this->gatewayFor($channel)) {
            error_log('CHANNEL PROCESS: ' . $channel . ' gateway not configured');
            return $results;
        }

        $limit = max(1, min(100, (int) $batchSize));
        $rows = $this->db->fetchAll(
            "SELECT id FROM channel_logs
             WHERE channel = ? AND status = 'pending' AND attempts < 5
             ORDER BY id ASC
             LIMIT {$limit}",
            [$channel]
        );

        foreach ($rows as $row) {
            if ($this->processOne((int) $row->id)) {
                $results['sent']++;
            } else {
                $still = $this->db->fetch("SELECT status FROM channel_logs WHERE id = ?", [$row->id]);
                if ($still && in_array($still->status, ['pending', 'failed'], true)) {
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
    public function getStats(string $channel): array
    {
        $rows = $this->db->fetchAll(
            "SELECT status, COUNT(*) AS cnt FROM channel_logs WHERE channel = ? GROUP BY status",
            [$channel]
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

    /** Gateway for a channel, or null when not configured/enabled. */
    private function gatewayFor(string $channel): ?object
    {
        return $channel === 'telegram' ? TelegramGateway::fromConfig() : null;
    }
}
