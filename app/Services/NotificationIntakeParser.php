<?php

namespace App\Services;

use App\Support\GoodsCatalog;
use App\Support\Phone;
use App\Support\TurkishCities;
use App\Support\TurkishText;
use App\Support\VehicleClassifier;

/**
 * Android bildirim iletici (MacroDroid vb.) ile gelen WhatsApp bildirimini ilan mesajlarına ayırır.
 *
 * Grup bildirimlerinde başlık grup adı, metin "Gönderen: mesaj" satırlarıdır. Yeni Android
 * sürümlerinde WhatsApp göndereni ayrı alanda taşır ve metin yalnız mesajın kendisidir; bu durumda
 * metnin tamamı tek mesaj sayılır (gönderen varsa "Gönderen @ Grup: mesaj" biçimli ticker'dan alınır).
 * Özet bildirimleri ("12 mesaj 3 sohbet") atlanır; sohbet/ilan ayrımını ön filtre yapar.
 *
 * Facebook grup bildirimleri de aynı iletici ile gelir (uygulama adı "Facebook"): "Ad Soyad, Grup Adı grubunda
 * paylaştı: metin" ya da başlık grup adı, metin gönderi. Yorum / beğeni / arkadaşlık bildirimleri atlanır; gönderi
 * metni olmayan bildirim ("... grubunda paylaştı" kadar) atlanır. Facebook uzun gönderiyi "…" ile kısaltır; kalan
 * tekrar denetimi (aynı numara + rota) WhatsApp'ta da gelen aynı ilanı tek kayıtta birleştirir.
 */
final class NotificationIntakeParser
{
    /** Özet / sistem bildirimi: başlıkta ya da metinde ("12 mesaj 3 sohbet", "5 new messages"). */
    private const SUMMARY_PATTERNS = [
        '/^\s*\d+\s+(?:yeni\s+)?mesaj/iu',
        '/\d+\s+sohbet(?:ten)?\b/iu',
        '/^\s*\d+\s+new\s+messages?/iu',
        '/^\s*(?:whatsapp|whatsapp business)\s*$/iu',
        '/mesaj(?:lar)?ınız var/iu',
    ];

    /**
     * Yedekleme / arama bildirimleri: yalnız BAŞLIKTA ve tam sözcük olarak bakılır. Eski sürüm bu sözcükleri metinde de
     * sınırsız arıyordu; "fiyat görüşmeli", "yük arıyoruz", "görüşmek için arayın" yazan gerçek ilanlar özet sanılıp eleniyordu.
     */
    private const SYSTEM_TITLE_PATTERN = '/(?<!\p{L})(?:yedekleme|backup|kaçırılan|cevapsız)(?!\p{L})/iu';

    /** "arama / call / görüşme" tek başına sistem bildirimi sayılmaz: "Yük Arama Grubu" gerçek bir grup adıdır. Yalnız kısa başlık + kısa metinde. */
    private const SYSTEM_WEAK_TITLE_PATTERN = '/(?<!\p{L})(?:arama|call|görüşme)(?!\p{L})/iu';

    /** Kısa metinde (40 karakterden az) sistem bildirimi: "Yedekleme tamamlandı", "Cevapsız arama". "arama/görüşme" tek başına yetmez. */
    private const SYSTEM_SHORT_TEXT_PATTERN = '/(?<!\p{L})(?:yedekleme|backup|kaçırılan|cevapsız)(?!\p{L})/iu';

    /**
     * "Etiket: değer" biçimli ilan satırlarının etiket sözcükleri: bu sözcüklerden oluşan önek gönderen adı DEĞİLDİR
     * ("Kalkış: Ankara", "Yükleme yeri: Gebze", "İletişim: 0532…"). Eski sürüm 11 sözcük dışındaki her öneki gönderen sayıp
     * etiketli ilanı 3-4 parçaya bölüyor, parçaların hepsi eleniyordu.
     */
    private const LABEL_WORDS = ['kalkış', 'kalkis', 'varış', 'varis', 'çıkış', 'cikis', 'boşaltma', 'bosaltma', 'yükleme', 'yukleme', 'yeri', 'yer', 'noktası', 'noktasi', 'nokta',
        'irtibat', 'iletişim', 'iletisim', 'nereden', 'nereye', 'ödeme', 'odeme', 'tarih', 'tarihi', 'kasa', 'adres', 'fiyat', 'fiyatı', 'fiyati', 'tonaj', 'not', 'notlar',
        'araç', 'arac', 'yük', 'yuk', 'yükü', 'yuku', 'tel', 'telefon', 'rota', 'teslim', 'teslimat', 'güzergah', 'guzergah', 'güzergâh', 'navlun', 'ücret', 'ucret', 'miktar',
        'ağırlık', 'agirlik', 'saat', 'numara', 'gsm', 'cep', 'whatsapp', 'firma', 'cinsi', 'tipi', 'türü', 'turu', 'dorse', 'bilgi', 'detay', 'açıklama', 'aciklama',
        'malzeme', 'ürün', 'urun', 'mal', 'adet', 'palet', 'kg', 'ton', 'km', 'nakliye', 'taşıma', 'tasima', 'hedef', 'başlangıç', 'baslangic', 'bitiş', 'bitis', 'kişi', 'kisi',
        'isim', 'ad', 'yetkili', 'sahibi', 'ilan', 'ilanı', 'ilani', 'lazım', 'lazim', 'aranıyor', 'araniyor', 'acil', 'önemli', 'onemli', 'dikkat', 'zaman', 'gün', 'gun',
        'sevk', 'sevkiyat', 'tır', 'tir', 'kamyon', 'kamyonet', 'tenteli', 'frigo', 'damper', 'damperli', 'panelvan', 'kırkayak', 'kirkayak', 'uyarı', 'uyari', 'mesaj',
        'konum', 'il', 'ilçe', 'ilce', 'şehir', 'sehir', 'depo', 'fabrika', 'liman', 'alıcı', 'alici', 'gönderici', 'gonderici', 'müşteri', 'musteri', 've', 'ile'];

