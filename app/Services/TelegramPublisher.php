<?php

namespace App\Services;

use App\Models\Load;
use App\Support\Settings;
use App\Support\VehicleTypes;
use Illuminate\Support\Facades\Http;

/**
 * Telegram kanalı: yalnız sistem ilanları (yük sahibi üyelerin açtığı ilanlar), ücretsiz üyelere açıldığı anda.
 * Dış kaynak ilanlar kanala gönderilmez. Bot anahtarı ve kanal kimliği panel ayarlarından okunur.
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

    /** Herkese açılan sistem ilanının kanal mesajı (rota, yük, araç, fiyat, tarih, siteye bağlantı). */
    public function messageForLoad(Load $load): string
    {
        $e = fn (?string $v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $lines = ['🚛 <b>'.$e($load->pickup_location).' → '.$e($load->delivery_location).'</b>'];

        $facts = [];
        if ($load->goods_type) {
            $facts[] = '📦 '.$e($load->goods_type);
        }
        if ((int) $load->weight > 0) {
            $facts[] = '⚖️ '.rtrim(rtrim(number_format((int) $load->weight / 1000, 1, ',', '.'), '0'), ',').' ton';
        }
        if ($load->vehicle_type) {
            $facts[] = '🚚 '.$e(VehicleTypes::label($load->vehicle_type));
        }
        if ($facts !== []) {
            $lines[] = implode(' · ', $facts);
        }
        if ((float) $load->price > 0) {
            $lines[] = '💰 '.number_format((float) $load->price, 0, ',', '.').' ₺ (teslimat onaylı güvenli ödeme)';
        }
        if ($load->pickup_date) {
            $lines[] = '📅 Yükleme: '.$load->pickup_date->format('d.m.Y');
        }
        $lines[] = '🏷️ NavlunIQ sistem ilanı · #'.$load->id.' · 🕒 '.optional($load->published_at)->format('d.m.Y H:i');

        $url = rtrim((string) config('app.url'), '/').'/panel/sofor/ilan-havuzu?ilan='.$load->id;
        $lines[] = '👉 <a href="'.$e($url).'">Teklif vermek için NavlunIQ\'ya gir</a>';

        return implode("\n", $lines);
    }

    /** Kanalın herkese açık bağlantısı (@kanal ise); özel kanalda null. */
    public static function channelUrl(): ?string
    {
        $id = Settings::string('telegram_channel_id');

        return str_starts_with($id, '@') && strlen($id) > 1 ? 'https://t.me/'.substr($id, 1) : null;
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
