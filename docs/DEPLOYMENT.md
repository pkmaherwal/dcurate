# Deploying to the Hostinger VPS (AlmaLinux 9 + CyberPanel)

One VPS runs everything:

| Part | Runs as |
|---|---|
| Website | OpenLiteSpeed (managed by CyberPanel) with PHP 8.3 (LSPHP) |
| Database | MariaDB (managed by CyberPanel) |
| Queues and cache | Redis |
| Queue workers | Horizon, kept running by Supervisor |
| Scheduler | cron |
| Python extractor (optional RSSHub) | Docker |

All commands run as `root` over SSH unless noted. Set these once per SSH session:

```bash
DOMAIN=curation.example.com                     # your site's domain in CyberPanel
APP=/home/$DOMAIN/dc                            # where the code lives
PHP=/usr/local/lsws/lsphp83/bin/php             # PHP 8.3 (LSPHP)
SITEUSER=$(stat -c %U /home/$DOMAIN)            # the Linux user CyberPanel created for the site
```

**Layout:**
- The code sits in `/home/$DOMAIN/dc`.
- CyberPanel's `public_html` is replaced by a symlink to `dc/public`, so only `public/` is reachable from the web.
- CyberPanel's Let's Encrypt renewals keep working, because they write into `public_html`.

## 1. PHP 8.3 for the command line

Already done if `php -v` shows 8.3. For reference:

```bash
dnf install -y lsphp83 lsphp83-common lsphp83-mysqlnd lsphp83-pdo lsphp83-mbstring lsphp83-xml lsphp83-bcmath \
  lsphp83-intl lsphp83-opcache lsphp83-process lsphp83-sodium lsphp83-zip lsphp83-gd lsphp83-pecl-redis
ln -sf /usr/local/lsws/lsphp83/bin/php /usr/bin/php && hash -r
php -m | grep -Ei 'pcntl|posix|redis|intl|zip|bcmath|mbstring|pdo_mysql|dom|curl|sodium'   # all must be listed
```

- `lsphp83-process` provides `pcntl` and `posix`, which Horizon needs.
- CyberPanel upgrades can reset the `/usr/bin/php` link, so check `php -v` after an upgrade.

## 2. Server packages

```bash
dnf install -y epel-release
dnf install -y git unzip redis supervisor
systemctl enable --now redis supervisord

# Composer (skip if `composer --version` already works)
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Docker (for the extractor)
dnf config-manager --add-repo https://download.docker.com/linux/rhel/docker-ce.repo
dnf install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin
systemctl enable --now docker
```

If the Docker install complains about `podman` or `buildah`, remove them first: `dnf remove -y podman buildah`.

## 3. Website, database and SSL in CyberPanel

1. **Websites → Create Website.** Enter your domain and pick **PHP 8.3**.
2. **Databases → Create Database** for that website. Note the database name, user and password exactly as CyberPanel shows them.
3. **SSL** comes later, in step 4.

Open **Websites → List Websites → Manage → vHost Conf** and check it contains:

```
rewrite  {
  enable                  1
  autoLoadHtaccess        1
}
```

Laravel's `public/.htaccess` routes every URL to `index.php`; OpenLiteSpeed only reads it with this block.

## 4. Get the code from GitHub

The server reads the private repo with a **deploy key**: an SSH key that only has read access to `pkmaherwal/dcurate`. Skip steps 1–3 below if `ssh -T git@github-dcurate` already greets you.

1. Create the key and print it:
   ```bash
   ssh-keygen -t ed25519 -C "dcurate-deploy@vps" -f ~/.ssh/dcurate_deploy -N ""
   cat ~/.ssh/dcurate_deploy.pub
   ```

2. Add it on GitHub:
   - Open https://github.com/pkmaherwal/dcurate/settings/keys → **Add deploy key**.
   - Paste the key and leave **Allow write access** unchecked.

3. Point SSH at the key, then test the connection:
   ```bash
   cat >> ~/.ssh/config <<'CONF'
   Host github-dcurate
       HostName github.com
       User git
       IdentityFile ~/.ssh/dcurate_deploy
       IdentitiesOnly yes
   CONF
   chmod 700 ~/.ssh && chmod 600 ~/.ssh/config ~/.ssh/dcurate_deploy
   ssh -T git@github-dcurate
   ```
   - The first time, accept GitHub's key only if its fingerprint is `SHA256:+DiY3wvvV6TuJJhbpZisF/zLDA0zPMSvHdkr4UvCOqU`.
   - Success looks like: "Hi pkmaherwal/dcurate! You've successfully authenticated…".

4. Clone the code and make `public_html` point at it:
   ```bash
   git clone git@github-dcurate:pkmaherwal/dcurate.git $APP
   mv /home/$DOMAIN/public_html /home/$DOMAIN/public_html.cyberpanel-default
   ln -s $APP/public /home/$DOMAIN/public_html
   chown -R $SITEUSER:$SITEUSER $APP
   chown -h $SITEUSER:$SITEUSER /home/$DOMAIN/public_html
   ```

5. Issue SSL: **SSL → Manage SSL**, select the domain, then **Issue SSL**.

## 5. Configure the app

```bash
cd $APP
sudo -u $SITEUSER cp .env.example .env
sudo -u $SITEUSER -H $PHP $(command -v composer) install --no-dev --optimize-autoloader --no-interaction
sudo -u $SITEUSER $PHP artisan key:generate
```

