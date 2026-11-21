<?php

namespace App\Console\Commands;

use App\Services\Translations;
use App\Support\Units;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Fixes amounts and units of Russian recipes (WP Ultimate Recipe "recipe_ingredients") that the
 * site can't read: typos in units ("шепотка", "CV" typed with the English layout for "см"), an
 * amount in the unit field ("1 пучок") or a unit in the amount field ("100 мл"), "1?2" for "1/2"
 * (a lost "½"), cloves of garlic written as "дольки", typos in group names. Translations are left alone: their amounts
 * are the Russian recipe's. Safe to re-run; --dry-run lists the changes.
 */
class FixRecipeIngredients extends Command
{
    protected $signature = 'recipes:fix-ingredients {--dry-run}';

    protected $description = 'Fix typos and mixed-up fields in ingredient amounts and units of Russian recipes';

    /** Misspelt units, compared as Units::normalize() writes them, and the right spelling. */
    private const TYPOS = [
        'чтл' => 'ч.л.', 'чд' => 'ч.л.', 'тл' => 'ч.л.',
        'стд' => 'ст.л.', 'стле' => 'ст.л.',
        'шепотка' => 'щепотка', 'щепогтка' => 'щепотка',
        'зучика' => 'зубчика', 'зубика' => 'зубчика',
        'гол' => 'головки', 'бут' => 'бутылка', 'качан' => 'кочан',
        'ст' => 'стакана', 'сткана' => 'стакана', 'чашкаи' => 'чашки',
        'учок' => 'пучок',
        'покусу' => 'по вкусу', 'плвкусу' => 'по вкусу', 'поукусу' => 'по вкусу', 'повкучу' => 'по вкусу', 'повксу' => 'по вкусу',
        // Typed with the English keyboard layout: "с" and "м" are the keys of "c" and "v".
        'сv' => 'см',
        'долькипо0,5мм' => 'ломтика',
    ];

    /** Rows no rule can guess: [recipe slug, ingredient, field => value]. */
    private const ROWS = [
        // "1/2 1/2": the unit was lost; the translation says teaspoons.
        ['fo-bo-s-sousom-nuk-cham', 'Перец черный молотый', 'unit' => 'ч.л.'],
        ['fo-bo-s-sousom-nuk-cham', 'Перец белый молотый', 'unit' => 'ч.л.'],
        ['fo-bo-s-sousom-nuk-cham', 'Кинза свежая', 'unit' => 'веточек'],
        ['vetnamskie-zharenye-nemy', 'Соль', 'unit' => 'ч.л.'],
        // The name typed in the amount field.
        ['ostryj-salat-s-teljatinoj-yum-neua-yang', 'Листья базилика', 'amount' => ''],
    ];

    /** Misspelt words of ingredient group names. */
    private const GROUP_TYPOS = ['НЕОБЯЗАТЕЛНАЯ' => 'НЕОБЯЗАТЕЛЬНАЯ', 'МИНИМУМ 4 ИХ' => 'МИНИМУМ 4 ИЗ'];

    /** Spelling a vague amount takes from the unit field: "пару веточек" → "пару" + "веточек". */
    private const VAGUE = ['пару', 'несколько'];

    private ConnectionInterface $db;

    private array $rowFixes = [];

    public function handle(): int
    {
        $this->db = DB::connection('site');
        $dry = (bool) $this->option('dry-run');
        foreach (self::ROWS as $row) {
            $this->rowFixes[$row[0]][mb_strtolower($row[1])] = array_slice($row, 2, null, true);
        }

        $translations = app(Translations::class)->translatedIds();
        $rows = $this->db->table('postmeta as m')
            ->join('posts as p', 'p.ID', '=', 'm.post_id')
            ->where('p.post_type', 'recipe')
            ->whereNotIn('p.ID', $translations)
            ->where('m.meta_key', 'recipe_ingredients')
            ->get(['m.meta_id', 'p.ID', 'p.post_name', 'm.meta_value']);

        $changed = 0;
        foreach ($rows as $row) {
            $items = @unserialize((string) $row->meta_value, ['allowed_classes' => false]);
            if (! is_array($items)) {
                continue;
            }
            $fixed = $items;
            foreach ($items as $k => $item) {
                if (is_array($item) && trim($item['ingredient'] ?? '') !== '') {
                    $fixed[$k] = $this->fix($item, $row->post_name);
                    if ($fixed[$k] !== $item) {
                        $changed++;
                        $this->line("{$row->ID} {$row->post_name}: «{$item['amount']}|{$item['unit']}|{$item['ingredient']}» → «{$fixed[$k]['amount']}|{$fixed[$k]['unit']}»");
                    }
                }
            }
            if ($fixed !== $items && ! $dry) {
                $this->db->table('postmeta')->where('meta_id', $row->meta_id)->update(['meta_value' => serialize($fixed)]);
            }
        }

        $this->info(($dry ? 'Would fix ' : 'Fixed ')."$changed ingredients.");
        if (! $dry && $changed) {
            $this->call('cache:clear');
        }

        return self::SUCCESS;
    }

