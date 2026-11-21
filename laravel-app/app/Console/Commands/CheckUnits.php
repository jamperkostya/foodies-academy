<?php

namespace App\Console\Commands;

use App\Services\Translations;
use App\Support\Units;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Units of ingredients in published recipes that config/units.php doesn't know: a typo to fix in
 * the recipe, a quantity written in the unit field ("1/2", "1 пучок"), or a new unit to add there.
 * Without a code a unit can't be converted for readers abroad.
 */
class CheckUnits extends Command
{
    protected $signature = 'units:check';

    protected $description = 'List ingredient units of published recipes missing from config/units.php';

    public function handle(): int
    {
        $rows = DB::connection('site')->table('postmeta as m')
            ->join('posts as p', 'p.ID', '=', 'm.post_id')
            ->where('p.post_type', 'recipe')
            ->where('p.post_status', 'publish')
            // Russian recipes: a translation's units are the Russian recipe's.
            ->whereNotIn('p.ID', app(Translations::class)->translatedIds())
            ->where('m.meta_key', 'recipe_ingredients')
            ->get(['p.ID', 'm.meta_value']);

        $unknown = [];
        foreach ($rows as $row) {
            $items = @unserialize((string) $row->meta_value, ['allowed_classes' => false]);
            foreach (is_array($items) ? $items : [] as $item) {
                $unit = trim((string) ($item['unit'] ?? ''));
                if (is_array($item) && trim($item['ingredient'] ?? '') !== '' && Units::code($unit) === null) {
                    $unknown[$unit][] = (int) $row->ID;
                }
            }
        }

        if (! $unknown) {
            $this->info("All units of {$rows->count()} recipes are known.");

            return self::SUCCESS;
        }
        uasort($unknown, fn ($a, $b) => count($b) <=> count($a));
        $this->table(['Unit', 'Times', 'Recipes (ID)'], collect($unknown)->map(fn ($ids, $unit) => [
            $unit, count($ids), implode(', ', array_slice(array_unique($ids), 0, 10)),
        ])->values()->all());
        $this->warn(count($unknown).' unknown units: fix them in the recipes or add them to config/units.php.');

        return self::SUCCESS;
    }
}
