#!/usr/bin/env bash
# Panelden "Siteyi güncelle" düğmesi için tek seferlik sunucu kurulumu (root olarak çalıştırın):
#   bash /var/www/navluniq/deploy/install-update-button.sh
# - /usr/local/bin/navluniq-update sarmalayıcısını yazar (bash /root/update.sh çalıştırır)
# - www-data kullanıcısına yalnız bu komut için şifresiz sudo izni verir
set -euo pipefail

UPDATE_SH="${UPDATE_SH:-/root/update.sh}"
WEB_USER="${WEB_USER:-www-data}"

if [ ! -f "$UPDATE_SH" ]; then
  echo "Güncelleme betiği bulunamadı: $UPDATE_SH (UPDATE_SH=... ile yolu verin)"; exit 1
fi

cat > /usr/local/bin/navluniq-update <<WRAP
#!/usr/bin/env bash
# Panelden tetiklenen güncelleme: $UPDATE_SH'yi root olarak çalıştırır.
set -o pipefail
echo "[navluniq-update] \$(date '+%d.%m.%Y %H:%M:%S') update.sh başlıyor"
bash "$UPDATE_SH"
WRAP
chmod 755 /usr/local/bin/navluniq-update

cat > /etc/sudoers.d/navluniq-update <<SUDO
$WEB_USER ALL=(root) NOPASSWD: /usr/local/bin/navluniq-update
SUDO
chmod 440 /etc/sudoers.d/navluniq-update
visudo -cf /etc/sudoers.d/navluniq-update >/dev/null

touch /var/www/navluniq/storage/logs/update.log 2>/dev/null || true
chown "$WEB_USER":"$WEB_USER" /var/www/navluniq/storage/logs/update.log 2>/dev/null || true

echo "Tamam. Panel → Sistem Sağlığı → \"Siteyi güncelle\" artık çalışır."
echo "Deneme (www-data olarak): sudo -u $WEB_USER sudo -n /usr/local/bin/navluniq-update"
