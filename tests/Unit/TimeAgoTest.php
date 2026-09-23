<?php

namespace Tests\Unit;

use App\Support\TimeAgo;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class TimeAgoTest extends TestCase
{
    public function test_labels_are_coarse_and_turkish(): void
    {
        Carbon::setTestNow('2026-09-23 12:00:00');
        $this->assertSame('az önce', TimeAgo::label(now()->subSeconds(57)));
        $this->assertSame('1 dk önce', TimeAgo::label(now()->subSeconds(61)));
        $this->assertSame('45 dk önce', TimeAgo::label(now()->subMinutes(45)));
        $this->assertSame('3 sa önce', TimeAgo::label(now()->subHours(3)->subMinutes(10)));
        $this->assertSame('dün', TimeAgo::label(now()->subDay()->subHour()));
        $this->assertSame('6 gün önce', TimeAgo::label(now()->subDays(6)));
        $this->assertSame('01.08.2026', TimeAgo::label(now()->subDays(53)));
        $this->assertSame('3 gün sonra', TimeAgo::label(now()->addDays(3)->addHour()));
        $this->assertSame('birazdan', TimeAgo::label(now()->addSeconds(30)));
        $this->assertSame('2 sa önce', TimeAgo::label('2026-09-23 10:00:00'));
        $this->assertSame('Hiç', TimeAgo::label(null, 'Hiç'));
        $this->assertNull(TimeAgo::label(null));
        Carbon::setTestNow();
    }
}
