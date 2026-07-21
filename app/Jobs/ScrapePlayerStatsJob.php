<?php

namespace App\Jobs;

use App\Models\Fixture;
use App\Models\PipelineRun;
use App\Models\PlayerScrapeProgress;
use App\Services\Fbref\ImportPlayerStatsService;
use App\Support\ProcessOutput;
use App\Support\Python;
use App\Support\Seasons;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Nightly (04:00 Africa/Lagos) resumable player-stats scrape.
 *
 * Each run: (1) enqueues progress rows for finished fixtures that gained an
 * fbref_game_id since last night — which is how newly played matches join
 * the queue automatically; (2) takes one batch, newest kickoffs first, so
 * current-season data becomes useful immediately while the 3-season
 * backfill drains over successive nights; (3) scrapes the batch via
 * scripts/fbref_scrape_players.py and imports it, marking each fixture
 * done or failed (retried up to 3 attempts). The app is fully functional
 * throughout — player features simply cover what has landed so far.
 */
class ScrapePlayerStatsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(private ?int $batchSize = null)
    {
        $this->timeout = (int) config('africode.fbref.player_scrape_timeout_seconds') + 300;
    }

    public function handle(ImportPlayerStatsService $import): void
    {
        PipelineRun::track('ScrapePlayerStatsJob', function () use ($import) {
            $enqueued = $this->discoverWork();

            $batch = PlayerScrapeProgress::query()
                ->where(fn ($query) => $query
                    ->where('player_scrape_progress.status', PlayerScrapeProgress::STATUS_PENDING)
                    ->orWhere(fn ($retry) => $retry
                        ->where('player_scrape_progress.status', PlayerScrapeProgress::STATUS_FAILED)
                        ->where('player_scrape_progress.attempts', '<', PlayerScrapeProgress::MAX_ATTEMPTS)))
                ->join('fixtures', 'fixtures.id', '=', 'player_scrape_progress.fixture_id')
                ->orderByDesc('fixtures.kickoff_utc')
                ->limit($this->batchSize ?? (int) config('africode.fbref.player_batch_size'))
                ->get(['player_scrape_progress.*', 'fixtures.fbref_game_id as game_id']);

            if ($batch->isEmpty()) {
                return ['message' => 'player backfill complete', 'enqueued' => $enqueued, 'remaining' => 0];
            }

            $result = $import->run($this->scrapeBatch($batch->pluck('game_id')->all()));

            foreach ($batch as $progress) {
                if (in_array($progress->game_id, $result['imported_game_ids'], true)) {
                    $progress->update([
                        'status' => PlayerScrapeProgress::STATUS_DONE,
                        'scraped_at' => now(),
                        'last_error' => null,
                    ]);
                } else {
                    $progress->update([
                        'status' => PlayerScrapeProgress::STATUS_FAILED,
                        'attempts' => $progress->attempts + 1,
                        'last_error' => Str::limit($result['failed'][$progress->game_id] ?? 'not in scrape output', 500),
                    ]);
                }
            }

            return [
                'enqueued' => $enqueued,
                'batch' => $batch->count(),
                'imported' => count($result['imported_game_ids']),
                'players_created' => $result['players_created'],
                'stats_rows' => $result['stats_rows'],
                'remaining' => PlayerScrapeProgress::where('status', PlayerScrapeProgress::STATUS_PENDING)->count(),
            ];
        });
    }

    /**
     * Add progress rows for finished fixtures not yet in the queue.
     */
    private function discoverWork(): int
    {
        $missing = Fixture::query()
            ->finished()
            ->whereNotNull('fbref_game_id')
            ->whereNotIn('id', PlayerScrapeProgress::select('fixture_id'))
            ->pluck('id');

        $now = now();
        PlayerScrapeProgress::insert($missing->map(fn (int $fixtureId) => [
            'fixture_id' => $fixtureId,
            'status' => PlayerScrapeProgress::STATUS_PENDING,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());

        return $missing->count();
    }

    /**
     * Run the python scraper for a batch of game ids; returns the JSON path.
     *
     * @param  list<string>  $gameIds
     */
    private function scrapeBatch(array $gameIds): string
    {
        $output = config('africode.fbref.player_output_path');
        File::ensureDirectoryExists(dirname($output));

        $process = new Process(Python::command([
            config('africode.fbref.player_script_path'),
            '--output', $output,
            '--seasons', ...(config('africode.fbref.seasons') ?? Seasons::tracked()),
            ...(filled(config('africode.fbref.proxy')) ? ['--proxy', config('africode.fbref.proxy')] : []),
            '--match-ids', ...$gameIds,
        ], withDisplay: true));
        $process->setTimeout((int) config('africode.fbref.player_scrape_timeout_seconds'));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                'Player scrape exited with code %d: %s',
                $process->getExitCode(),
                ProcessOutput::tail($process),
            ));
        }

        return $output;
    }
}
