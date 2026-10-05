# Okuma katmanları (ilan hattı)

Gruptan gelen her mesaj sırayla "okuma katmanlarından" geçer. Her katman ya mesajı eler, ya ilanlara ayırıp rotayı kesin
kuralla çözer, ya da kesin kural bulunamayınca makul bir yorum yapar. Katmanlar `App\Support\IntakeLayers::LAYERS` tablosunda
tanımlıdır; sıra kodda sabittir.

**Katmanlar elle açılıp kapanmaz.** (Osman, 2026-10-05: "açıp kapatmak bizim elimizde olmasın; aşama aşama, kendini izleyen bir
sistem".) Her katmanın bir aşaması vardır ve aşamayı sistem yönetir (`App\Services\IntakeLayerReview`, saatlik `intake-layers:review`):

| Aşama | Ne demek |
|---|---|
| kalıcı | Kanıtlanmış eleme/çözüm katmanları; her zaman etkin, aşaması yok |
| gölge | Katman çalışır, ne yapacağını `intake_layer_samples` tablosuna yazar ve yapay zeka hakemine sorar; ilanı **etkilemez** |
| etkin | Katman ilanı etkiler; yayın sonrası sonuçlar (şoför "Aradım", yapay zeka doğrulaması, yönetici düzeltme/ret) izlenir |
| duraklatıldı | Etkinken hata oranı eşiği aştı; gölgeye döndü. Taze gölge kanıtıyla yeniden etkinleşir |

Eşikler (`IntakeLayerReview`): gölgeden etkine geçiş için en az 30 hakemli örnek ve ≥ %85 uyum; etkinden duraklatmaya geçiş için son
7 günde en az 20 sonuç ve ≥ %30 düzeltme/ret oranı. Yalnız son aşama değişiminden sonraki örnekler sayılır. Hakem saatte en çok 30
örnek alır (kota). Her aşama değişimi etkinlik günlüğüne, Telegram'a (`alert_telegram_chat_id`) ve yönetici bildirimine düşer.
Durum: Dış Kaynak İlanları → hat karnesi → "Okuma katmanları (7 gün)" (aşama, aday/elenen, gölge uyumu, sonuç dağılımı).

Yeni yorum katmanı **her zaman gölgede başlar** (`IntakeLayers::STAGE_DEFAULTS`); sonuçla izlenen (hakemi olmayan) katmanlar etkin
başlayabilir ama aynı duraklatma kuralına bağlıdır.

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
katmana eşlenir (`IntakeLayers::layerForReason`). Dış kaynak hat karnesi son 7 günün sayısını gösterir.

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
- Hat karnesi katman satırları: gölge uyumu ve yayın sonrası sonuç; oran düşerse sistem katmanı kendiliğinden gölgeye alır.
- Altın set (`resources/data/altin-set.json`) iki yönü de sabitler: iki satırlık ilan çözülür, üç satırlık liste `no_pickup`, fiilli
  ilanlar aynen. Testler: `IntakeLayersTest`, `PendingDecisionsTest`, `LocationAuditTest`.

## Yeni katman eklerken

1. `IntakeLayers::LAYERS`'a satır (anahtar, tür, `lifecycle` = managed, `judge` = ai_route | outcome, etiket, açıklama, gerekçeler).
2. `IntakeLayers::STAGE_DEFAULTS` ve `Settings::DEFAULTS`'a `intake_layer_stage_<anahtar> => 'shadow'`.
3. Kodda `IntakeLayers::enabled('<anahtar>')` kapısı; gölgede `IntakeLayerReview::recordShadow(...)` ile örnek; açılan parçaya
   `'layer' => '<anahtar>'`.
4. Test + altın set örneği. Hat karnesi satırı ve aşama yönetimi kendiliğinden gelir.
