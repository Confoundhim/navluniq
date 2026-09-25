#!/usr/bin/env bash
# =============================================================================
# NavlunIQ: siteyi YENİ sunucuya taşıma (yeni sunucuda root ile çalıştırılır).
#
# Eski sunucuda alınan tam yedeği (deploy/backup.sh çıktısı: veritabanı + .env + belgeler + SSL sertifikası)
# yeni sunucuya kurar. Adımlar: paketler → veritabanı → kod → .env ve belgeler → deploy/install.sh
# (PHP, nginx, SSL, cron, kuyruk işçisi) → panel "Siteyi güncelle" düğmesi. Eski sunucuya dokunmaz.
#
# Kullanım (yeni sunucuda):
#   bash tasima.sh --kontrol /root/navluniq-YYYYmmdd-HHMMSS.tar.gz     # yalnız yedeği inceler, hiçbir şey kurmaz
#   LETSENCRYPT_EMAIL=siz@ornek.com bash tasima.sh /root/navluniq-YYYYmmdd-HHMMSS.tar.gz
#   DOMAIN=2.28.215.168 bash tasima.sh --deneme /root/navluniq-YYYYmmdd-HHMMSS.tar.gz   # IP ile açılan deneme kopyası
#
# --deneme (deneme kopyası): site IP ile http üzerinden açılır, gerçek verinin kopyasıyla çalışır ama dışarıya
#   hiçbir şey göndermez: e-posta kapalı (MAIL_MAILER=log), Telegram kapalı, ödeme sağlayıcısı boş; yöneticiler
#   sabit kodla (123456) girer (php artisan deneme:izole). Canlı siteyi ve kullanıcıları etkilemez.
#
# İsteğe bağlı ortam değişkenleri:
#   DOMAIN            alan adı ya da IP (varsayılan: yedekteki APP_URL; --deneme ile sunucunun IP'si)
#   LETSENCRYPT_EMAIL SSL yenilemeleri için e-posta (alan adı verildiyse zorunlu; IP ile gerekmez)
#   APP_BRANCH        çekilecek dal (varsayılan: main)
#   FORCE_IMPORT=1    veritabanında tablo olsa bile dökümü yeniden yükle (mevcut tablolar silinir!)
#
# Tekrar çalıştırmak güvenlidir: dolu veritabanına ikinci kez döküm yüklenmez, kod ve .env üstüne yazılmaz
# (.env yalnız yoksa yazılır), belgeler üstüne yazılır (aynı dosyalar).
# =============================================================================
set -euo pipefail

APP_DIR="/var/www/navluniq"
REPO_URL="https://github.com/Confoundhim/navluniq.git"
APP_BRANCH="${APP_BRANCH:-main}"

log()  { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
ok()   { printf '\033[1;32m✔ %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m! %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31m✘ %s\033[0m\n' "$*" >&2; exit 1; }

KONTROL=0
DENEME=0
ARCHIVE=""
for arg in "$@"; do
    case "$arg" in
        --kontrol) KONTROL=1 ;;
        --deneme) DENEME=1 ;;
        *) ARCHIVE="$arg" ;;
    esac
done
[[ -n "$ARCHIVE" ]] || fail "Yedek dosyası verilmedi. Örnek: bash tasima.sh /root/navluniq-20260925-120000.tar.gz"
[[ -f "$ARCHIVE" ]] || fail "Yedek dosyası bulunamadı: $ARCHIVE"
[[ $EUID -eq 0 ]] || fail "Bu betik root olarak çalıştırılmalı."

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# -----------------------------------------------------------------------------
log "Yedek açılıyor"
tar -C "$WORK" -xzf "$ARCHIVE"
Y="$WORK/yedek"
[[ -f "$Y/veritabani.sql.gz" ]] || fail "Yedekte veritabanı dökümü yok (veritabani.sql.gz). Eski sunucuda deploy/backup.sh ile alınan yedek gerekir."
[[ -f "$Y/env" ]] || fail "Yedekte .env yok."

