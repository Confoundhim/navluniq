<?php

namespace Tests\Feature\Ops;

use App\Jobs\QueueHeartbeat;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Tests\TestCase;

/** I4: kuyruk yeniden deneme süresi iş zaman aşımından uzun; nabız işi tekil; işçi şablonu --tries zorlamaz. */
class QueueConfigTest extends TestCase
{
    public function test_database_queue_retry_after_exceeds_job_timeout(): void
    {
        $this->assertSame(180, config('queue.connections.database.retry_after'));
        $this->assertGreaterThan(120, config('queue.connections.database.retry_after'));
    }

    public function test_queue_heartbeat_job_is_unique(): void
    {
        $job = new QueueHeartbeat;
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame(55, $job->uniqueFor);
        $this->assertLessThan(60, $job->uniqueFor);
    }

    public function test_supervisor_template_does_not_force_tries(): void
    {
        $script = file_get_contents(base_path('deploy/install.sh'));
        $this->assertStringContainsString('queue:work ${QUEUE_CONN}', $script);
        $this->assertStringNotContainsString('--tries=3', $script);
    }
}
