<?php

namespace App\Support;

/**
 * Okuma katmanları. Gruptan gelen bir mesaj sırayla bu katmanlardan geçer; her katman ya mesajı eler (eleme), ya ilanlara
 * ayırıp rotayı çözer (çözüm), ya da kesin kural bulamayınca makul bir yorum yapar (yorum). Her katman yönetici panelinden
 * (Ayarlar → Dış kaynak → Okuma katmanları) tek tek açılıp kapanır; kapanan katman yokmuş gibi davranılır ve mesaj bir
 * sonraki katmana iner. Yeni bir katman eklenince buraya bir satır ve Settings::DEFAULTS'a "intake_layer_<anahtar>" girer;
 * panel listesi bu tablodan üretilir (Osman, 2026-10-05: "kaç katman varsa aç/kapa ayarı olsun, katman arttıkça dinamik olsun").
 *
 * Kayıt açılan her aday parse_metadata.layer ile hangi katmanın çözdüğünü taşır; elenen mesajın gerekçesi canlı akışta
 * (IntakeEvent.reason) durur. Panel her katmanın son 7 gündeki sayısını bu iki yerden okur.
 */
final class IntakeLayers
{
    public const PREFIX = 'intake_layer_';

    /** kind: eleme | çözüm | yorum. reasons: canlı akışta bu katmanın yazdığı gerekçe anahtarları (sayım için). */
    public const LAYERS = [
        'foreign_script' => ['kind' => 'eleme', 'label' => 'Yabancı alfabe', 'reasons' => ['foreign_script'],
            'desc' => 'Kiril ya da Arap alfabesiyle yazılmış mesaj Türkiye ilanı değildir; yapay zekaya gitmeden elenir.'],
        'lexicon_not_load' => ['kind' => 'eleme', 'label' => 'Sözlük: "ilan değil" ifadeleri', 'reasons' => ['lexicon_not_load'],
            'desc' => 'Sözlük ve öğrenme ekranında öğrettiğiniz ifadeler (satılık, iş arıyorum…) mesajı eler.'],
        'not_load_pattern' => ['kind' => 'eleme', 'label' => 'Sabit "ilan değil" kalıpları', 'reasons' => ['not_load_pattern'],
            'desc' => 'Boş araç arayan nakliyeci, şoför/eleman ilanı, fatura ve e-arşiv reklamı, satılık/kiralık araç kalıpları elenir.'],
        'logistics_signal' => ['kind' => 'eleme', 'label' => 'Lojistik işaret ön elemesi', 'reasons' => ['no_logistics_signal'],
            'desc' => 'Telefonu olan ama rota, tonaj, fiyat, araç ya da yük sözcüğü taşımayan sohbet mesajı yapay zekaya gitmeden elenir.'],
        'series' => ['kind' => 'çözüm', 'label' => 'Seri ilan (başlık + boşaltma listesi)', 'reasons' => [],
            'desc' => '"X yükler" başlığı altındaki her boşaltma satırı, "il / ilçe" listesi, "A = B" ve "GEBZE+TUZLA yükler" biçimleri ayrı ilanlara ayrılır.'],
        'comma_list' => ['kind' => 'çözüm', 'label' => 'Virgüllü varış listesi', 'reasons' => [],
            'desc' => '"İstanbul çıkışlı: Ankara, İzmir, Bursa" ve "… yükleme, A, B boşaltma 3 araç" biçimleri her varış için ayrı ilan olur.'],
        'round_trip' => ['kind' => 'çözüm', 'label' => 'Gidiş-dönüş', 'reasons' => [],
            'desc' => '"Ankara-İstanbul / İstanbul-Ankara gidiş dönüş" iki ilan olur.'],
        'no_pickup_filter' => ['kind' => 'eleme', 'label' => 'Kalkışsız varış listesi elemesi', 'reasons' => ['pickup_missing'],
            'desc' => 'Fiilsiz, alt alta yalnız "yer + araç" satırlarından oluşan üç ve daha çok satırlık liste; kalkış yazmadığı için satırlar birbirine rota diye bağlanmaz.'],
        'two_line_route' => ['kind' => 'yorum', 'label' => 'İki satırlık ilan yorumu', 'reasons' => [],
            'desc' => 'Fiilsiz tam iki "yer + araç" satırı: ilk yer kalkış, ikinci yer varış sayılır. Yapay zeka rotayı doğrularsa normal, doğrulamazsa "bilgi eksik" rozetiyle yayınlanır.'],
        'sender_pickup_memory' => ['kind' => 'yorum', 'label' => 'Gönderen hafızasından kalkış', 'reasons' => [],
            'desc' => 'Kalkışı yazmayan listelerde gönderenin bilinen kalkışı (öğrettiğiniz ya da son 30 günün ilanlarından çıkan) kullanılır; her satır "bilgi eksik" rozetiyle yayınlanır. Bilinmiyorsa aday kuyrukta "Kalkış öğret" ile bekler.'],
        'template_memory' => ['kind' => 'çözüm', 'label' => 'Şablon hafızası (gönderen kalıbı)', 'reasons' => ['template_not_load'],
            'desc' => 'Aynı numaranın daha önce doğrulanmış kalıbı yapay zekasız çözülür; "ilan değil" diye öğrenilmiş kalıp elenir.'],
        'rule_strong' => ['kind' => 'çözüm', 'label' => 'Kesin kural kısayolu', 'reasons' => [],
            'desc' => 'İki il katalogda birebir, telefon ve açık araç adı varsa yapay zeka beklenmeden yayına gider (kota harcanmaz).'],
        'ai_not_load' => ['kind' => 'eleme', 'label' => 'Yapay zeka "ilan değil" elemesi', 'reasons' => ['ai_not_load'],
            'desc' => 'Yapay zeka %80 ve üstü güvenle "yük ilanı değil" derse mesaj elenir ve gönderenin kalıbı öğrenilir.'],
    ];

    public static function enabled(string $key): bool
    {
        if (! isset(self::LAYERS[$key])) {
            return true;
        }

        return Settings::bool(self::PREFIX.$key);
    }

    public static function settingKey(string $key): string
    {
        return self::PREFIX.$key;
    }

    /** @return array<string, string> ayar anahtarı → 1 */
    public static function defaults(): array
    {
        $out = [];
        foreach (array_keys(self::LAYERS) as $key) {
            $out[self::PREFIX.$key] = 1;
        }

        return $out;
    }

    /** Gerekçe anahtarından katman anahtarı (canlı akış sayımı). */
    public static function layerForReason(string $reason): ?string
    {
        foreach (self::LAYERS as $key => $layer) {
            if (in_array($reason, $layer['reasons'], true)) {
                return $key;
            }
        }

        return null;
    }
}
