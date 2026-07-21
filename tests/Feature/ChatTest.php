<?php

namespace Tests\Feature;

use App\Models\ChatLog;
use App\Models\Fixture;
use App\Models\MatchStat;
use App\Models\Prediction;
use App\Models\Team;
use App\Models\TeamProfile;
use App\Services\Chat\ChatToolbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        config(['africode.gemini.api_key' => 'test-key']);
        RateLimiter::clear('chat-minute:127.0.0.1');
        RateLimiter::clear('chat-day:127.0.0.1');
    }

    private function geminiText(string $text): array
    {
        return [
            'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => $text]]]]],
            'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 20, 'totalTokenCount' => 120],
        ];
    }

    private function geminiFunctionCall(string $name, array $args): array
    {
        return [
            'candidates' => [['content' => ['role' => 'model', 'parts' => [
                // Gemini 3+ attaches a signature that must be echoed back.
                ['functionCall' => ['name' => $name, 'args' => $args], 'thoughtSignature' => 'sig-123'],
            ]]]],
            'usageMetadata' => ['promptTokenCount' => 80, 'candidatesTokenCount' => 10, 'totalTokenCount' => 90],
        ];
    }

    public function test_simple_exchange_returns_reply_and_logs_it(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->geminiText('Hello! Ask me about fixtures.'))]);

        $this->postJson('/api/chat', ['message' => 'Hi'])
            ->assertOk()
            ->assertJson(['reply' => 'Hello! Ask me about fixtures.']);

        $log = ChatLog::first();
        $this->assertSame('Hi', $log->message);
        $this->assertSame('ok', $log->status);
        $this->assertSame(1, $log->gemini_requests);
        $this->assertSame(120, $log->total_tokens);
        $this->assertNull($log->tools_called);
    }

    public function test_tool_call_round_trip_executes_whitelisted_query(): void
    {
        $arsenal = Team::where('name', 'Arsenal')->first();
        TeamProfile::create([
            'team_id' => $arsenal->id, 'season' => '2025-2026', 'matches_played' => 21,
            'attack_strength' => 1.31, 'corners_for_avg' => 6.3,
        ]);

        Http::fakeSequence('generativelanguage.googleapis.com/*')
            ->push($this->geminiFunctionCall('get_team_stats', ['team' => 'Arsenal']))
            ->push($this->geminiText('Arsenal average 6.3 corners per match.'));

        $this->postJson('/api/chat', ['message' => 'How many corners do Arsenal win?'])
            ->assertOk()
            ->assertJson(['reply' => 'Arsenal average 6.3 corners per match.']);

        // Second request must echo the call (with its thought signature —
        // Gemini 3 rejects the round with a 400 otherwise) and carry the
        // tool result back.
        Http::assertSentCount(2);
        Http::assertSent(function ($request) {
            $turns = collect($request->data()['contents'] ?? []);

            $echoed = $turns->contains(fn ($turn) => collect($turn['parts'] ?? [])->contains(
                fn ($part) => ($part['functionCall']['name'] ?? null) === 'get_team_stats'
                    && ($part['thoughtSignature'] ?? null) === 'sig-123',
            ));
            $answered = $turns->contains(fn ($turn) => collect($turn['parts'] ?? [])->contains(
                fn ($part) => isset($part['functionResponse']['response']['result']),
            ));

            // Only the second request has both; assertSent needs one match.
            return $echoed && $answered;
        });

        $log = ChatLog::first();
        $this->assertSame(['get_team_stats'], $log->tools_called);
        $this->assertSame(2, $log->gemini_requests);
        $this->assertSame(210, $log->total_tokens);
    }

    public function test_tool_loop_stops_after_max_rounds(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                $this->geminiFunctionCall('get_fixtures', ['days' => 7]),
            ),
        ]);

        $response = $this->postJson('/api/chat', ['message' => 'Loop forever please']);

        $response->assertOk();
        Http::assertSentCount(5); // hard cap on tool rounds
        $this->assertSame(5, ChatLog::first()->gemini_requests);
        $this->assertNotSame('', $response->json('reply'));
    }

    public function test_conversation_history_is_kept_in_session_and_trimmed_to_ten(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->geminiText('Reply'))]);

        for ($i = 1; $i <= 7; $i++) {
            $this->postJson('/api/chat', ['message' => "Message {$i}"])->assertOk();
        }

        $history = session('chat_history');
        $this->assertCount(10, $history); // 7 user + 7 model turns, trimmed to 10
        $this->assertSame('Message 3', $history[0]['text']);

        // History is replayed to Gemini on the next call.
        Http::assertSent(function ($request) {
            $texts = collect($request->data()['contents'] ?? [])
                ->flatMap(fn ($turn) => collect($turn['parts'])->pluck('text'));

            return $texts->contains('Message 6') || ! $texts->contains('Message 7');
        });
    }

    public function test_input_guard_rejects_long_and_empty_messages_without_calling_gemini(): void
    {
        Http::fake();

        $this->postJson('/api/chat', ['message' => str_repeat('a', 501)])
            ->assertStatus(422)
            ->assertJsonPath('reply', fn ($reply) => str_contains($reply, '500'));

        $this->postJson('/api/chat', ['message' => '  '])
            ->assertStatus(422);

        Http::assertNothingSent();
        $this->assertSame(2, ChatLog::where('status', 'rejected')->count());
    }

    public function test_per_minute_rate_limit_returns_friendly_message(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->geminiText('ok'))]);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/chat', ['message' => 'hi'])->assertOk();
        }

        $this->postJson('/api/chat', ['message' => 'one too many'])
            ->assertStatus(429)
            ->assertJsonPath('reply', fn ($reply) => str_contains($reply, 'quickly'));

        $this->assertSame(1, ChatLog::where('status', 'limited')->count());
    }

    public function test_daily_limit_returns_try_again_tomorrow(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->geminiText('ok'))]);
        config(['africode.gemini.per_minute' => 100, 'africode.gemini.per_day' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/chat', ['message' => 'hi'])->assertOk();
        }

        $this->postJson('/api/chat', ['message' => 'again'])
            ->assertStatus(429)
            ->assertJson(['reply' => "You've reached today's chat limit, try again tomorrow."]);
    }

    public function test_global_daily_cap_blocks_without_calling_gemini(): void
    {
        Http::fake();
        config(['africode.gemini.daily_cap' => 5]);
        Cache::put('gemini-quota:'.now('Africa/Lagos')->toDateString(), 5, 3600);

        $this->postJson('/api/chat', ['message' => 'hello?'])
            ->assertStatus(429)
            ->assertJsonPath('reply', fn ($reply) => str_contains($reply, 'resting'));

        Http::assertNothingSent();
        $this->assertSame('capped', ChatLog::first()->status);
    }

    public function test_gemini_requests_count_against_the_global_cap(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->geminiText('ok'))]);

        $this->postJson('/api/chat', ['message' => 'hi'])->assertOk();
        $this->postJson('/api/chat', ['message' => 'hi again'])->assertOk();

        $this->assertSame(2, Cache::get('gemini-quota:'.now('Africa/Lagos')->toDateString()));
    }

    public function test_gemini_error_returns_friendly_reply_and_logs_error(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => 'boom'], 500)]);

        $this->postJson('/api/chat', ['message' => 'hi'])
            ->assertOk()
            ->assertJsonPath('reply', fn ($reply) => str_contains($reply, 'wrong'));

        $this->assertSame('error', ChatLog::first()->status);
        $this->assertEmpty(session('chat_history')); // failed turns don't pollute history
    }

    public function test_overloaded_primary_model_falls_back_and_answers(): void
    {
        Sleep::fake(); // skip retry backoff delays

        // Primary model 503s through all 3 attempts, fallback then answers.
        Http::fakeSequence('generativelanguage.googleapis.com/*')
            ->pushStatus(503)->pushStatus(503)->pushStatus(503)
            ->push($this->geminiText('Hello from the fallback.'));

        $this->postJson('/api/chat', ['message' => 'hi'])
            ->assertOk()
            ->assertJson(['reply' => 'Hello from the fallback.']);

        Http::assertSentCount(4);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'gemini-flash-lite-latest:generateContent'));
        $this->assertSame('ok', ChatLog::first()->status);
    }

    public function test_timed_out_primary_model_falls_back_immediately(): void
    {
        $calls = 0;
        Http::fake(function ($request) use (&$calls) {
            if (++$calls === 1) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return Http::response($this->geminiText('Fallback answer.'));
        });

        $this->postJson('/api/chat', ['message' => 'hi'])
            ->assertOk()
            ->assertJson(['reply' => 'Fallback answer.']);

        $this->assertSame(2, $calls); // no retries on the stalled model
        Http::assertSent(fn ($request) => str_contains($request->url(), 'gemini-flash-lite-latest:generateContent'));
    }

    public function test_persistent_503_returns_busy_reply(): void
    {
        Sleep::fake();

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => 'overloaded'], 503)]);

        $this->postJson('/api/chat', ['message' => 'hi'])
            ->assertOk()
            ->assertJsonPath('reply', fn ($reply) => str_contains($reply, 'busy'));

        Http::assertSentCount(6); // 3 attempts on the primary + 3 on the fallback
        $this->assertSame('error', ChatLog::first()->status);
    }

    public function test_toolbox_answers_queries_from_seeded_data(): void
    {
        $toolbox = app(ChatToolbox::class);

        $arsenal = Team::where('name', 'Arsenal')->first();
        $spurs = Team::where('name', 'Tottenham Hotspur')->first();

        $fixture = Fixture::create([
            'league_id' => $arsenal->league_id, 'season' => '2025-2026', 'matchday' => 22,
            'home_team_id' => $arsenal->id, 'away_team_id' => $spurs->id,
            'kickoff_utc' => now('UTC')->addDays(2), 'status' => 'scheduled', 'is_derby' => true,
        ]);
        Prediction::create([
            'fixture_id' => $fixture->id, 'generated_at' => now(), 'model_version' => 'v1.0.0',
            'best_bet_market' => 'corners', 'best_bet_line' => 9.5, 'best_bet_direction' => 'over',
            'best_bet_probability' => 0.78, 'headline_text' => 'Over 9.5 corners — 78%',
        ]);

        // Season totals summed from finished fixtures + match stats.
        $finished = Fixture::create([
            'league_id' => $arsenal->league_id, 'season' => '2025-2026',
            'home_team_id' => $arsenal->id, 'away_team_id' => $spurs->id,
            'kickoff_utc' => now('UTC')->subDays(3), 'status' => 'finished',
            'home_goals' => 3, 'away_goals' => 1,
        ]);
        MatchStat::create([
            'fixture_id' => $finished->id, 'team_id' => $arsenal->id, 'is_home' => true,
            'goals' => 3, 'corners_for' => 7, 'yellows' => 2, 'shots_on_target' => 6,
            'xg' => 2.4, 'xga' => 0.8, 'source' => 'fdcouk',
        ]);
        $teamStats = $toolbox->execute('get_team_stats', ['team' => 'Arsenal', 'season' => '2025-2026']);
        $this->assertSame(1, $teamStats['season_totals']['played']);
        $this->assertSame(1, $teamStats['season_totals']['wins']);
        $this->assertSame(3, $teamStats['season_totals']['goals_for']);
        $this->assertSame(1, $teamStats['season_totals']['goals_against']);
        $this->assertSame(2.4, $teamStats['season_totals']['xg_for']);
        $this->assertSame(7, $teamStats['season_totals']['corners_for']);

        // Fuzzy team match + fixtures with best bet.
        $fixtures = $toolbox->execute('get_fixtures', ['team' => 'arsen', 'days' => 7]);
        $this->assertSame('Over 9.5 corners — 78%', $fixtures['fixtures'][0]['best_bet']);
        $this->assertTrue($fixtures['fixtures'][0]['derby']);

        // Prediction lookup by team pair.
        $prediction = $toolbox->execute('get_predictions', ['team' => 'Arsenal', 'opponent' => 'Tottenham']);
        $this->assertSame('Over 9.5 corners — 78%', $prediction['best_bet']);

        // Unknown team is a graceful error, not an exception.
        $this->assertArrayHasKey('error', $toolbox->execute('get_team_stats', ['team' => 'Wrexham']));

        // Parameter validation blocks out-of-range values.
        $this->assertArrayHasKey('error', $toolbox->execute('get_fixtures', ['days' => 99]));
        $this->assertArrayHasKey('error', $toolbox->execute('get_team_stats', ['team' => str_repeat('x', 80)]));

        // Unknown tool name refused.
        $this->assertArrayHasKey('error', $toolbox->execute('drop_table', []));
    }
}
