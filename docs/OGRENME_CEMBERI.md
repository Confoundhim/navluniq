# Öğrenme Çemberi (ücretsiz yapay zeka → kurallarımız)

Amaç: ücretsiz yapay zeka ilanları çözdükçe kurallarımız gelişsin, yapay zekaya gitmesi gereken ilan payı zamanla düşsün;
ama hiçbir şey kontrol dışı öğrenilmesin.

## Akış

1. **Kural önce çalışır** (`LoadIntakeService` → `AiParserService::parseCheap`, `LoadStandardizer`): il/ilçe kataloğu, sözlük, yük
   kataloğu, kasa/araç sözcükleri. Kural yeterse yapay zeka çağrılmaz.
2. **Yapay zeka gerekirse** ("Kural eksik bırakınca" ya da "Her ilanda" kipi) cevap ilana işlenir ve `RuleFeedbackService::fromAi`
   kuralla karşılaştırır:
   - kuralın çözemediği ya da farklı ile götürdüğü yer yazımı → **konum önerisi** (`busan` → Konya; `kahraman maras` → Kahramanmaraş);
   - kuralın bulamadığı yük sözcüğü (yapay zekanın mesajdan aldığı yazım) → **yük önerisi** (`pekmez` → Gıda).
   Öneri `ai_lexicon` tablosunda `status=suggested`, `source=ai` olarak durur; kaç ayrı ilanda görüldüğü (`hits`) ve kısa bir
   açıklama (`note`) ile. Sözlüğe kendiliğinden **girmez**.
3. **Onay**: Dış Kaynak İlanları → Sözlük ve öğrenme → Öneriler. **Onayla** sözlüğe alır (`status=active`); o andan sonra aynı yazım
   yapay zeka çağrılmadan kuralla çözülür. **Yok say** (`status=ignored`) bir daha önermez. Eşik ayarı
   (Ayarlar → Dış kaynak → "Öneri kendiliğinden onaylansın", varsayılan 0 = kapalı): aynı öneri bu kadar ayrı ilanda görülürse
   kendiliğinden onaylanır. Yapay zeka aynı yazıma bir başka karşılık verirse sayaç sıfırlanır; çelişkili öneri kendiliğinden onaylanmaz.
4. **Denetim** (`scraped-loads:ai-audit`, her gün 05:20; ayar "Günlük denetim", varsayılan 5): kuralla çözülmüş ilanlardan rastgele
   örneklem yapay zekaya sorulur. İlan değişmez; sonuç `parse_metadata.audit` alanına yazılır (uyuştu / fark listesi). Uyuşmazlık
   yine öneri olur. Kota bitince o gün durur.
5. **Sayaçlar**: Sistem sağlığı → "Öğrenme çemberi": bu hafta kuralla çözülen, yapay zeka gereken, denetlenen (uyuşmazlık), bekleyen
   öneri, kendiliğinden onaylanan. Hedef: yapay zeka payı düşer, bekleyen öneri sıfıra iner.

## Güvenlik kuralları

- Gündelik sözcükler (`TurkishCities::STOP_WORDS`, `AiParserService::PLACE_NOISE`), sayılar, 3 sözcükten uzun ifadeler önerilmez.
- Aktif bir sözlük girdisi (yönetici ya da öğrenilmiş) olan yazım için öneri açılmaz; yöneticinin kararı geçerlidir.
- Aynı ilan sayacı iki kez artırmaz (`last_load_id`).
- Yönetici düzenlemiş ilanlar denetlenmez; yapay zekanın baktığı ilanlar denetlenmez.
- Testler: `tests/Feature/Services/RuleFeedbackTest.php`.

## Öğrenmenin kuralı ezmesine karşı korumalar (2026-10-01 denetimi)

- **Konum sözlüğü** yalnız yöneticinin tek tek verdiği karardan öğrenir (toplu "Yayınla" öğrenmez). Mesajdaki bağlaç çiftlerinden
  rotaya ait olanı seçer (`LearningService::routePairFor`: bir ucu kesin rotayla örtüşen çift); "ÇOK ACİL - KONYA - MERSİN" başlığındaki
  "çok" → "acil" gibi çiftler ve gündelik/firma/hitap sözcükleri (`isNoiseTerm`) asla takma ad olmaz. Katalogda bilinen ad hiç öğrenilmez.
- **Yapay zeka önerileri** aynı çift seçimini kullanır; mesajda birden çok ilan varsa konum önerisi yapılmaz; yük önerisi 4+ harf ve
  genel ad olmayan sözcük için düşer ("yük", "mal", "palet" olmaz).
- **Şablon hafızası** (`TemplateMemory`): kalıpta yer adı yalnız birebir yazımla ("sinan" Sincan olmaz), il + ilçe tek yuva
  ("ankara sincan"). Kalıp kuralın çözdüğü ucu ezmez, yalnız çözülemeyen ucu doldurur (kural iki ucu aynı ile düşürmüşse ve kalıp iki
  farklı il veriyorsa kalıp kazanır). Yer geçen kalıp "ilan değil" diye öğrenilmez. Yönetici onayıyla (güven 1.0) öğrenilmiş kalıp
  yapay zekanın daha düşük güvenli çözümüyle ezilmez. Yapay zeka yolunda öğrenilen kalıba araç yalnız kural kesin okuduysa yazılır.
- **Yerel sınıflandırıcı** yalnız güvenilir örnekten öğrenir: yönetici kararı ya da yapay zekanın çelişkisiz ≥0,8 güvenli çözümü;
  eksik bilgili ilan ve kural puanıyla kendiliğinden yayınlanan aday örnek değildir. "Geçmişten yeniden öğren" aynı süzgeci kullanır ve
  yapay zekanın "ilan değil" dediği metinleri almaz. Araç sözlüğü sözcüğü 8 puan (açık araç adı 10 puan onu yener).
- **Sözlük önbelleği** sürüm damgalıdır (`ai:lexicon:version`): panelden yapılan değişikliği uzun ömürlü kuyruk işçileri 30 sn içinde
  görür (`queue:restart` beklenmez). "İlan değil" / "ilan işareti" ifadeleri en az 4 harf.
- **Yeniden konumlama** (`relocateFromRaw`): seri ilan parçalarına dokunmaz; zorlamalı kipte yapay zeka çözümünün üstüne yalnız kural
  kesinse yazar (yer katalogda birebir, il adı metinde yazılı ya da ilçe adı tek ilde); eski değer `parse_metadata.relocated_from`'a yazılır.