    /** Etiket sözcüklerinin kökleri: "Yükleme günü", "Boşaltma adresi", "Tel no", "Ödeme şekli", "Avrupa yakası" gibi ek almış biçimler de etikettir. */
    private const LABEL_STEMS = ['gun', 'adres', 'nokta', 'bilgi', 'saat', 'sekl', 'sekil', 'tarih', 'yer', 'irtibat', 'gsm', 'tel', 'yuk', 'bosalt', 'yukle', 'kalkis', 'varis',
        'teslim', 'fiyat', 'arac', 'odeme', 'yaka', 'avrupa', 'anadolu', 'var', 'aciklama', 'not', 'ton', 'navlun', 'ucret', 'guzergah', 'rota', 'kasa', 'dorse', 'malzeme', 'urun'];

    /** Facebook ekran dökümünde arayüz satırları (düğme, sayaç, zaman, rozet); gönderi metni değildir. */
    private const SCREEN_NOISE = [
        '/^(?:gruplar|groups|ana sayfa|home|ara|search|bildirimler|notifications|menü|menu|facebook|akış|feed|sizin için|for you|keşfet|discover|gönderi oluştur|create post|aklınızda ne var\??|what\'s on your mind\??|hikayeler?|reels|videolar|video|pazar yeri|marketplace|arkadaşlar|friends|profil|profile|gruplarınız|your groups|en son|latest|popüler|popular|tümünü gör|see all)$/iu',
        '/^(?:beğen|yorum yap|paylaş|gönder|devamını gör|daha az gör|daha fazla|katıl|takip et|takibi bırak|yönetici|moderatör|öne çıkan|yeni üye|grup uzmanı|en çok katkıda bulunan|yorum yaz|yorum yazın|tüm yorumları gör|diğer yorumları gör|en alakalı|çeviriyi gör|çevirisine bak|gönderiyi gör|gönderiyi görüntüle|görüntüle|yazın|like|comment|share|send|see more|see translation|join|follow|top contributor|admin|moderator)\b.{0,20}$/iu',
        '/^\d+\s*(?:yorum|paylaşım|görüntüleme|beğeni|kişi|comments?|shares?|views?|likes?)\s*$/iu', // sayaç satırı: birim sözcüğü şart ("24" tek başına tonaj olabilir)
        '/^(?=.*[\p{So}\p{Sk}\p{P}])[\p{So}\p{Sk}\p{P}\s\d]+$/u', // yalnız süs/noktalama (en az bir işaret; salt sayı satırı gövdeden düşmez)
        '/^(?:az önce|şimdi|just now|dün(?:\s.*)?|yesterday.*|\d+\s*(?:sn|dk|sa|g|hafta|ay|yıl|s|m|h|d|w)\b.*|\d{1,2}\s+(?:ocak|şubat|mart|nisan|mayıs|haziran|temmuz|ağustos|eylül|ekim|kasım|aralık)\b.*)$/iu',
        '/^(?:yorum(?:unuzu)? yaz(?:ın)?|bir yorum yaz(?:ın)?|write a comment)\W*$/iu',
    ];

    /** Yazar satırı: "Ad Soyad · 2 sa", "Ad Soyad · Dün 14:03" */
    private const SCREEN_AUTHOR = '/·\s*(?:az önce|şimdi|dün|yesterday|just now|\d+\s*(?:sn|dk|sa|g|hafta|s|m|h|d|w)\b|\d{1,2}\s+\p{L}+)/iu';

    /** Reklam ve önerilen gönderiler ilan değildir. */
    private const SCREEN_SKIP_BLOCK = '/^(?:sponsorlu|önerilen(?:\s+gönderi)?|önerilen gruplar|sponsored|suggested)\b/iu';

    /**
     * Erişilebilirlik dökümü (hazır makronun "Ekran içeriğini oku" çıktısı): "Paylaş" düğmesi okunmaz; her gönderi
     * "Ad•3s•Paylaşılanlar: Herkese açık grup" ve "Ad'in gönderisi için diğer seçenekler" başlık satırlarıyla gelir.
     */
    private const A11Y_ANCHOR = '/^(.+?)[\'’](?:in|ın|un|ün|nin|nın|nun|nün)\s+gönderisi için diğer seçenekler$/iu';

    private const A11Y_SHARED = '/•\s*Paylaşılanlar\s*:/iu';

    /** Grup sayfasının başlığı: "Grup Adı'da Ara" arama kutusu. */
    private const A11Y_GROUP_PAGE = '/^(.{2,120}?)[\'’](?:da|de|ta|te|nda|nde)\s+Ara$/iu';

    /** Başlık bölgesindeki arayüz satırları (yazar adı ayrıca elenir). */
    private const A11Y_HEADER_UI = '/^(?:takip et|katıl|gönderiyi gizle|grup kapak fotoğrafı|gönderiyi yönet|daha fazla bilgi edin|en alakalı|geri|tümünü gör|gönderiyi kaydet|ifade bırak|ara|kapat|beğen|yorum yap|yorum|yorumlar|paylaş|gönder|yanıtla|bildirimler|menü|profil|kaydet|kaydedildi|diğer|daha fazla|ana sayfa|reels|videolar|arkadaşlar|hikayeler?|çevirisini gör|tepki ver|yorum yaz|gruba katıl|gruplar|grup|sayfa|anonim üye|yönetici|moderatör|yeni üye|en çok katkıda bulunan|.*videosunu gizle(?:\s+\d+)?|.*gönderisini gizle|.*fotoğrafı|.*kapak fotoğrafı)$|(?:profil resmi|hikayesini aç.*|hikayesi, görmedin|grubuna git|sayfasına git)$/iu';

