// Interface strings for the browser's scripts: the page's language from <html lang>, the
// translations from the JSON BaseLayout puts in the page (only on pages not in Russian).
import { DEFAULT_LANG, localize, pluralForm, type Lang } from "../i18n/lang";

// Outside a browser (tests of the scripts) the page is Russian.
const page = typeof document === "undefined" ? null : document;

export const pageLang: Lang = page?.documentElement.lang === "en" ? "en" : DEFAULT_LANG;

let strings: Record<string, string> = {};
try {
	strings = JSON.parse(page?.getElementById("i18n-strings")?.textContent || "{}");
} catch {
	// A broken JSON leaves the Russian texts.
}

/** A string in the page's language: tc("Сохранено"), tc("{n} из {total} шагов", { n, total }). */
export function tc(russian: string, vars: Record<string, string | number> = {}): string {
	const text = pageLang === DEFAULT_LANG ? russian : (strings[russian] ?? russian);
	return text.replace(/\{(\w+)\}/g, (match, name) => (name in vars ? String(vars[name]) : match));
}

/** A word for a count in the page's language. */
export const pluralc = (n: number, forms: [string, string, string]): string => tc(pluralForm(pageLang, n, forms));

/** A site address in the page's language. */
export const href = (path: string): string => localize(pageLang, path);
