#!/usr/bin/env bash
# =============================================================================
# NavlunIQ canlı sunucu kurulumu (Ubuntu 22.04/24.04 veya Debian 12, root ile)
#
# İlk kurulumda ve sonraki her çalıştırmada güvenle tekrar çalıştırılabilir.
# Gerekli değerler ortam değişkeni olarak verilir:
#
#   DB_DATABASE, DB_USERNAME, DB_PASSWORD   (zorunlu)
#   DOMAIN            alan adı ya da sunucu IP'si (varsayılan: sunucu IP'si)
#   LETSENCRYPT_EMAIL alan adı verildiyse ücretsiz SSL için e-posta
#   ADMIN_INIT_EMAIL, ADMIN_INIT_PASSWORD, ADMIN_INIT_PHONE  ilk yönetici
#   APP_BRANCH        çekilecek dal (varsayılan: main)
#   PHP_VERSION       kurulacak PHP sürümü (varsayılan: 8.4, composer.lock ile uyumlu)
#
# Örnek:
#   DB_DATABASE=navluniq_live DB_USERNAME=navluniq_user DB_PASSWORD='...' \
#   ADMIN_INIT_EMAIL=admin@site.com ADMIN_INIT_PASSWORD='...' ADMIN_INIT_PHONE=05xxxxxxxxx \
#   bash /var/www/navluniq/deploy/install.sh
# =============================================================================
set -euo pipefail

APP_DIR="/var/www/navluniq"
REPO_URL="https://github.com/Confoundhim/navluniq.git"
APP_BRANCH="${APP_BRANCH:-main}"
PHP_VERSION="${PHP_VERSION:-8.4}"
NODE_MAJOR="20"