    /** Grup adı sayılabilecek satır: nakliye/grup sözcüğü taşıyan ya da işaretli ("•Katıl", kapak fotoğrafı sonrası) satır. */
    private const GROUP_HINT_WORDS = '/(?<!\p{L})(?:yük|yuk|nakliye|nakliyat|nakliyeci|lojistik|tır|tir|kamyon|kamyonet|kamyoncu|tırcı|tirci|şoför|sofor|sürücü|dorse|treyler|frigo|kargo|taşıma|tasima|taşımacı|sefer|navlun|araç|arac|ilan|grup|grubu|gruplar|platform|topluluk|dernek|derneği|birlik|birliği|kulüp|kulübü|club|forum|pazar|pazarı|borsa|borsası|türkiye|turkiye|anadolu|marmara|ege|akdeniz|karadeniz)(?!\p{L})/iu';

    /** Gövdeye girmeyen ek satırlar: fotoğraf/video/bağlantı kutuları, "diğer" düğmesi, sayfa başlığı. */
    private const A11Y_BODY_NOISE = '/^(?:fotoğraf(?:\s+\d+\s*\/\s*\d+.*)?|.*fotoğrafı genişlet|reels videosu|mevcut reels videosunu oynat|sesini aç|\+\d+|paylaşılan bağlantı:.*|bağlantı görseli paylaşıldı|bu içerik hakkında|.*arka plan(?: görseli)?|diğer|daha fazla|geri|facebook logosu|oluştur,.*|.+, \\d+ \\/ \\d+|.+, tab \\d+ of \\d+|üyelik araçları için daha fazla seçenek|grup gönderileri|grupların|senin için|senin hareketlerin|keşfet|grup ara|tümünü gör|grup kur|ayarlar|yöneticinin onaylaması bekleniyor.*|öne çıkanlar|sen|rehberler|fotoğraflar)$/iu';

    /**
     * Grup listesi / bildirim ekranı artıkları: gönderi gövdesine karışınca grup adlarındaki il adları rota sanılıyordu
     * (2026-10-09 dökümü: "ÇORLU TEKİRDAĞ TRAKYA EDİRNE NAKLİYECİLER SİTESİ ⏎ 1 yeni gönderi ⏎ Grubu sabitle" → Çorlu → Edirne).
     */
    public const FEED_CHROME = '/^(?:grubu sabitle|grubun sabitlemesini kaldır|sabitlenenler.*|\d+\+?\s*yeni gönderi|.*\b\d[\d.,]*\s*(?:b\s*)?üye\b.*|.*\bve \d+ arkadaşın üye.*|.*beğenen arkadaşlar.*|okunmadı|.*için bildirim ayarlarını yönet.*|sıralama:.*|en sık ziyaret ettiklerin|group cover photo|grup kapak fotoğrafı|.*günde \d+\+?\s*gönderi.*|bildirimler, tab.*|\d+ veya daha fazla yeni|şimdi .{1,80}[\'’]d[ae]:.*|.+[\'’](?:da|de|ta|te|nda|nde) ara|beğen düğmesi\..*|paylaş düğmesi\..*|yorum düğmesi\..*|.*çift dokun.*|.*ifade bırakmak.*|gönderi .*düğmesi.*|facebook[\'’]ta arkadaş değilsiniz|\d+ ortak arkadaş.*|arkadaşlık isteği gönderildi|profili gör|mesajlar ve aramalar uçtan uca.*|.+[\'’]d[ae] (?:okudu|çalışıyor|yaşıyor|çalıştı|okuyor)|.*kanalını takip edin.*|https?:\/\/\S+)$/iu';

    /** Grup adı sözcükleri (ek almış biçimler dahil: "nakliyeciler sitesi", "tırcıları topluluk"). */
    public const FEED_GROUP_WORDS = '/(?:nakliyeci|nakliye|nakliyat|lojistik|yük|yuk|tırcı|tirci|kamyoncu|kamyon|borsa|portal|platform|topluluk|dernek|grubu|grup|sitesi|şoför|sofor)/iu';

    /** Satır satır: arayüz artığı satırlar ve art arda tekrarlanan grup adı satırları atılır (gövde metninden ya da bildirim metninden). */
    public static function stripFeedChrome(string $text): string
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $out = [];
        $n = count($lines);
        for ($i = 0; $i < $n; $i++) {
            $line = trim($lines[$i]);
            $lower = TurkishText::lower($line);
            if ($line !== '' && preg_match(self::FEED_CHROME, $lower) === 1) {
                continue;
            }
            // Bildirim başlığından taşan kesik grup adı ("MERSİN NAKLİYECİLE…"): ilk satır, "…" ile biter, rakam yok → ilan metni değil
            if ($i === 0 && $line !== '' && str_ends_with($line, '…') && preg_match('/\d/', $line) !== 1 && str_word_count($line) <= 5) {
                continue;
            }
            // Grup listesi her adı iki kez yazar: "X NAKLİYECİLER SİTESİ ⏎ X NAKLİYECİLER SİTESİ"; grup/nakliye sözcüklü tekrar satırı grup adıdır
            if ($line !== '' && isset($lines[$i + 1]) && trim($lines[$i + 1]) === $line && preg_match(self::FEED_GROUP_WORDS, $lower) === 1 && ! LoadIntakeService::hasPhone($line)) {
                $i++;

                continue;
            }
            $out[] = $lines[$i];
        }

