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
