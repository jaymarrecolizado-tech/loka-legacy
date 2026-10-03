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

$tools = aiToolRegistry();

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
        $respond(['ok' => false, 'error' => $result['error']], 403);
    }

    $respond([
        'ok' => true,
        'executed' => [
            'tool' => $verified['tool'],
            'summary' => $result['summary'],
            'data' => $result['data'],
            'link' => $result['link'],
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
    $mins = max(1, (int) ceil($gate['remaining'] / 60));
    $respond([
        'ok' => false,
        'error' => 'You have reached the AI assistant limit for this hour. Try again in about ' . $mins . ' minute(s).',
        'rate_limited' => true,
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

$proposal = null;
if ($answer['tool'] !== null) {
    $toolId = $answer['tool']['tool'];
    $tool = $tools[$toolId];

    // Read tools run immediately — they only read, and authorisation is
    // re-checked inside the handler.
    if (empty($tool['mutating'])) {
        $result = aiToolExecute($toolId, $answer['tool']['args']);
        $proposal = [
            'tool' => $toolId,
            'mutating' => false,
            'executed' => true,
            'ok' => $result['ok'],
            'summary' => $result['ok'] ? $result['summary'] : $result['error'],
            'link' => $result['link'],
            'data' => $result['data'],
        ];
    } else {
        $proposal = [
            'tool' => $toolId,
            'mutating' => true,
            'executed' => false,
            'label' => $tool['description'],
            'args' => $answer['tool']['args'],
            'confirm_token' => aiConfirmToken((int) userId(), $toolId, $answer['tool']['args']),
        ];
    }
}

$respond([
    'ok' => true,
    'reply' => $answer['reply'],
    'proposal' => $proposal,
    'remaining' => aiAssistantRateLimit(),
]);