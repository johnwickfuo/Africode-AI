<?php

namespace App\Services\FootballData;

use DateTimeInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * Thin client for the football-data.org v4 API (free tier).
 *
 * Free tier allows 10 requests/minute; per the spec we sleep 7 seconds
 * between consecutive requests regardless, and retry transient failures
 * with exponential backoff. Must only ever be used from queued jobs —
 * never in the HTTP request lifecycle.
 */
class FootballDataClient
{
    private bool $throttleNextRequest = false;

    /**
     * All matches for a competition inside a date window (fixtures and results).
     *
     * @return list<array<string, mixed>>
     */
    public function competitionMatches(string $competitionCode, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $response = $this->get("competitions/{$competitionCode}/matches", [
            'dateFrom' => $from->format('Y-m-d'),
            'dateTo' => $to->format('Y-m-d'),
        ]);

        return $response['matches'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        $token = config('africode.footballdata.token');

        if (blank($token)) {
            throw new RuntimeException(
                'FOOTBALLDATA_TOKEN is not set. Register at https://www.football-data.org/client/register and add it to .env.'
            );
        }

        if ($this->throttleNextRequest) {
            Sleep::for((int) config('africode.footballdata.request_sleep_seconds', 7))->seconds();
        }
        $this->throttleNextRequest = true;

        return Http::baseUrl(config('africode.footballdata.base_url'))
            ->withHeaders(['X-Auth-Token' => $token])
            ->timeout(30)
            ->retry(3, fn (int $attempt) => $attempt ** 2 * 1000, throw: true)
            ->get($path, $query)
            ->throw()
            ->json() ?? [];
    }
}