Edit `$APP/.env` (for example `nano $APP/.env`):

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://curation.example.com

DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=<database from CyberPanel>
DB_USERNAME=<user from CyberPanel>
DB_PASSWORD=<password from CyberPanel>

QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
REDIS_HOST=127.0.0.1

MAIL_MAILER=smtp           # source-paused alerts and member invitations
# MAIL_HOST / MAIL_PORT / MAIL_USERNAME / MAIL_PASSWORD / MAIL_FROM_ADDRESS ...

OPENAI_API_KEY=sk-...
GEMINI_API_KEY=            # optional fallback
CURATION_SUPER_ADMINS=you@example.com   # who may open /horizon

EXTRACTOR_URL=http://127.0.0.1:8100
EXTRACTOR_TOKEN=<long random string, e.g. from: openssl rand -hex 32>
```

Run the deploy script once. It installs dependencies, migrates and caches. The compiled CSS/JS is committed in `public/build`, so the server doesn't need Node. Then create your workspace and login:

```bash
bash $APP/deploy/deploy.sh $DOMAIN
sudo -u $SITEUSER $PHP artisan workspace:create "Your Company" you@example.com   # asks for your name and a password
```

Open `https://$DOMAIN` and log in.

## 6. Queue workers and scheduler

```bash
# Horizon under Supervisor
sed -e "s/__DOMAIN__/$DOMAIN/g" -e "s/__SITEUSER__/$SITEUSER/g" $APP/deploy/supervisor/dc-horizon.ini > /etc/supervisord.d/dc-horizon.ini
supervisorctl reread && supervisorctl update
supervisorctl status dc-horizon            # RUNNING

# Scheduler (every minute, as the site user); appends to any existing cron jobs
( crontab -u $SITEUSER -l 2>/dev/null; sed "s/__DOMAIN__/$DOMAIN/g" $APP/deploy/crontab | grep -v '^#' ) | crontab -u $SITEUSER -
crontab -u $SITEUSER -l
```

You can also add the cron line in CyberPanel under **Websites → Manage → Cron Jobs** instead.

- **Horizon runs two worker pools** (`config/horizon.php`):
  - `fetch` for sources
  - `ai` for enrichment
  
  Slow crawls therefore never hold up AI work.
- **The `/horizon` dashboard** is open only to the emails in `CURATION_SUPER_ADMINS`.

## 7. Extractor (and optional RSSHub)

```bash
cd $APP/extractor
EXTRACTOR_TOKEN=<same value as in .env> docker compose up -d --build
curl -s http://127.0.0.1:8100/health                 # {"ok":true}

# Optional: self-hosted RSSHub on 127.0.0.1:1200, then set RSSHUB_URL=http://127.0.0.1:1200 in .env
EXTRACTOR_TOKEN=<same value> docker compose --profile rsshub up -d
```

- **Both containers listen on `127.0.0.1` only**, so you don't need to open any firewall ports.
- **The first build takes a few minutes**, because it downloads a headless Chromium.
- **Without the extractor**, plain websites still work: the app falls back to normal HTTP. Only pages marked "needs JavaScript" require it.

## 8. Updating

Before pushing, run `npm run build` locally and commit `public/build` whenever you changed CSS, JS or Blade classes. Then:

```bash
bash /home/$DOMAIN/dc/deploy/deploy.sh $DOMAIN
```

The script does the following, in order:
1. Turns on maintenance mode.
2. Pulls the code and fixes file ownership.
3. Runs `composer install`.
4. Migrates the database.
5. Caches config, routes and views.
6. Restarts Horizon.
7. Turns maintenance mode off.
8. Gracefully restarts LiteSpeed.

When `extractor/` changes, also run `cd $APP/extractor && docker compose up -d --build`. After editing `.env` by hand, run `sudo -u $SITEUSER $PHP artisan optimize && sudo -u $SITEUSER $PHP artisan horizon:terminate`.

## Troubleshooting

| Problem | Fix |
|---|---|
| Home page works but other pages give 404 | `.htaccess` is not loaded: check the `rewrite` block in vHost Conf (step 3), then `/usr/local/lsws/bin/lswsctrl restart`. |
| `fatal: detected dubious ownership` | `git config --global --add safe.directory $APP` (the deploy script does this). |
| `Permission denied` in `storage/` or `bootstrap/cache` | `chown -R $SITEUSER:$SITEUSER $APP` |
| Composer or artisan say PHP is too old | `/usr/bin/php` was reset; relink it (step 1). |
| Jobs not running | `supervisorctl status dc-horizon`; logs in `$APP/storage/logs/horizon.log`. |
| Nothing fetched | `crontab -u $SITEUSER -l` shows the scheduler line; `sudo -u $SITEUSER $PHP artisan schedule:list`. |
| Website-type sources fail with "extractor service is not reachable" | `docker compose ps` in `$APP/extractor`; the token in `.env` must match. |

## Instagram (optional)

- **Official (free):**
  1. Create a Meta developer app.
  2. Link your Instagram professional account to a Facebook Page.
  3. Generate a long-lived token with `instagram_basic`, `instagram_manage_insights` and `pages_read_engagement`.
  4. Set `INSTAGRAM_IG_USER_ID` and `INSTAGRAM_ACCESS_TOKEN`.
  
  This reads Business and Creator accounts only.
- **Apify (paid):** set `APIFY_TOKEN` and choose "Apify" on the source (or `INSTAGRAM_DRIVER=apify`). It works for any public account.
