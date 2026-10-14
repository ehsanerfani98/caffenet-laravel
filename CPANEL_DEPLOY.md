# Caffenet Smart Queue — cPanel Deployment Guide

This guide walks you through deploying the project to a cPanel shared hosting account **without SSH access**, using only the File Manager and Cron Jobs UI.

---

## 1. Local Preparation (on your dev machine)

### 1.1 Install dependencies locally

```bash
composer install --no-dev --optimize-autoloader
```

> cPanel shared hosting does **not** have Composer. You must upload the `vendor/` directory yourself.

### 1.2 Build frontend assets (optional — already committed)

```bash
# The repo already ships public/css/app.css and public/js/*.js built.
# Rebuild only if you changed the frontend:
npm install
npm run build
```

### 1.3 Set up environment file

Copy `.env.example` → `.env` and fill in:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com
APP_KEY=                          # Generate locally with: php artisan key:generate
DB_CONNECTION=sqlite
DB_DATABASE=/home/USERNAME/laravel/database/database.sqlite
SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database
ARTISAN_RUNNER_TOKEN=<generate_a_40_char_random_string>
# Optional: ARTISAN_RUNNER_IPS=your.home.ip.address
```

Generate a strong token for the Artisan Runner:

```bash
php -r "echo bin2hex(random_bytes(20));"
```

---

## 2. Upload to cPanel

### 2.1 Zip the project (excluding dev-only files)

From the project root:

```bash
zip -r caffenet.zip . \
  -x "node_modules/*" \
  -x ".git/*" \
  -x "storage/logs/*.log" \
  -x "database/database.sqlite"
```

### 2.2 Upload via cPanel File Manager

1. Log in to cPanel → **File Manager**.
2. Navigate to `/home/USERNAME/` (NOT `public_html`).
3. Create a folder named `laravel`.
4. Upload `caffenet.zip` into `/home/USERNAME/laravel/`.
5. Right-click → **Extract**. The project files should now be at `/home/USERNAME/laravel/artisan`, `/home/USERNAME/laravel/composer.json`, etc.

### 2.3 Move `public/` contents to `public_html/`

The `public/` directory of Laravel must be served from `public_html/`. Two options:

**Option A (recommended): symlink**

In cPanel → **Terminal** (if available) or via a PHP script:

```bash
mv public_html public_html_backup
ln -s /home/USERNAME/laravel/public /home/USERNAME/public_html
```

**Option B: copy + edit index.php**

1. Copy everything from `/home/USERNAME/laravel/public/` to `/home/USERNAME/public_html/`.
2. Edit `/home/USERNAME/public_html/index.php` and update the two `__DIR__` paths to point to `/home/USERNAME/laravel/`:

```php
require __DIR__.'/../laravel/vendor/autoload.php';
$app = require_once __DIR__.'/../laravel/bootstrap/app.php';
```

### 2.4 Create the SQLite database file

In cPanel File Manager:

1. Navigate to `/home/USERNAME/laravel/database/`.
2. Click **New File** → name it `database.sqlite`.
3. Make sure the file is empty (0 bytes).

### 2.5 Set permissions

Right-click → **Permissions** on these directories; set to **775**:

- `/home/USERNAME/laravel/storage/`
- `/home/USERNAME/laravel/bootstrap/cache/`
- `/home/USERNAME/laravel/database/`

---

## 3. Run Migrations & Seeders (no SSH)

The Artisan Runner lets you run whitelisted Artisan commands from your browser.

### 3.1 Open the runner URL

Visit (replace with your domain):

```
https://yourdomain.com/api/_artisan/run/migrate?token=YOUR_ARTISAN_RUNNER_TOKEN
```

You should see JSON:

```json
{"command":"migrate","exit_code":0,"output":"..."}
```

### 3.2 Run the seeder

```
https://yourdomain.com/api/_artisan/run/db:seed?token=YOUR_ARTISAN_RUNNER_TOKEN
```

### 3.3 Generate capacity slots

```
https://yourdomain.com/api/_artisan/run/queue:slots:generate?token=YOUR_ARTISAN_RUNNER_TOKEN
```

### 3.4 Cache config / routes / views

```
https://yourdomain.com/api/_artisan/run/config:cache?token=YOUR_ARTISAN_RUNNER_TOKEN
https://yourdomain.com/api/_artisan/run/route:cache?token=YOUR_ARTISAN_RUNNER_TOKEN
https://yourdomain.com/api/_artisan/run/view:cache?token=YOUR_ARTISAN_RUNNER_TOKEN
```

> If you change `.env` later, run `config:clear` first, then `config:cache` again.

---

## 4. Configure Cron Jobs

In cPanel → **Cron Jobs** → set **Common Settings: Every Minute (* * * * *)**.

Add three cron jobs:

### 4.1 Laravel Scheduler

```bash
* * * * * /usr/local/bin/php /home/USERNAME/laravel/artisan schedule:run >> /home/USERNAME/laravel/storage/logs/scheduler.log 2>&1
```

### 4.2 Queue Worker (no Supervisor)

```bash
* * * * * /usr/local/bin/php /home/USERNAME/laravel/artisan queue:work --stop-when-empty --max-time=50 --tries=1 >> /home/USERNAME/laravel/storage/logs/queue.log 2>&1
```

### 4.3 Daily SQLite Backup (optional but recommended)

```bash
0 3 * * * cp /home/USERNAME/laravel/database/database.sqlite /home/USERNAME/backups/db-$(date +\%F).sqlite 2>/dev/null
```

> Find the PHP binary path in cPanel → **MultiPHP Manager** → "PHP Version" or via **Terminal** running `which php`. Common paths: `/usr/local/bin/php`, `/opt/cpanel/ea-php83/root/usr/bin/php`.

---

## 5. HTTPS & SSL

In cPanel → **SSL/TLS Status** → **Run AutoTLS** for your domain.

To force HTTPS, add to `/home/USERNAME/public_html/.htaccess`:

```apache
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

