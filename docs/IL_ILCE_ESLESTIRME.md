# İl / İlçe Eşleştirme Kuralları ve Yazım Biçimleri

Sitede il ve ilçe tek bir kaynaktan gelir: `resources/data/tr-locations.json` (81 il, 973 ilçe, resmî listeyle
karşılaştırılmış; il başına ilçe sayısı testle sabitlenir). Gruplardan gelen ilanlar, yük sahibinin yazdığı adresler,
şoför filtreleri ve sefer/dönüş yükü hesapları hep bu tabloyu kullanır (`App\Support\TurkishLocations`,
`App\Support\TurkishCities`). Aşağıda hangi yazımların nasıl çözüldüğü ve nelerin bilerek çözülmediği listelenir.
Her satır testle korunur: `tests/Unit/LocationSpellingTest.php`.

## Çözülen yazım biçimleri

| Biçim | Örnekler | Nasıl çözülür |
|---|---|---|
| Birebir ad | Ankara, İzmir Aliağa | Tablodan |
| Türkçe karaktersiz / küçük harf | sanliurfa viransehir, canakkale gokceada | ç→c, ş→s, ğ→g, ı→i, ö→o, ü→u sadeleştirmesi |
| Ekli yazım | İzmirden, Aliağaya, Ankara'dan, Gebzeden, Mecitözünden, Tekkeköyünden | -dan/-den/-ndan/-nden/-a/-e/-ya/-ye ekleri atılır |
| Noktalı il kısaltması | Ç.KALE, K.MARAŞ, Ş.URFA, D.BAKIR, G.ANTEP, K.KALE, T.DAĞ, B.KESİR, E.ŞEHİR, N.ŞEHİR, A.KARAHİSAR, K.MONU, G.HANE | "İlk harf + il adının sonu" kuralı; tek bir il uyarsa |
| Noktasız il kısaltması | GANTEP, KMARAŞ, KKALE, ZONG, İST, ANK | Takma ad listesi + aynı kural (son ek en az 4 harf) |
| Kısa / eski il adları | Afyon, Urfa, Antep, Maraş, İzmit, Adapazarı, Antakya | Takma ad listesi |
| Ayrık yazım | Kahraman Maraş, Gazi Antep, Kırık Kale, Şanlı Urfa, Afyon Karahisar | Ardışık 2-3 sözcük birleştirilip denenir |
| Yazım hatası | DİYARBAKR, istanbl, Eregli | 5-7 harfte 1, 8+ harfte 2 harf farkı (kısa adlarda yok) |
| İlçe kısaltması (il bilinirken) | Ş.KARAAĞAÇ, K.KARABEKİR, K.ÇEKMECE, B.ÇEKMECE, G.O.PAŞA, S.BEYLİ, M.KEMALPAŞA, Ç.KÖY, K.YAKA, K.HAMAM, Y.MAHALLE, Ş.KOÇHİSAR, B.PAZARI | Aynı ilin ilçelerinde "ilk harf + son ek", tek ilçe uyarsa |
| Ayrık ilçe adı | Mustafa Kemal Paşa, Sultan Beyli | 2-3 sözcük birleştirilir |
| Eski ilçe adı | Kazan → Kahramankazan, Eyüp → Eyüpsultan | Ek yer listesi (`TurkishLocations::EXTRA_PLACES`) |
| Semt / OSB / liman / sınır kapısı | Ostim, Gebze OSB, Hadımköy, Ambarlı, Cilvegözü, Habur, Kapıkule, Büyükada | Ek yer listesi; ilçe düzeyinde konum verir |
| Yöneticinin öğrettiği kısaltmalar | (panel → Sözlük → konum) | `Lexicon` konum sözlüğü, her şeyden önce bakılır |

## Bilerek çözülmeyenler (yanlış eşleşme olmasın diye)

- Gün ifadeleri yer sanılmaz: "Pazar günü", "Çarşamba sabahı", "Cuma akşamı" atılır; il ile yazılınca ("Rize Pazar", "Samsun Çarşamba") çözülür.
- Gündelik sözcükler il sanılmaz: kadar, burda, sonra, kamyon, tenteli, sanayi, liman, merkez, **kahraman** (tek başına), sultan, mustafa, kemal.
- Kısa il adlarında (Kars, Bolu, Van, Muş) yazım hatası toleransı yoktur; birebir yazılmalıdır.
- Kısaltma birden çok ile uyuyorsa çözülmez (örnek: "K.ELİ" Kırklareli'ne de Kocaeli'ne de uyar; açık yazılmalı).
- İlçe adı tek başına (il yazılmadan) yazıldıysa yalnız birebir ya da tek harf hatasıyla çözülür; aynı adı taşıyan ilçelerde
  (Ereğli: Konya / Zonguldak, Altınyayla: Burdur / Sivas, Kemer, Gönen, Aksu…) il yazılmamışsa **ilk bulunan il** alınır;
  bu yüzden ilanda il de geçiyorsa sistem ili önce okur ve ilçeyi o ilde arar.

