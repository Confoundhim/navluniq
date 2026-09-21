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

# Sarmalayıcı önce geçici dosyaya yazılıp yerine taşınır: çalışan bir güncelleme betiği yarım okunmaz.
WRAP_TMP="$(mktemp /tmp/navluniq-update.XXXXXX)"
cat > "$WRAP_TMP" <<WRAP
#!/usr/bin/env bash
# Panelden tetiklenen güncelleme: $UPDATE_SH'yi root olarak çalıştırır.
set -o pipefail
# Süreç kendi systemd kapsamına (scope) alınır: update.sh içindeki PHP-FPM yeniden başlatması
# panelden başlayan bu süreci öldüremez. systemd yoksa doğrudan devam eder.
if [ -z "\${NAVLUNIQ_SCOPED:-}" ] && command -v systemd-run >/dev/null 2>&1 \\
   && systemd-run --scope --quiet --unit "navluniq-update-test-\$\$" true >/dev/null 2>&1; then
    export NAVLUNIQ_SCOPED=1
    exec systemd-run --scope --quiet --unit "navluniq-update-\$(date +%s)" bash "\$0" "\$@"
fi
echo "[navluniq-update] \$(date '+%d.%m.%Y %H:%M:%S') update.sh başlıyor"
bash "$UPDATE_SH"
WRAP
chmod 755 "$WRAP_TMP"
mv -f "$WRAP_TMP" /usr/local/bin/navluniq-update

cat > /etc/sudoers.d/navluniq-update <<SUDO
$WEB_USER ALL=(root) NOPASSWD: /usr/local/bin/navluniq-update
SUDO
chmod 440 /etc/sudoers.d/navluniq-update
visudo -cf /etc/sudoers.d/navluniq-update >/dev/null

touch /var/www/navluniq/storage/logs/update.log 2>/dev/null || true
chown "$WEB_USER":"$WEB_USER" /var/www/navluniq/storage/logs/update.log 2>/dev/null || true

echo "Tamam. Panel → Sistem Sağlığı → \"Siteyi güncelle\" artık çalışır."
echo "Deneme (www-data olarak): sudo -u $WEB_USER sudo -n /usr/local/bin/navluniq-update"