env_get() { sed -nE "s/^$1=\"?([^\"]*)\"?$/\1/p" "$Y/env" | head -n1; }
DB_DATABASE="$(env_get DB_DATABASE)"; DB_USERNAME="$(env_get DB_USERNAME)"; DB_PASSWORD="$(env_get DB_PASSWORD)"
APP_KEY="$(env_get APP_KEY)"
[[ -n "$DB_DATABASE" && -n "$DB_USERNAME" && -n "$DB_PASSWORD" ]] || fail "Yedekteki .env içinde DB_DATABASE / DB_USERNAME / DB_PASSWORD eksik."
[[ "$APP_KEY" == base64:* ]] || fail "Yedekteki .env içinde APP_KEY yok; şifreli veriler (telefonlar) bu anahtarsız okunamaz."
if [[ $DENEME -eq 1 && -z "${DOMAIN:-}" ]]; then
    DOMAIN="$(hostname -I 2>/dev/null | awk '{print $1}')"
fi
DOMAIN="${DOMAIN:-$(env_get APP_URL | sed -E 's|^https?://||; s|/.*$||')}"
DOMAIN="${DOMAIN#www.}"
[[ -n "$DOMAIN" ]] || fail "Alan adı bulunamadı; DOMAIN=navluniq.com gibi verin."
IS_IP=0; [[ "$DOMAIN" =~ ^[0-9.]+$ ]] && IS_IP=1
[[ $DENEME -eq 1 ]] && echo "  Kip        : DENEME KOPYASI (http://${DOMAIN}, dışarıya gönderim kapalı)"

echo "  Veritabanı : $DB_DATABASE (kullanıcı $DB_USERNAME)"
echo "  Alan adı   : $DOMAIN"
echo "  Döküm      : $(du -h "$Y/veritabani.sql.gz" | cut -f1)"
[[ -f "$Y/storage-app.tar.gz" ]] && echo "  Belgeler   : $(du -h "$Y/storage-app.tar.gz" | cut -f1)" || warn "Yedekte belge arşivi yok (storage-app.tar.gz)."
[[ -f "$Y/letsencrypt.tar.gz" ]] && echo "  SSL        : sertifika yedekte var (https hemen çalışır)" || warn "Yedekte SSL sertifikası yok; alan adı yeni sunucuya döndükten sonra sertifika alınır."
[[ -f "$Y/SURUM.txt" ]] && sed 's/^/  /' "$Y/SURUM.txt"

if [[ $KONTROL -eq 1 ]]; then
    ok "Kontrol tamam; hiçbir şey kurulmadı. Kurmak için --kontrol olmadan çalıştırın."
    exit 0
fi
[[ $IS_IP -eq 1 || -n "${LETSENCRYPT_EMAIL:-}" ]] || fail "LETSENCRYPT_EMAIL verilmedi (https için gerekli). Örnek: LETSENCRYPT_EMAIL=siz@ornek.com bash tasima.sh $ARCHIVE"

export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a NEEDRESTART_SUSPEND=1

# -----------------------------------------------------------------------------
log "Sistem paketleri (MySQL, Redis, supervisor, git)"
apt-get update -qq
apt-get install -y -qq git curl ca-certificates mysql-server redis-server supervisor >/dev/null
systemctl enable --now mysql redis-server supervisor >/dev/null 2>&1 || true
ok "mysql $(mysql --version | awk '{print $3}' | tr -d ,), redis, supervisor hazır"

# MySQL: her kayıtta diske onay beklenmez (tek sunucu, kopya yok); yavaş diskte fark çok büyük.
if [[ -d /etc/mysql/mysql.conf.d ]]; then
    printf '[mysqld]\ninnodb_flush_log_at_trx_commit = 2\nskip-log-bin\n' > /etc/mysql/mysql.conf.d/99-navluniq.cnf
    systemctl restart mysql
    ok "MySQL ayarı yazıldı (99-navluniq.cnf)"
fi

