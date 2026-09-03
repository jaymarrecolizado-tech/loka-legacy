# LOKA Fleet Management System - Production Package

**Version:** 2.5.1
**Build Date:** March 16, 2026
**Deployment Target:** https://lokafleet.dictr2.cloud (187.77.150.203)

---

## Quick Start

### For Immediate Deployment

1. **Read:** `QUICK_START.md` - Fast-track deployment guide
2. **Create Archive:** Run `create_archive.bat` (Windows) or use tar
3. **Upload:** Upload the archive to your VPS
4. **Run Setup:** Execute `setup.sh` on the server

### For Detailed Instructions

Read `DEPLOYMENT_GUIDE.md` for comprehensive deployment documentation.

---

## Package Contents

```
prod2prod/
├── README.md              # This file
├── QUICK_START.md         # Quick deployment guide (START HERE)
├── DEPLOYMENT_GUIDE.md    # Detailed deployment documentation
├── create_archive.bat     # Windows script to create deployment archive
├── setup.sh               # Server-side setup automation script
│
├── .htaccess              # Apache configuration (production)
├── .env.example           # Environment variable template
├── .env.production        # Production environment template
├── health.php             # Health check endpoint
├── index.php              # Main application router
├── migrate.php            # Database migration runner
│
├── api/                   # REST API endpoints
│   ├── requests.php
│   └── vehicle_types.php
│
├── assets/
│   └── dist/             # Built frontend assets (minified JS/CSS)
│       ├── .vite/
│       └── js/
│
├── classes/              # Core PHP classes
│   ├── Auth.php
│   ├── Database.php
│   ├── EmailQueue.php
│   ├── Mailer.php
│   ├── Security.php
│   └── ...
│
├── config/               # Configuration files
│   ├── bootstrap.php
│   ├── constants.php
│   ├── database.php
│   ├── mail.php
│   ├── security.php
│   └── ...
│
├── cron/                 # Scheduled tasks
│   └── process_queue.php # Email queue processor (REQUIRED)
│
├── includes/             # PHP includes
│   ├── footer.php
│   ├── functions.php     # Helper functions
│   ├── header.php
│   └── sidebar.php
│
├── libraries/            # Third-party libraries
│   └── tcpdf/            # PDF generation
│
├── logs/                 # Application logs (writable directory)
│   ├── audit/            # Audit logs
│   ├── email_queue/      # Email queue logs
│   ├── sessions/         # Session files
│   ├── *.log             # Log files (app.log, error.log, etc.)
│
├── migrations/           # Database migrations
│   └── *.php             # Migration files
│
├── pages/                # Page files
│   ├── admin/
│   ├── auth/
│   ├── dashboard/
│   ├── requests/
│   └── ...
│
└── vendor/               # Composer dependencies
    └── (third-party packages)
```

---

## Deployment Methods

### Method 1: Hostinger File Manager (Recommended)

1. **Create Archive**
   ```bash
   # Windows
   create_archive.bat

   # Or with tar
   tar -czf loka-fleet.tar.gz .
   ```

2. **Upload**
   - Log in to Hostinger hPanel
   - Go to Files → File Manager
   - Navigate to `public_html/`
   - Upload `loka-fleet.tar.gz`
   - Right-click → Extract

3. **Run Setup**
   ```bash
   ssh user@187.77.150.203
   cd /var/www/html
   chmod +x setup.sh
   ./setup.sh
   ```

### Method 2: SFTP/SCP

```bash
# Create archive
tar -czf loka-fleet.tar.gz .

# Upload
scp loka-fleet.tar.gz user@187.77.150.203:/tmp/

# SSH to server
ssh user@187.77.150.203

# Extract
cd /var/www/html
tar -xzf /tmp/loka-fleet.tar.gz
rm /tmp/loka-fleet.tar.gz

# Run setup
chmod +x setup.sh
./setup.sh
```

### Method 3: Git Clone (For Development)

