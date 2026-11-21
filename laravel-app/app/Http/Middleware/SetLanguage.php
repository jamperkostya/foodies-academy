<?php

namespace App\Http\Middleware;

use App\Support\Lang;
use Closure;
use Illuminate\Http\Request;

/** ?lang=en: the API answers with the English translations; an unknown language is a 404. */
class SetLanguage
{
    public function handle(Request $request, Closure $next)
    {
        $lang = (string) $request->query('lang', Lang::DEFAULT);
        abort_unless(in_array($lang, Lang::ALL, true), 404, 'Unknown language');
        Lang::set($lang);
        // Validation messages and dates in the visitor's language (lang/ru, Laravel's own en).
        $locale = app()->getLocale();
        app()->setLocale($lang);

        try {
            return $next($request);
        } finally {
            // Tests and long-running workers serve several requests in one process.
            Lang::set(Lang::DEFAULT);
            app()->setLocale($locale);
        }
    }
}
