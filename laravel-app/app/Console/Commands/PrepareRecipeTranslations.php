<?php

namespace App\Console\Commands;

use App\Services\Translations;
use App\Support\Units;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Turns a file of recipe translations into what WordPress imports (wp foodies import-recipe-translations):
 * every translation bound to its Russian recipe, ingredients in the recipe's own order.
 *
 * The source file has no recipe ids: a translation is found by its photo (photo_url), the
 * featured image, a gallery photo or a photo uploaded to the recipe. Its ingredients carry the
 * amount and unit in one English string ("1.5 tbsp"); only names, notes and groups are taken:
 * amounts and units stay those of the Russian recipe, so a translation can't change them and the
 * site converts them for the reader (config/units.php). An ingredient list that doesn't line up
 * with the Russian one is left out, the recipe is imported without it. Instructions are the
 * translation's own: the translator split long steps, and steps have no photos to keep in line.
 * Category and country are not read: they are the recipe's terms, translated once per term
 * (database/content/translations/terms.{lang}.json).
 */
class PrepareRecipeTranslations extends Command
{
    protected $signature = 'recipes:prepare-translations {source : JSON file of translations} {--lang=en}
        {--output= : Where to write (default database/content/translations/recipes.{lang}.json)}';

    protected $description = 'Bind recipe translations from a JSON file to the Russian recipes for import into WordPress';

    private ConnectionInterface $db;

    /** Words a title in English keeps lowercase, unless first. */
    private const SMALL_WORDS = ['a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'from', 'in', 'into', 'of', 'on', 'or', 'the', 'to', 'with',
        'à', 'a', 'al', 'alla', 'au', 'aux', 'de', 'del', 'della', 'des', 'di', 'du', 'e', 'en', 'et', 'la', 'le', 'les', 'y'];

