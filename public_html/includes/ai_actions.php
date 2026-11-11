<?php
/**
 * LOKA - AI assistant EXECUTING actions (Plan #40, All Father only)
 *
 * The assistant may perform real changes on the All Father's instruction, but
 * under three hard rules:
 *
 *  1. ONE implementation. Every action delegates to includes/maintenance_service.php
 *     (or includes/rollback_service.php), which the real screens also call. The
 *     assistant is never a second copy of the rules.
 *  2. ALL-FATHER ONLY, and never while View-as is active — otherwise an action
 *     could be taken under an impersonated identity.
 *  3. NOTHING runs until All Father sees a before/after diff and types a
 *     confirmation phrase. A single click is not enough (Plan #40 decision).
 *
 * Every execution is audited with actor, prompt reference and the state change.
 */

if (!defined('AI_ACTIONS_LOADED')) {

    define('AI_ACTIONS_LOADED', 1);

    /** Executing actions are All Father only, and refused under View-as. */
    function aiActionsAllowed(): bool
    {
        return aiToolRealAllFatherOnly() && !isViewingAs();
    }

    /** Human reason when the current caller may not execute actions. */
    function aiActionsDenialReason(): string
    {
        if (!isRealAllFather()) {
            return 'Executing AI actions are restricted to All Father.';
        }
        if (isViewingAs()) {
            return 'AI actions are blocked while View-as is active, so an action is never taken under an impersonated identity.';
        }
        return '';
    }

    /**
     * Actions the assistant may perform, as tool-id => [label, arg schema].
     *
     * @return array<string, array{label:string, description:string, args:array, mutating:bool}>
     */
    function aiActionDefinitions(): array
    {
        return [
            'create_care_item' => [
                'label' => 'Creating a vehicle care item',
                'description' => 'Create a PENDING vehicle care item for a vehicle. It still needs approval by a human.',
                'args' => [
                    'vehicle_id' => ['type' => 'integer'],
                    'care_type' => ['type' => 'string', 'maxLength' => 32],
                    'title' => ['type' => 'string', 'maxLength' => 255],
                    'due_date' => ['type' => 'string', 'maxLength' => 20],
                ],
            ],
            'approve_care_item' => [
                'label' => 'Approving a vehicle care item',
                'description' => 'Approve (schedule) a PENDING vehicle care item.',
                'args' => ['care_id' => ['type' => 'integer']],
            ],
            'complete_care_item' => [
                'label' => 'Completing a vehicle care item',
                'description' => 'Mark a vehicle care item completed, rolling any recurring interval forward.',
                'args' => [
                    'care_id' => ['type' => 'integer'],
                    'completed_mileage' => ['type' => 'integer'],
                ],
            ],
            'complete_repair_ticket' => [
                'label' => 'Completing a repair ticket',
                'description' => 'Complete a repair ticket, stamp the odometer, release the vehicle and write Repair History.',
                'args' => [
                    'ticket_id' => ['type' => 'integer'],
                    'odometer' => ['type' => 'integer'],
                    'actual_cost' => ['type' => 'number'],
                ],
            ],
            'rollback_request' => [
                'label' => 'Rolling back a trip request',
                'description' => 'Roll a trip request back to an earlier workflow stage, reversing the side effects.',
                'args' => [
                    'request_id' => ['type' => 'integer'],
                    'target_stage' => ['type' => 'string', 'maxLength' => 40],
                    'reason' => ['type' => 'string', 'maxLength' => 200],
                ],
            ],
        ];
    }

    /**
     * The phrase All Father must type to release one specific action.
     * Derived from the action id, so it cannot be replayed for another action.
     */
    function aiActionConfirmPhrase(string $toolId, array $args): string
    {
        $target = '';
        foreach (['request_id', 'ticket_id', 'care_id', 'vehicle_id'] as $k) {
            if (isset($args[$k])) {
                $target = (string) $args[$k];
                break;
            }
        }
        return trim($toolId . ' ' . $target);
    }

    /**
     * Build the before/after diff for a proposed action WITHOUT writing.
     *
     * @return array{ok:bool,error:string,summary:string,before:array,after:array,link:?string,phrase:string}
     */
    function aiActionPreview(string $toolId, array $args): array
    {
        $defs = aiActionDefinitions();
        $fail = static fn(string $e): array => [
            'ok' => false, 'error' => $e, 'summary' => '', 'before' => [], 'after' => [],
            'link' => null, 'phrase' => '',
        ];

        if (!isset($defs[$toolId])) {
            return $fail('Unknown action.');
        }
        if (!aiActionsAllowed()) {
            return $fail(aiActionsDenialReason());
        }
        require_once INCLUDES_PATH . '/maintenance_service.php';

        if ($toolId === 'create_care_item') {
            $res = maintenanceServicePreviewCreateCare(
                (int) ($args['vehicle_id'] ?? 0),
                (string) ($args['care_type'] ?? CARE_TYPE_OTHER),
                (string) ($args['title'] ?? ''),
                (string) ($args['due_date'] ?? '')
            );
        } elseif ($toolId === 'approve_care_item') {
            $item = maintenanceServiceCareItem((int) ($args['care_id'] ?? 0));
            if (!$item) {
                return $fail('That care item does not exist.');
            }
            $res = maintenanceServicePreviewApproveCare($item);
            $res['link'] = '/?page=maintenance&action=care-edit&id=' . (int) $item->id;
        } elseif ($toolId === 'complete_care_item') {
            $item = maintenanceServiceCareItem((int) ($args['care_id'] ?? 0));
            if (!$item) {
                return $fail('That care item does not exist.');
            }
            $mileage = isset($args['completed_mileage']) && $args['completed_mileage'] !== ''
                ? (int) $args['completed_mileage'] : null;
            $res = maintenanceServicePreviewCompleteCare($item, $mileage);
            $res['link'] = '/?page=maintenance&action=care-edit&id=' . (int) $item->id;
        } elseif ($toolId === 'complete_repair_ticket') {
            $mr = maintenanceServiceTicket((int) ($args['ticket_id'] ?? 0));
            if (!$mr) {
                return $fail('That repair ticket does not exist.');
            }
            $odometer = isset($args['odometer']) && $args['odometer'] !== '' ? (int) $args['odometer'] : null;
            $res = maintenanceServicePreviewCompleteTicket($mr, $odometer);
            $res['link'] = '/?page=maintenance&action=view&id=' . (int) $mr->id;
        } else { // rollback_request
            require_once INCLUDES_PATH . '/rollback_service.php';
            $requestId = (int) ($args['request_id'] ?? 0);
            $target = (string) ($args['target_stage'] ?? '');
            $reason = (string) ($args['reason'] ?? '');
            $res = rollbackServicePreview($requestId, $target, $reason);
        }

        if (!$res['ok']) {
            return $fail($res['error']);
        }

        return [
            'ok' => true,
            'error' => '',
            'summary' => $res['summary'],
            'before' => $res['before'],
            'after' => $res['after'],
            'link' => $res['link'] ?? null,
            'phrase' => aiActionConfirmPhrase($toolId, $args),
        ];
    }

    /**
     * Execute a previously previewed action. The caller must have passed the
     * typed confirmation phrase, checked against this exact action.
     *
     * @param  int $promptAuditId the ai_prompt audit row that led here
     * @return array{ok:bool,error:string,summary:string,link:?string}
     */
    function aiActionRun(string $toolId, array $args, string $typedPhrase, int $promptAuditId = 0): array
    {
        $preview = aiActionPreview($toolId, $args);
        if (!$preview['ok']) {
            auditLog('ai_action_denied', 'ai_tool', null, null, [
                'tool' => $toolId, 'reason' => $preview['error'],
            ]);
            return ['ok' => false, 'error' => $preview['error'], 'summary' => '', 'link' => null];
        }

        $expected = $preview['phrase'];
        if (trim($typedPhrase) !== $expected) {
            auditLog('ai_action_denied', 'ai_tool', null, null, [
                'tool' => $toolId, 'reason' => 'confirmation phrase mismatch',
                'expected' => $expected, 'typed' => mb_substr(trim($typedPhrase), 0, 40),
            ]);
            return [
                'ok' => false,
                'error' => 'Confirmation did not match. Type exactly: ' . $expected,
                'summary' => '', 'link' => null,
            ];
        }

        require_once INCLUDES_PATH . '/maintenance_service.php';
        $actorId = (int) userId();
        $link = $preview['link'];

        if ($toolId === 'create_care_item') {
            $res = maintenanceServiceCreateCare(
                (int) ($args['vehicle_id'] ?? 0),
                (string) ($args['care_type'] ?? CARE_TYPE_OTHER),
                (string) ($args['title'] ?? ''),
                (string) ($args['due_date'] ?? ''),
                $actorId
            );
        } elseif ($toolId === 'approve_care_item') {
            $res = maintenanceServiceApproveCare(
                maintenanceServiceCareItem((int) ($args['care_id'] ?? 0)),
                $actorId
            );
        } elseif ($toolId === 'complete_care_item') {
            $mileage = isset($args['completed_mileage']) && $args['completed_mileage'] !== ''
                ? (int) $args['completed_mileage'] : null;
            $res = maintenanceServiceCompleteCare(
                maintenanceServiceCareItem((int) ($args['care_id'] ?? 0)),
                $mileage,
                [],
                $actorId
            );
        } elseif ($toolId === 'complete_repair_ticket') {
            $odometer = isset($args['odometer']) && $args['odometer'] !== '' ? (int) $args['odometer'] : null;
            $cost = isset($args['actual_cost']) && $args['actual_cost'] !== '' ? (float) $args['actual_cost'] : null;
            $res = maintenanceServiceCompleteTicket(
                maintenanceServiceTicket((int) ($args['ticket_id'] ?? 0)),
                $odometer,
                $cost,
                $actorId
            );
        } else { // rollback_request
            require_once INCLUDES_PATH . '/rollback_service.php';
            $res = rollbackServiceRun(
                (int) ($args['request_id'] ?? 0),
                (string) ($args['target_stage'] ?? ''),
                (string) ($args['reason'] ?? ''),
                $actorId
            );
        }

        if (!$res['ok']) {
            auditLog('ai_action_failed', 'ai_tool', null, null, ['tool' => $toolId, 'error' => $res['error']]);
            return ['ok' => false, 'error' => $res['error'], 'summary' => '', 'link' => null];
        }

        auditLog('ai_action_executed', 'ai_tool', null, null, [
            'tool' => $toolId,
            'args' => $args,
            'actor_user_id' => $actorId,
            'instructed_by' => $actorId,
            'prompt_ref' => $promptAuditId ?: null,
            'via' => 'ai_assistant',
            'before' => $preview['before'],
            'after' => $res['after'] ?? [],
            'summary' => mb_substr((string) $res['summary'], 0, 300),
        ]);

        return ['ok' => true, 'error' => '', 'summary' => $res['summary'], 'link' => $link];
    }
}