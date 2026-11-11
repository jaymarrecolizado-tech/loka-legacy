<?php
/**
 * Resolve secrets for CLI migrations.
 * Webroot may have no .env (nginx); production keeps it as ../.env.lokastage.
 */
$envFile = __DIR__ . '/../.env';
if (!is_file($envFile)) {
    $envFile = __DIR__ . '/../../.env.lokastage';
}
