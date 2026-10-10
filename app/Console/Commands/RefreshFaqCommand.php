<?php

namespace App\Console\Commands;

use App\Models\Faq;
use App\Support\FreightPayment;
use Database\Seeders\FaqSeeder;
use Illuminate\Console\Command;

/** SSS metinlerini koddaki güncel sürümle yeniler; --if-stale ile yalnız eski (cüzdan/havuz/bloke/escrow geçen) metin varsa. */
class RefreshFaqCommand extends Command
{
    protected $signature = 'faq:refresh {--if-stale : Yalnız ödeme kuruluşu kurallarına aykırı eski ifade içeren SSS varsa yenile}';

    protected $description = 'SSS metinlerini güncel seed ile yeniler';

    public const STALE_PATTERN = '/cüzdan|cuzdan|bakiye|bloke|escrow|güvenli havuz|havuz hesab|havuza (?:al|aktar)|bildirim kanalı vaat edilmez|standart üyelere açılmadan 20 dakika önce premium|numara kısmen gizlenir|onaylı dış kaynak ilanları önce premium/iu';

    /**
     * Navlun ödeme yolu kipine göre eskiyen ifadeler (2026-10-10): doğrudan kipte "lisanslı ödeme kuruluşu üzerinden navlun / kayıtlı IBAN /
     * hizmet bedeli düşülerek" cümleleri, platform kipinde "navlun şoförle doğrudan ödenir" cümleleri eski sayılır.
     */
    public const DIRECT_STALE_PATTERN = '/kayıtlı IBAN|hizmet bedeli düşülerek|ödeme kuruluşu iyzico üzerinden|teslimat onayına kadar/iu';

    public const PLATFORM_STALE_PATTERN = '/doğrudan ödenir|NavlunIQ tahsilat yapmaz|komisyon almaz/iu';

    public function handle(): int
    {
        if ($this->option('if-stale') && ! self::isStale()) {
            $this->info('SSS metinleri güncel; değişiklik yok.');

            return self::SUCCESS;
        }

        (new FaqSeeder)->run();
        $this->info('SSS metinleri güncel seed ile yenilendi.');

        return self::SUCCESS;
    }

    public static function isStale(): bool
    {
        $modePattern = FreightPayment::direct() ? self::DIRECT_STALE_PATTERN : self::PLATFORM_STALE_PATTERN;

        return Faq::query()->get()->contains(fn (Faq $faq) => preg_match(self::STALE_PATTERN, (string) $faq->answer.' '.(string) $faq->question) === 1
            || preg_match($modePattern, (string) $faq->answer.' '.(string) $faq->question) === 1);
    }
}
