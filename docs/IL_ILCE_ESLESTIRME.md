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
| Ekli yazım | İzmirden, Aliağaya, Ankara'dan, Gebzeden | -dan/-den/-a/-e/-ya/-ye ekleri atılır |
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
