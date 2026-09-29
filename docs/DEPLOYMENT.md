# Deploying to the Hostinger VPS

One VPS runs everything: Nginx + PHP-FPM, MySQL, Redis, Horizon (under Supervisor), the scheduler (cron) and the Python extractor (Docker). Ubuntu 24.04 is assumed; adjust paths if yours differ.

## 1. Server packages

```bash
sudo apt update
sudo apt install -y nginx mysql-server redis-server supervisor unzip git \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-redis php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath php8.3-intl
curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer
# Node (assets) and Docker (extractor)
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash - && sudo apt install -y nodejs
curl -fsSL https://get.docker.com | sudo sh
```

## 2. Database

```bash
sudo mysql -e "CREATE DATABASE dc CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'dc'@'localhost' IDENTIFIED BY 'change-me';
GRANT ALL ON dc.* TO 'dc'@'localhost';"
```

## 3. App

```bash
sudo mkdir -p /var/www/dc && sudo chown $USER:www-data /var/www/dc
git clone <your-repo> /var/www/dc/current && cd /var/www/dc/current
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env && php artisan key:generate
```

Edit `.env`:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://curation.example.com

DB_CONNECTION=mysql
DB_DATABASE=dc
DB_USERNAME=dc
DB_PASSWORD=change-me

QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
REDIS_HOST=127.0.0.1

MAIL_MAILER=smtp           # for source-paused alerts and member invitations
# MAIL_HOST / MAIL_USERNAME / MAIL_PASSWORD / MAIL_FROM_ADDRESS ...

OPENAI_API_KEY=sk-...
GEMINI_API_KEY=            # optional fallback
CURATION_SUPER_ADMINS=you@example.com   # who may open /horizon

EXTRACTOR_URL=http://127.0.0.1:8100
EXTRACTOR_TOKEN=<long random string>
```

```bash
php artisan migrate --force
php artisan storage:link
php artisan workspace:create "Your Company" you@example.com   # asks for your name and password
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo chown -R www-data:www-data storage bootstrap/cache
```

## 4. Web server

```bash
sudo cp deploy/nginx/dc.conf /etc/nginx/sites-available/dc.conf   # set server_name
sudo ln -s /etc/nginx/sites-available/dc.conf /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo apt install -y certbot python3-certbot-nginx && sudo certbot --nginx -d curation.example.com
```

## 5. Workers and scheduler

```bash
sudo mkdir -p /var/www/dc/shared/storage/logs
sudo cp deploy/supervisor/horizon.conf /etc/supervisor/conf.d/dc-horizon.conf
sudo supervisorctl reread && sudo supervisorctl update
sudo crontab -u www-data deploy/crontab
```

Horizon runs two supervisors (see `config/horizon.php`): `fetch` (sources) and `ai` (enrichment), so slow crawls never block AI work. Open `/horizon` as a super admin to watch them.

## 6. Extractor (and optional RSSHub)

```bash
cd /var/www/dc/current/extractor
EXTRACTOR_TOKEN=<same as .env> docker compose up -d --build
curl -s http://127.0.0.1:8100/health          # {"ok":true}
# optional: self-hosted RSSHub on 127.0.0.1:1200, then set RSSHUB_URL=http://127.0.0.1:1200
EXTRACTOR_TOKEN=<same> docker compose --profile rsshub up -d
```

Both containers bind to `127.0.0.1` only; nothing is exposed publicly.

## 7. Updating

```bash
cd /var/www/dc/current
php artisan down
git pull && composer install --no-dev --optimize-autoloader && npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan horizon:terminate     # Supervisor restarts it with the new code
php artisan up
(cd extractor && docker compose up -d --build)   # only when extractor/ changed
```

## Instagram (optional)

- **Official (free):** create a Meta developer app, link your Instagram professional account to a Facebook Page, and generate a long-lived token with `instagram_basic`, `instagram_manage_insights` and `pages_read_engagement`. Set `INSTAGRAM_IG_USER_ID` and `INSTAGRAM_ACCESS_TOKEN`. Reads Business/Creator accounts only.
- **Apify (paid):** set `APIFY_TOKEN` and choose "Apify" on the source (or `INSTAGRAM_DRIVER=apify`). Works for any public account.