```bash
# Clone repository
git clone <repository-url> /var/www/html
cd /var/www/html

# Build frontend (if needed)
npm install
npm run build

# Install dependencies
composer install --no-dev

# Copy environment
cp .env.production .env

# Edit .env with your credentials
nano .env

# Run setup
chmod +x setup.sh
./setup.sh
```

---

## Server Requirements

### Minimum
- **PHP:** 8.0 or higher
- **MySQL/MariaDB:** 5.7 or higher
- **RAM:** 2GB minimum (4GB recommended)
- **Disk:** 20GB minimum
- **Web Server:** Apache with mod_rewrite

### Required PHP Extensions
- pdo, pdo_mysql
- mbstring
- json
- openssl
- curl
- gd or imagick
- zip
- intl

### Verify PHP Setup
```bash
php -v
php -m | grep -E "pdo|mysqli|mbstring|json|openssl|curl"
```

---

## Configuration

### 1. Environment File

Copy `.env.production` to `.env` and update:

```env
# Database
DB_HOST=localhost
DB_DATABASE=fleet_management
DB_USERNAME=loka_db_user
DB_PASSWORD=YOUR_SECURE_PASSWORD

# Application
APP_URL=https://lokafleet.dictr2.cloud
APP_DEBUG=false

# Email (SMTP)
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=your-email@gmail.com
SMTP_PASSWORD=your-app-password
```

**Important:** Never commit `.env` to version control!

### 2. File Permissions

```bash
# Standard permissions
find /var/www/html -type d -exec chmod 755 {} \;
find /var/www/html -type f -exec chmod 644 {} \;

# Writable directories
chmod 777 /var/www/html/logs
chmod 666 /var/www/html/logs/*.log

# Protect .env
chmod 600 /var/www/html/.env

# Executable scripts
chmod 755 /var/www/html/cron/*.php
chmod 755 /var/www/html/setup.sh
```

### 3. Cron Job

Email queue processor is **required** for notification emails:

```bash
crontab -e
# Add this line:
*/2 * * * * php /var/www/html/cron/process_queue.php >> /var/www/html/logs/cron.log 2>&1
```

---

## Database Setup

### Option 1: Using Setup Script

The `setup.sh` script will create the database and user for you.

### Option 2: Manual Setup

```bash
# Login to MySQL
mysql -u root -p

# Create database and user
CREATE DATABASE fleet_management CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'loka_db_user'@'localhost' IDENTIFIED BY 'your_password';
GRANT ALL PRIVILEGES ON fleet_management.* TO 'loka_db_user'@'localhost';
FLUSH PRIVILEGES;

# Exit
EXIT;

# Import schema (if available)
mysql -u loka_db_user -p fleet_management < schema.sql

# Or run migrations
php migrate.php
```

---

## SSL Certificate

### Hostinger Free SSL (Easiest)

1. Log in to Hostinger hPanel
2. Go to Domains → Find `lokafleet.dictr2.cloud`
3. Click Manage → SSL
4. SSL is automatically enabled

### Let's Encrypt (Certbot)

```bash
# Install certbot
sudo apt update
sudo apt install certbot python3-certbot-apache

# Get certificate
sudo certbot --apache -d lokafleet.dictr2.cloud

# Auto-renewal is configured automatically
```

---

## Verification

### Health Check

Visit: `https://lokafleet.dictr2.cloud/health.php`

Expected response:
```json
{
  "status": "healthy",
  "database": "connected",
  "timestamp": "2026-03-16T..."
}
```

### Application Access

- **Login Page:** `https://lokafleet.dictr2.cloud/`
- **Dashboard:** `https://lokafleet.dictr2.cloud/?page=dashboard`
- **Admin Panel:** `https://lokafleet.dictr2.cloud/?page=admin`

### Check Logs

```bash
# Application logs
tail -f /var/www/html/logs/app.log

# Error logs
tail -f /var/www/html/logs/error.log

# Email queue logs
tail -f /var/www/html/logs/email_queue.log

# Apache logs
tail -f /var/log/apache2/error.log
```

---

## Default Credentials

⚠️ **IMPORTANT:** Change these immediately after first login!

