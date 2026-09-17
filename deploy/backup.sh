#!/usr/bin/env bash
# NavlunIQ tam yedek: veritabanı dökümü + .env + storage/app (belgeler, yüklemeler) + kod sürümü.
# Çıktı: /var/backups/navluniq/navluniq-YYYYmmdd-HHMMSS.tar.gz  (son 14 yedek tutulur)
#   bash /var/www/navluniq/deploy/backup.sh
set -euo pipefail

APP_DIR="/var/www/navluniq"
OUT_DIR="${BACKUP_DIR:-/var/backups/navluniq}"
KEEP="${BACKUP_KEEP:-14}"
STAMP="$(date +%Y%m%d-%H%M%S)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

env_get() { sed -nE "s/^$1=\"?([^\"]*)\"?$/\1/p" "$APP_DIR/.env" | head -n1; }
DB_HOST="$(env_get DB_HOST)"; DB_PORT="$(env_get DB_PORT)"; DB_NAME="$(env_get DB_DATABASE)"
DB_USER="$(env_get DB_USERNAME)"; DB_PASS="$(env_get DB_PASSWORD)"

mkdir -p "$OUT_DIR" "$WORK/yedek"
chmod 700 "$OUT_DIR"

echo "==> Veritabanı dökümü ($DB_NAME)"
MYSQL_PWD="$DB_PASS" mysqldump --host="${DB_HOST:-127.0.0.1}" --port="${DB_PORT:-3306}" --user="$DB_USER" \
    --single-transaction --quick --no-tablespaces --routines --triggers --default-character-set=utf8mb4 \
    "$DB_NAME" | gzip -9 > "$WORK/yedek/veritabani.sql.gz"

echo "==> Ortam dosyası ve depolama"
cp "$APP_DIR/.env" "$WORK/yedek/env"
tar -C "$APP_DIR/storage" -czf "$WORK/yedek/storage-app.tar.gz" app 2>/dev/null || true

echo "==> Sürüm bilgisi"
{
    echo "tarih: $(date -Is)"
    echo "kod: $(git -C "$APP_DIR" rev-parse HEAD 2>/dev/null || echo bilinmiyor) ($(git -C "$APP_DIR" rev-parse --abbrev-ref HEAD 2>/dev/null || echo -))"
    echo "php: $(php -r 'echo PHP_VERSION;')"
    echo "sunucu: $(hostname)"
} > "$WORK/yedek/SURUM.txt"

ARCHIVE="$OUT_DIR/navluniq-$STAMP.tar.gz"
tar -C "$WORK" -czf "$ARCHIVE" yedek
chmod 600 "$ARCHIVE"

# Eski yedekleri sınırla
ls -1t "$OUT_DIR"/navluniq-*.tar.gz 2>/dev/null | tail -n +"$((KEEP + 1))" | xargs -r rm -f

echo "==> Yedek hazır: $ARCHIVE ($(du -h "$ARCHIVE" | cut -f1))"
echo "    Bilgisayara indirmek için (PowerShell): scp root@$(hostname -I | awk '{print $1}'):$ARCHIVE ."
echo "    En son yedeği indirmek için: scp \"root@$(hostname -I | awk '{print $1}'):$OUT_DIR/navluniq-*.tar.gz\" ."
