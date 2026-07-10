<?php

namespace App\Jobs;

use App\Models\PipelineRun;
use App\Support\Seasons;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Nightly (02:00 Africa/Lagos): runs scripts/fbref_scrape.py, which pulls the
 * Big-5 schedule + team match logs for the tracked seasons and atomically
 * writes storage/app/pipeline/fbref_latest.json.
 *
 * A failed scrape is non-fatal to the app: the previous JSON stays in place,
 * predictions keep flowing from the last good data, and the failure is
 * recorded in pipeline_runs (surfaced in the UI freshness stamp).
 */
class ScrapeFbrefJob implements ShouldQueue
{
    use Queueable;

    // The script retries each FBref read internally with backoff; re-running
    // the whole scrape on the queue would just hammer FBref again tonight.
    public int $tries = 1;

    public int $timeout;

    public function __construct()
    {
        $this->timeout = (int) config('africode.fbref.scrape_timeout_seconds') + 300;
    }

    public function handle(): void
    {
        PipelineRun::track('ScrapeFbrefJob', function () {
            $output = config('africode.fbref.output_path');
            File::ensureDirectoryExists(dirname($output));

            $process = new Process(\App\Support\Python::command([
                config('africode.fbref.script_path'),
                '--output', $output,
                '--seasons', ...(config('africode.fbref.seasons') ?? Seasons::tracked()),
                ...(filled(config('africode.fbref.proxy')) ? ['--proxy', config('africode.fbref.proxy')] : []),
            ], withDisplay: true));
            $process->setTimeout((int) config('africode.fbref.scrape_timeout_seconds'));
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException(sprintf(
                    'FBref scrape exited with code %d: %s',
                    $process->getExitCode(),
                    \App\Support\ProcessOutput::tail($process),
                ));
            }

            Log::info('FBref scrape finished', [
                'output' => $output,
                'stderr_tail' => Str::limit(trim($process->getErrorOutput()), 500),
            ]);

            return ['output' => $output];
        });
    }
}
