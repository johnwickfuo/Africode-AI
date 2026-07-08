<?php

namespace App\Http\Middleware;

use App\Models\PipelineRun;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'appName' => config('app.name'),
            // Data-freshness stamps for the footer (spec 3.3: the app keeps
            // serving last-good data, so the UI always says how old it is).
            'pipeline' => fn () => [
                'fixtures_as_of' => $this->lastSuccess('SyncFixturesJob'),
                'stats_as_of' => $this->lastSuccess('ImportFbrefDataJob'),
                'predictions_as_of' => $this->lastSuccess('GeneratePredictionsJob'),
            ],
        ];
    }

    private function lastSuccess(string $jobName): ?string
    {
        return PipelineRun::lastSuccessfulRun($jobName)
            ?->finished_at
            ?->timezone(config('africode.display_timezone'))
            ->isoFormat('D MMM, HH:mm');
    }
}