---

## 6. Post-Deploy Checklist

| ✓ | Check |
|---|-------|
| ☐ | Visiting `https://yourdomain.com/` loads the SPA |
| ☐ | `/api/health` returns `{"status":"ok"}` |
| ☐ | `/api/capacity/current` returns a tier (green/yellow/orange/red) |
| ☐ | `/up` (Laravel health check) returns 200 |
| ☐ | Cron jobs are listed in cPanel → **Cron Jobs** |
| ☐ | `storage/logs/queue.log` is being written to (check after 1 min) |
| ☐ | `storage/logs/artisan-runner.log` exists (touch one runner URL) |
| ☐ | SQLite backup folder `/home/USERNAME/backups/` exists |

---

## 7. Disabling the Artisan Runner (hardening)

Once everything is deployed and working, you can disable the Artisan Runner by editing `/home/USERNAME/laravel/.env`:

```dotenv
ARTISAN_RUNNER_TOKEN=disabled
```

Then re-cache config:

```
https://yourdomain.com/api/_artisan/run/config:clear?token=<old_token>
```

> You can re-enable it any time by setting a new token + `config:clear`.

---

## 8. Troubleshooting

### "500 Internal Server Error" on first visit

1. Check `/home/USERNAME/laravel/storage/logs/laravel.log`.
2. Make sure `/home/USERNAME/laravel/storage/` and `/home/z/my-project/caffenet/bootstrap/cache/` are writable (775).
3. Make sure `APP_KEY` is set in `.env` (run `php artisan key:generate` locally and copy the value).
4. Make sure the SQLite database file exists at the path in `DB_DATABASE`.

### Cron jobs not running

1. Verify the PHP binary path: in cPanel Terminal, run `which php`.
2. Check the cron log: cPanel → **Cron Jobs** → **Email** section at the bottom — set an email and watch for output.

### Queue not processing

1. Confirm `QUEUE_CONNECTION=database` in `.env`.
2. Visit `https://yourdomain.com/api/_artisan/run/queue:work?token=...` — should output worker activity.
3. Check `storage/logs/queue.log`.

### Capacity indicator always green

The first cron run (within 1 minute) generates slots. Visit `/api/_artisan/run/queue:slots:generate?token=...` to trigger immediately.

---

## 9. Updating the Project

To pull updates later:

1. On your dev machine: `git pull`, `composer install --no-dev`, re-zip.
2. Upload the zip, extract over the existing `/home/USERNAME/laravel/`.
3. Visit `/_artisan/run/migrate?token=...` to apply new migrations.
4. Visit `/_artisan/run/config:clear` then `/_artisan/run/config:cache` if `.env` changed.
5. Visit `/_artisan/run/cache:clear` to bust cached data.
