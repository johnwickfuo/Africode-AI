<?php

namespace Tests\Feature;

use App\Models\Rivalry;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RivalryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    private function team(string $name): Team
    {
        return Team::where('name', $name)->firstOrFail();
    }

    public function test_derby_pairs_match_in_both_directions_only(): void
    {
        $arsenal = $this->team('Arsenal');
        $spurs = $this->team('Tottenham Hotspur');

        $this->assertTrue(Rivalry::isDerbyPair($arsenal->id, $spurs->id));
        $this->assertTrue(Rivalry::isDerbyPair($spurs->id, $arsenal->id), 'order must not matter');
    }

    public function test_unrelated_pair_is_not_a_derby(): void
    {
        // Regression: array conditions passed to orWhere() were joined with
        // OR, so any club appearing in some rivalry made every one of its
        // fixtures a "derby" — inflating the cards model's derby uplift.
        $luton = $this->team('Luton Town');      // rival of Cardiff City
        $spurs = $this->team('Tottenham Hotspur'); // rival of Arsenal

        $this->assertFalse(Rivalry::isDerbyPair($luton->id, $spurs->id));
        $this->assertFalse(Rivalry::isDerbyPair($spurs->id, $luton->id));
    }

    public function test_cross_division_derbies_are_seeded(): void
    {
        // Sheffield United (Championship) and Wednesday, Bristol City
        // (Championship) and Rovers (League Two) — rivalries that span tiers.
        $this->assertTrue(Rivalry::isDerbyPair(
            $this->team('Sheffield United')->id,
            $this->team('Sheffield Wednesday')->id,
        ));
        $this->assertTrue(Rivalry::isDerbyPair(
            $this->team('Bristol City')->id,
            $this->team('Bristol Rovers')->id,
        ));
    }
}
