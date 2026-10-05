#!/usr/bin/env bash
# Depo GitHub'da gizliye alındığında sunucunun kodu çekebilmesi için okuma yetkili "deploy key" kurar.
# Root ile bir kez çalıştırılır; "Siteyi güncelle" düğmesi ve deploy/update.sh sonrasında aynen çalışır.
#   bash /var/www/navluniq/deploy/github-erisim.sh          # anahtarı üretir, GitHub'a eklenecek satırı yazar, bağlantıyı dener
#   bash /var/www/navluniq/deploy/github-erisim.sh --kontrol # yalnız bağlantıyı dener
# GitHub tarafı: depo → Settings → Deploy keys → Add deploy key → başlık "navluniq sunucu", anahtar: aşağıda yazılan satır,
# "Allow write access" İŞARETLENMEZ (sunucu yalnız okur). Sonra bu betik tekrar --kontrol ile çalıştırılır.
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/navluniq}"
REPO="${REPO:-Confoundhim/navluniq}"
KEY="/root/.ssh/navluniq_deploy"
HOST_ALIAS="github.com-navluniq"
SSH_URL="git@${HOST_ALIAS}:${REPO}.git"

ok()   { printf '\033[1;32m✔ %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m! %s\033[0m\n' "$*"; }

check() {
    if GIT_SSH_COMMAND="ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new" git ls-remote --heads "$SSH_URL" main >/dev/null 2>&1; then
        ok "GitHub'a SSH ile erişiliyor ($SSH_URL)"
        return 0
    fi
    warn "GitHub'a henüz erişilemiyor. Deploy key GitHub'a eklendi mi? (depo → Settings → Deploy keys)"
    return 1
}

if [[ "${1:-}" == "--kontrol" ]]; then
    check
    exit $?
fi

mkdir -p /root/.ssh && chmod 700 /root/.ssh
if [[ ! -f "$KEY" ]]; then
    ssh-keygen -t ed25519 -N '' -C "navluniq-sunucu" -f "$KEY" >/dev/null
    ok "Anahtar üretildi: $KEY"
else
    ok "Anahtar zaten var: $KEY"
fi

# Yalnız bu depo için ayrı takma ad: root'un başka GitHub anahtarları varsa karışmaz.
if ! grep -q "Host $HOST_ALIAS" /root/.ssh/config 2>/dev/null; then
    cat >> /root/.ssh/config <<CONF

Host $HOST_ALIAS
    HostName github.com
    User git
    IdentityFile $KEY
    IdentitiesOnly yes
CONF
    chmod 600 /root/.ssh/config
    ok "SSH ayarı yazıldı (/root/.ssh/config)"
fi
ssh-keyscan -t ed25519 github.com 2>/dev/null >> /root/.ssh/known_hosts || true
sort -u /root/.ssh/known_hosts -o /root/.ssh/known_hosts 2>/dev/null || true

if [[ -d "$APP_DIR/.git" ]]; then
    git -C "$APP_DIR" remote set-url origin "$SSH_URL"
    ok "Depo adresi SSH'a çevrildi: $SSH_URL"
fi

echo
echo "GitHub'a eklenecek deploy key (tek satır, olduğu gibi kopyalayın):"
echo "------------------------------------------------------------------"
cat "${KEY}.pub"
echo "------------------------------------------------------------------"
echo "GitHub → depo → Settings → Deploy keys → Add deploy key → yapıştır → kaydet (yazma izni verme)."
echo "Sonra: bash $APP_DIR/deploy/github-erisim.sh --kontrol"
echo
check || true
