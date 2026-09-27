#!/usr/bin/env bash
# =============================================================================
# Canlı yedeğini (deploy/backup.sh çıktısı) yerel docker ortamına yükler. "app" kabının içinde çalışır:
#   docker compose run --rm app bash deploy/docker/yerel-geri-yukle.sh /yedek/navluniq-YYYYmmdd-HHMMSS.tar.gz
#
# Yaptıkları: yedeği açar, yerel .env yazar (APP_KEY canlıdan gelir; şifreli telefonlar okunur), veritabanını
# sıfırlayıp dökümü yükler, belgeleri yerine koyar, bağımlılıkları kurar, ön yüzü derler, migration çalıştırır,
# deneme kopyasını yalıtır (e-posta/Telegram kapalı, ödeme boş, yöneticiler kod 123456 ile girer).
# Tekrar çalıştırmak güvenlidir; her seferinde veritabanı yedekten yeniden yüklenir.
# =============================================================================
set -euo pipefail

APP_DIR="/var/www/navluniq"
ARCHIVE="${1:-}"
DB_HOST="${DB_HOST:-mysql}"; DB_NAME="${DB_NAME:-navluniq}"; DB_USER="${DB_USER:-navluniq}"; DB_PASS="${DB_PASS:-navluniq}"
ROOT_PASS="${MYSQL_ROOT_PASSWORD:-root}"
PORT="${YEREL_PORT:-8080}"

log()  { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
ok()   { printf '\033[1;32m✔ %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31m✘ %s\033[0m\n' "$*" >&2; exit 1; }

[[ -n "$ARCHIVE" ]] || fail "Yedek dosyası verilmedi. Örnek: bash deploy/docker/yerel-geri-yukle.sh /yedek/navluniq-20260927-150000.tar.gz"
[[ -f "$ARCHIVE" ]] || fail "Yedek bulunamadı: $ARCHIVE (dosyayı proje klasöründeki 'yedek' klasörüne koyun)"
cd "$APP_DIR"

WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT
log "Yedek açılıyor"
tar -C "$WORK" -xzf "$ARCHIVE"
Y="$WORK/yedek"
[[ -f "$Y/veritabani.sql.gz" && -f "$Y/env" ]] || fail "Yedekte veritabanı dökümü ya da .env yok."
[[ -f "$Y/SURUM.txt" ]] && sed 's/^/  /' "$Y/SURUM.txt"

log "Yerel .env"
cp "$Y/env" .env
set_env() {
    local key="$1" value="$2"
    if grep -qE "^${key}=" .env; then
        sed -i -E "s|^${key}=.*|${key}=${value}|" .env
    else
        printf '%s=%s\n' "$key" "$value" >> .env
    fi
}
set_env APP_ENV local
set_env APP_DEBUG true
set_env APP_URL "http://localhost:${PORT}"
set_env DB_CONNECTION mysql
set_env DB_HOST "$DB_HOST"
set_env DB_PORT 3306
set_env DB_DATABASE "$DB_NAME"
set_env DB_USERNAME "$DB_USER"
set_env DB_PASSWORD "$DB_PASS"
set_env REDIS_HOST redis
set_env REDIS_PORT 6379
set_env CACHE_STORE redis
set_env SESSION_DRIVER database
set_env QUEUE_CONNECTION database
set_env SESSION_SECURE_COOKIE false
set_env SESSION_DOMAIN ""
set_env MAIL_MAILER log
grep -qE '^APP_KEY="?base64:' .env || fail "Yedekteki .env içinde APP_KEY yok; şifreli veriler okunamaz."
ok ".env yazıldı (APP_KEY canlıdan, adres http://localhost:${PORT}, e-posta kapalı)"

log "Veritabanı bekleniyor"
for i in $(seq 1 60); do
    mysqladmin ping -h "$DB_HOST" -u root -p"$ROOT_PASS" --silent >/dev/null 2>&1 && break
    sleep 2
    [[ $i -eq 60 ]] && fail "MySQL 2 dakikada hazır olmadı; 'docker compose up -d mysql' çalışıyor mu?"
done
mysql -h "$DB_HOST" -u root -p"$ROOT_PASS" -e "DROP DATABASE IF EXISTS \`${DB_NAME}\`; CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%'; FLUSH PRIVILEGES;"
log "Döküm yükleniyor"
gunzip -c "$Y/veritabani.sql.gz" | mysql -h "$DB_HOST" -u root -p"$ROOT_PASS" --default-character-set=utf8mb4 "$DB_NAME"
ok "$(mysql -h "$DB_HOST" -u root -p"$ROOT_PASS" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}'") tablo yüklendi"

log "Belgeler ve yüklemeler"
mkdir -p storage/app
[[ -f "$Y/storage-app.tar.gz" ]] && tar -C storage -xzf "$Y/storage-app.tar.gz" && ok "storage/app yerine kondu"
mkdir -p storage/app/kyc storage/app/private storage/app/public storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
chmod -R a+rwX storage bootstrap/cache

log "Bağımlılıklar ve derleme (ilk seferde birkaç dakika)"
composer install --no-interaction --prefer-dist --quiet
npm ci --silent --no-audit --no-fund
npm run build --silent
ok "vendor ve public/build hazır"

log "Migration ve önbellekler"
php artisan migrate --force --no-interaction
[[ -L public/storage ]] || php artisan storage:link --quiet
php artisan optimize:clear --quiet
php artisan deneme:izole --no-interaction
chmod -R a+rwX storage bootstrap/cache

cat <<SUMMARY

=============================================================
 Yerel kopya hazır
   Adres   : http://localhost:${PORT}
   Yönetim : http://localhost:${PORT}/adminsystem  (kendi e-postanız ve şifreniz, doğrulama kodu 123456)
   Başlat  : docker compose up -d      Durdur: docker compose down
 E-posta, Telegram ve ödeme kapalı; kimseye bir şey gitmez. Yeni yedek yüklemek için aynı komutu tekrar çalıştırın.
=============================================================
SUMMARY
