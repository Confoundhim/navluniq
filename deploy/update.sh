#!/usr/bin/env bash
# NavlunIQ canlı sunucu güncellemesi: kodu çeker, bağımlılıkları ve derlemeyi
# yeniler, migration'ları uygular, önbellekleri tazeler. Root ile çalıştırılır.
#   bash /var/www/navluniq/deploy/update.sh            # main dalı
#   APP_BRANCH=baska-dal bash /var/www/navluniq/deploy/update.sh
set -euo pipefail

# Betik çalışırken /root/update.sh kendini güncellediğinde bash dosyayı yarım okumasın:
# her zaman geçici bir kopyadan çalışır, kopya bitince silinir.
if [[ -z "${NAVLUNIQ_UPDATE_COPY:-}" ]]; then
    UPDATE_TMP="$(mktemp /tmp/navluniq-update.XXXXXX)"
    cp "$0" "$UPDATE_TMP"
    NAVLUNIQ_UPDATE_COPY=1 exec bash "$UPDATE_TMP" "$@"
fi

APP_DIR="/var/www/navluniq"
APP_BRANCH="${APP_BRANCH:-main}"

log() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
ok()  { printf '\033[1;32m✔ %s\033[0m\n' "$*"; }

git config --global --add safe.directory "$APP_DIR" >/dev/null 2>&1 || true
cd "$APP_DIR"
BEFORE="$(git rev-parse --short HEAD)"
trap 'php artisan up --quiet >/dev/null 2>&1 || true; rm -f "$0"' EXIT

log "Bakım modu"
php artisan down --retry=15 --quiet || true
# Takılı kalmış PHP-FPM istekleri açık işlem/tablo kilidi bırakabilir; migrate bu kilidi beklerken
# "ilerlemiyor" görünür. Bakım modunda FPM yeniden başlatılır, kilitler serbest kalır.
PHP_FPM="$(systemctl list-units --type=service --state=running 'php*-fpm*' --no-legend | awk '{print $1}' | head -n1)"
[[ -n "$PHP_FPM" ]] && systemctl restart "$PHP_FPM"

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
if ! php artisan migrate --force --no-interaction; then
    echo "Migration başarısız. Kilit bekleyen sorgular:"
    mysql -e "SELECT id, user, time, state, LEFT(info, 120) AS info FROM information_schema.processlist WHERE command <> 'Sleep' ORDER BY time DESC LIMIT 10" 2>/dev/null || true
    echo "Çözüm: yukarıdaki uzun süreli bağlantıyı 'mysql -e \"KILL <id>\"' ile sonlandırıp betiği yeniden çalıştırın."
    exit 1
fi
# Araç tipi boş kalmış dış kaynak ilanlarını sınıflandırıcıyla doldur (yalnız boş olanlar; tekrar çalıştırmak güvenli).
php artisan scraped-loads:classify --no-interaction || true

log "Roller ve izinler"
php artisan db:seed --force --no-interaction --class=RolesAndPermissionsSeeder --quiet
# Sözleşme metinleri: künye yer tutucusu taşımayan eski biçim varsa güncel şablonla bir kez yenilenir; sonrası panelden.
php artisan legal:refresh --if-stale --no-interaction || true
# SSS: ödeme kuruluşu kurallarına aykırı eski ifade (cüzdan/havuz/bloke/escrow) içeren metin varsa güncel seed ile yenilenir.
php artisan faq:refresh --if-stale --no-interaction || true

log "PHP sınırları"
PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
for sapi in fpm cli; do
    [[ -d "/etc/php/${PHP_VER}/${sapi}/conf.d" ]] || continue
    cat > "/etc/php/${PHP_VER}/${sapi}/conf.d/99-navluniq.ini" <<'INI'
; NavlunIQ (deploy/update.sh tarafından yazılır)
upload_max_filesize = 12M
post_max_size = 32M
memory_limit = 256M
max_execution_time = 120
max_input_time = 120
INI
done
ok "upload_max_filesize 12M, post_max_size 32M"

# /root/update.sh kopyasını depodaki güncel sürümle eşitle (bir sonraki çalıştırma yeni betiği kullanır)
if [[ -f /root/update.sh ]] && ! cmp -s "$APP_DIR/deploy/update.sh" /root/update.sh; then
    cp "$APP_DIR/deploy/update.sh" /root/update.sh
    ok "/root/update.sh güncellendi (bir sonraki çalıştırmada geçerli)"
fi

log "Zamanlayıcı (her dakika)"
SCHED_LINE="* * * * * cd ${APP_DIR} && php artisan schedule:run >> /dev/null 2>&1"
{ crontab -u www-data -l 2>/dev/null | grep -vF "schedule:run" || true; echo "$SCHED_LINE"; } | crontab -u www-data -
ok "www-data crontab: schedule:run (otomatik onay, Telegram, teklif/abonelik süreleri, e-posta yeniden deneme)"

log "Gece yedeği (03:00)"
BACKUP_LINE="0 3 * * * bash ${APP_DIR}/deploy/backup.sh >> /var/log/navluniq-backup.log 2>&1"
{ crontab -l 2>/dev/null | grep -vF "deploy/backup.sh" || true; echo "$BACKUP_LINE"; } | crontab -
ok "root crontab: her gece 03:00 tam yedek (/var/backups/navluniq)"

log "İzinler ve önbellekler"
mkdir -p storage/app/kyc storage/app/private
chown -R www-data:www-data "$APP_DIR"
chmod -R ug+rwx storage bootstrap/cache
chmod 640 .env
runuser -u www-data -- php artisan optimize:clear --quiet
runuser -u www-data -- php artisan optimize --quiet

PHP_FPM="$(systemctl list-units --type=service --state=running 'php*-fpm*' --no-legend | awk '{print $1}' | head -n1)"
[[ -n "$PHP_FPM" ]] && systemctl restart "$PHP_FPM"

ok "Güncelleme tamamlandı (${AFTER})"
