<?php

namespace Database\Seeders;

use App\Jobs\RecalculateMetreTotals;
use App\Models\Metre;
use App\Models\MetreLine;
use App\Models\Reference;
use App\Models\SubReference;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fills one throwaway metre with enough lines to judge whether the grid actually stays
 * fluid, rather than assuming it does.
 *
 *   php artisan db:seed --class=MetreLineStressTestSeeder
 *
 * Idempotent: re-running replaces the test metre's lines instead of piling more on.
 */
class MetreLineStressTestSeeder extends Seeder
{
    private const LINE_COUNT = 250;

    /** Fixed so the metre keeps the same URL across runs. */
    private const METRE_ID = '5712E57E-5712-4E57-9E57-5712E57E5712';

    private const PROJECT_ID = '6EFAC292-A17A-4F4F-97E8-A2BE4200D11E';

    public function run(): void
    {
        $catalogue = $this->catalogue();

        $metre = Metre::updateOrCreate(
            ['id' => self::METRE_ID],
            [
                'project_id' => self::PROJECT_ID,
                'name' => 'Métré de charge ('.self::LINE_COUNT.' lignes)',
                'is_status_site_b' => true,
                'is_accepted_b' => true,
                'ind_project' => 1,
            ],
        );

        $metre->metreLines()->delete();

        // The observer queues a recalculation per saved line, which for 250 lines would mean
        // 250 dispatches for a result that is identical to one. Insert without events, then
        // recalculate once at the end.
        MetreLine::withoutEvents(function () use ($metre, $catalogue) {
            foreach (array_chunk($this->rows($metre, $catalogue), 50) as $chunk) {
                DB::table('metre_lines')->insert($chunk);
            }
        });

        RecalculateMetreTotals::dispatchSync($metre->refresh());

        $this->command?->info(sprintf(
            '%d lines on metre %s — grid at /metres/%s/lines',
            self::LINE_COUNT,
            $metre->name,
            $metre->id,
        ));
    }

    /**
     * @return array{references: Collection, subReferences: Collection}
     */
    private function catalogue(): array
    {
        // Enough catalogue entries for the dependent select to be worth exercising: several
        // references, each with its own sub-references.
        $references = collect(range(1, 6))->map(fn (int $i) => Reference::firstOrCreate(
            ['code' => 900 + $i],
            ['title_fr' => "Poste de charge {$i}", 'title_en' => "Stress item {$i}"],
        ));

        $subReferences = $references->flatMap(fn (Reference $reference) => collect(range(1, 5))
            ->map(fn (int $j) => SubReference::firstOrCreate(
                ['reference_id' => $reference->id, 'code' => $reference->code * 10 + $j],
                ['title_fr' => "Sous-poste {$reference->code}.{$j}"],
            )));

        return ['references' => $references, 'subReferences' => $subReferences];
    }

    /**
     * Built as plain arrays for a bulk insert: 250 Eloquent saves would spend most of their
     * time on model hydration that the grid never sees.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(Metre $metre, array $catalogue): array
    {
        $now = now();
        $units = ['m', 'm2', 'm3', 'pce', 'h', 'kg', 'ens'];
        $rows = [];

        for ($i = 1; $i <= self::LINE_COUNT; $i++) {
            $reference = $catalogue['references'][($i - 1) % $catalogue['references']->count()];
            $subReference = $catalogue['subReferences']
                ->where('reference_id', $reference->id)
                ->values()[($i - 1) % 5];

            // Deterministic but varied: every 7th line is an option (so its totals must read
            // 0), every 11th has no sub-reference, every 13th sells at a loss so the negative
            // margin styling gets exercised, and every 17th is "pour mémoire" - the unit is
            // not editable from the grid, so without these the pm rule could not be seen at
            // all (both quantity cells greyed out, every total at zero).
            $isPourMemoire = $i % 17 === 0;
            $isOption = $i % 7 === 0;
            $priceBuy = round(40 + ($i % 37) * 2.5, 2);
            $priceOrdered = round($priceBuy * 1.05, 2);
            $priceSales = $i % 13 === 0
                ? round($priceOrdered * 0.85, 2)
                : round($priceOrdered * 1.28, 2);

            $rows[] = [
                'id' => (string) Str::uuid(),
                'metre_id' => $metre->id,
                'reference_id' => $reference->id,
                'sub_reference_id' => $i % 11 === 0 ? null : $subReference->id,
                'description' => "Ligne de charge {$i} — ".Str::random(mt_rand(10, 40)),
                'sort_order' => $i,
                'sequence_number' => $i,
                'unit' => $isPourMemoire ? 'pm' : $units[$i % count($units)],
                // Null on a pm line: this bulk insert bypasses the observer, so the invariant
                // it enforces has to be honoured here by hand. Seeding a quantity behind a
                // greyed-out cell would render a state the application never produces, and
                // read as a bug rather than as the rule working.
                'quantity' => $isPourMemoire ? null : round(1 + ($i % 23) * 1.5, 4),
                'quantity_ordered' => $isPourMemoire ? null : round(1 + ($i % 19) * 1.5, 4),
                'price_sales' => $priceSales,
                'price_ordered' => $priceOrdered,
                'price_buy' => $priceBuy,
                'is_option_b' => $isOption,
                'is_locked_bae' => false,
                'is_imported_b' => false,
                'is_tender_line_b' => false,
                'is_estimated_price_b' => false,
                'is_delivered_b' => false,
                'metc_is_present_b' => false,
                'tender_supp1_omit_b' => false,
                'tender_supp2_omit_b' => false,
                'tender_supp3_omit_b' => false,
                'tender_supp4_omit_b' => false,
                'tender_supp5_omit_b' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }
}
