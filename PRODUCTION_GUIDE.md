# LOKA Fleet Management System — Production Deployment Guide

Target: **Hostinger KVM 2** VPS (Ubuntu + Apache + MySQL), domain `lokafleet.dictr2.cloud`.
The `prod/` folder is a clean, upload-ready package: no secrets, no runtime logs, no dev junk.

---

## 1. Package Contents

```
prod/
├── PRODUCTION_GUIDE.md   ← this file
└── public_html/          ← the application (upload this to your web root)
    ├── index.php         (front controller / router)
    ├── .htaccess         (routing, security headers, file protection)
    ├── .env.example      (environment template — copy to .env and fill in)
    ├── health.php        (health endpoint for load balancers)
    ├── migrate.php       (database migration runner)
    ├── setup.sh          (one-shot VPS setup: perms, DB, cron, logrotate)
    ├── api/  assets/  classes/  config/  cron/  includes/
    ├── libraries/  migrations/  pages/  vendor/
    ├── logs/             (empty — must be writable by PHP)
    └── cache/            (empty — must be writable by PHP)
```

Deliberately excluded: `.env` (secrets), `logs/*.log`, `cache/*`, `*.zip`.

---

## 2. Server Requirements

| Requirement | Version / Notes |
|---|---|
| PHP | 8.1+ (extensions: `pdo_mysql`, `openssl`, `mbstring`, `json`, `curl`) |
| MySQL / MariaDB | 8.0+ / 10.6+, charset **utf8mb4** |
| Apache | 2.4 with `mod_rewrite` enabled (AllowOverride All) |
| Cron | available (KVM 2 full VPS — yes) |
| SMTP | working account (Hostinger email or transactional provider) |

---

## 3. Deployment Steps

### Step 1 — Upload the app
Upload the contents of `prod/public_html/` to your web root, e.g.:
```
/home/dictr2-lokafleet/htdocs/lokafleet.dictr2.cloud/public_html/
```
(SFTP/File Manager, or `rsync -avz prod/public_html/ user@kvm2:<webroot>/`)

### Step 2 — Create `.env`
```bash
cd <webroot>
cp .env.example .env
nano .env
```
Fill in:
- `APP_ENV=production`, `APP_DEBUG=false` (**never** true in production)
- `APP_URL=https://lokafleet.dictr2.cloud`
- `DB_*` credentials
- `SMTP_*` / `MAIL_*` credentials
- `MAIL_SYNC_SEND=false` (default — emails go through the cron; do NOT enable)

Then: `chmod 600 .env`

### Step 3 — Database
```bash
# Create DB (utf8mb4!) and user, then import your schema/dump:
mysql -u <admin> -p -e "CREATE DATABASE loka_fleet CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u <admin> -p loka_fleet < your_dump.sql

# Run all pending migrations (creates missing columns/tables, idempotent):
php migrate.php
```

### Step 4 — Permissions
```bash
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;
chmod -R 775 logs cache
chmod 600 .env
chmod 755 cron/*.php setup.sh
```

### Step 5 — Cron (email queue — REQUIRED)
The app only **queues** emails during form submissions; this cron sends them:
```bash
crontab -e
# add (adjust php path via `command -v php`):
0-59/2 * * * * /usr/bin/php /home/dictr2-lokafleet/htdocs/lokafleet.dictr2.cloud/public_html/cron/process_queue.php >> /home/dictr2-lokafleet/htdocs/lokafleet.dictr2.cloud/public_html/logs/cron.log 2>&1
```
Or just run `./setup.sh` on the VPS — it installs the cron + log rotation automatically.

Verify after 2 minutes:
```bash
tail -f logs/cron.log     # should show "Queue processor finished"
```

### Step 6 — Apache & SSL
- `mod_rewrite` enabled, `AllowOverride All` for the web root (the app routes everything through `index.php`)
- TLS via Hostinger hPanel free SSL or certbot
- Force HTTPS once certificates work (the rewrite rule is in `.htaccess`, commented)

---

## 4. Post-Deploy Checklist

- [ ] `https://lokafleet.dictr2.cloud` loads the login page
- [ ] Log in as admin; Dashboard renders with charts
- [ ] Submit a test request → returns **instantly** (no hang), appears in Requests
- [ ] Within ~2 min, notification emails arrive (check `logs/cron.log` + Settings → Email Queue)
- [ ] Double-click submit / reload-resubmit → NO duplicate request is created
- [ ] Reports → Trip Requests renders charts, pagination, exports (CSV + PDF)
- [ ] PDF export shows full destinations (`A -> B -> C`, no `?` characters)
- [ ] Admin → Request Rollback appears in sidebar; roll back a test request
- [ ] `https://.../health.php` returns JSON (if your LB uses it)
- [ ] Direct access to `/.env`, `/config/`, `/classes/`, `/logs/error.log` → **403**
- [ ] `APP_DEBUG=false` — errors go to `logs/error.log`, never to the screen

## 5. Security Notes

- `.htaccess` blocks: `.env`, all dotfiles, `*.sql|*.log|*.zip|*.bak`, direct access to `config/`, `classes/`, `includes/`, `cron/`, and every PHP file except `index.php` (all pages route through it)
- Keep `.env` at `chmod 600`; never commit it (it's git-ignored)
- The admin password once exposed in old source (`Q1w2e3r4t5!@#QWE`) — ensure it is **not** used in production; rotate if it ever was
- Keep `vendor/` as shipped (TCPDF etc.); update deliberately, not casually

## 6. Troubleshooting

| Symptom | Check |
|---|---|
| Emails never arrive | `logs/cron.log` exists & updates? `.env` SMTP creds? Settings → Email Queue for pending count |
| Pages 404 / routes broken | `mod_rewrite` + `AllowOverride All` |
| Blank page / 500 | `logs/error.log`; `APP_DEBUG=true` temporarily on a staging copy only |
| Migrations fail | DB user needs ALTER/CREATE privileges on the app DB |
| Slow submissions again | Someone set `MAIL_SYNC_SEND=true` — set it back to false |
