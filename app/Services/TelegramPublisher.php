<?php

namespace App\Services;

use App\Models\ScrapedLoad;
use App\Support\Settings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Ücretsiz üyelere açılan dış kaynak ilanlarını Telegram kanalına gönderir.
 * Bot anahtarı ve kanal kimliği yönetim panelindeki ayarlardan okunur.
 */
class TelegramPublisher
{
    public const MAX_ATTEMPTS = 5;

    public function isConfigured(): bool
    {
        return Settings::bool('telegram_post_enabled')
            && Settings::string('telegram_bot_token') !== ''
            && Settings::string('telegram_channel_id') !== '';
    }

    /** Sırası gelen ilanları gönderir; gönderilen sayısını döndürür. */
    public function publishDue(): int
    {
        if (! $this->isConfigured()) {
            return 0;
        }

        $sent = 0;
        ScrapedLoad::query()
            ->where('visibility', 'public')->where('status', '!=', 'rejected')
            ->whereNull('telegram_posted_at')
            ->where('telegram_attempts', '<', self::MAX_ATTEMPTS)
            ->whereNotNull('available_to_free_at')->where('available_to_free_at', '<=', now())
            ->where('created_at', '>=', now()->subDays(3))
            ->orderBy('available_to_free_at')->limit(20)->get()
            ->each(function (ScrapedLoad $load) use (&$sent): void {
                $load->increment('telegram_attempts');
                try {
                    $this->send($this->messageFor($load));
                    $load->update(['telegram_posted_at' => now()]);
                    $sent++;
                } catch (\Throwable $e) {
                    Log::warning('Telegram paylaşımı başarısız.', ['scraped_load_id' => $load->id, 'error' => $e->getMessage()]);
                }
            });

        return $sent;
    }

    public function messageFor(ScrapedLoad $load): string
    {
        $e = fn (?string $v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $lines = [($load->isUrgent() ? '🔴 <b>ACİL</b> ' : '').'🚛 <b>'.$e($load->routeLabel()).'</b>'];

        $facts = [];
        if ($load->goods_type) {
            $facts[] = '📦 '.$e($load->goods_type);
        }
        if ($w = $load->weightLabel()) {
            $facts[] = '⚖️ '.$w;
        }
        if ($v = $load->vehicleLabel()) {
            $facts[] = '🚚 '.$e($v);
        }
        foreach ($load->traitLabels() as $trait) {
            $facts[] = ($trait === 'Soğuk zincir' ? '❄️ ' : '⚠️ ').$e($trait);
        }
        if ($facts !== []) {
            $lines[] = implode(' · ', $facts);
        }
        if ((float) $load->price > 0) {
            $lines[] = '💰 '.number_format((float) $load->price, 0, ',', '.').' ₺';
        }
        if ($note = $load->meta('pickup_note')) {
            $lines[] = '📅 Yükleme: '.$e((string) $note);
        }

        $sources = (int) $load->duplicate_count;
        $lines[] = '📍 '.($sources > 1 ? "{$sources} kaynakta görüldü" : 'Dış kaynak ilanı').' · 🕒 '.optional($load->created_at)->format('d.m.Y H:i');

        $phone = Settings::bool('telegram_show_full_phone') ? $load->formatted_phone : $load->masked_phone;
        $lines[] = '📞 '.$e($phone);

        $url = rtrim((string) config('app.url'), '/').'/panel/sofor/ilan-havuzu?tab=external';
        $lines[] = '👉 <a href="'.$e($url).'">Tam numara ve tüm ilanlar NavlunIQ\'da</a>';

        return implode("\n", $lines);
    }

    /** @throws \RuntimeException */
    public function send(string $html): array
    {
        $token = Settings::string('telegram_bot_token');
        $response = Http::timeout(15)->asJson()->post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => Settings::string('telegram_channel_id'),
            'text' => $html,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]);

        if (! $response->successful() || ! ($response->json('ok') ?? false)) {
            throw new \RuntimeException('telegram_http_'.$response->status().': '.(string) $response->json('description', ''));
        }

        return (array) $response->json('result', []);
    }
}