log()  { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
ok()   { printf '\033[1;32m✔ %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31m✘ %s\033[0m\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || fail "Bu betik root olarak çalıştırılmalı."
# Veritabanı bilgileri verilmemişse mevcut .env dosyasından okunur.
if [[ -f /var/www/navluniq/.env ]]; then
    env_get() { sed -nE "s/^$1=\"?([^\"]*)\"?$/\1/p" /var/www/navluniq/.env | head -n1; }
    DB_DATABASE="${DB_DATABASE:-$(env_get DB_DATABASE)}"
    DB_USERNAME="${DB_USERNAME:-$(env_get DB_USERNAME)}"
    DB_PASSWORD="${DB_PASSWORD:-$(env_get DB_PASSWORD)}"
    [[ -n "${DOMAIN:-}" ]] || DOMAIN="$(env_get APP_URL | sed -E 's|^https?://||')"
fi
[[ -n "${DB_DATABASE:-}" && -n "${DB_USERNAME:-}" && -n "${DB_PASSWORD:-}" ]] \
    || fail "DB_DATABASE, DB_USERNAME ve DB_PASSWORD ortam değişkenleri zorunlu."

SERVER_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
DOMAIN="${DOMAIN:-$SERVER_IP}"
DOMAIN="${DOMAIN#www.}"
IS_DOMAIN=0
[[ "$DOMAIN" =~ ^[0-9.]+$ ]] || IS_DOMAIN=1
# nginx alan adıyla birlikte www'yi de tanır (zararsız); sertifikaya www yalnız bu sunucuya yönlendirilmişse eklenir.
SERVER_NAMES="$DOMAIN"; CERT_DOMAINS=(-d "$DOMAIN")
if [[ $IS_DOMAIN -eq 1 ]]; then
    SERVER_NAMES="$DOMAIN www.${DOMAIN}"
    getent hosts "www.${DOMAIN}" 2>/dev/null | grep -q "$SERVER_IP" && CERT_DOMAINS+=(-d "www.${DOMAIN}")
fi

export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a NEEDRESTART_SUSPEND=1

# -----------------------------------------------------------------------------
log "Sistem paketleri"
apt-get update -qq
apt-get install -y -qq ca-certificates curl git unzip gnupg lsb-release software-properties-common acl >/dev/null

if ! command -v php >/dev/null || ! php -r "exit(version_compare(PHP_VERSION, '${PHP_VERSION}.0', '>=') ? 0 : 1);"; then
    log "PHP ${PHP_VERSION} deposu ekleniyor"
    if [[ "$(lsb_release -is)" == "Ubuntu" ]]; then
        add-apt-repository -y ppa:ondrej/php >/dev/null
    else
        curl -sSLo /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg
        echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" \
            > /etc/apt/sources.list.d/php.list
    fi
    apt-get update -qq
fi

apt-get install -y -qq nginx redis-server supervisor \
    php${PHP_VERSION}-fpm php${PHP_VERSION}-cli php${PHP_VERSION}-mysql php${PHP_VERSION}-mbstring \
    php${PHP_VERSION}-xml php${PHP_VERSION}-curl php${PHP_VERSION}-zip php${PHP_VERSION}-bcmath \
    php${PHP_VERSION}-gd php${PHP_VERSION}-intl php${PHP_VERSION}-redis >/dev/null
systemctl enable --now redis-server supervisor >/dev/null 2>&1 || true
ok "nginx, redis, supervisor ve PHP ${PHP_VERSION} hazır"

update-alternatives --set php "/usr/bin/php${PHP_VERSION}" >/dev/null 2>&1 || true

# PHP sınırları: KYC belgeleri 10 MB'a kadar kabul edilir; PHP'nin varsayılan 2 MB
# yükleme sınırı bu yüzden yükseltilir (FPM ve CLI için ortak ayar dosyası).
for sapi in fpm cli; do
    [[ -d "/etc/php/${PHP_VERSION}/${sapi}/conf.d" ]] || continue
    cat > "/etc/php/${PHP_VERSION}/${sapi}/conf.d/99-navluniq.ini" <<'INI'
; NavlunIQ (deploy/install.sh tarafından yazılır)
upload_max_filesize = 12M
post_max_size = 32M
memory_limit = 256M
max_execution_time = 120
max_input_time = 120
INI
done
# PHP-FPM işçi sayısı: paketin varsayılanı 5'tir; birkaç eşzamanlı istek (telefon iletici, sayfa yenilemeleri)
# hepsini doldurur ve site bekler. Belleğe göre 8-30 arası işçi (her işçi ~50-80 MB).
POOL="/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf"
if [[ -f "$POOL" ]]; then
    RAM_MB="$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)"
    MAXC=$(( RAM_MB / 200 )); (( MAXC < 8 )) && MAXC=8; (( MAXC > 30 )) && MAXC=30
    sed -i -E "s/^;?pm.max_children = .*/pm.max_children = ${MAXC}/; s/^;?pm.start_servers = .*/pm.start_servers = 4/; s/^;?pm.min_spare_servers = .*/pm.min_spare_servers = 2/; s/^;?pm.max_spare_servers = .*/pm.max_spare_servers = 6/" "$POOL"
    ok "PHP-FPM işçi sayısı: ${MAXC} (bellek ${RAM_MB} MB)"
fi
systemctl restart "php${PHP_VERSION}-fpm" >/dev/null 2>&1 || true
ok "PHP yükleme sınırı 12 MB"

# MySQL bu sunucudaysa: her kayıtta diske onay beklenmez (tek sunucu, kopya yok). Yavaş diskte fark çok büyük.
if [[ -d /etc/mysql/mysql.conf.d ]] && systemctl is-active --quiet mysql 2>/dev/null; then
    MYCNF="/etc/mysql/mysql.conf.d/99-navluniq.cnf"
    WANT=$'[mysqld]\ninnodb_flush_log_at_trx_commit = 2\nskip-log-bin\n'
    if [[ ! -f "$MYCNF" ]] || [[ "$(cat "$MYCNF")" != "$(printf '%s' "$WANT")" ]]; then
        printf '%s' "$WANT" > "$MYCNF"
        systemctl restart mysql
        ok "MySQL ayarı yazıldı (99-navluniq.cnf)"
    fi
fi

if ! command -v composer >/dev/null; then
    log "Composer kuruluyor"
    curl -sS https://getcomposer.org/installer | php -- --quiet --install-dir=/usr/local/bin --filename=composer
fi
ok "composer $(composer --version 2>/dev/null | awk '{print $3}')"

if ! command -v node >/dev/null || [[ "$(node -v | cut -c2- | cut -d. -f1)" -lt "$NODE_MAJOR" ]]; then
    log "Node.js ${NODE_MAJOR} kuruluyor"
    curl -fsSL "https://deb.nodesource.com/setup_${NODE_MAJOR}.x" | bash - >/dev/null
    apt-get install -y -qq nodejs >/dev/null
fi
ok "node $(node -v)"

# -----------------------------------------------------------------------------
log "Uygulama kodu (${APP_BRANCH})"
git config --global --add safe.directory "$APP_DIR" >/dev/null 2>&1 || true
if [[ -d "$APP_DIR/.git" ]]; then
    git -C "$APP_DIR" fetch --quiet origin "$APP_BRANCH"
    git -C "$APP_DIR" checkout --quiet "$APP_BRANCH"
    git -C "$APP_DIR" reset --quiet --hard "origin/$APP_BRANCH"
else
    if [[ -d "$APP_DIR" && -n "$(ls -A "$APP_DIR" 2>/dev/null)" ]]; then
        mv "$APP_DIR" "${APP_DIR}.eski-$(date +%Y%m%d%H%M%S)"
    fi
    git clone --quiet --branch "$APP_BRANCH" "$REPO_URL" "$APP_DIR"
fi
cd "$APP_DIR"
ok "kod $(git rev-parse --short HEAD)"

# -----------------------------------------------------------------------------
log ".env dosyası"
if [[ ! -f .env ]]; then
    cp .env.example .env
    FIRST_INSTALL=1
else
    FIRST_INSTALL=0
fi

set_env() {  # set_env ANAHTAR DEĞER
    local key="$1" value="$2"
    local escaped
    escaped="$(printf '%s' "$value" | sed -e 's/[\/&|]/\\&/g')"
    if grep -qE "^${key}=" .env; then
        sed -i -E "s|^${key}=.*|${key}=\"${escaped}\"|" .env
    else
        printf '%s="%s"\n' "$key" "$value" >> .env
    fi
}

SCHEME="http"; [[ $IS_DOMAIN -eq 1 && -n "${LETSENCRYPT_EMAIL:-}" ]] && SCHEME="https"
set_env APP_ENV production
set_env APP_DEBUG false
set_env APP_URL "${SCHEME}://${DOMAIN}"
set_env LOG_LEVEL error
set_env DB_CONNECTION mysql
set_env DB_HOST 127.0.0.1
set_env DB_PORT "${DB_PORT:-3306}"
set_env DB_DATABASE "$DB_DATABASE"
set_env DB_USERNAME "$DB_USERNAME"
set_env DB_PASSWORD "$DB_PASSWORD"
set_env SESSION_SECURE_COOKIE "$([[ $SCHEME == https ]] && echo true || echo false)"
# Önbellek/oturum sürücüsü yalnız ilk kurulumda seçilir; var olan .env (ör. taşınan sunucu) olduğu gibi kalır.
if [[ $FIRST_INSTALL -eq 1 ]] || ! grep -qE '^CACHE_STORE=' .env; then
    if php -m | grep -qi '^redis$' && (command -v redis-cli >/dev/null && redis-cli -h 127.0.0.1 ping 2>/dev/null | grep -q PONG); then
        set_env CACHE_STORE redis
        set_env SESSION_DRIVER database
        set_env REDIS_HOST 127.0.0.1
        set_env REDIS_PORT 6379
        ok "önbellek: redis · oturum: veritabanı"
    else
        set_env CACHE_STORE database
        set_env SESSION_DRIVER database
        ok "önbellek ve oturum: veritabanı"
    fi
fi
grep -qE '^QUEUE_CONNECTION=' .env || set_env QUEUE_CONNECTION database
# İsteğe bağlı anahtarlar: ortam değişkeni olarak verilmişse .env'e yazılır.
for key in ADMIN_INIT_EMAIL ADMIN_INIT_PASSWORD ADMIN_INIT_PHONE \
           MAIL_MAILER MAIL_HOST MAIL_PORT MAIL_USERNAME MAIL_PASSWORD MAIL_ENCRYPTION MAIL_FROM_ADDRESS \
           COMPANY_NAME COMPANY_TAX_OFFICE COMPANY_TAX_NO COMPANY_MERSIS_NO COMPANY_ADDRESS COMPANY_PHONE COMPANY_EMAIL \
           LEGAL_EFFECTIVE_DATE; do
    [[ -n "${!key:-}" ]] && set_env "$key" "${!key}"
done
chmod 640 .env
ok ".env güncellendi"

# -----------------------------------------------------------------------------
log "Bağımlılıklar ve derleme"
composer install --no-dev --optimize-autoloader --no-interaction --quiet
npm ci --silent --no-audit --no-fund
npm run build --silent
ok "vendor ve public/build hazır"

grep -qE '^APP_KEY="?base64:' .env || php artisan key:generate --force --quiet

# -----------------------------------------------------------------------------
log "Veritabanı"
php artisan migrate --force --no-interaction
if [[ $FIRST_INSTALL -eq 1 ]]; then
    php artisan db:seed --force --no-interaction
    ok "ilk veriler (roller, ilk yönetici, SSS, sözleşmeler) yüklendi"
fi

# -----------------------------------------------------------------------------
log "Dosya izinleri ve önbellekler"
mkdir -p storage/app/kyc storage/app/private storage/app/public
[[ -L public/storage ]] || php artisan storage:link --quiet
chown -R www-data:www-data "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 755 {} +
find "$APP_DIR" -type f -exec chmod 644 {} +
chmod -R ug+rwx storage bootstrap/cache
chmod 640 .env
chmod +x deploy/*.sh
runuser -u www-data -- php artisan optimize:clear --quiet
runuser -u www-data -- php artisan optimize --quiet
ok "config/route/view önbellekleri oluşturuldu"

# -----------------------------------------------------------------------------
log "nginx"
PHP_SOCK="$(ls /run/php/php${PHP_VERSION}-fpm.sock 2>/dev/null || ls /run/php/php*-fpm.sock | head -n1)"
sed -e "s|__DOMAIN__|${SERVER_NAMES}|g" -e "s|__PHP_SOCK__|${PHP_SOCK}|g" deploy/nginx.conf > /etc/nginx/sites-available/navluniq
ln -sf /etc/nginx/sites-available/navluniq /etc/nginx/sites-enabled/navluniq
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl enable --now "php${PHP_VERSION}-fpm" nginx >/dev/null 2>&1
systemctl reload nginx
ok "site yayında: http://${DOMAIN}"

if [[ $IS_DOMAIN -eq 1 && -n "${LETSENCRYPT_EMAIL:-}" ]]; then
    log "SSL sertifikası (Let's Encrypt)"
    apt-get install -y -qq certbot python3-certbot-nginx >/dev/null
    if [[ -f "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" ]]; then
        # Sertifika zaten var (ör. taşınan sunucu): doğrulama istemeden nginx'e bağlanır; alan adı henüz buraya
        # dönmemiş olsa bile https çalışır. Yenilemeyi certbot alan adı döndükten sonra kendisi yapar.
        certbot install --nginx --cert-name "$DOMAIN" --non-interactive --redirect \
            && ok "https://${DOMAIN} aktif (mevcut sertifika)" \
            || echo "Mevcut sertifika bağlanamadı; alan adı bu sunucuya döndükten sonra betiği tekrar çalıştırın."
    else
        certbot --nginx "${CERT_DOMAINS[@]}" --non-interactive --agree-tos -m "$LETSENCRYPT_EMAIL" --redirect \
            && ok "https://${DOMAIN} aktif" \
            || echo "Sertifika alınamadı; alan adının bu sunucuya yönlendiğinden emin olup betiği tekrar çalıştırın."
    fi
fi

# -----------------------------------------------------------------------------
log "Zamanlanmış görevler"
CRON_LINE="* * * * * cd ${APP_DIR} && php artisan schedule:run >> /dev/null 2>&1"
{ crontab -u www-data -l 2>/dev/null | grep -vF "schedule:run" || true; echo "$CRON_LINE"; } | crontab -u www-data -
ok "her dakika schedule:run (www-data)"

# Kuyruk işçileri: telefon iletici mesajları ve nabız işi burada işlenir. İşçi .env ile aynı bağlantıyı dinler.
if command -v supervisorctl >/dev/null; then
    QUEUE_CONN="$(sed -nE 's/^QUEUE_CONNECTION="?([^"]*)"?$/\1/p' .env | head -n1)"; QUEUE_CONN="${QUEUE_CONN:-database}"
    cat > /etc/supervisor/conf.d/navluniq-worker.conf <<SUPERVISOR
[program:navluniq-worker]
process_name=%(program_name)s_%(process_num)02d
command=php ${APP_DIR}/artisan queue:work ${QUEUE_CONN} --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=${APP_DIR}/storage/logs/worker.log
stopwaitsecs=180
SUPERVISOR
    supervisorctl reread >/dev/null && supervisorctl update >/dev/null
    supervisorctl restart 'navluniq-worker:*' >/dev/null 2>&1 || true
    ok "kuyruk işçisi: 2 süreç, bağlantı ${QUEUE_CONN} (supervisor)"
fi

# -----------------------------------------------------------------------------
log "Sağlık kontrolü"
HTTP_CODE="$(curl -s -L -k -o /dev/null -w '%{http_code}' --resolve "${DOMAIN}:80:127.0.0.1" --resolve "${DOMAIN}:443:127.0.0.1" "http://${DOMAIN}/" || true)"
[[ "$HTTP_CODE" == "200" ]] && ok "ana sayfa 200 döndü" || echo "Ana sayfa ${HTTP_CODE} döndü; storage/logs/laravel.log dosyasına bakın."

cat <<SUMMARY

=============================================================
 NavlunIQ kurulumu tamamlandı
   Adres      : ${SCHEME}://${DOMAIN}
   Yönetim    : ${SCHEME}://${DOMAIN}/adminsystem
   Kod        : ${APP_DIR} ($(git rev-parse --short HEAD), ${APP_BRANCH})
   Güncelleme : bash ${APP_DIR}/deploy/update.sh
 Sonraki adımlar:
   - .env içinde MAIL_* değerlerini doldurun (OTP e-postaları için şart)
   - COMPANY_* alanlarını doldurun, ardından: php artisan config:cache
   - İlk yönetici şifresini panelden değiştirin
=============================================================
SUMMARY
