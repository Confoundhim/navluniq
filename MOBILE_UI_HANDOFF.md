# NavlunIQ mobil ve görsel kalite geçişi

## Uygulanan düzenlemeler
- Anasayfa, genel sayfalar, kayıt/giriş ekranları ile admin, yük sahibi ve şoför panellerindeki toplam 78 Blade görünümü kaynak düzeyinde tarandı.
- Mobilde sabit kalan iki ve üç kolonlu formlar tek kolon başlangıcına çevrildi; geniş ekran kırılımları korundu.
- Yönetim tabloları küçük ekranlarda sayfayı taşırmak yerine dokunmatik yatay kaydırma kullanır.
- Modal katmanları kısa ekranlarda üstten hizalanır, güvenli iç boşluk kullanır ve dikey kaydırılabilir.
- Panel ana içerik kabuklarında yatay taşma ve minimum genişlik zinciri düzeltildi.
- Küçük ekranlarda form alanlarının otomatik yakınlaştırma yapmaması için 16px giriş boyutu uygulandı.
- Başlık, bildirim ve aksiyon satırlarında mobil sarma ve tutarlı boşluk eklendi.
- Admin ekranlarındaki eski iç doküman numaraları olan köşeli parantezli 170 referans temizlendi.
- 320px alt sınır, esnek medya ve güvenli maksimum genişlik kuralları eklendi.

## Kabul notu
Kaynak ve üretim derleme kontrolleri paketteki `scripts/validate-production.ps1` ile yapılır. Gerçek iOS/Android WebView; klavye açılması, kamera/dosya seçimi, güvenli alanlar ve arka plan konum izinleri için fiziksel cihaz kabul testi ayrıca zorunludur.
