<?php
/**
 * LOKA - AI assistant chat endpoint (Plan #40, experimental)
 *
 * POST ?page=api&action=ai_chat
 *   op=ask    body: {prompt}
 *             -> {reply, proposal:{tool,label,args,summary,confirm_token}|null}
 *   op=confirm body: {confirm_token}
 *             -> {reply, executed:{summary,link}}
 *
 * Hardening (Plan #40 decision 4):
 *  - session cookie + CSRF on every call (same-origin app; no CORS headers)
 *  - per-user hourly rate limit via the existing Security lockout table
 *  - tools come from the registry only; an unknown tool is rejected + audited
 *  - authorisation re-checked inside aiToolExecute(), never from the model
 *  - mutating tools need a signed, 2-minute, single-user confirm token
 *  - every proposal and execution is written to audit_logs
 *  - API key never leaves the server
 */

require_once INCLUDES_PATH . '/ai_assistant.php';
require_once INCLUDES_PATH . '/ai_tools.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

/** JSON reply and stop. */
$respond = static function (array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

// The chat posts JSON, but verifyCsrf() reads $_POST — merge the body in
// BEFORE the auth/CSRF gate so the token is actually checked.
$raw = file_get_contents('php://input') ?: '';
if (strlen($raw) > 20000) {
    $respond(['ok' => false, 'error' => 'Request too large.'], 413);
}
$decoded = json_decode($raw, true);
$body = is_array($decoded) ? $decoded : $_POST;
if (is_array($decoded)) {
    foreach ($decoded as $k => $v) {
        if (is_scalar($v) && !isset($_POST[$k])) {
            $_POST[$k] = $v;
        }
    }
}

requireAuth();
requireCsrf();

$status = aiAssistantStatus();
if (!$status['ready']) {
    $reason = $status['reason'] === 'no_api_key'
        ? 'The AI assistant has no provider API key configured yet. Ask an All Father to set one in System Control.'
        : 'The AI assistant is disabled.';
    $respond(['ok' => false, 'error' => $reason, 'disabled' => true], 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $respond(['ok' => false, 'error' => 'POST required.'], 405);
}

$op = (string) ($body['op'] ?? 'ask');

// Full registry — used to resolve a confirmed call and to read its mutating
// flag. Execution is gated again inside aiToolExecute().
$allTools = aiToolRegistry();

// Only the tools THIS user may use are advertised to the provider, so a
// requester or guard is never told that ops-only actions exist.
$tools = aiToolsForCurrentUser();

if ($op === 'confirm' || $op === 'action') {
    // Mutations get their own burst window: a stolen session must not be
    // able to fire confirm/action in a tight loop inside the token TTL.
    $mut = Security::getInstance();
    $mutUid = (string) userId();
    if ($mut->isRateLimited('ai_mutate_min', $mutUid, aiAssistantBurstLimit(), 60)) {
        auditLog('ai_mutate_rate_limited', 'ai_tool', null, null, ['op' => $op]);
        $respond(['ok' => false, 'error' => 'Too many change requests — slow down.', 'rate_limited' => true, 'scope' => 'minute'], 429);
    }
    $mut->recordAttempt('ai_mutate_min', $mutUid);
}

/* ------------------------------------------------------------------ */
/* action — execute an All Father action after its typed confirmation   */
/* ------------------------------------------------------------------ */
if ($op === 'action') {
    require_once INCLUDES_PATH . '/ai_actions.php';

    $toolId = (string) ($body['tool'] ?? '');
    $args = (array) ($body['args'] ?? []);
    $phrase = (string) ($body['phrase'] ?? '');
    $promptRef = (int) ($body['prompt_ref'] ?? 0);

    if (!isset($allTools[$toolId]) || empty($allTools[$toolId]['is_action'])) {
        $respond(['ok' => false, 'error' => 'That is not an executable action.'], 400);
    }

    // Re-sanitise server-side: never trust the args the browser echoes back.
    $args = aiSanitizeToolArgs($toolId, $args, $allTools);

    $result = aiActionRun($toolId, $args, $phrase, $promptRef);
    if (!$result['ok']) {
        $respond(['ok' => false, 'error' => $result['error']], 403);
    }
    $respond([
        'ok' => true,
        'executed' => [
            'tool' => $toolId,
            'label' => $allTools[$toolId]['label'],
            'trace' => $result['summary'],
            'mutating' => true,
            'summary' => $result['summary'],
            'link' => $result['link'],
        ],
    ]);
}

/* ------------------------------------------------------------------ */
/* confirm — run a previously proposed mutating tool                    */
/* ------------------------------------------------------------------ */
if ($op === 'confirm') {
    $token = (string) ($body['confirm_token'] ?? '');
    $verified = aiConfirmTokenVerify($token, (int) userId());
    if (!$verified['ok']) {
        auditLog('ai_confirm_rejected', 'ai_tool', null, null, ['reason' => $verified['reason']]);
        $respond(['ok' => false, 'error' => $verified['reason']], 403);
    }

    $result = aiToolExecute($verified['tool'], $verified['args']);
    if (!$result['ok']) {
        $respond([
            'ok' => false,
            'error' => $result['error'],
            'trace' => aiToolTraceLine($verified['tool'], $verified['args']),
        ], 403);
    }

    $respond([
        'ok' => true,
        'executed' => [
            'tool'     => $verified['tool'],
            'label'    => aiToolLabel($verified['tool']),
            'trace'    => aiToolTraceLine($verified['tool'], $verified['args']),
            'mutating' => !empty($allTools[$verified['tool']]['mutating'] ?? false),
            'summary'  => $result['summary'],
            'data'     => $result['data'],
            'link'     => $result['link'],
        ],
    ]);
}

/* ------------------------------------------------------------------ */
/* ask — prompt the model, return a reply and at most one proposal      */
/* ------------------------------------------------------------------ */
if ($op !== 'ask') {
    $respond(['ok' => false, 'error' => 'Unknown operation.'], 400);
}

// Rate limit before spending a provider call.
$gate = aiAssistantRateGate();
if (!$gate['allowed']) {
    $wait = $gate['scope'] === 'minute'
        ? max(1, (int) ceil($gate['remaining'] / 60))
        : max(1, (int) ceil($gate['remaining'] / 60));
    $respond([
        'ok' => false,
        'error' => 'You have reached the AI assistant limit'
            . ($gate['scope'] === 'minute' ? ' for this minute' : ' for this hour')
            . ' (' . ($gate['scope'] === 'minute' ? aiAssistantBurstLimit() : aiAssistantRateLimit())
            . ' prompt(s)). Try again in about ' . $wait . ' minute(s).',
        'rate_limited' => true,
        'scope' => $gate['scope'],
    ], 429);
}

$prompt = (string) ($body['prompt'] ?? '');
if (strlen($prompt) > 20000) {
    $respond(['ok' => false, 'error' => 'Prompt too large.'], 413);
}
if (trim($prompt) === '') {
    $respond(['ok' => false, 'error' => 'Please type a question.'], 400);
}

$answer = aiAssistantAsk($prompt, $tools);
if (!$answer['ok']) {
    $respond(['ok' => false, 'error' => $answer['error']], 502);
}

aiAssistantRecordPrompt();
auditLog('ai_prompt', 'ai_assistant', null, null, [
    'chars' => mb_strlen($prompt),
    'proposed_tool' => $answer['tool']['tool'] ?? null,
]);
// auditLog() is void by design, so read back the id we just wrote — it is the
// reference an executed action is audited against.
$promptAuditId = (int) db()->getConnection()->lastInsertId();

// The endpoint PROPOSES only — it never executes here. That is what lets the UI
// show "Searching your trips — query: SBY 225" on screen *while* the call runs,
// instead of the action appearing with its answer already in hand. Execution
// happens on op=confirm, through the same signed-token + audit path for BOTH
// read and mutating tools.
$proposal = null;
if ($answer['tool'] !== null) {
    $toolId = $answer['tool']['tool'];
    $tool = $tools[$toolId];
    $args = $answer['tool']['args'];

    if (!empty($tool['is_action'])) {
        // Plan #40 executing action: return a before/after diff and the phrase
        // All Father must type. Nothing is executed on this round trip.
        require_once INCLUDES_PATH . '/ai_actions.php';
        $preview = aiActionPreview($toolId, $args);
        $proposal = [
            'kind'          => 'action',
            'tool'          => $toolId,
            'mutating'      => true,
            'label'         => aiToolLabel($toolId),
            'trace'         => aiToolTraceLine($toolId, $args),
            'description'   => $tool['description'],
            'args'          => $args,
            'ok'            => $preview['ok'],
            'error'         => $preview['error'],
            'summary'       => $preview['summary'],
            'before'        => $preview['before'],
            'after'         => $preview['after'],
            'phrase'        => $preview['phrase'],
            'link'          => $preview['link'],
            'prompt_ref'    => (int) $promptAuditId,
        ];
    } else {
        $proposal = [
            'tool'          => $toolId,
            'mutating'      => !empty($tool['mutating']),
            'label'         => aiToolLabel($toolId),
            'trace'         => aiToolTraceLine($toolId, $args),
            'description'   => $tool['description'],
            'args'          => $args,
            'confirm_token' => aiConfirmToken((int) userId(), $toolId, $args),
        ];
    }
}

$respond([
    'ok' => true,
    'reply' => $answer['reply'],
    'proposal' => $proposal,
    'remaining' => aiAssistantRateLimit(),
]);