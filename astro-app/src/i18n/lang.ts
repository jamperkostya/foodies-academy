// Languages of the site, without side effects: used by pages and by the browser's scripts.
// Russian is the original at the root (/recipe/x); English lives under /en (/en/recipe/y).

export type Lang = "ru" | "en";

export const LANGS: Lang[] = ["ru", "en"];
export const DEFAULT_LANG: Lang = "ru";

/** Locale for dates, numbers and og:locale. */
export const LOCALES: Record<Lang, string> = { ru: "ru-RU", en: "en-US" };

/** The language of a page by its address: /en, /en/…, en/….html during the build. */
export function langOf(url: URL | string | undefined): Lang {
	const path = typeof url === "string" ? url : (url?.pathname ?? "/");
	return /^\/?en(\/|\.html$|$)/.test(path) ? "en" : "ru";
}

/**
 * Whether this build makes the pages of `lang`: BUILD_LANGS="en" rebuilds the English site only
 * (an English recipe was edited), "ru" the Russian one; unset, both. Pages with parameters of the
 * other languages are left out (getStaticPaths returns none), the deploy takes only what was built.
 */
export function builds(lang: Lang): boolean {
	const langs = String(import.meta.env.BUILD_LANGS ?? "").split(",").map((code) => code.trim()).filter(Boolean);
	return langs.length === 0 || langs.includes(lang);
}

/** The language of a route pattern in getStaticPaths ({ routePattern }). */
export const langOfRoute = (routePattern: string | undefined): Lang => langOf(routePattern ?? "/");

/**
 * A site address in `lang`: "/recipe/x" → "/en/recipe/x", "/" → "/en". Other links stay, and so do
 * files (sprite.svg, favicon.ico) but JSON: the search index has one per language.
 */
export function localize<T extends string | undefined>(lang: Lang, path: T): T {
	if (typeof path !== "string" || lang === DEFAULT_LANG || !path.startsWith("/") || path.startsWith("//") || /^\/en(\/|$)/.test(path)) return path;
	const file = path.split(/[?#]/)[0];
	if (/\.[a-z0-9]{2,5}$/i.test(file) && !file.endsWith(".json")) return path;
	return (path === "/" ? "/en" : `/en${path}`) as T;
}

/** The same address without the language prefix: "/en/recipe/x" → "/recipe/x". */
export const unlocalize = (path: string): string => path.replace(/^\/en(?=\/|$)/, "") || "/";

/** A Laravel API URL asking for `lang`: "…/recipes/top?limit=5" → "…/recipes/top?limit=5&lang=en". */
export function withLang(lang: Lang, url: string): string {
	return lang === DEFAULT_LANG ? url : `${url}${url.includes("?") ? "&" : "?"}lang=${lang}`;
}

/** Plural form: Russian has three forms ("1 отзыв", "2 отзыва", "5 отзывов"); other languages take the 1st or the 3rd, translated. */
export function pluralForm(lang: Lang, n: number, [one, few, many]: [string, string, string]): string {
	if (lang !== DEFAULT_LANG) return Math.abs(n) === 1 ? one : many;
	const mod10 = Math.abs(n) % 10;
	const mod100 = Math.abs(n) % 100;
	if (mod10 === 1 && mod100 !== 11) return one;
	if (mod10 >= 2 && mod10 <= 4 && (mod100 < 10 || mod100 >= 20)) return few;
	return many;
}
