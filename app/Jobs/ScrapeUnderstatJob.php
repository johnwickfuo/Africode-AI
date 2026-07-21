<?php

namespace App\Jobs;

use App\Models\PipelineRun;
use App\Services\Understat\ImportUnderstatService;
use App\Support\ProcessOutput;
use App\Support\Python;
use App\Support\Seasons;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Nightly (04:00 Africa/Lagos): scrapes understat.com (plain HTTP, works
 * from any IP) for player match data and per-match team xG, then imports.
 * Roster responses are disk-cached by the script, so the historical
 * backfill drains a bounded batch per night, newest matches first, and
 * the app stays fully usable throughout.
 */
class ScrapeUnderstatJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(private ?int $batchSize = null)
    {
        $this->timeout = (int) config('africode.understat.timeout_seconds') + 300;
    }

    public function handle(ImportUnderstatService $import): void
    {
        PipelineRun::track('ScrapeUnderstatJob', function () use ($import) {
            $output = config('africode.understat.output_path');
            File::ensureDirectoryExists(dirname($output));

            $process = new Process(Python::command([
                config('africode.understat.script_path'),
                '--output', $output,
                '--seasons', ...(config('africode.fbref.seasons') ?? Seasons::tracked()),
                '--max-match-fetches', (string) ($this->batchSize ?? (int) config('africode.understat.batch_size')),
            ]));
            $process->setTimeout((int) config('africode.understat.timeout_seconds'));
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException(sprintf(
                    'Understat scrape exited with code %d: %s',
                    $process->getExitCode(),
                    ProcessOutput::tail($process),
                ));
            }

            return $import->run($output);
        });
    }
}
