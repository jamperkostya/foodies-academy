<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Lang;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/strings?lang=en → {"Русская строка": "Translation"}: the interface strings as
 * translated in the WordPress admin (Polylang: Languages → Translations, group "Foodies").
 * Polylang keeps them in the language term's meta "_pll_strings_translations": [[source, translation]].
 */
class StringsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => Cache::remember(Lang::key('strings'), now()->addMinutes(10), function () {
            if (Lang::isDefault()) {
                return (object) [];
            }
            $value = DB::connection('site')->table('termmeta as m')
                ->join('term_taxonomy as tt', fn ($j) => $j->on('tt.term_id', '=', 'm.term_id')->where('tt.taxonomy', 'language'))
                ->join('terms as t', 't.term_id', '=', 'tt.term_id')
                ->where('t.slug', Lang::current())
                ->where('m.meta_key', '_pll_strings_translations')
                ->value('m.meta_value');
            $pairs = @unserialize((string) $value, ['allowed_classes' => false]);
            $strings = [];
            foreach (is_array($pairs) ? $pairs : [] as $pair) {
                if (is_array($pair) && ($pair[0] ?? '') !== '' && ($pair[1] ?? '') !== '' && $pair[0] !== $pair[1]) {
                    $strings[(string) $pair[0]] = (string) $pair[1];
                }
            }

            return (object) $strings;
        })]);
    }
}