        return implode("\n", $out);
    }

    /** Facebook'ta ilan olmayan bildirimler (yorum, beğeni, arkadaşlık, etkinlik…). */
    private const FACEBOOK_SKIP = '/(?<!\p{L})(?:yorum\s+yaptı|yorumladı|beğendi|tepki\s+verdi|arkadaşlık|etiketledi|bahsetti|commented|reacted|liked|friend\s+request|tagged|mentioned)(?!\p{L})/iu';

    /**
     * Etkinlik / hatırlatma / doğum günü / anı bildirimleri: yalnız başlıkta ya da metnin BAŞINDA aranır. Eski sürüm "hatırlat" ve
     * "etkinlik" parçalarını gönderinin her yerinde arıyordu; "etkinlik malzemesi taşınacak" yazan ilan eleniyordu.
     */
    private const FACEBOOK_SKIP_EVENT = '/(?<!\p{L})(?:doğum\s+gün\p{L}*|hatırlatma\p{L}*|canlı\s+yayın\p{L}*|etkinli[kğ]\p{L}*|anı(?:nız|ları)|birthday|memories|is\s+live)(?!\p{L})/iu';

    /**
     * @param  array{title?:?string, text?:?string, text_big?:?string, ticker?:?string, app?:?string}  $payload
     * @return array{skipped:?string, group:?string, platform:string, messages:list<array{sender:?string, phone:?string, text:string}>}
     */
    public static function parse(array $payload): array
    {
        $app = trim((string) ($payload['app'] ?? ''));
        if (($payload['kind'] ?? null) === 'screen') {
            return self::parseFacebookScreen((string) ($payload['text_big'] ?? $payload['text'] ?? ''), trim((string) ($payload['title'] ?? '')));
        }
        if (preg_match('/facebook/iu', $app)) {
            return self::parseFacebook($payload);
        }
        if ($app !== '' && ! preg_match('/whatsapp/iu', $app)) {
            return self::skip('not_whatsapp');
        }

        $title = trim((string) ($payload['title'] ?? ''));
        $text = trim((string) ($payload['text_big'] ?? ''));
        if ($text === '') {
            $text = trim((string) ($payload['text'] ?? ''));
        }
        if ($title === '' || $text === '') {
            return self::skip('empty');
        }

        foreach (self::SUMMARY_PATTERNS as $pattern) {
            if (preg_match($pattern, $title) || preg_match($pattern, $text)) {
                return self::skip('summary_notification');
            }
        }
        // Sistem bildirimi başlığı kısadır ("Yedekleme", "Cevapsız arama") ve metni de kısadır; "Yük Backup Grubu: Ali" gibi bir grup adı
        // uzun bir ilan metniyle gelince sistem bildirimi değildir (eski sürüm o grubun bütün mesajlarını atıyordu).
        $titleWords = count(array_filter(preg_split('/\s+/u', trim($title)) ?: []));
        if ((preg_match(self::SYSTEM_TITLE_PATTERN, $title) && ($titleWords <= 2 || ! LoadIntakeService::hasPhone($text))) || (mb_strlen($text) < 40 && preg_match(self::SYSTEM_SHORT_TEXT_PATTERN, $text))) {
            return self::skip('summary_notification');
        }
        if (preg_match(self::SYSTEM_WEAK_TITLE_PATTERN, $title) && mb_strlen($text) < 40 && $titleWords <= 2) {
            return self::skip('summary_notification'); // "Sesli arama", "Cevapsız arama" gibi kısa sistem başlığı + kısa metin
        }

        // Başlık biçimleri: "Grup", "Grup (3 mesaj)", "Grup (3 mesaj): Gönderen", "Grup: Gönderen", "Gönderen @ Grup".
        [$group, $titleSender] = self::splitTitle($title);

        $messages = [];
        if ($titleSender !== null) {
            // Gönderen başlıkta: metin tek mesajdır, satırlar "Gönderen: mesaj" diye bölünmez
            // (aksi halde "Ankara: İzmir 24 ton" gibi satırlar gönderen sanılır).
            $messages[] = ['sender' => $titleSender, 'phone' => self::phoneFrom($titleSender), 'text' => $text];
        } else {
            // "Gönderen: mesaj" satırları yalnız İLK satır gönderen önekiyle başlıyorsa bölünür (gruplanmış bildirim biçimi).
            // İlk satır etiketle ("Kalkış: Ankara") ya da öneksiz başlıyorsa metnin tamamı tek mesajdır; sonraki "Varış: İzmir",
            // "İletişim: 0532…" satırları devam satırıdır.
            $grouped = null;
            $seenSenders = [];
            foreach (preg_split('/\R/u', $text) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $isSenderLine = preg_match('/^([^:\n]{1,40}?):\s+(.+)$/su', $line, $m) === 1 && self::looksLikeSenderPrefix(trim($m[1]), $seenSenders);
                $grouped ??= $isSenderLine;
                if ($grouped && $isSenderLine) {
                    $sender = trim($m[1]);
                    $seenSenders[] = TurkishText::lower($sender);
                    $messages[] = ['sender' => $sender, 'phone' => self::phoneFrom($sender), 'text' => trim($m[2])];
                } elseif ($messages !== []) {
                    $last = array_key_last($messages);
                    $messages[$last]['text'] .= "\n".$line; // önceki mesajın devam satırı
                }
            }
        }

        if ($messages === []) {
            // Gönderen öneki yok: metin tek bir mesajdır. Ticker "Gönderen @ Grup: mesaj" biçimindeyse göndereni oradan al.
            $sender = self::senderFromTicker(trim((string) ($payload['ticker'] ?? '')), $group);
            $messages[] = ['sender' => $sender, 'phone' => $sender !== null ? self::phoneFrom($sender) : null, 'text' => $text];
        }

        return ['skipped' => null, 'group' => $group, 'platform' => 'whatsapp', 'messages' => $messages];
    }

    /**
     * "X: metin" satırındaki X bir gönderen adı mı? Gönderen: telefon numarası, ya da en çok 3 sözcüklük, rakamsız,
     * yalnız harf/nokta/kesme içeren, ilan etiketi olmayan ve yer adına çözülmeyen bir ad ("Ahmet Usta", "Mehmet Y.").
     * Daha önce görülen gönderen adı her zaman gönderendir. "Kalkış", "Yükleme yeri", "Araç", "Ankara" gönderen değildir.
     *
     * @param  list<string>  $seenSendersLower
     */
    public static function looksLikeSenderPrefix(string $prefix, array $seenSendersLower = []): bool
    {
        $prefix = trim($prefix);
        if ($prefix === '') {
            return false;
        }
        if (self::phoneFrom($prefix) !== null) {
            return true; // numarayla kayıtlı gönderen
        }
        $lower = TurkishText::lower($prefix);
        if (in_array($lower, $seenSendersLower, true)) {
            return true;
        }
        if (preg_match('/\d/u', $prefix) || preg_match('/[^\p{L}\s.\'’\-]/u', $prefix)) {
            return false; // rakam ya da emoji/işaret: etiket, saat, ölçü
        }
        $words = array_values(array_filter(preg_split('/\s+/u', $lower) ?: [], fn ($w) => $w !== ''));
        if ($words === [] || count($words) > 3) {
            return false;
        }
        $labelWords = 0;
        foreach ($words as $w) {
            $w = trim($w, ".'’-");
            $ascii = TurkishCities::ascii($w);
            $stemHit = false;
            foreach (self::LABEL_STEMS as $stem) {
                if (str_starts_with($ascii, $stem) && strlen($ascii) <= strlen($stem) + 5) {
                    $stemHit = true;
                    break;
                }
            }
            if ($stemHit || in_array($w, self::LABEL_WORDS, true) || in_array($ascii, ['no', 'nr', 'num'], true)) {
                $labelWords++;
            }
        }
        if ($labelWords === count($words)) {
            return false; // "Kalkış", "Yükleme yeri", "Araç tipi", "İletişim"
        }
        if (LoadIntakeService::isNotLoadPattern($prefix) || GoodsCatalog::detect(VehicleClassifier::normalize($prefix)) !== null) {
            return false; // "Boş araç", "Mermer": lojistik sözcüğü, ad değil
        }

        return AiParserService::placesIn($prefix, 1) === []; // "Ankara: İzmir 24 ton" gönderen değil, rota satırı
    }

    /**
     * Facebook grup gönderisi bildirimi. Biçimler:
     *  - başlık "Facebook", metin "Ad Soyad, Grup Adı grubunda paylaştı: gönderi"
     *  - başlık "Grup Adı", metin "Ad Soyad: gönderi" ya da "Ad Soyad gönderi paylaştı: gönderi"
     *  - metin "Ad Soyad posted in Grup Adı: gönderi" (İngilizce arayüz)
     * Gönderinin tamamı text_big'te ise o kullanılır.
     */
    private static function parseFacebook(array $payload): array
    {
        $title = trim((string) ($payload['title'] ?? ''));
        $text = trim((string) ($payload['text'] ?? ''));
        $big = trim((string) ($payload['text_big'] ?? ''));
        if (mb_strlen($big) > mb_strlen($text)) {
            $text = $big;
        }
        if ($text === '') {
            return self::skip('empty');
        }
        if (preg_match(self::FACEBOOK_SKIP, $title.' '.mb_substr($text, 0, 160)) || preg_match(self::FACEBOOK_SKIP_EVENT, $title.' | '.mb_substr($text, 0, 40))) {
            return self::skip('facebook_not_post');
        }
        $titleIsApp = $title === '' || preg_match('/^facebook(?:\s+lite)?$/iu', $title) === 1;
        $group = null;
        $sender = null;
        $body = null;

        if (preg_match('/^(?<s>.{1,80}?),\s+(?<g>.{1,120}?)\s+grubunda\s+(?:yeni\s+bir\s+)?(?:gönderi\s+|bir\s+şey\s+)?paylaştı\s*[:\-–]?\s*(?<t>.*)$/su', $text, $m)
            || preg_match('/^(?<s>.{1,80}?)\s+posted\s+in\s+(?<g>.{1,120}?)\s*[:\-–]\s*(?<t>.*)$/su', $text, $m)) {
            $sender = trim($m['s']);
            $group = trim($m['g']);
            $body = trim($m['t']);
        } elseif (preg_match('/^(?<h>.{1,200}?)\s+grubunda\s+(?:yeni\s+bir\s+)?(?:gönderi\s+|bir\s+şey\s+)?paylaştı\s*[:\-–]?\s*(?<t>.*)$/su', $text, $m)) {
            // "Ad Soyad Grup Adı grubunda paylaştı": gönderen ile grup ayrılamaz; grup adı başlıktan, yoksa başlık cümlesinden
            $group = $titleIsApp ? trim($m['h']) : $title;
            $body = trim($m['t']);
        } elseif (! $titleIsApp) {
            $group = $title;
            $body = $text;
            if (preg_match('/^(?<s>[^:\n]{1,60}?)\s*(?:gönderi\s+paylaştı|paylaştı)?\s*:\s+(?<t>.+)$/su', $text, $m) && self::looksLikeSenderPrefix(trim($m['s']))) {
                $sender = trim($m['s']);
                $body = trim($m['t']);
            }
        } else {
            return self::skip('facebook_no_group');
        }

        $body = trim(preg_replace('/^[«"“]+|[»"”]+$/u', '', trim((string) $body)) ?? '');
        $body = trim(preg_replace('/\s*(?:…|\.\.\.)\s*$/u', '', $body) ?? $body); // kısaltma işareti
        if ($group === null || $group === '' || mb_strlen($body) < 12) {
            return self::skip('facebook_no_body');
        }

        return ['skipped' => null, 'group' => $group, 'platform' => 'facebook', 'messages' => [['sender' => $sender, 'phone' => null, 'text' => $body]]];
    }

    /**
     * Facebook ekran dökümü: iletici telefonda tek dokunuşla "Gruplar" akışı (ya da tek bir grup) aşağı kaydırılıp ekrandaki
     * yazı okunur ve tek istekte gelir. Facebook sunucusuna otomatik istek atılmaz. Döküm gönderilere ayrılır: her gönderinin
     * sonunda "Beğen / Yorum yap / Paylaş" düğme satırları vardır; başında grup adı (akış kipinde) ve yazar satırı bulunur.
     * Yazar adı saklanmaz. Kaydırma sırasında aynı gönderi iki ekranda görünür; döküm içinde tekrarlar elenir (kalanı
     * alımdaki tekrar denetimi yakalar). Reklam ("Sponsorlu") ve önerilen gönderiler atlanır.
     *
     * @param  string  $groupHint  tek bir grubun içinden alınan dökümde grup adı (title); boşsa her gönderi kendi grubunu taşır
     * @return array{skipped:?string, group:?string, platform:string, messages:list<array{sender:?string, phone:?string, text:string, group:string}>}
     */
    public static function parseFacebookScreen(string $dump, string $groupHint = ''): array
    {
        $dump = trim($dump);
        if ($dump === '') {
            return self::skip('empty');
        }
        $groupHint = preg_match('/^(?:facebook|ekran|screen|gruplar|groups)$/iu', $groupHint) ? '' : $groupHint;
        $dump = self::flattenJsonChunks($dump);
        if (preg_match(self::A11Y_ANCHOR, $dump) === 1 || preg_match(self::A11Y_SHARED, $dump) === 1) {
            return self::parseAccessibilityScreens($dump, $groupHint);
        }
        // Bloklar: "Paylaş" (düğme satırı sonu) her gönderiyi kapatır; makronun ekranlar arasına koyduğu "-----" de sınırdır.
        $blocks = [];
        $current = [];
        foreach (preg_split('/\R/u', $dump) ?: [] as $line) {
            $line = trim(preg_replace('/\s+/u', ' ', $line) ?? $line);
            if ($line === '' || preg_match('/^-{3,}$/', $line)) {
                if (preg_match('/^-{3,}$/', $line) && $current !== []) {
                    $blocks[] = $current;
                    $current = [];
                }

                continue;
            }
            $current[] = $line;
            if (preg_match('/^(?:paylaş|share)$/iu', $line)) {
                $blocks[] = $current;
                $current = [];
            }
        }
        if ($current !== []) {
            $blocks[] = $current;
        }

        $messages = [];
        $seen = [];
        foreach ($blocks as $lines) {
            if (array_filter($lines, fn ($l) => preg_match(self::SCREEN_SKIP_BLOCK, $l) === 1) !== []) {
                continue;
            }
            $lines = array_values(array_filter($lines, fn ($l) => ! self::isScreenNoise($l)));
            if ($lines === []) {
                continue;
            }
            $group = $groupHint;
            if ($group === '') {
                $group = array_shift($lines); // akış kipinde ilk satır grup adıdır
            }
            // Yazar satırı: "Ad Soyad · 2 sa" ya da ad + ayrı zaman satırı (zaman satırı gürültü olarak zaten atıldı)
            if (isset($lines[0]) && preg_match(self::SCREEN_AUTHOR, $lines[0])) {
                array_shift($lines);
            } elseif (isset($lines[0]) && self::looksLikePersonName($lines[0])) {
                array_shift($lines);
            }
            $body = trim(implode("\n", $lines));
            $body = trim(preg_replace('/\s*(?:…|\.\.\.)\s*$/u', '', $body) ?? $body);
            if ($group === null || $group === '' || mb_strlen(preg_replace('/[^\p{L}\p{N}]/u', '', $body) ?? '') < 12) {
                continue;
            }
            $key = TurkishCities::ascii(preg_replace('/[^\p{L}\p{N}]+/u', '', $body) ?? $body);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $messages[] = ['sender' => null, 'phone' => null, 'text' => $body, 'group' => mb_substr($group, 0, 120)];
        }
        if ($messages === []) {
            return self::skip('facebook_screen_empty');
        }

        return ['skipped' => null, 'group' => $groupHint !== '' ? $groupHint : $messages[0]['group'], 'platform' => 'facebook', 'messages' => $messages];
    }

    /**
     * Erişilebilirlik dökümünü gönderilere böler. Her ekran ("-----" arası) ayrı okunur; gönderinin çapası
     * "Ad'in gönderisi için diğer seçenekler" satırıdır. Çapadan geriye doğru başlık bölgesi (yazar, "•Paylaşılanlar",
     * Takip Et/Katıl, grup adı) taranır; gövde çapadan sonraki satırlardan bir sonraki gönderinin başlığına kadardır.
     * Grup adı: grup sayfasındaysa "Grup Adı'da Ara" satırından, akıştaysa başlık bölgesindeki yazar dışı satırdan
     * ("Grup•Katıl" eki atılır). Yazar adı ve profil satırları saklanmaz.
     *
     * @return array{skipped:?string, group:?string, platform:string, messages:list<array{sender:?string, phone:?string, text:string, group:string}>}
     */
    private static function parseAccessibilityScreens(string $dump, string $groupHint): array
    {
        $messages = [];
        $seen = [];
        foreach (preg_split('/^-{3,}$/mu', $dump) ?: [] as $screen) {
            $lines = [];
            foreach (preg_split('/\R/u', $screen) ?: [] as $line) {
                $line = trim(preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', preg_replace('/\s+/u', ' ', $line) ?? $line) ?? $line);
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
            $pageGroup = '';
            foreach ($lines as $line) {
                if (preg_match(self::A11Y_GROUP_PAGE, $line, $m)) {
                    $pageGroup = trim($m[1]);
                    break;
                }
            }
            $anchors = [];
            foreach ($lines as $i => $line) {
                if (preg_match(self::A11Y_ANCHOR, $line, $m)) {
                    $anchors[] = ['at' => $i, 'author' => trim($m[1])];
                }
            }
            if ($anchors === []) {
                continue;
            }
            $authors = array_map(fn ($a) => TurkishText::lower($a['author']), $anchors);
            // Her çapanın başlık bölgesinin başı: geriye doğru yazar/arayüz/"•Paylaşılanlar" satırları ve en çok iki grup adayı.
            foreach ($anchors as $k => &$anchor) {
                $floor = $k > 0 ? $anchors[$k - 1]['at'] + 1 : 0;
                $start = $anchor['at'];
                $candidates = [];
                $shaped = 0; // başlıkta en çok iki "ad gibi" satır; fazlası önceki gönderinin gövdesidir
                $sponsored = false;
                for ($i = $anchor['at'] - 1; $i >= $floor && $i >= $anchor['at'] - 12; $i--) {
                    $line = $lines[$i];
                    if (preg_match(self::SCREEN_SKIP_BLOCK, $line)) {
                        $sponsored = true;
                        $start = $i;

                        continue;
                    }
                    if (self::isAuthorLine($line, $anchor['author']) || preg_match(self::A11Y_SHARED, $line) || preg_match(self::A11Y_HEADER_UI, TurkishText::lower($line))) {
                        $start = $i;

                        continue;
                    }
                    $clean = trim(preg_replace('/\s*•\s*(?:katıl|takip et)\s*$/iu', '', $line, -1, $marked) ?? $line);
                    if ($shaped < 2 && mb_strlen($clean) <= 80 && ! preg_match('/[.!?:]\s*$|\d{3}/u', $clean) && ! LoadIntakeService::hasPhone($clean)) {
                        $shaped++;
                        // Grup adı yalnız güvenilir işaretle: "•Katıl/•Takip Et" eki, kapak fotoğrafı/profil satırından hemen sonra,
                        // aynı satırın başlıkta tekrarı ya da grup/nakliye sözcüğü. Düğme yazısı ve yazar/kişi adı grup olamaz.
                        $afterCover = $i > 0 && preg_match('/(?:kapak fotoğrafı|profil resmi)$/iu', $lines[$i - 1]) === 1;
                        $repeated = in_array($clean, $candidates, true) || (isset($lines[$i + 1]) && trim(preg_replace('/\s*•.*$/u', '', $lines[$i + 1]) ?? '') === $clean);
                        $signal = $marked > 0 || $afterCover || $repeated;
                        if (self::isPlausibleGroupName($clean, $authors, $signal) && ($signal || preg_match(self::GROUP_HINT_WORDS, $clean))) {
                            $candidates[] = $clean;
                        }
                        $start = $i;

                        continue;
                    }
                    break;
                }
                $anchor['start'] = $start;
                $anchor['sponsored'] = $sponsored;
                $anchor['group'] = $pageGroup !== '' ? $pageGroup : ($candidates !== [] ? end($candidates) : ($groupHint !== '' ? $groupHint : 'Facebook akışı'));
            }
            unset($anchor);

            foreach ($anchors as $k => $anchor) {
                if ($anchor['sponsored']) {
                    continue;
                }
                $end = isset($anchors[$k + 1]) ? $anchors[$k + 1]['start'] : count($lines);
                $body = [];
                $truncated = false;
                for ($i = $anchor['at'] + 1; $i < $end; $i++) {
                    // "… diğer" (devamını gör) düğmesi satır sonunda; kısaltılmış gövde olduğu gibi kalır, gönderi "kesik" işaretlenir
                    $line = trim(preg_replace('/\\s*(?:…|\\.\\.\\.)\\s*diğer\\s*$/u', '', $lines[$i], -1, $cut) ?? $lines[$i]);
                    $truncated = $truncated || $cut > 0;
                    $lower = TurkishText::lower($line); // /i bayrağı İ/I dönüşümünü bilmez
                    if ($line === '' || $line === $pageGroup || self::isAuthorLine($line, $anchor['author']) || preg_match(self::A11Y_BODY_NOISE, $lower) || preg_match(self::A11Y_HEADER_UI, $lower) || self::isScreenNoise($lower)
                        || preg_match(self::FEED_CHROME, $lower) || preg_match(self::A11Y_GROUP_PAGE, $line)) {
                        continue;
                    }
                    if ($body !== [] && mb_stripos(end($body), $line) !== false) {
                        continue; // "#etiket" gibi bir önceki satırın parçası olan tekrar
                    }
                    $body[] = $line;
                }
                $text = trim(self::stripFeedChrome(implode("\n", $body)));
                if (mb_strlen(preg_replace('/[^\p{L}\p{N}]/u', '', $text) ?? '') < 12) {
                    continue;
                }
                $key = TurkishCities::ascii(preg_replace('/[^\p{L}\p{N}]+/u', '', $text) ?? $text);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $messages[] = ['sender' => null, 'phone' => null, 'text' => $text, 'group' => mb_substr($anchor['group'], 0, 120), 'truncated' => $truncated];
            }
        }
        if ($messages === []) {
            return self::skip('facebook_screen_empty');
        }

        return ['skipped' => null, 'group' => $groupHint !== '' ? $groupHint : $messages[0]['group'], 'platform' => 'facebook', 'messages' => $messages];
    }

    /**
     * Grup adı olabilir mi: arayüz yazısı değil, ekrandaki bir yazarın adı değil, grup/nakliye sözcüğü taşımayan 1-4 sözcüklük
     * kişi adı görünümünde değil ("turgut özdem" grup sanılmıştı). Gerçek grup adları ya işaretle ya sözcükle gelir.
     *
     * @param  list<string>  $authorsLower
     */
    private static function isPlausibleGroupName(string $clean, array $authorsLower, bool $signal = false): bool
    {
        $lower = TurkishText::lower($clean);
        if (mb_strlen($clean) < 3 || preg_match(self::A11Y_HEADER_UI, $lower) || preg_match(self::A11Y_BODY_NOISE, $lower) || self::isScreenNoise($lower)) {
            return false;
        }
        foreach ($authorsLower as $author) {
            if ($author !== '' && ($lower === $author || str_starts_with($lower, $author.' ') || str_starts_with($author, $lower))) {
                return false;
            }
        }
        if ($signal || preg_match(self::GROUP_HINT_WORDS, $clean)) {
            return true; // "•Katıl" / kapak fotoğrafı / tekrar işareti ya da sözcük: kişi adı denetimine gerek yok
        }

        return ! self::looksLikePersonName($clean);
    }

    /** Yazar adıyla başlayan satırlar: "Ad", "Ad•Takip Et", "Ad profil resmi", "Ad profesyonel hissediyor." */
    private static function isAuthorLine(string $line, string $author): bool
    {
        return $author !== '' && mb_stripos($line, $author) === 0;
    }

    /**
     * Hazır makro ekran içeriğini sözlük olarak JSON biçiminde ekler ({lvjson=parca}: görünüm kimliği → yazı). "-----" ile
     * ayrılmış her parça JSON nesnesiyse değerleri sırayla satır yapılır; düz metin parçalar olduğu gibi kalır.
     */
    private static function flattenJsonChunks(string $dump): string
    {
        $out = [];
        foreach (preg_split('/^-{3,}$/mu', $dump) ?: [] as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }
            if (str_starts_with($chunk, '{') || str_starts_with($chunk, '[')) {
                $decoded = json_decode($chunk, true);
                if (is_array($decoded)) {
                    $lines = [];
                    array_walk_recursive($decoded, function ($v) use (&$lines): void {
                        if (is_scalar($v) && trim((string) $v) !== '') {
                            $lines[] = trim((string) $v);
                        }
                    });
                    $chunk = implode("\n", $lines);
                }
            }
            $out[] = $chunk;
        }

        return implode("\n-----\n", $out);
    }

    private static function isScreenNoise(string $line): bool
    {
        if (LoadIntakeService::hasPhone($line)) {
            return false; // "0532 111 22 33" sayaç değil, ilanın numarasıdır
        }
        foreach (self::SCREEN_NOISE as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Kısa, rakamsız, yer adı ve nakliye sözcüğü içermeyen 1-4 sözcük: yazar adı sayılır ve saklanmaz. */
    private static function looksLikePersonName(string $line): bool
    {
        $words = preg_split('/\s+/u', trim($line)) ?: [];
        if ($words === [] || count($words) > 4 || mb_strlen($line) > 40 || preg_match('/[\d:@\/\-→]/u', $line)) {
            return false;
        }
        if (preg_match('/(?<!\p{L})(?:yük|yuk|ton|tır|tir|kamyon|kamyonet|dorse|tente|frigo|damper|acil|araç|arac|nakliye|lojistik|çıkış|cikis|yükleme|yukleme|boşalt|bosalt|iner|fiyat|palet|kg)/iu', $line)) {
            return false;
        }

        return AiParserService::placesIn($line, 1) === [];
    }

    /**
     * Tanı amacıyla saklanan ham ekran dökümünden yazar adlarını siler (KVKK): çapa satırı "Ad'in gönderisi için diğer seçenekler",
     * başlık "Ad•3s•Paylaşılanlar: …" ve yazarla başlayan satırlar ("Ad profil resmi") "Yazar" ile değiştirilir; döküm yapısı korunur.
     */
    public static function redactAuthors(string $dump): string
    {
        $authors = [];
        $lines = preg_split('/\R/u', $dump) ?: [];
        foreach ($lines as $line) {
            if (preg_match(self::A11Y_ANCHOR, trim($line), $m)) {
                $authors[] = trim($m[1]);
            }
        }
        $authors = array_values(array_unique(array_filter($authors, fn ($a) => mb_strlen($a) >= 2)));
        usort($authors, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a)); // uzun ad önce ("Ali Veli" içindeki "Ali" yarım kalmasın)
        $out = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (preg_match(self::A11Y_ANCHOR, $trimmed)) {
                $line = 'Yazar\'ın gönderisi için diğer seçenekler';
            } elseif (preg_match(self::A11Y_SHARED, $trimmed)) {
                $line = 'Yazar'.preg_replace('/^.*?(?=•)/u', '', $trimmed);
            } else {
                foreach ($authors as $author) {
                    if (mb_stripos($trimmed, $author) === 0) {
                        $line = 'Yazar'.mb_substr($trimmed, mb_strlen($author));
                        break;
                    }
                }
            }
            $out[] = $line;
        }

        return implode("\n", $out);
    }

    /** Grup ilan kaynağı için sabit tanımlayıcı: aynı grup adı her zaman aynı kaynağa düşer (Facebook: fb:, WhatsApp: notif:). */
    public static function sourceIdentifier(string $group, string $platform = 'whatsapp'): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', TurkishCities::ascii($group)) ?? '';

        return ($platform === 'facebook' ? 'fb:' : 'notif:').trim($slug, '-');
    }

    /**
     * Bildirim başlığını grup adı ve (varsa) gönderene ayırır.
     *
     * @return array{0:string, 1:?string}
     */
    public static function splitTitle(string $title): array
    {
        // "(3 mesaj)" / "(2 new messages)" parçası nerede olursa olsun atılır.
        $clean = trim(preg_replace('/\s*\(\d+\s+(?:yeni\s+)?(?:mesaj|new\s+messages?|messages?)\)\s*/iu', ' ', $title) ?? $title);
        $clean = trim(preg_replace('/\s{2,}/u', ' ', $clean) ?? $clean);

        if (preg_match('/^(.{1,80}?)\s+@\s+(.{1,120})$/su', $clean, $m)) {
            return [trim($m[2]), trim($m[1])]; // "Gönderen @ Grup"
        }
        if (preg_match('/^(.{1,120}?)\s*:\s+(.{1,80})$/su', $clean, $m)) {
            return [trim($m[1]), trim($m[2])]; // "Grup: Gönderen"
        }

        return [$clean, null];
    }

    private static function senderFromTicker(string $ticker, string $group): ?string
    {
        if ($ticker === '') {
            return null;
        }
        if (preg_match('/^(.{1,60}?)\s*@\s*(.{1,120}?):\s/su', $ticker, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/^([^:\n]{1,40}?):\s/su', $ticker, $m) && trim($m[1]) !== $group) {
            return trim($m[1]);
        }

        return null;
    }

    private static function phoneFrom(string $sender): ?string
    {
        return Phone::normalizeContact($sender);
    }

    /** Ekran dökümünde "görüldü" önbellek anahtarı (grup + metin); kaynak onay beklerken alım bu anahtarı serbest bırakır. */
    public static function screenSeenKey(string $group, string $text): string
    {
        return 'fb:seen:'.sha1($group.'|'.trim($text));
    }

    private static function skip(string $reason): array
    {
        return ['skipped' => $reason, 'group' => null, 'platform' => 'whatsapp', 'messages' => []];
    }
}
