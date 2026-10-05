# Okuma katmanları (ilan hattı)

Gruptan gelen her mesaj sırayla "okuma katmanlarından" geçer. Her katman ya mesajı eler, ya ilanlara ayırıp rotayı kesin
kuralla çözer, ya da kesin kural bulunamayınca makul bir yorum yapar. Katmanlar `App\Support\IntakeLayers::LAYERS` tablosunda
tanımlıdır; **Ayarlar → Dış kaynak ve Telegram → Okuma katmanları** bölümünden tek tek açılıp kapanır (ayar anahtarı
`intake_layer_<katman>`, varsayılan açık). Kapanan katman yokmuş gibi davranılır ve mesaj sonraki katmana iner; hiçbir katman
silinmez, hiçbir katman bir diğerinin kuralını ezmez.

## Sıra ve türler

| Sıra | Katman | Tür | Ne yapar |
|---|---|---|---|
| 1 | Yabancı alfabe | eleme | Kiril/Arap alfabesiyle yazılmış mesaj elenir |
| 2 | Sözlük "ilan değil" | eleme | Yöneticinin öğrettiği ifadeler |
| 3 | Sabit "ilan değil" kalıpları | eleme | Boş araç arayan nakliyeci, şoför/eleman ilanı, fatura reklamı, satılık/kiralık. "satılık değil" gibi olumsuzlama elemez; muhasebe sözcüğü ("e-fatura kesilir") rota taşıyan ilanda yalnız nottur |
| 4 | Lojistik işaret ön elemesi | eleme | Telefon var ama rota/tonaj/fiyat/araç/yük sözcüğü yoksa sohbet |
| 5 | Seri ilan | çözüm | "X yükler" + boşaltma satırları, "il / ilçe" listesi, "A = B", "GEBZE+TUZLA yükler" |
| 6 | Virgüllü varış listesi | çözüm | "İstanbul çıkışlı: Ankara, İzmir, Bursa" |
| 7 | Gidiş-dönüş | çözüm | "Ankara-İstanbul / İstanbul-Ankara" |
| 8 | **İki satırlık ilan yorumu** | yorum | Fiilsiz tam iki "yer + araç" satırı: ilk yer kalkış, ikinci yer varış |
| 9 | Kalkışsız varış listesi elemesi | eleme | Üç ve daha çok fiilsiz "yer + araç" satırı: satırlar rota diye bağlanmaz |
| 10 | **Gönderen hafızasından kalkış** | yorum | 9'a takılan listede gönderenin bilinen kalkışı kullanılır; bilinmiyorsa aday "Kalkış öğret" ile bekler |
| 11 | Genel okuma | çekirdek | Boş satır / rota satırı sınırları, ortak numara, ortak bağlam (kapatılamaz) |
| 12 | Şablon hafızası | çözüm | Aynı numaranın doğrulanmış kalıbı yapay zekasız çözülür; "ilan değil" kalıbı eler |
| 13 | Kesin kural kısayolu | çözüm | İki il + telefon + açık araç adı → yapay zeka beklenmez |
| 14 | Yapay zeka "ilan değil" | eleme | %80+ güvenle "yük ilanı değil" → elenir, gönderenin kalıbı öğrenilir |

Her açılan aday `parse_metadata.layer` ile hangi katmanın çözdüğünü taşır; canlı akıştaki eleme gerekçesi (`IntakeEvent.reason`)
katmana eşlenir (`IntakeLayers::layerForReason`). Ayarlar ekranı ve Dış kaynak hat karnesi son 7 günün sayısını gösterir.

## Yorum katmanları nasıl yayınlanır

- Yorumla çözülen rota (`parse_metadata.route_inferred` = `two_line` ya da `sender_memory`) **tam ilan sayılmaz**. Yapay zeka iki ucu
  da verdiyse onun rotası geçerlidir ve `route_confirmed` yazılır → normal yayın. Vermediyse (kapalı, kota, kuşkulu) aday
  "bilgi eksik · arayıp sorun" rozetiyle yayınlanır (`ScrapedLoadService::INFERRED_ROUTE_BLOCKER`, `incompleteEligible`); şoför
  "Aradım, araç:" ile ya da yönetici düzenleyerek tamamlar. Puan ret sınırının altındaysa olağan ret.
- Gönderen hafızası (`App\Support\SenderPickupMemory`, tablo `sender_pickups`): önce yöneticinin öğrettiği kalkış, yoksa son 30 günde
  aynı numaradan yayınlanan ilanların kalkışı (en az 3 ilan, %80 aynı il; ilçe tekse ilçe). Numara düz saklanmaz (SHA-256).
- Kalkış bekleyen aday: `status=parsed_partial`, `parse_metadata.needs_pickup`, `dest_lines`. Yönetici kuyrukta "Kalkış öğret" ile
  "İl İlçe" yazar → hafızaya girer, mesaj yeniden okunur, her satır ayrı "bilgi eksik" ilan olur; aynı gönderenin sonraki listeleri
  kendiliğinden ayrılır. Öğretilmezse 48 saatte olağan yaş retine düşer.

## Güvenlik kemerleri

- Yorum katmanları yalnız üstteki hiçbir katmanın sahiplenmediği (eskiden çöpe giden) mesajlara bakar; yayınlanan hiçbir ilanı
  değiştiremez.
- Hat karnesi "Yorumla çözülen rota: toplam / yayında / doğrulanan / reddedilen" satırı: doğrulanma oranı düşerse katman kapatılır.
- Altın set (`resources/data/altin-set.json`) iki yönü de sabitler: iki satırlık ilan çözülür, üç satırlık liste `no_pickup`, fiilli
  ilanlar aynen. Testler: `IntakeLayersTest`, `PendingDecisionsTest`, `LocationAuditTest`.

## Yeni katman eklerken

1. `IntakeLayers::LAYERS`'a satır (anahtar, tür, etiket, açıklama, gerekçe anahtarları).
2. `Settings::DEFAULTS`'a `intake_layer_<anahtar> => 1`.
3. Kodda `IntakeLayers::enabled('<anahtar>')` kapısı; açılan parçaya `'layer' => '<anahtar>'`.
4. Test + altın set örneği. Panel listesi ve sayımlar kendiliğinden gelir.