```
Admin Username: admin
Admin Password: password
```

### Create New Admin

If no admin exists:

```bash
# Using reset script
php reset_admin_password.php

# Or directly in MySQL
mysql -u loka_db_user -p fleet_management
INSERT INTO users (username, email, password_hash, role, created_at)
VALUES ('admin', 'admin@lokafleet.dictr2.cloud',
        '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        'admin', NOW());
```

---

## Security Checklist

- [ ] Changed default admin password
- [ ] Set strong database password
- [ ] HTTPS/SSL is working
- [ ] `.env` file permissions set to 600
- [ ] Logs directory writable
- [ ] Firewall configured (allow 80, 443, 22)
- [ ] Fail2ban installed for SSH protection
- [ ] Disabled PHP error display (already in .htaccess)
- [ ] Directory browsing disabled (already in .htaccess)
- [ ] Direct access to .env blocked (already in .htaccess)

---

## Troubleshooting

### 500 Internal Server Error

```bash
# Check Apache error log
tail -50 /var/log/apache2/error.log

# Check application error log
tail -50 /var/www/html/logs/error.log
```

### Database Connection Failed

```bash
# Test MySQL connection
mysql -u loka_db_user -p fleet_management

# Verify .env credentials
cat /var/www/html/.env | grep DB_
```

### CSS/JS Not Loading

```bash
# Check if assets exist
ls -la /var/www/html/assets/dist/

# Verify .htaccess
cat /var/www/html/.htaccess
```

### Emails Not Sending

```bash
# Check email queue
mysql -u loka_db_user -p fleet_management
SELECT * FROM email_queue WHERE status = 'pending';

# Check email log
tail -50 /var/www/html/logs/email_queue.log

# Verify cron job
crontab -l
```

---

## Backup Strategy

### Database Backup

```bash
# Manual backup
mysqldump -u loka_db_user -p fleet_management > fleet_backup_$(date +%Y%m%d).sql

# Automated (via cron)
0 2 * * * mysqldump -u loka_db_user -p'PASSWORD' fleet_management | gzip > /backups/fleet_$(date +\%Y\%m\%d).sql.gz
```

### File Backup

```bash
# Backup application files
tar -czf loka_files_backup_$(date +%Y%m%d).tar.gz /var/www/html
```

---

## Maintenance

### Clear Cache

```bash
# Clear session files
rm -f /var/www/html/logs/sessions/*

# Clear rate limits (if needed)
mysql -u loka_db_user -p fleet_management
DELETE FROM rate_limits WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY);
```

### Update Application

```bash
# Backup current version
cd /var/www/html
tar -czf ../loka_backup_$(date +%Y%m%d).tar.gz .

# Upload new files
# Extract new version

# Restore .env and custom configs
cp ../loka_backup/.env .

# Run migrations
php migrate.php

# Set permissions
chmod 755 .
chmod 777 logs
```

---

## Performance Optimization

### Enable OpCache

Edit `/etc/php/8.0/apache2/php.ini`:
```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.max_accelerated_files=10000
opcache.revalidate_freq=60
```

Restart Apache:
```bash
sudo systemctl restart apache2
```

### Database Indexing

Ensure proper indexes on frequently queried tables:
```sql
SHOW INDEX FROM requests;
SHOW INDEX FROM users;
SHOW INDEX FROM vehicles;
```

---

## Support & Resources

### Documentation
- `QUICK_START.md` - Fast deployment guide
- `DEPLOYMENT_GUIDE.md` - Comprehensive deployment instructions
- `docs/STYLE_GUIDE.md` - UI/layout conventions
- `../Plan.md` - Project plan index and status

### Logs
- Application: `/var/www/html/logs/`
- Apache: `/var/log/apache2/`

### Monitoring
- Health Check: `https://lokafleet.dictr2.cloud/health.php`

---

## License

This software is proprietary. All rights reserved.

---

**Deployment Date:** March 16, 2026
**Target Server:** 187.77.150.203 (Hostinger KVM 2 VPS)
**Domain:** https://lokafleet.dictr2.cloud
