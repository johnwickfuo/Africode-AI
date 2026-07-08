<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dashboard_renders_with_seeded_leagues(): void
    {
        $this->seed();

        $this->get('/')
            ->assertStatus(200)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard', false)
                ->count('leagues', 5)
                ->has('fixtures')
            );
    }
}
