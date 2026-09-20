<?php

namespace App\Console\Commands;

use App\Models\Faq;
use Database\Seeders\FaqSeeder;
use Illuminate\Console\Command;

/** SSS metinlerini koddaki güncel sürümle yeniler; --if-stale ile yalnız eski (cüzdan/havuz/bloke/escrow geçen) metin varsa. */
class RefreshFaqCommand extends Command
{
    protected $signature = 'faq:refresh {--if-stale : Yalnız ödeme kuruluşu kurallarına aykırı eski ifade içeren SSS varsa yenile}';

    protected $description = 'SSS metinlerini güncel seed ile yeniler';

    public const STALE_PATTERN = '/cüzdan|cuzdan|bakiye|bloke|escrow|güvenli havuz|havuz hesab|havuza (?:al|aktar)|bildirim kanalı vaat edilmez|standart üyelere açılmadan 20 dakika önce premium/iu';

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
        return Faq::query()->get()->contains(fn (Faq $faq) => preg_match(self::STALE_PATTERN, (string) $faq->answer.' '.(string) $faq->question) === 1);
    }
}
