<?php

namespace App\Payments;

use App\Payments\Contracts\PaymentGateway;
use App\Payments\Gateways\IyzicoGateway;
use App\Payments\Gateways\NullGateway;
use App\Payments\Gateways\PaytrGateway;
use App\Support\Settings;

/**
 * Etkin ödeme kuruluşunu seçer. PAYMENT_PROVIDER ile seçilen sağlayıcı anahtarları girilmemişse
 * NullGateway döner; böylece hiçbir ödeme sahte "başarılı" olmaz.
 * Sağlayıcı panelden (payment_provider ayarı) ya da .env PAYMENT_PROVIDER ile seçilir.
 * Yeni sağlayıcı eklemek: adaptör yaz, REGISTRY'e ekle.
 */
class GatewayManager
{
    /** @var array<string, class-string<PaymentGateway>> */
    public const REGISTRY = [
        'paytr' => PaytrGateway::class,
        'iyzico' => IyzicoGateway::class,
    ];

    public const LABELS = ['paytr' => 'PayTR', 'iyzico' => 'iyzico'];

    /** Seçili sağlayıcı kimliği: panel ayarı → .env → paytr. */
    public static function selectedId(): string
    {
        try {
            $panel = Settings::string('payment_provider');
        } catch (\Throwable) {
            $panel = '';
        }

        return $panel !== '' ? $panel : (string) config('services.payment.provider', 'iyzico');
    }

    /** @var array<string, PaymentGateway> */
    private array $instances = [];

    public function active(): PaymentGateway
    {
        $gateway = $this->gateway(self::selectedId());

        return $gateway->isConfigured() ? $gateway : new NullGateway;
    }

    /** Anahtar girilmemiş olsa da seçilen sağlayıcıyı döndürür (durum ekranı için). */
    public function selected(): PaymentGateway
    {
        return $this->gateway(self::selectedId());
    }

    public function gateway(string $id): PaymentGateway
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        $class = self::REGISTRY[$id] ?? null;

        return $this->instances[$id] = $class ? app($class) : new NullGateway;
    }

    /** Testlerde ya da özel kurulumda etkin geçidi değiştirmek için. */
    public function swap(string $id, PaymentGateway $gateway): void
    {
        $this->instances[$id] = $gateway;
    }
}
