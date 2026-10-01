#!/usr/bin/env bash
# NavlunIQ Toplayıcı APK derlemesi. Android SDK gerekmez: Ubuntu paketleri (aapt, dalvik-exchange, zipalign, apksigner) +
# android.jar (API 34). Çıktı: public/toplayici/navluniq-toplayici.apk ve version.json. Tekrar çalıştırmak güvenli.
#   bash android/build.sh            # derle, imzala, public/toplayici/ altına koy
# Gerekenler: apt-get install -y aapt zipalign apksigner dalvik-exchange ; JDK 17+ (javac). android.jar:
#   curl -L -o android/.tools/android.jar https://raw.githubusercontent.com/Sable/android-platforms/master/android-34/android.jar
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$ROOT/android/toplayici"
TOOLS="$ROOT/android/.tools"
OUT="$ROOT/android/.build"
APK_DIR="$ROOT/public/toplayici"
KEYSTORE="$ROOT/android/keystore/toplayici.jks"
# Yan yükleme imzası: güncellemenin aynı uygulamanın üstüne kurulabilmesi için anahtar deposu depoda durur (gizli sayılmaz,
# Play Store'da yayın yoktur). Değişirse telefonlardaki uygulama silinip yeniden kurulmak zorunda kalır.
KS_PASS="navluniq-toplayici"
ANDROID_JAR="$TOOLS/android.jar"

for bin in aapt zipalign apksigner dalvik-exchange javac; do
  command -v "$bin" >/dev/null || { echo "eksik araç: $bin (apt-get install -y aapt zipalign apksigner dalvik-exchange)"; exit 1; }
done
mkdir -p "$TOOLS" "$APK_DIR"
if [ ! -f "$ANDROID_JAR" ]; then
  echo "android.jar indiriliyor…"
  curl -fsSL -o "$ANDROID_JAR" https://raw.githubusercontent.com/Sable/android-platforms/master/android-34/android.jar
fi
if [ ! -f "$KEYSTORE" ]; then
  mkdir -p "$(dirname "$KEYSTORE")"
  keytool -genkeypair -v -keystore "$KEYSTORE" -storepass "$KS_PASS" -keypass "$KS_PASS" -alias toplayici -keyalg RSA -keysize 2048 -validity 10950 \
    -dname "CN=NavlunIQ Toplayici, O=NavlunIQ, C=TR" >/dev/null
fi

rm -rf "$OUT"; mkdir -p "$OUT/classes" "$OUT/gen"
python3 "$SRC/make_icon.py" "$SRC/res/drawable/ic_launcher.png"

echo "1/5 kaynaklar (aapt)…"
aapt package -f -m -M "$SRC/AndroidManifest.xml" -S "$SRC/res" -I "$ANDROID_JAR" -J "$OUT/gen" -F "$OUT/res.apk"

echo "2/5 java → class…"
find "$SRC/src" "$OUT/gen" -name '*.java' > "$OUT/sources.txt"
javac -source 8 -target 8 -Xlint:-options -encoding UTF-8 -bootclasspath "$ANDROID_JAR" -classpath "$ANDROID_JAR" -d "$OUT/classes" @"$OUT/sources.txt"

echo "3/5 class → dex…"
dalvik-exchange --dex --min-sdk-version=26 --output="$OUT/classes.dex" "$OUT/classes"

echo "4/5 paketleme…"
cp "$OUT/res.apk" "$OUT/unsigned.apk"
( cd "$OUT" && zip -q -j unsigned.apk classes.dex )
# resources.arsc sıkıştırılmamış ve 4 bayt hizalı olmalı (targetSdk 30+); aapt zaten sıkıştırmaz, zipalign hizalar.
zipalign -f -p 4 "$OUT/unsigned.apk" "$OUT/aligned.apk"
zipalign -c -p 4 "$OUT/aligned.apk"

echo "5/5 imza…"
apksigner sign --ks "$KEYSTORE" --ks-pass "pass:$KS_PASS" --key-pass "pass:$KS_PASS" --ks-key-alias toplayici --min-sdk-version 26 \
  --out "$OUT/signed.apk" "$OUT/aligned.apk"
apksigner verify --min-sdk-version 26 "$OUT/signed.apk"

cp "$OUT/signed.apk" "$APK_DIR/navluniq-toplayici.apk"
VERSION_CODE=$(aapt dump badging "$OUT/signed.apk" | sed -n "s/.*versionCode='\([0-9]*\)'.*/\1/p")
VERSION_NAME=$(aapt dump badging "$OUT/signed.apk" | sed -n "s/.*versionName='\([^']*\)'.*/\1/p")
SIZE=$(stat -c %s "$APK_DIR/navluniq-toplayici.apk")
printf '{"versionCode": %s, "versionName": "%s", "file": "navluniq-toplayici.apk", "bytes": %s, "builtAt": "%s"}\n' \
  "$VERSION_CODE" "$VERSION_NAME" "$SIZE" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$APK_DIR/version.json"
echo "tamam: $APK_DIR/navluniq-toplayici.apk (v$VERSION_NAME, kod $VERSION_CODE, $SIZE bayt)"
