<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Okuma katmanları. Gruptan gelen bir mesaj sırayla bu katmanlardan geçer; her katman ya mesajı eler (eleme), ya ilanlara
 * ayırıp rotayı çözer (çözüm), ya da kesin kural bulunamayınca makul bir yorum yapar (yorum). Sıra kodda sabittir.
 *
 * Katmanlar elle açılıp kapanmaz (Osman, 2026-10-05: "açıp kapatmak bizim elimizde olmasın; aşama aşama, kendini izleyen bir
 * sistem"). Her katmanın bir **aşaması** vardır ve aşamayı sistem yönetir (App\Services\IntakeLayerReview, saatlik):
 *  - kalıcı (permanent): kanıtlanmış eleme/çözüm katmanları; her zaman etkin, aşaması değişmez.
 *  - yönetilen (managed): yeni / yorum katmanları. `shadow` (gölge) ile başlar: katman çalışır, ne yapacağını örnek tablosuna
 *    yazar ve yapay zeka hakemine sorar, ama ilanı ETKİLEMEZ. Yeterli örnekte hakemle uyum eşiği aşılınca kendiliğinden `active`
 *    (etkin) olur. Etkinken şoför/yönetici/yapay zeka doğrulamaları izlenir; hata oranı eşiği aşarsa kendiliğinden gölgeye döner
 *    (`paused`). Her aşama değişimi etkinlik günlüğüne, Telegram'a ve yönetici bildirimine düşer.
 *
 * Kayıt açılan her aday parse_metadata.layer ile hangi katmanın çözdüğünü taşır; elenen mesajın gerekçesi canlı akışta durur.
 * Yeni katman eklerken: buraya satır (lifecycle=managed ise shadow ile başlar), kodda `IntakeLayers::enabled('<anahtar>')` kapısı,
 * gölgede örnek yazımı (`IntakeLayerSample`), açılan parçaya `'layer'` damgası, test.
 */
final class IntakeLayers
{
    public const STAGE_ACTIVE = 'active';

    public const STAGE_SHADOW = 'shadow';

    public const STAGE_PAUSED = 'paused';

    public const STAGE_LABELS = [self::STAGE_ACTIVE => 'etkin', self::STAGE_SHADOW => 'gölge (izleniyor)', self::STAGE_PAUSED => 'duraklatıldı (gölgede)'];

    /**
     * kind: eleme | çözüm | yorum. lifecycle: permanent | managed. judge (yönetilen): ai_route (gölgede yapay zeka hakemi rotayı
     * doğrular) | outcome (yalnız yayın sonrası şoför/yönetici/yapay zeka sonuçları). reasons: canlı akışta bu katmanın gerekçeleri.
     */
    public const LAYERS = [
        'foreign_script' => ['kind' => 'eleme', 'lifecycle' => 'permanent', 'label' => 'Yabancı alfabe', 'reasons' => ['foreign_script'],
            'desc' => 'Kiril ya da Arap alfabesiyle yazılmış mesaj Türkiye ilanı değildir; yapay zekaya gitmeden elenir.'],
        'lexicon_not_load' => ['kind' => 'eleme', 'lifecycle' => 'permanent', 'label' => 'Sözlük: "ilan değil" ifadeleri', 'reasons' => ['lexicon_not_load'],
            'desc' => 'Sözlük ve öğrenme ekranında öğretilen ifadeler (satılık, iş arıyorum…) mesajı eler.'],
        'not_load_pattern' => ['kind' => 'eleme', 'lifecycle' => 'permanent', 'label' => 'Sabit "ilan değil" kalıpları', 'reasons' => ['not_load_pattern'],
            'desc' => 'Boş araç arayan nakliyeci, şoför/eleman ilanı, fatura reklamı, satılık/kiralık araç kalıpları elenir; "satılık değil" gibi olumsuzlama elemez.'],
        'logistics_signal' => ['kind' => 'eleme', 'lifecycle' => 'permanent', 'label' => 'Lojistik işaret ön elemesi', 'reasons' => ['no_logistics_signal'],
            'desc' => 'Telefonu olan ama rota, tonaj, fiyat, araç ya da yük sözcüğü taşımayan sohbet mesajı yapay zekaya gitmeden elenir.'],
        'series' => ['kind' => 'çözüm', 'lifecycle' => 'permanent', 'label' => 'Seri ilan (başlık + boşaltma listesi)', 'reasons' => [],
            'desc' => '"X yükler" başlığı altındaki her boşaltma satırı, "il / ilçe" listesi, "A = B" ve "GEBZE+TUZLA yükler" biçimleri ayrı ilanlara ayrılır.'],
        'comma_list' => ['kind' => 'çözüm', 'lifecycle' => 'permanent', 'label' => 'Virgüllü varış listesi', 'reasons' => [],
            'desc' => '"İstanbul çıkışlı: Ankara, İzmir, Bursa" ve "… yükleme, A, B boşaltma 3 araç" biçimleri her varış için ayrı ilan olur.'],
        'round_trip' => ['kind' => 'çözüm', 'lifecycle' => 'permanent', 'label' => 'Gidiş-dönüş', 'reasons' => [],
            'desc' => '"Ankara-İstanbul / İstanbul-Ankara gidiş dönüş" iki ilan olur.'],
        'two_line_route' => ['kind' => 'yorum', 'lifecycle' => 'managed', 'judge' => 'ai_route', 'label' => 'İki satırlık ilan yorumu', 'reasons' => [],
            'desc' => 'Fiilsiz tam iki "yer + araç" satırı: ilk yer kalkış, ikinci yer varış sayılır. Yapay zeka rotayı doğrularsa normal, doğrulamazsa "bilgi eksik" rozetiyle yayınlanır.'],
        'no_pickup_filter' => ['kind' => 'eleme', 'lifecycle' => 'permanent', 'label' => 'Kalkışsız varış listesi', 'reasons' => ['pickup_missing'],
            'desc' => 'Fiilsiz, alt alta yalnız "yer + araç" satırlarından oluşan üç ve daha çok satırlık liste; kalkış yazmadığı için satırlar birbirine rota diye bağlanmaz.'],
        'sender_pickup_memory' => ['kind' => 'yorum', 'lifecycle' => 'managed', 'judge' => 'outcome', 'label' => 'Gönderen hafızasından kalkış', 'reasons' => [],
            'desc' => 'Kalkışı yazmayan listede gönderenin bilinen kalkışı (öğretilen ya da son 30 günün ilanlarından çıkan) kullanılır; her satır "bilgi eksik" rozetiyle yayınlanır. Bilinmiyorsa aday kuyrukta "Kalkış öğret" ile bekler.'],
        'template_memory' => ['kind' => 'çözüm', 'lifecycle' => 'permanent', 'label' => 'Şablon hafızası (gönderen kalıbı)', 'reasons' => ['template_not_load'],
            'desc' => 'Aynı numaranın daha önce doğrulanmış kalıbı yapay zekasız çözülür; "ilan değil" diye öğrenilmiş kalıp elenir.'],
        'rule_strong' => ['kind' => 'çözüm', 'lifecycle' => 'permanent', 'label' => 'Kesin kural kısayolu', 'reasons' => [],
            'desc' => 'İki il katalogda birebir, telefon ve açık araç adı varsa yapay zeka beklenmeden yayına gider (kota harcanmaz).'],
        'ai_not_load' => ['kind' => 'eleme', 'lifecycle' => 'permanent', 'label' => 'Yapay zeka "ilan değil" elemesi', 'reasons' => ['ai_not_load'],
            'desc' => 'Yapay zeka %80 ve üstü güvenle "yük ilanı değil" derse mesaj elenir ve gönderenin kalıbı öğrenilir.'],
    ];

    /** Yönetilen katmanların başlangıç aşaması (Settings::DEFAULTS'taki `intake_layer_stage_<anahtar>`). */
    public const STAGE_DEFAULTS = ['two_line_route' => self::STAGE_SHADOW, 'sender_pickup_memory' => self::STAGE_ACTIVE];

    public static function stageKey(string $layer): string
    {
        return 'intake_layer_stage_'.$layer;
    }

    public static function isManaged(string $layer): bool
    {
        return (self::LAYERS[$layer]['lifecycle'] ?? 'permanent') === 'managed';
    }

    /** Katmanın aşaması: kalıcı katman her zaman etkin; yönetilen katmanınki ayarda (sistem yazar). */
    public static function stage(string $layer): string
    {
        if (! self::isManaged($layer)) {
            return self::STAGE_ACTIVE;
        }
        $stage = Settings::string(self::stageKey($layer));

        return in_array($stage, [self::STAGE_ACTIVE, self::STAGE_SHADOW, self::STAGE_PAUSED], true) ? $stage : (self::STAGE_DEFAULTS[$layer] ?? self::STAGE_SHADOW);
    }

    /** Katman ilanı etkiler mi? (Gölge ve duraklatılmış katman yalnız izler.) */
    public static function enabled(string $layer): bool
    {
        return self::stage($layer) === self::STAGE_ACTIVE;
    }

    /** Katman gölgede mi (çalışır, örnek yazar, ilanı etkilemez)? */
    public static function isShadow(string $layer): bool
    {
        return self::isManaged($layer) && self::stage($layer) !== self::STAGE_ACTIVE;
    }

    /** Yalnız IntakeLayerReview (ve testler) çağırır: aşama elle değiştirilmez. */
    public static function setStage(string $layer, string $stage): void
    {
        Settings::set(self::stageKey($layer), $stage);
        Settings::set(self::stageKey($layer).'_changed_at', now()->toDateTimeString());
    }

    /** Son aşama değişimi (gölge değerlendirmesi yalnız bundan sonraki örneklere bakar). */
    public static function stageChangedAt(string $layer): ?Carbon
    {
        $v = Settings::string(self::stageKey($layer).'_changed_at');

        return $v !== '' ? Carbon::parse($v, config('app.timezone')) : null;
    }

    public static function stageLabel(string $layer): string
    {
        return self::isManaged($layer) ? (self::STAGE_LABELS[self::stage($layer)] ?? self::stage($layer)) : 'kalıcı';
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