# -----------------------------------------------------------------------------
log "Veritabanı ve kullanıcı"
PW_SQL="${DB_PASSWORD//\'/\'\'}"
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USERNAME}'@'localhost' IDENTIFIED BY '${PW_SQL}';
CREATE USER IF NOT EXISTS '${DB_USERNAME}'@'127.0.0.1' IDENTIFIED BY '${PW_SQL}';
ALTER USER '${DB_USERNAME}'@'localhost' IDENTIFIED BY '${PW_SQL}';
ALTER USER '${DB_USERNAME}'@'127.0.0.1' IDENTIFIED BY '${PW_SQL}';
GRANT ALL PRIVILEGES ON \`${DB_DATABASE}\`.* TO '${DB_USERNAME}'@'localhost';
GRANT ALL PRIVILEGES ON \`${DB_DATABASE}\`.* TO '${DB_USERNAME}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
ok "veritabanı ${DB_DATABASE} ve kullanıcı hazır"

TABLE_COUNT="$(mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_DATABASE}'")"
if [[ "$TABLE_COUNT" -gt 0 && "${FORCE_IMPORT:-0}" != "1" ]]; then
    warn "Veritabanında zaten ${TABLE_COUNT} tablo var; döküm yeniden yüklenmedi (yeniden yüklemek için FORCE_IMPORT=1)."
else
    log "Veritabanı dökümü yükleniyor (${DB_DATABASE})"
    if [[ "$TABLE_COUNT" -gt 0 ]]; then
        mysql -e "DROP DATABASE \`${DB_DATABASE}\`; CREATE DATABASE \`${DB_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    fi
    gunzip -c "$Y/veritabani.sql.gz" | mysql --default-character-set=utf8mb4 "$DB_DATABASE"
    ok "döküm yüklendi: $(mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_DATABASE}'") tablo"
fi
MYSQL_PWD="$DB_PASSWORD" mysql --host=127.0.0.1 --user="$DB_USERNAME" -N -e "SELECT 1" "$DB_DATABASE" >/dev/null \
    && ok "uygulama kullanıcısı bağlanabiliyor" || fail "Uygulama kullanıcısı veritabanına bağlanamadı."

# -----------------------------------------------------------------------------
log "Uygulama kodu"
git config --global --add safe.directory "$APP_DIR" >/dev/null 2>&1 || true
if [[ ! -d "$APP_DIR/.git" ]]; then
    [[ -d "$APP_DIR" && -n "$(ls -A "$APP_DIR" 2>/dev/null)" ]] && mv "$APP_DIR" "${APP_DIR}.eski-$(date +%Y%m%d%H%M%S)"
    git clone --quiet --branch "$APP_BRANCH" "$REPO_URL" "$APP_DIR"
fi
ok "kod $(git -C "$APP_DIR" rev-parse --short HEAD) (${APP_BRANCH})"

# -----------------------------------------------------------------------------
log ".env ve belgeler"
if [[ -f "$APP_DIR/.env" ]]; then
    warn ".env zaten var; yedekteki .env üstüne yazılmadı."
else
    cp "$Y/env" "$APP_DIR/.env"
    chmod 640 "$APP_DIR/.env"
    ok ".env yedekten geldi (APP_KEY korundu)"
fi
if [[ $DENEME -eq 1 ]]; then
    # Deneme kopyası e-posta göndermez: iletiler storage/logs/laravel.log'a yazılır.
    if grep -qE '^MAIL_MAILER=' "$APP_DIR/.env"; then
        sed -i -E 's|^MAIL_MAILER=.*|MAIL_MAILER=log|' "$APP_DIR/.env"
    else
        printf 'MAIL_MAILER=log\n' >> "$APP_DIR/.env"
    fi
    # Çerez alan adı canlı siteye bağlıysa IP ile giriş tutmaz; deneme kopyasında boş bırakılır.
    sed -i -E 's|^SESSION_DOMAIN=.*|SESSION_DOMAIN=|' "$APP_DIR/.env"
    ok "deneme kipi: e-posta gönderimi kapalı (MAIL_MAILER=log)"
fi
mkdir -p "$APP_DIR/storage/app"
if [[ -f "$Y/storage-app.tar.gz" ]]; then
    tar -C "$APP_DIR/storage" -xzf "$Y/storage-app.tar.gz"
    ok "belgeler ve yüklemeler yerine kondu ($(du -sh "$APP_DIR/storage/app" | cut -f1))"
fi

# SSL sertifikası eski sunucudan geliyorsa alan adı henüz buraya dönmeden https çalışır;
# install.sh içindeki certbot mevcut sertifikayı görür ve yenisini istemeden nginx'e bağlar.
if [[ $DENEME -eq 0 && -f "$Y/letsencrypt.tar.gz" ]]; then
    apt-get install -y -qq certbot python3-certbot-nginx >/dev/null
    tar -C /etc -xzf "$Y/letsencrypt.tar.gz"
    ok "SSL sertifikası /etc/letsencrypt altına kondu"
fi

# -----------------------------------------------------------------------------
log "Kurulum betiği (PHP, nginx, SSL, cron, kuyruk işçisi)"
DOMAIN="$DOMAIN" LETSENCRYPT_EMAIL="${LETSENCRYPT_EMAIL:-}" APP_BRANCH="$APP_BRANCH" \
DB_DATABASE="$DB_DATABASE" DB_USERNAME="$DB_USERNAME" DB_PASSWORD="$DB_PASSWORD" \
    bash "$APP_DIR/deploy/install.sh"

if [[ $DENEME -eq 1 ]]; then
    log "Deneme kopyası yalıtımı"
    (cd "$APP_DIR" && runuser -u www-data -- php artisan deneme:izole --no-interaction) \
        || warn "Yalıtım komutu çalışmadı; kurulum sonrası elle: php artisan deneme:izole"
fi

# -----------------------------------------------------------------------------
log "Panelden güncelleme düğmesi"
cp "$APP_DIR/deploy/update.sh" /root/update.sh
chmod +x /root/update.sh
bash "$APP_DIR/deploy/install-update-button.sh" >/dev/null && ok "Siteyi güncelle düğmesi hazır" || warn "Güncelleme düğmesi kurulamadı; sonra: bash $APP_DIR/deploy/install-update-button.sh"

# -----------------------------------------------------------------------------
log "Son kontrol"
cd "$APP_DIR"
runuser -u www-data -- php artisan migrate:status --no-interaction 2>/dev/null | tail -n 3 || true
USERS="$(MYSQL_PWD="$DB_PASSWORD" mysql --host=127.0.0.1 --user="$DB_USERNAME" -N -e "SELECT COUNT(*) FROM users" "$DB_DATABASE" 2>/dev/null || echo '?')"
LOADS="$(MYSQL_PWD="$DB_PASSWORD" mysql --host=127.0.0.1 --user="$DB_USERNAME" -N -e "SELECT COUNT(*) FROM scraped_loads" "$DB_DATABASE" 2>/dev/null || echo '?')"
if [[ $IS_IP -eq 1 ]]; then
    SITE_URL="http://${DOMAIN}/"
    HTTP_CODE="$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1/" -H "Host: ${DOMAIN}" || true)"
else
    SITE_URL="https://${DOMAIN}/"
    HTTP_CODE="$(curl -s -k -o /dev/null -w '%{http_code}' --resolve "${DOMAIN}:443:127.0.0.1" "https://${DOMAIN}/" || true)"
fi

cat <<SUMMARY

=============================================================
 Taşıma tamamlandı (bu sunucu: $(hostname -I | awk '{print $1}'))
   Kullanıcı sayısı    : ${USERS}
   Dış kaynak ilanı    : ${LOADS}
   ${SITE_URL}  : ${HTTP_CODE} (bu sunucudan)
$(if [[ $DENEME -eq 1 ]]; then cat <<DENEME
 DENEME KOPYASI: tarayıcıda ${SITE_URL} açın; yönetici e-postanız ve şifrenizle girin, kod 123456.
   E-posta ve Telegram gönderimi kapalı, ödeme sağlayıcısı boş; gerçek kullanıcılar etkilenmez.
   Beğenirseniz gerçek geçiş: docs/SUNUCU_TASINMA.md → "Geçiş günü". Vazgeçerseniz sunucuyu kapatmanız yeter.
DENEME
else cat <<GERCEK
 Sıradaki adımlar: docs/SUNUCU_TASINMA.md → "Geçiş günü"
   1. Bilgisayarınızın hosts dosyasıyla bu IP'yi deneyin (giriş, ilan listesi, yönetici paneli).
   2. Eski sunucuda bakım modu + son yedek, burada FORCE_IMPORT=1 ile yeniden yükleme.
   3. Alan adını bu IP'ye çevirin; panelde Sistem sağlığı kontrolü.
GERCEK
fi)
=============================================================
SUMMARY
