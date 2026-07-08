<?php

namespace Tests\Feature;

use App\Jobs\ScrapeFbrefJob;
use App\Models\PipelineRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exercises ScrapeFbrefJob's Process handling with stub python scripts —
 * the real soccerdata scrape is exercised on the VPS, not in CI.
 */
class FbrefScrapeJobTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/fbref_scrape_'.uniqid();
        mkdir($this->dir);
        config([
            'africode.fbref.output_path' => $this->dir.'/fbref_latest.json',
            'africode.fbref.scrape_timeout_seconds' => 30,
        ]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);

        parent::tearDown();
    }

    private function useStubScript(string $python): void
    {
        $path = $this->dir.'/stub.py';
        file_put_contents($path, $python);
        config(['africode.fbref.script_path' => $path]);
    }

    public function test_successful_scrape_records_pipeline_success(): void
    {
        // Stub honouring the real contract: --output/--seasons args, writes JSON.
        $this->useStubScript(<<<'PY'
            import argparse, json
            p = argparse.ArgumentParser()
            p.add_argument("--output", required=True)
            p.add_argument("--seasons", nargs="+")
            a = p.parse_args()
            with open(a.output, "w") as f:
                json.dump({"matches": [], "seasons": a.seasons}, f)
            PY);

        ScrapeFbrefJob::dispatchSync();

        $this->assertFileExists($this->dir.'/fbref_latest.json');
        $payload = json_decode(file_get_contents($this->dir.'/fbref_latest.json'), true);
        $this->assertSame(['2324', '2425', '2526'], $payload['seasons']);
        $this->assertSame(PipelineRun::STATUS_SUCCESS, PipelineRun::latest('id')->first()->status);
    }

    public function test_failed_scrape_records_pipeline_failure_with_stderr(): void
    {
        $this->useStubScript(<<<'PY'
            import sys
            print("FBref layout changed: table not found", file=sys.stderr)
            sys.exit(1)
            PY);

        $this->expectException(\RuntimeException::class);

        try {
            ScrapeFbrefJob::dispatchSync();
        } finally {
            $run = PipelineRun::latest('id')->first();
            $this->assertSame(PipelineRun::STATUS_FAILED, $run->status);
            $this->assertStringContainsString('FBref layout changed', $run->error);
        }
    }
}
