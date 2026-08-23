#!/bin/bash

###############################################################################
# LOKA Fleet Management System - Server Setup Script
# Run this script on the VPS after uploading files
#
# Usage:
#   chmod +x setup.sh
#   ./setup.sh
#
# This script will:
#   - Set file permissions
#   - Create database and user
#   - Import schema (if present)
#   - Set up cron job
#   - Configure SSL (optional)
###############################################################################

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Configuration variables
APP_NAME="LOKA Fleet Management"
DB_NAME="fleet_management"
DB_USER="loka_db_user"
WEB_ROOT="/var/www/html"
LOG_DIR="$WEB_ROOT/logs"

echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}  LOKA Fleet - Server Setup Script${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    echo -e "${YELLOW}Note: Some commands may require sudo privileges${NC}"
fi

# Step 1: Set file permissions
echo -e "${GREEN}[1/7] Setting file permissions...${NC}"
cd "$WEB_ROOT"

# Set directory permissions
find "$WEB_ROOT" -type d -exec chmod 755 {} \; 2>/dev/null || true

# Set file permissions
find "$WEB_ROOT" -type f -exec chmod 644 {} \; 2>/dev/null || true

# Make logs writable
chmod 777 "$LOG_DIR"
chmod 666 "$LOG_DIR"/*.log 2>/dev/null || true

# Make cron jobs executable
chmod 755 "$WEB_ROOT/cron"/*.php 2>/dev/null || true

# Protect .env file
chmod 600 "$WEB_ROOT/.env" 2>/dev/null || true

echo -e "${GREEN}✓ File permissions set${NC}"
echo ""

# Step 2: Check for .env file
echo -e "${GREEN}[2/7] Checking environment configuration...${NC}"

if [ ! -f "$WEB_ROOT/.env" ]; then
    if [ -f "$WEB_ROOT/.env.production" ]; then
        echo -e "${YELLOW}Creating .env from template...${NC}"
        cp "$WEB_ROOT/.env.production" "$WEB_ROOT/.env"
        echo -e "${GREEN}✓ Created .env file${NC}"
        echo -e "${YELLOW}Please edit .env and update DB_PASSWORD and SMTP_PASSWORD${NC}"
    else
        echo -e "${RED}✗ No .env.production found!${NC}"
        exit 1
    fi
else
    echo -e "${GREEN}✓ .env file exists${NC}"
fi
echo ""

# Step 3: Database Setup
echo -e "${GREEN}[3/7] Setting up database...${NC}"

read -p "Do you want to create a new database? (y/n): " create_db

if [ "$create_db" = "y" ] || [ "$create_db" = "Y" ]; then
    read -sp "Enter MySQL root password: " mysql_root_pass
    echo ""

    read -p "Enter database name [$DB_NAME]: " input_db_name
    DB_NAME=${input_db_name:-$DB_NAME}

    read -p "Enter database user [$DB_USER]: " input_db_user
    DB_USER=${input_db_user:-$DB_USER}

    read -sp "Enter database password for $DB_USER: " db_pass
    echo ""

    if [ -z "$db_pass" ]; then
        echo -e "${RED}Database password cannot be empty${NC}"
        exit 1
    fi

    # Create database and user
    mysql -u root -p"$mysql_root_pass" <<EOF
CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$db_pass';
GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
EOF

    echo -e "${GREEN}✓ Database and user created${NC}"

    # Update .env file with database credentials
    if command -v sed &> /dev/null; then
        sed -i "s/DB_DATABASE=.*/DB_DATABASE=$DB_NAME/" "$WEB_ROOT/.env"
        sed -i "s/DB_USERNAME=.*/DB_USERNAME=$DB_USER/" "$WEB_ROOT/.env"
        sed -i "s/DB_PASSWORD=.*/DB_PASSWORD=$db_pass/" "$WEB_ROOT/.env"
        echo -e "${GREEN}✓ Updated .env with database credentials${NC}"
    else
        echo -e "${YELLOW}Please manually update DB credentials in .env file${NC}"
    fi
else
    echo -e "${YELLOW}Skipping database creation${NC}"
fi
echo ""

# Step 4: Import Database Schema (if schema.sql exists)
echo -e "${GREEN}[4/7] Checking for database schema...${NC}"

if [ -f "$WEB_ROOT/schema.sql" ]; then
    read -p "Found schema.sql. Do you want to import it? (y/n): " import_schema

    if [ "$import_schema" = "y" ] || [ "$import_schema" = "Y" ]; then
        read -sp "Enter database password for $DB_USER: " db_pass
        echo ""

        mysql -u "$DB_USER" -p"$db_pass" "$DB_NAME" < "$WEB_ROOT/schema.sql"
        echo -e "${GREEN}✓ Database schema imported${NC}"
    fi
elif [ -f "$WEB_ROOT/migrate.php" ]; then
    echo -e "${YELLOW}Found migrate.php. You can run it manually: php migrate.php${NC}"
else
    echo -e "${YELLOW}No schema.sql or migrate.php found. Database may need manual setup.${NC}"
fi
echo ""

# Step 5: Create Admin User
echo -e "${GREEN}[5/7] Creating admin user...${NC}"

if [ -f "$WEB_ROOT/reset_admin_password.php" ]; then
    read -p "Do you want to create/reset admin password? (y/n): " create_admin

    if [ "$create_admin" = "y" ] || [ "$create_admin" = "Y" ]; then
        php "$WEB_ROOT/reset_admin_password.php"
    fi
else
    echo -e "${YELLOW}No admin reset script found${NC}"
    echo -e "${YELLOW}You can create admin manually in the database${NC}"
fi
echo ""

# Step 6: Setup Cron Job
echo -e "${GREEN}[6/7] Setting up cron job for email queue...${NC}"

CRON_JOB="*/2 * * * * php $WEB_ROOT/cron/process_queue.php >> $WEB_ROOT/logs/cron.log 2>&1"

if crontab -l 2>/dev/null | grep -q "process_queue.php"; then
    echo -e "${YELLOW}✓ Cron job already exists${NC}"
else
    (crontab -l 2>/dev/null; echo "$CRON_JOB") | crontab -
    echo -e "${GREEN}✓ Cron job added${NC}"
fi
echo ""

# Step 7: SSL Configuration
echo -e "${GREEN}[7/7] SSL Configuration...${NC}"

if command -v certbot &> /dev/null; then
    read -p "Do you want to configure SSL with Let's Encrypt? (y/n): " setup_ssl

    if [ "$setup_ssl" = "y" ] || [ "$setup_ssl" = "Y" ]; then
        read -p "Enter domain (e.g., lokafleet.dictr2.cloud): " domain

        if [ ! -z "$domain" ]; then
            certbot --apache -d "$domain"
            echo -e "${GREEN}✓ SSL configured${NC}"
        fi
    fi
else
    echo -e "${YELLOW}Certbot not installed. Install with: sudo apt install certbot python3-certbot-apache${NC}"
    echo -e "${YELLOW}Or use Hostinger's free SSL from hPanel${NC}"
fi
echo ""

# Final Summary
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}  Setup Complete!${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo -e "${YELLOW}Next steps:${NC}"
echo "1. Update remaining values in .env (SMTP credentials, etc.)"
echo "2. Import database schema if not done"
echo "3. Create admin user if not done"
echo "4. Test the application"
echo ""
echo -e "${YELLOW}Important URLs:${NC}"
echo "  - Application: https://$(hostname)/"
echo "  - Health Check: https://$(hostname)/health.php"
echo ""
echo -e "${YELLOW}Check logs:${NC}"
echo "  - tail -f $LOG_DIR/error.log"
echo "  - tail -f $LOG_DIR/app.log"
echo ""
echo -e "${YELLOW}Permissions check:${NC}"
echo "  - ls -la $WEB_ROOT/.env"
echo "  - ls -la $LOG_DIR/"
echo ""
echo -e "${GREEN}Done!${NC}"
