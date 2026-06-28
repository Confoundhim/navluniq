#!/bin/bash
# ============================================================
# NavlunIQ - Tek Tıkla Kurulum Scripti
# Sunucu: Ubuntu 24.04 LTS
# Domain: navluniq.com
# ============================================================

set -e

echo "========================================"
echo "  NavlunIQ Kurulumu Basliyor..."
echo "  Bu islem 10-15 dk surebilir."
echo "========================================"

# Renkli cikti
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

log() { echo -e "${GREEN}[OK]${NC} $1"; }
warn() { echo -e "${YELLOW}[INFO]${NC} $1"; }
error() { echo -e "${RED}[HATA]${NC} $1"; }

# ============================================================
# 1. Git Kurulumu
# ============================================================
warn "Adim 1/10: Git kuruluyor..."
apt update -qq
apt install -y -qq git curl wget
log "Git kuruldu"

# ============================================================
# 2. Node.js 20 Kurulumu (NVM ile)
# ============================================================
warn "Adim 2/10: Node.js 20 kuruluyor..."
if ! command -v node &> /dev/null || [ "$(node -v | cut -d'v' -f2 | cut -d'.' -f1)" != "20" ]; then
    curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.0/install.sh | bash
    export NVM_DIR="$HOME/.nvm"
    [ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"
    nvm install 20
    nvm use 20
    # Kalici yap
    echo 'export NVM_DIR="$HOME/.nvm"' >> ~/.bashrc
    echo '[ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"' >> ~/.bashrc
fi
log "Node.js $(node -v) kuruldu"

# ============================================================
# 3. PM2 Kurulumu
# ============================================================
warn "Adim 3/10: PM2 kuruluyor..."
npm install -g pm2
log "PM2 kuruldu"

# ============================================================
# 4. Nginx Kurulumu
# ============================================================
warn "Adim 4/10: Nginx kuruluyor..."
apt install -y -qq nginx
log "Nginx kuruldu"

# ============================================================
# 5. MySQL Kurulumu ve Yapilandirma
# ============================================================
warn "Adim 5/10: MySQL kuruluyor..."
apt install -y -qq mysql-server
systemctl start mysql
systemctl enable mysql

warn "MySQL veritabani olusturuluyor..."
mysql -u root << 'MYSQL_SCRIPT'
CREATE DATABASE IF NOT EXISTS navluniq CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'navlun_user'@'localhost' IDENTIFIED BY 'NavlunDB2024!';
GRANT ALL PRIVILEGES ON navluniq.* TO 'navlun_user'@'localhost';
FLUSH PRIVILEGES;
MYSQL_SCRIPT
log "MySQL ve veritabani hazir"

# ============================================================
# 6. Proje Dosyalarini Cek
# ============================================================
warn "Adim 6/10: NavlunIQ projesi indiriliyor..."
mkdir -p /var/www
cd /var/www

# Eski varsa sil
if [ -d "/var/www/navluniq" ]; then
    rm -rf /var/www/navluniq
fi

git clone https://github.com/Confoundhim/navluniq.git
log "Proje indirildi"

# ============================================================
# 7. Ortam Degiskenleri
# ============================================================
warn "Adim 7/10: Ortam degiskenleri ayarlaniyor..."
cat > /var/www/navluniq/.env << 'EOF'
DATABASE_URL=mysql://navlun_user:NavlunDB2024!@localhost:3306/navluniq
NODE_ENV=production
PORT=3000
JWT_SECRET=navluniq-super-gizli-anahtar-2024
EOF
log ".env dosyasi olusturuldu"

# ============================================================
# 8. Bagimliliklar ve Build
# ============================================================
warn "Adim 8/10: Bagimliliklar yukleniyor..."
cd /var/www/navluniq
npm install --silent
log "Bagimliliklar yuklendi"

warn "Adim 9/10: Veritabani senkronize ediliyor..."
export NVM_DIR="$HOME/.nvm"
[ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"
npx drizzle-kit push --config=drizzle.config.ts --force 2>/dev/null || npm run db:push -- --force 2>/dev/null || true
log "Veritabani senkronize edildi"

warn "Build aliniyor..."
npm run build
log "Build tamamlandi"

# ============================================================
# 9. PM2 ile Baslat
# ============================================================
warn "Adim 10/10: Uygulama baslatiliyor..."
pm2 delete navluniq 2>/dev/null || true
pm2 start dist/boot.js --name "navluniq"
pm2 save

# Startup scripti
echo -e "${YELLOW}PM2 startup ayarlaniyor...${NC}"
STARTUP_CMD=$(pm2 startup systemd | grep "sudo systemctl" | tail -1)
if [ -n "$STARTUP_CMD" ]; then
    eval "$STARTUP_CMD"
fi
log "PM2 startup ayarlandi"

# ============================================================
# 10. Nginx Yapilandirma
# ============================================================
warn "Nginx yapilandiriliyor..."
cat > /etc/nginx/sites-available/navluniq << 'EOF'
server {
    listen 80;
    server_name navluniq.com www.navluniq.com 45.8.196.168;

    location / {
        proxy_pass http://localhost:3000;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection 'upgrade';
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_cache_bypass $http_upgrade;
    }

    location /api {
        proxy_pass http://localhost:3000/api;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }
}
EOF

ln -sf /etc/nginx/sites-available/navluniq /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl restart nginx
log "Nginx yapilandirildi"

# ============================================================
# 11. Firewall
# ============================================================
warn "Firewall ayarlaniyor..."
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable
log "Firewall aktif"

# ============================================================
# Bitti!
# ============================================================
echo ""
echo "========================================"
echo -e "  ${GREEN}NavlunIQ Kurulumu Tamamlandi!${NC}"
echo "========================================"
echo ""
echo "  URL: http://45.8.196.168"
echo "  Domain: http://navluniq.com"
echo "  Admin: http://45.8.196.168/#/admin"
echo ""
echo "  Sorun olursa: pm2 logs"
echo "  Yeniden baslat: pm2 restart navluniq"
echo "========================================"
