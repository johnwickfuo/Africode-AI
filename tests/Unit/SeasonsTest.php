<?php

namespace Tests\Unit;

use App\Support\Seasons;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class SeasonsTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_tracked_seasons_before_august_rollover(): void
    {
        Carbon::setTestNow('2026-07-10 12:00:00');

        $this->assertSame(['2324', '2425', '2526'], Seasons::tracked());
    }

    public function test_tracked_seasons_roll_over_in_august(): void
    {
        Carbon::setTestNow('2026-08-01 00:00:00');

        $this->assertSame(['2425', '2526', '2627'], Seasons::tracked());
    }

    public function test_year_boundary_stays_in_current_season(): void
    {
        Carbon::setTestNow('2027-01-15 12:00:00');

        $this->assertSame(['2425', '2526', '2627'], Seasons::tracked());
    }
}
