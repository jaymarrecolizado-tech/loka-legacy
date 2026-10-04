<?php
/**
 * All Father — AI assistant settings (Plan #40)
 *
 * Toggle + provider key/model/base URL + the tool inventory. The key is
 * write-only: it is never rendered back into the page.
 */

require_once INCLUDES_PATH . '/ai_assistant.php';
require_once INCLUDES_PATH . '/ai_tools.php';
requireSystemControl();

$pageTitle = 'AI Assistant';
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $op = postSafe('op', '', 20);
    // Pressing Enter in a text field submits without a submitter button.
    if ($op === '') {
        $op = 'save';
    }
    try {
        if ($op === 'refresh_models') {
            $result = aiFetchFreeModels(true);
            if ($result['ok']) {
                redirectWith(
                    '/?page=security&action=ai-assistant',
                    'success',
                    'Loaded ' . count($result['models']) . ' free chat model(s) from the provider.'
                );
            }
            redirectWith(
                '/?page=security&action=ai-assistant',
                'warning',
                $result['error'] ?: 'Could not load the model catalogue.'
            );
        }

        if ($op === 'save') {
            $enabled = post('ai_assistant_enabled', '0') === '1' ? '1' : '0';
            $baseUrl = trim(postSafe('ai_base_url', '', 200)) ?: AI_DEFAULT_BASE_URL;
            $model = trim(postSafe('ai_model', '', 120)) ?: 'openrouter/free';
            $limit = max(1, min(600, (int) post('ai_rate_limit_per_hour', 30)));

            // Only allow an http(s) endpoint so the key cannot be exfiltrated.
            if (!preg_match('#^https?://#i', $baseUrl)) {
                throw new InvalidArgumentException('The API base URL must start with http:// or https://');
            }

            $upsert = static function (string $key, string $value, string $type = 'string'): void {
                db()->query(
                    "INSERT INTO settings (`key`, value, type, category, created_at, updated_at)
                     VALUES (?, ?, ?, 'experimental', NOW(), NOW())
                     ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()",
                    [$key, $value, $type]
                );
            };

            aiAssistantSaveKey(postSafe('ai_api_key', '', 300));
            $upsert('ai_base_url', $baseUrl);
            // Never persist a model the provider's free list does not contain
            // without saying so — but DO allow it, since a paid or self-hosted
            // model is a legitimate choice for an All Father.
            $upsert('ai_model', $model);
            $upsert('ai_rate_limit_per_hour', (string) $limit, 'integer');
            if (aiAssistantApiKey() === '') {
                throw new InvalidArgumentException('A provider API key is required before the assistant can be switched on.');
            }
            $upsert('ai_assistant_enabled', $enabled, 'boolean');

            auditLog('ai_assistant_settings_updated', 'settings', null, null, [
                'enabled' => $enabled,
                'model' => $model,
                'rate_limit_per_hour' => $limit,
            ]);
            redirectWith('/?page=security&action=ai-assistant', 'success', 'AI assistant settings saved.');
        }
    } catch (Throwable $e) {
        $flash = ['danger', $e->getMessage()];
    }
}

$status = aiAssistantStatus();
$hasKey = aiAssistantApiKey() !== '';
// Last 4 only: enough to tell WHICH key is active, not enough to use it. The
// key field itself stays write-only, so a blank field after saving must not
// look like a failure.
$keyFingerprint = $hasKey ? substr(aiAssistantApiKey(), -4) : '';
$tools = aiToolSummaries();

