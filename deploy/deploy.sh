#!/usr/bin/env bash
#
# Install or update the app on the CyberPanel VPS. Run as root from anywhere:
#
#   bash /home/curation.example.com/dc/deploy/deploy.sh curation.example.com
#
# Git runs as root (it holds the GitHub deploy key); everything else runs as the
# site user CyberPanel created, with the LSPHP 8.3 binary.
set -euo pipefail

DOMAIN="${1:?Usage: deploy.sh <domain>}"
APP="/home/${DOMAIN}/dc"
PHP="${PHP:-/usr/local/lsws/lsphp83/bin/php}"
COMPOSER="$(command -v composer)"
SITEUSER="$(stat -c %U "/home/${DOMAIN}")"

run() { sudo -u "$SITEUSER" -H "$@"; }

cd "$APP"

if [ ! -f .env ]; then
    echo "Missing $APP/.env. Create it first (docs/DEPLOYMENT.md, step 5)." >&2
    exit 1
fi

# Git refuses to work in a repository owned by another user unless it is marked safe.
git config --global --get-all safe.directory | grep -qxF "$APP" || git config --global --add safe.directory "$APP"

INSTALLED=0
[ -d vendor ] && INSTALLED=1

[ "$INSTALLED" = 1 ] && run "$PHP" artisan down --retry=30 || true

git pull --ff-only
chown -R "$SITEUSER:$SITEUSER" "$APP"

run "$PHP" "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction

run "$PHP" artisan migrate --force
[ -L public/storage ] || run "$PHP" artisan storage:link
run "$PHP" artisan optimize

# Horizon finishes its current jobs and exits; Supervisor starts it again on the new code.
run "$PHP" artisan horizon:terminate || true
run "$PHP" artisan up

# Restart LiteSpeed gracefully so its PHP workers drop cached code.
/usr/local/lsws/bin/lswsctrl restart >/dev/null

echo "Deployed $(git rev-parse --short HEAD) to $DOMAIN"
