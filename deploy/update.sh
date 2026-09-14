#!/usr/bin/env bash
# NavlunIQ canlı sunucu güncellemesi: kodu çeker, bağımlılıkları ve derlemeyi
# yeniler, migration'ları uygular, önbellekleri tazeler. Root ile çalıştırılır.
#   bash /var/www/navluniq/deploy/update.sh            # main dalı
#   APP_BRANCH=baska-dal bash /var/www/navluniq/deploy/update.sh
set -euo pipefail

APP_DIR="/var/www/navluniq"
APP_BRANCH="${APP_BRANCH:-main}"

log() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
ok()  { printf '\033[1;32m✔ %s\033[0m\n' "$*"; }

git config --global --add safe.directory "$APP_DIR" >/dev/null 2>&1 || true
cd "$APP_DIR"
BEFORE="$(git rev-parse --short HEAD)"
trap 'php artisan up --quiet >/dev/null 2>&1 || true' EXIT

log "Bakım modu"
php artisan down --retry=15 --quiet || true

log "Kod (${APP_BRANCH})"
git fetch --quiet origin "$APP_BRANCH"
git checkout --quiet "$APP_BRANCH"
git reset --quiet --hard "origin/$APP_BRANCH"
AFTER="$(git rev-parse --short HEAD)"
ok "${BEFORE} → ${AFTER}"

log "Bağımlılıklar ve derleme"
composer install --no-dev --optimize-autoloader --no-interaction --quiet
npm ci --silent --no-audit --no-fund
npm run build --silent

log "Veritabanı"
php artisan migrate --force --no-interaction

log "Roller ve izinler"
php artisan db:seed --force --no-interaction --class=RolesAndPermissionsSeeder --quiet
# SSS ve sözleşme metinleri panelden düzenlenebildiği için otomatik yenilenmez.
# Koddaki güncel metinleri yüklemek için: php artisan db:seed --class=FaqSeeder --force

log "İzinler ve önbellekler"
mkdir -p storage/app/kyc storage/app/private
chown -R www-data:www-data "$APP_DIR"
chmod -R ug+rwx storage bootstrap/cache
chmod 640 .env
runuser -u www-data -- php artisan optimize:clear --quiet
runuser -u www-data -- php artisan optimize --quiet

PHP_FPM="$(systemctl list-units --type=service --state=running 'php*-fpm*' --no-legend | awk '{print $1}' | head -n1)"
[[ -n "$PHP_FPM" ]] && systemctl reload "$PHP_FPM"

ok "Güncelleme tamamlandı (${AFTER})"