// Free chat models from the provider catalogue (cached; never blocks the page).
$models = aiCachedFreeModels();
$modelsAt = (int) tripSetting('ai_free_models_at', '0');
$modelIsListed = aiModelIsFreeAndUsable(aiAssistantModel());
$toolCapable = array_values(array_filter($models, static fn($m) => !empty($m['tools'])));
$routerModels = array_values(array_filter($models, static fn($m) => !empty($m['router'])));

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid px-4 py-4">
    <div class="mb-2">
        <h4 class="mb-1"><i class="bi bi-stars me-2"></i>AI Assistant</h4>
        <p class="text-muted small mb-0">
            Experimental (Plan #40), ships <strong>off</strong>. The model can only
            <em>propose</em> an action from the tool registry below; PHP re-checks your
            role on every call, and anything that changes data needs an explicit
            confirmation first.
        </p>
    </div>

    <?php require __DIR__ . '/partials/subnav.php'; ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash[0]) ?>"><?= e($flash[1]) ?></div>
    <?php endif; ?>

    <?php if (!$hasKey): ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            No provider API key is stored, so the assistant cannot answer. Add one below and save.
        </div>
    <?php endif; ?>

    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-body">
                    <h5 class="mb-3">Settings</h5>
                    <form method="POST">
                        <?= csrfField() ?>
                        <!-- No hidden op=save here. It used to fight a nested
                             "refresh models" form: the browser merged both into
                             one form, submitted op twice, and PHP kept the LAST
                             value — so clicking Save settings ran the refresh
                             branch and silently never saved the API key.
                             The clicked submit button now carries op, and an
                             empty op (Enter in a text field) means save. -->

                        <div class="form-check mb-3">
                            <input type="checkbox" name="ai_assistant_enabled" value="1" class="form-check-input"
                                   id="aiEnabled" <?= aiAssistantEnabled() ? 'checked' : '' ?>>
                            <label class="form-check-label" for="aiEnabled">
                                Enable the AI assistant
                            </label>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="ai_api_key">OpenRouter API key</label>
                            <input type="password" name="ai_api_key" class="form-control" id="ai_api_key"
                                   autocomplete="new-password"
                                   placeholder="<?= $hasKey ? '•••••••• (leave blank to keep the current key)' : 'sk-or-v1-...' ?>">
                            <div class="form-text">Stored server-side only and never sent to the browser.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="ai_base_url">API base URL</label>
                            <input type="text" name="ai_base_url" class="form-control" id="ai_base_url"
                                   value="<?= e(aiAssistantBaseUrl()) ?>">
                            <div class="form-text">
                                OpenRouter by default (<code><?= e(AI_DEFAULT_BASE_URL) ?></code>). Any
                                OpenAI-compatible endpoint works.
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <label class="form-label mb-0" for="ai_model">Model</label>
                                <button type="submit" name="op" value="refresh_models" formnovalidate
                                        class="btn btn-sm btn-outline-secondary ms-auto">
                                    <i class="bi bi-arrow-clockwise me-1"></i>
                                    <?= $models === [] ? 'Load free models' : 'Refresh' ?>
                                </button>
                            </div>

                            <?php if ($models === []): ?>
                                <input type="text" name="ai_model" class="form-control" id="ai_model"
                                       value="<?= e(aiAssistantModel()) ?>" list="aiModelList"
                                       placeholder="openrouter/free">
                                <div class="form-text">
                                    Free model list not loaded yet. Use
                                    <em>Load free models</em> to pull the catalogue from OpenRouter.
                                </div>
                            <?php else: ?>
                                <select name="ai_model" class="form-select" id="ai_model">
                                    <?php if (!$modelIsListed): ?>
                                        <option value="<?= e(aiAssistantModel()) ?>" selected>
                                            <?= e(aiAssistantModel()) ?> — current (not in the free list)
                                        </option>
                                    <?php endif; ?>
                                    <?php foreach ($models as $m): ?>
                                        <option value="<?= e($m['id']) ?>" <?= $m['id'] === aiAssistantModel() ? 'selected' : '' ?>>
                                            <?= e($m['name']) ?>
                                            <?php if (!empty($m['router'])): ?> — auto-picks a free model<?php endif; ?>
                                            <?php if (empty($m['tools'])): ?> — no tool support<?php endif; ?>
                                            <?php if (!empty($m['context'])): ?> · <?= number_format((int) $m['context'] / 1000) ?>k ctx<?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">
                                    <?= count($models) ?> free chat model(s) ·
                                    <?= count($toolCapable) ?> with tool support ·
                                    <?= count($routerModels) ?> router<?= count($routerModels) === 1 ? '' : 's' ?> ·
                                    <?php if ($modelsAt): ?>
                                        catalogue loaded <?= e(formatDateTime(date(DATETIME_FORMAT, $modelsAt))) ?>
                                    <?php else: ?>not loaded yet<?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="ai_rate_limit_per_hour">Prompts per user per hour</label>
                            <input type="number" name="ai_rate_limit_per_hour" class="form-control"
                                   id="ai_rate_limit_per_hour" min="1" max="600"
                                   value="<?= aiAssistantRateLimit() ?>">
                            <div class="form-text">
                                This app-side limit is separate from OpenRouter's own free-tier limits.
                            </div>
                        </div>

                        <button type="submit" name="op" value="save" class="btn btn-primary">
                            <i class="bi bi-save me-1"></i>Save settings
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><h5 class="mb-0">Status</h5></div>
                <div class="card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <div class="fs-4 fw-semibold"><?= aiAssistantEnabled() ? 'ON' : 'OFF' ?></div>
                            <div class="small text-muted">Feature switch</div>
                        </div>
                        <div class="col-6">
                            <div class="fs-4 fw-semibold"><?= $hasKey ? 'SET' : 'MISSING' ?></div>
                            <div class="small text-muted">Provider API key</div>
                        </div>
                    </div>
                    <div class="alert alert-<?= $status['ready'] ? 'success' : 'secondary' ?> mb-3">
                        <?php if ($status['ready']): ?>
                            Ready. The chat bubble appears on authenticated pages.
                        <?php elseif ($status['reason'] === 'no_api_key'): ?>
                            Blocked: no OpenRouter API key.
                        <?php else: ?>
                            Blocked: the feature switch is off.
                        <?php endif; ?>
                    </div>

                    <dl class="row small mb-3">
                        <dt class="col-6">Provider</dt>
                        <dd class="col-6 text-end">OpenRouter</dd>
                        <dt class="col-6">Endpoint</dt>
                        <dd class="col-6 text-end font-monospace"><?= e(aiAssistantBaseUrl()) ?></dd>
                        <dt class="col-6">Model</dt>
                        <dd class="col-6 text-end font-monospace"><?= e(aiAssistantModel()) ?></dd>
                        <dt class="col-6">API key</dt>
                        <dd class="col-6 text-end">
                            <?php if ($hasKey): ?>
                                <span class="badge bg-success">stored</span>
                                <span class="font-monospace small text-muted">••••••••<?= e($keyFingerprint) ?></span>
                            <?php else: ?>
                                <span class="badge bg-secondary">not set</span>
                            <?php endif; ?>
                        </dd>
                        <dt class="col-6">Free &amp; tool-capable</dt>
                        <dd class="col-6 text-end">
                            <?php if ($modelIsListed): ?>
                                <span class="badge bg-success">yes</span>
                            <?php elseif ($models === []): ?>
                                <span class="badge bg-secondary">catalogue not loaded</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">not in the free list</span>
                            <?php endif; ?>
                        </dd>
                    </dl>
                    <h6>Hardening in force</h6>
                    <ul class="small text-muted mb-0">
                        <li>Model output is a tool <em>proposal</em> only — no SQL, shell, file write or eval path exists.</li>
                        <li>Unknown tool names are rejected and audited.</li>
                        <li>Ownership / assignee is re-checked at execute time, never trusted from the model.</li>
                        <li>Mutating tools require a signed confirm token that expires in <?= AI_CONFIRM_TTL_SECONDS ?>s.</li>
                        <li>View-as is honoured; All Father-only tools are refused while impersonating.</li>
                        <li>Every prompt, proposal, denial and execution is written to <code>audit_logs</code>.</li>
                        <li>Rate limit: <?= aiAssistantRateLimit() ?> prompt(s) per user per hour.</li>
                        <li>The API key stays server-side; the model catalogue is fetched without it.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h5 class="mb-0">Tool registry <span class="badge bg-secondary"><?= count($tools) ?></span></h5></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 no-datatable">
                    <thead><tr><th>Tool</th><th>What it does</th><th style="width:10%">Mutating</th></tr></thead>
                    <tbody>
                    <?php foreach ($tools as $t): ?>
                        <tr>
                            <td><code><?= e($t['id']) ?></code></td>
                            <td><?= e($t['description']) ?></td>
                            <td>
                                <?php if ($t['mutating']): ?>
                                    <span class="badge bg-warning text-dark">confirm</span>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted border">read</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>