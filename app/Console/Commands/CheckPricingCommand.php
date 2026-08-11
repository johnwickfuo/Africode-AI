<?php

namespace App\Console\Commands;

use App\Models\Fixture;
use App\Services\Odds\MarketPricing;
use Illuminate\Console\Command;

/**
 * Checks the odds estimator against reality.
 *
 * Most of what a ticket is built from — corners, cards, team goals, the
 * goal lines other than 2.5 — has no published price anywhere free, so it
 * is priced from the model's probability and an assumed margin. There is
 * no way to know whether that assumption is any good in those markets.
 *
 * But 1X2 and the 2.5-goals line ARE published. So price them as if they
 * were not, compare against what the books actually offer, and the error
 * on the markets we can see is the best available evidence about the ones
 * we cannot. It catches both halves of the problem at once: a margin set
 * too low, and a model whose probabilities are off.
 */
class CheckPricingCommand extends Command
{
    protected $signature = 'africode:check-pricing
        {--days=14 : How far back to look for priced fixtures}';

    protected $description = 'Compare estimated odds against the prices bookmakers actually published';

    public function handle(MarketPricing $pricing): int
    {
        $fixtures = Fixture::query()
            ->whereHas('odds')
            ->where('kickoff_utc', '>=', now('UTC')->subDays((int) $this->option('days')))
            ->with(['odds', 'predictions' => fn ($q) => $q->champion()->orderByDesc('generated_at'), 'predictions.markets'])
            ->get();

        /** @var array<string, list<float>> $ratios */
        $ratios = [];

        foreach ($fixtures as $fixture) {
            $prediction = $fixture->predictions->first();
            if ($prediction === null) {
                continue;
            }

            foreach ($prediction->markets as $market) {
                $real = $pricing->price(
                    $market->market, $market->line !== null ? (float) $market->line : null,
                    $market->direction, (float) $market->probability, $fixture->odds,
                );

                if (! $real['priced']) {
                    continue;
                }

                // The same pick priced as though nothing published it.
                $estimated = $pricing->price(
                    $market->market, $market->line !== null ? (float) $market->line : null,
                    $market->direction, (float) $market->probability, null,
                );

                $key = $market->market.($market->line !== null ? ' '.$market->line : '');
                $ratios[$key][] = $estimated['odds'] / $real['odds'];
            }
        }

        if ($ratios === []) {
            $this->warn('No fixtures with both a prediction and published odds in that window.');
            $this->line('Run africode:import-odds --now first, then generate predictions.');

            return self::SUCCESS;
        }

        $multiplier = (float) config('africode.odds.margin_multiplier', 1.0);
        $rows = [];
        $all = [];

        foreach ($ratios as $market => $values) {
            sort($values);
            $median = $values[intdiv(count($values), 2)];
            $all = array_merge($all, $values);
            $rows[] = [
                $market,
                count($values),
                sprintf('%+.1f%%', ($median - 1) * 100),
                $median > 1 ? 'we quote too long' : 'we quote too short',
            ];
        }

        $this->table(['Market', 'Picks', 'Estimate vs real', 'Meaning'], $rows);

        sort($all);
        $overall = $all[intdiv(count($all), 2)];

        $this->newLine();
        $this->line(sprintf('Overall the estimate is %+.1f%% against the published price.', ($overall - 1) * 100));

        // price = fair/(1+m). To move the estimate onto the real price the
        // whole margin has to scale by the ratio we are out by.
        $suggested = $multiplier * $overall;
        $this->line(sprintf(
            'ODDS_MARGIN_MULTIPLIER is %.2f; %.2f would line the estimate up with these books.',
            $multiplier,
            $suggested,
        ));
        $this->line('Your own bookmaker is likely wider still — compare a real slip before settling on a figure.');

        return self::SUCCESS;
    }
}
