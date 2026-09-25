<?php

namespace App\Jobs;

use App\Models\IntakeEvent;
use App\Services\LoadIntakeService;
use App\Services\NotificationIntakeParser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Bildirim iletici ile gelen tek bir grup mesajını ayrıştırma hattından geçirir ve sonucunu canlı akışa yazar.
 *
 * Telefon isteği bu işi kuyruğa bırakıp hemen döner; yapay zeka çağrısı ve tekrar kilidi (25 sn'ye kadar bekleme)
 * web sunucusunun sınırlı PHP işçilerini meşgul etmez. Kuyruk işçisi yoksa aynı iş istek içinde çalıştırılır.
 */
class ProcessNotificationMessage implements ShouldQueue
{
    use Queueable;

    /** Yapay zeka çağrısı tekrar edilmez; başarısız olursa canlı akışa "işlenemedi" düşer. */
    public int $tries = 1;

    public int $timeout = 150;

    /**
     * @param  array{text:string, phone:?string, sender?:?string}  $message
     */
    public function __construct(
        public readonly string $group,
        public readonly string $platform,
        public readonly array $message,
        public readonly ?string $title = null,
        public readonly ?string $ip = null,
    ) {}

    /** @return array{code:int, success:bool, message:string, status:string, scraped_load_id?:int, reason?:string, created_ids?:list<int>, segments?:list<array>} */
    public function handle(LoadIntakeService $intake): array
    {
        $result = $intake->intake([
            'group_name' => $this->group,
            'source_jid' => NotificationIntakeParser::sourceIdentifier($this->group, $this->platform),
            'source_type' => $this->platform === 'facebook' ? 'facebook' : 'notification',
            'raw_message' => $this->message['text'],
            'sender_phone' => $this->message['phone'] ?? null,
            // Aynı bildirimin tekrar teslimi için sabit kimlik; içerik aynıysa değişmez.
            'message_id' => substr(hash('sha256', $this->group.'|'.($this->message['sender'] ?? '').'|'.$this->message['text']), 0, 40),
        ]);
        // Mesaj birden çok ilan barındırıyorsa her ilan canlı akışta ayrı satır olur (kendi sonucu ve adayıyla).
        $parts = count($result['segments'] ?? []) > 1 ? $result['segments'] : [$result + ['excerpt' => $this->message['text']]];
        foreach ($parts as $part) {
            IntakeEvent::record($part['status'], ['source_name' => $this->group, 'title' => $this->title, 'excerpt' => $part['excerpt'] ?? $this->message['text'],
                'reason' => $part['reason'] ?? null, 'scraped_load_id' => $part['scraped_load_id'] ?? null, 'ip' => $this->ip]);
        }

        return $result;
    }

    public function failed(?Throwable $e): void
    {
        IntakeEvent::record('failed', ['source_name' => $this->group, 'title' => $this->title, 'excerpt' => $this->message['text'],
            'reason' => $e ? mb_substr(get_class($e).': '.$e->getMessage(), 0, 300) : 'unknown', 'ip' => $this->ip]);
    }
}