    public function handle(): int
    {
        $this->db = DB::connection('site');
        $lang = (string) $this->option('lang');
        $source = json_decode((string) @file_get_contents($this->argument('source')), true);
        if (! is_array($source)) {
            $this->error('Not a JSON list of translations: '.$this->argument('source'));

            return self::FAILURE;
        }

        $recipes = $this->recipesByPhoto();
        $translations = [];
        $skipped = [];
        foreach ($source as $index => $item) {
            $path = preg_replace('~^.*?/wp-content/uploads/~', '', (string) ($item['photo_url'] ?? ''));
            $id = $recipes[$path] ?? null;
            if (! $id) {
                $skipped[] = "#$index «".($item['title'] ?? '')."»: no recipe with the photo $path";

                continue;
            }
            if (isset($translations[$id])) {
                $skipped[] = "#$index «{$item['title']}»: recipe $id is translated by «{$translations[$id]['title']}» already";

                continue;
            }
            $translation = $this->translation($id, $item);
            if (! $translation) {
                $skipped[] = "#$index «{$item['title']}»: no title for recipe $id";

                continue;
            }
            $translations[$id] = $translation;
        }

        ksort($translations);
        $output = $this->option('output') ?: database_path("content/translations/recipes.$lang.json");
        @mkdir(dirname($output), 0775, true);
        file_put_contents($output, json_encode([
            'lang' => $lang,
            'recipes' => array_values($translations),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

        $this->report($translations, $skipped, $output);

        return self::SUCCESS;
    }

    /** Recipe id of every uploaded photo, the featured image first. */
    private function recipesByPhoto(): array
    {
        // Russian recipes only: a translation has the same photos.
        $recipes = $this->db->table('posts')->where('post_type', 'recipe')->whereIn('post_status', ['publish', 'draft'])
            ->whereNotIn('ID', app(Translations::class)->translatedIds())->pluck('ID')->flip();
        $byAttachment = [];
        // Weakest first: a later, stronger link overwrites (a photo uploaded to one recipe can be
        // the cover of another).
        foreach ($this->db->table('posts')->where('post_type', 'attachment')->where('post_parent', '>', 0)->pluck('post_parent', 'ID') as $attachment => $parent) {
            if ($recipes->has($parent)) {
                $byAttachment[$attachment] = (int) $parent;
            }
        }
        $meta = $this->db->table('postmeta')->whereIn('meta_key', ['gallery', '_thumbnail_id'])->whereIn('post_id', $recipes->keys())
            ->orderByRaw("meta_key = '_thumbnail_id'")->get(['post_id', 'meta_key', 'meta_value']);
        foreach ($meta as $row) {
            $ids = $row->meta_key === 'gallery' ? @unserialize((string) $row->meta_value, ['allowed_classes' => false]) : [$row->meta_value];
            foreach (is_array($ids) ? $ids : [] as $attachment) {
                $byAttachment[(int) $attachment] = (int) $row->post_id;
            }
        }

        $covers = $meta->where('meta_key', '_thumbnail_id')->pluck('meta_value')->map(fn ($id) => (int) $id)->flip();
        $byPath = [];
        $files = $this->db->table('postmeta')->where('meta_key', '_wp_attached_file')->whereIn('post_id', array_keys($byAttachment))->pluck('meta_value', 'post_id');
        // One file can be in the library twice: the featured image's copy wins.
        foreach ($files as $attachment => $path) {
            if (! isset($byPath[$path]) || $covers->has($attachment)) {
                $byPath[$path] = $byAttachment[$attachment];
            }
        }

        return $byPath;
    }

    private function translation(int $id, array $item): ?array
    {
        $post = $this->db->table('posts')->where('ID', $id)->first(['post_name', 'post_title']);
        $warnings = [];

        $title = $this->title((string) ($item['title'] ?? ''));
        if ($title === '' || strcasecmp($title, 'draft') === 0) {
            // "МАПО ТОФУ (Mapo Tofu)": the Russian title often has the original name.
            preg_match('/\(([^()\p{Cyrillic}]+)\)/u', $post->post_title, $match);
            $title = $this->title($match[1] ?? '');
            if ($title === '') {
                return null;
            }
            $warnings[] = "the title «{$item['title']}» replaced with the name in the Russian title";
        }

        $ingredients = $this->ingredients($id, $item['ingredients'] ?? [], $warnings);
        $instructions = [];
        foreach ($item['instructions'] ?? [] as $group => $steps) {
            foreach ((array) $steps as $step) {
                $text = trim(preg_replace('/\s+/u', ' ', (string) $step));
                if ($text !== '') {
                    $instructions[] = ['group' => $this->group((string) $group), 'text' => $this->temperatures($text)];
                }
            }
        }

        $texts = json_encode([$title, $ingredients, $instructions], JSON_UNESCAPED_UNICODE);
        if (preg_match('/\p{Cyrillic}/u', $texts)) {
            $warnings[] = 'Russian text left in the translation';
        }

        return [
            'source_id' => $id,
            'source_slug' => $post->post_name,
            'photo' => preg_replace('~^.*?/wp-content/uploads/~', '', (string) $item['photo_url']),
            'title' => $title,
            'ingredients' => $ingredients,
            'instructions' => $instructions,
            'warnings' => $warnings,
        ];
    }

    /**
     * Names, notes and groups of the translated ingredients in the order of the Russian ones,
     * null when the lists don't line up (another count, or most amounts differ).
     */
    private function ingredients(int $id, array $groups, array &$warnings): ?array
    {
        $russian = @unserialize((string) $this->db->table('postmeta')->where('post_id', $id)->where('meta_key', 'recipe_ingredients')->value('meta_value'), ['allowed_classes' => false]);
        $russian = array_values(array_filter(is_array($russian) ? $russian : [], fn ($i) => is_array($i) && trim($i['ingredient'] ?? '') !== ''));
        $english = [];
        foreach ($groups as $group => $items) {
            foreach ((array) $items as $item) {
                $english[] = ['group' => $this->group((string) $group)] + $item;
            }
        }
        if (count($english) !== count($russian)) {
            $warnings[] = 'ingredients not imported: '.count($english).' in the translation, '.count($russian).' in the recipe';

            return null;
        }

        $result = [];
        $differ = [];
        foreach ($russian as $k => $ru) {
            $item = $english[$k];
            $amount = (string) ($item['amount'] ?? '');
            if ($this->number((string) $ru['amount']) !== $this->number($amount) && trim((string) $ru['amount']) !== '') {
                $differ[] = '#'.($k + 1)." «{$ru['amount']} {$ru['unit']} {$ru['ingredient']}» / «$amount {$item['item']}»";
            }
            $name = $this->withoutRussian((string) ($item['item'] ?? ''));
            $notes = '';
            // WP Ultimate Recipe shows "name (notes)": the translator wrote them as one, split them back.
            if (trim((string) $ru['notes']) !== '' && preg_match('/^(.+?)\s*\(([^()]+)\)$/u', $name, $match)) {
                [$name, $notes] = [$match[1], $match[2]];
            }
            // For the recipe editor in the admin only: the site takes the unit of the Russian recipe.
            $code = Units::code((string) $ru['unit']);
            $unit = $code === null ? trim((string) $ru['unit']) : Units::label($code, is_numeric($ru['amount_normalized'] ?? null) ? (float) $ru['amount_normalized'] : null);
            $result[] = ['name' => $name, 'notes' => $notes, 'group' => $item['group'], 'unit' => $unit];
        }
        if (count($differ) > count($russian) / 2) {
            $warnings[] = 'ingredients not imported: the amounts differ from the recipe, the lists may not line up';

            return null;
        }
        foreach ($differ as $line) {
            $warnings[] = "an ingredient amount differs: $line";
        }

        return $result;
    }

    /** The number an amount starts with, comma or dot: "1,5" and "1.5 tbsp" are both "1.5". */
    private function number(string $amount): string
    {
        preg_match('/^[\d\/.,\s-]+/', trim($amount), $match);

        return str_replace([',', ' '], ['.', ''], trim($match[0] ?? ''));
    }

    private function group(string $group): string
    {
        return strcasecmp($group, 'default') === 0 ? '' : trim($group);
    }

    /** The Russian original the translator left in parentheses dropped: "Banana (Банан)" → "Banana". */
    private function withoutRussian(string $text): string
    {
        // Nested once at most: "(Капуста свежая(квашеная, сырая))".
        return trim(preg_replace(['/\s*\((?:[^()]|\([^()]*\))*\p{Cyrillic}(?:[^()]|\([^()]*\))*\)/u', '/\s+/u'], ['', ' '], $text));
    }

    /** Abbreviations a title keeps in capitals. */
    private const ABBREVIATIONS = ['BBQ', 'USA', 'UK', 'TV', 'XO', 'KFC', 'BLT', 'DIY'];

    /**
     * Words in ALL CAPS → Title Case, Russian in parentheses dropped:
     * "BOMBAY POTATOES (КАРТОФЕЛЬ ПО-БОМБЕЙСКИ)" → "Bombay Potatoes", "OSSO BUCO (osso buco)" → "Osso Buco (osso buco)".
     */
    private function title(string $title): string
    {
        $words = explode(' ', $this->withoutRussian($title));
        foreach ($words as $i => $word) {
            $letters = preg_replace('/[^\p{L}]/u', '', $word);
            if (mb_strlen($letters) < 2 || $letters !== mb_strtoupper($letters) || in_array($letters, self::ABBREVIATIONS, true)) {
                continue;
            }
            $lower = mb_strtolower($word);
            $words[$i] = $i > 0 && in_array(trim($lower, '()'), self::SMALL_WORDS, true)
                ? $lower
                : preg_replace_callback('/(^|[\s(\-"\'])(\p{Ll})/u', fn ($m) => $m[1].mb_strtoupper($m[2]), $lower);
        }

        return implode(' ', $words);
    }

    /** Oven temperatures with °F for American readers: "225 degrees C" → "225°C (425°F)". */
    private function temperatures(string $text): string
    {
        if (preg_match('/\d\s*°?\s*F\b/u', $text)) {
            return $text;
        }

        return preg_replace_callback('/\b(\d{3})\s*(?:°|º|degrees?\s*)?\s*(?:C\b|С\b|Celsius)(?!\s*\(\d+\s*°?F)/u', function ($m) {
            $celsius = (int) $m[1];

            return $celsius >= 100 && $celsius <= 300 ? "{$celsius}°C (".Units::temperature($celsius, 'us').')' : $m[0];
        }, $text);
    }

    private function report(array $translations, array $skipped, string $output): void
    {
        $published = $this->db->table('posts')->where('post_type', 'recipe')->where('post_status', 'publish')
            ->whereNotIn('ID', app(Translations::class)->translatedIds())->pluck('ID');
        $warnings = collect($translations)->flatMap(fn ($t) => array_map(fn ($w) => preg_replace('/:.*$/', '', $w), $t['warnings']))->countBy()->sortDesc();

        $this->info(count($translations).' translations written to '.$output);
        $this->line('Published recipes without a translation: '.$published->diff(array_keys($translations))->count());
        $this->line('Without ingredients (to fix by hand): '.collect($translations)->whereNull('ingredients')->count());
        foreach ($warnings as $warning => $times) {
            $this->line("  $times × $warning");
        }
        foreach ($skipped as $line) {
            $this->warn("Skipped $line");
        }
    }
}