## Yeni bir yazım görülünce

1. Sık görülüyorsa panelden **Sözlük → konum** ile öğretilir (kod değişmez).
2. Kalıcı olsun isteniyorsa `TurkishCities::ALIASES` (il) ya da `TurkishLocations::EXTRA_PLACES` (ilçe/semt) listesine eklenir ve
   `LocationSpellingTest` içine bir satır yazılır.
3. Resmî ilçe listesi değişirse (yeni ilçe): `tr-locations.json` güncellenir, `TurkishLocationsDataTest` içindeki il başına
   sayı tablosu düzeltilir, kayıtlı ilanlar için migration yazılır (örnek: `0001_01_30`).

## Eş adlı ilçeler, il-ilçe sırası ve yer olmayan sözcükler (2026-10-01 denetimi)

- **Eş adlı ilçe** (birden çok ilde aynı ad: Gölbaşı, Kemalpaşa, Ereğli, Pınarbaşı, Yenişehir, Pazar, Saray, Kale…) tek başına
  yazılınca `TurkishLocations::PREFERRED_DISTRICT_PROVINCE` tablosundaki il kazanır (nakliyede kastedilen: Ankara Gölbaşı,
  İzmir Kemalpaşa, Konya Ereğli, Kayseri Pınarbaşı). Haritada olmayan eş adlar il kodu sırasıyla ilk ile gider. Gerçek ilçe adı
  takma addan (semt/OSB) önce gelir: "Pınarbaşı" Kayseri ilçesidir, İzmir Bornova semti değil. `isAmbiguousDistrict()` belirsizliği söyler.
- **İl adı ilçeden sonra** yazılmışsa ("Gölbaşı Ankara", "Kemalpaşa/İzmir", "Ereğli Zonguldak") o il kazanır; önceki sözcük o ilin
  ilçesiyse ilçe olur, değilse ("Torbalı çimento Konya", "Kiraz Isparta") atılır. Yakın eşleme burada kullanılmaz.
- **Bitişik il-ilçe** ("ANKARA-SİNCAN - İZMİR", "Mersin-Tarsus, Kayseri", "İzmir/Kemalpaşa") rota değil tek yerdir
  (`AiParserService::unglueProvinceDistrict`); iki il ("Bursa-İstanbul") dokunulmaz.
- **Yazım hatası toleransı son çaredir:** il adında yalnız metinde iki kesin yer bulunamadıysa ("Hatası yok Konya Bursa" → Hatay değil),
  ilçe adında yalnız ilden hemen sonraki tek sözcükte ve 6+ harfte ("Bursa Gemlk"; "Bursa yemlik arpa" Gemlik değil); yük sözcükleri
  (saman, sebze, meyve) hiç eşlenmez. `normalizeLocation` yakın eşleme yapmaz ("Haftaya Ankara" Hatay olmaz).
- **Yer olmayan sözcükler:** gün ve ay adları (Perşembe, Aralık), "olur / orta / güney", bölge adları (Akdeniz, Marmara), yük adları
  olan ilçeler (Kiraz, Kavak, Maden, Torbalı, Mısır) tek başına yer sayılmaz (`PLACE_NOISE`, `GOODS_LIKE_DISTRICTS`); il ile yazılınca
  ("Ordu Perşembe", "İzmir Torbalı") çözülür. Ardından firma eki ("Kartal Nakliyat", "Demirci Lojistik") ya da hitap ("Can Bey") gelen
  sözcük yer değildir. Çok tesisli firma adları (Oyak, Limak, Tüpraş, Gübretaş) takma ad değildir; "Avrupa / Anadolu" tek başına
  İstanbul değildir; "Ada" Adana değildir; "Mısır" yurt dışı yer değildir.
- **Yön:** bağlaçlı rotada rol sözcüğü tarafı belirler ("İzmir teslim - Bursa yükleme" → Bursa → İzmir); satır fiille başlıyorsa
  yerler fiilden sonra gelir ("Teslim İzmir Yükleme Bursa"); hal ekleri yön verir ("İzmire gidecek Bursadan"; "-da/-de" vermez).
- **Kesin / zayıf yer:** `placesIn` il adı yazılmış yeri "strong" işaretler; metinde iki kesin yer varsa tek başına ilçe/semt
  sözcükleri çifte girmez. Sonuç etiketi her yerde `TurkishLocations::label()` ile yazılır ("Edirne Edirne Merkez" olmaz).
- Testler: `tests/Feature/Services/LocationAuditTest` (60+ örnek), altın sette `es-adli-ilce-*`, `bitisik-il-ilce`, `rol-sozcugu-yon` vb.

