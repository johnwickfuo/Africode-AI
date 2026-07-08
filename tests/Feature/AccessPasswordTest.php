<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessPasswordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_app_is_open_when_no_password_is_configured(): void
    {
        config(['africode.access_password' => null]);

        $this->get('/')->assertOk();
    }

    public function test_password_gate_challenges_and_accepts_basic_auth(): void
    {
        config(['africode.access_password' => 'top-secret']);

        $this->get('/')
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate');

        // Wrong password still blocked.
        $this->withHeaders(['Authorization' => 'Basic '.base64_encode('john:nope')])
            ->get('/')
            ->assertStatus(401);

        // Any username with the right password gets in.
        $this->withHeaders(['Authorization' => 'Basic '.base64_encode('john:top-secret')])
            ->get('/')
            ->assertOk();
    }
}