    private function fix(array $item, string $slug): array
    {
        $amount = trim((string) ($item['amount'] ?? ''));
        $unit = trim(preg_replace('/\s+/u', ' ', (string) ($item['unit'] ?? '')));
        $amount = preg_replace('/(\d)\s*\?\s*(\d)/', '$1/$2', $amount);

        // "100 мл" in the amount field, nothing in the unit's.
        if ($unit === '' && preg_match('/^([\d\/.,\-\s]*\d)\s*(\D.*)$/u', $amount, $m) && $this->known($m[2])) {
            [$amount, $unit] = [trim($m[1]), trim($m[2])];
        }
        if ($unit === '' && Units::code($amount) === 'to_taste') {
            [$amount, $unit] = ['', $amount];
        }
        // "1 пучок", "пару веточек" in the unit field, nothing in the amount's.
        if ($amount === '' && preg_match('/^(\d[\d\/.,\-]*|'.implode('|', self::VAGUE).')\s+(.+)$/iu', $unit, $m) && $this->known($m[2])) {
            [$amount, $unit] = [$m[1], $m[2]];
        }
        $unit = self::TYPOS[Units::normalize($unit)] ?? $unit;
        // Garlic comes in cloves, ginger and galangal in slices.
        $name = mb_strtolower((string) $item['ingredient']);
        $forms = match (true) {
            str_contains($name, 'чеснок') => ['долька' => 'зубчик', 'дольки' => 'зубчика', 'долек' => 'зубчиков'],
            str_contains($name, 'галангал') || str_contains($name, 'имбир') => ['долька' => 'ломтик', 'дольки' => 'ломтика', 'долек' => 'ломтиков'],
            default => [],
        };
        $unit = $forms[mb_strtolower($unit)] ?? $unit;

        $fix = $this->rowFixes[$slug][trim($name)] ?? [];
        $amount = $fix['amount'] ?? $amount;
        $unit = $fix['unit'] ?? $unit;
        $group = strtr((string) ($item['group'] ?? ''), self::GROUP_TYPOS);

        if ($group !== (string) ($item['group'] ?? '')) {
            $item['group'] = $group;
        }
        if ($amount === trim((string) ($item['amount'] ?? '')) && $unit === trim((string) ($item['unit'] ?? ''))) {
            return $item;
        }

        return ['amount' => $amount, 'unit' => $unit, 'amount_normalized' => $this->normalized($amount)] + $item;
    }

    private function known(string $unit): bool
    {
        $unit = self::TYPOS[Units::normalize($unit)] ?? $unit;

        return ! in_array(Units::code($unit), [null, ''], true);
    }

    /** The number WP Ultimate Recipe rescales: "1 1/2" → 1.5, "1,5" → 1.5, "2-3" → 2, "пару" → 0. */
    private function normalized(string $amount): float
    {
        $first = trim(explode('-', str_replace(['–', '—'], '-', $amount))[0]);
        if (preg_match('/^(?:(\d+)\s+)?(\d+)\/(\d+)$/', $first, $m)) {
            return (float) $m[1] + ((int) $m[3] ? $m[2] / $m[3] : 0);
        }

        return is_numeric(str_replace(',', '.', $first)) ? (float) str_replace(',', '.', $first) : 0.0;
    }
}
