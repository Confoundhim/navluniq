<?php

namespace App\Payments;

use App\Payments\Contracts\PaymentGateway;
use App\Payments\Gateways\NullGateway;
use App\Payments\Gateways\PaytrGateway;

/**
 * Etkin ödeme kuruluşunu seçer. PAYMENT_PROVIDER ile seçilen sağlayıcı anahtarları girilmemişse
 * NullGateway döner; böylece hiçbir ödeme sahte "başarılı" olmaz.
 * Yeni sağlayıcı eklemek: adaptör yaz, REGISTRY'e ekle, .env'de PAYMENT_PROVIDER'ı değiştir.
 */
class GatewayManager
{
    /** @var array<string, class-string<PaymentGateway>> */
    public const REGISTRY = [
        'paytr' => PaytrGateway::class,
    ];

    /** @var array<string, PaymentGateway> */
    private array $instances = [];

    public function active(): PaymentGateway
    {
        $id = (string) config('services.payment.provider', 'paytr');
        $gateway = $this->gateway($id);

        return $gateway->isConfigured() ? $gateway : new NullGateway;
    }

    /** Anahtar girilmemiş olsa da seçilen sağlayıcıyı döndürür (durum ekranı için). */
    public function selected(): PaymentGateway
    {
        return $this->gateway((string) config('services.payment.provider', 'paytr'));
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
