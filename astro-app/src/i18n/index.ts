// Interface strings of the site. The Russian text is the key; translations edited in the
// WordPress admin (Polylang: Languages → Translations) win over the defaults in foodies-strings.json.
import defaults from "../../../wp-content/mu-plugins/foodies-strings.json";
import { DEFAULT_LANG, LOCALES, pluralForm, withLang, type Lang } from "./lang";

export * from "./lang";

const API_URL = import.meta.env.API_URL ?? "http://localhost:8000/api";

type Strings = Record<string, string>;

// Translations edited in the WordPress admin win; foodies-strings.json has the defaults.
const fromDefaults: Record<string, Strings> = { ru: {}, en: {} };
// Strings of the browser's scripts ("client": true): only these go into the page.
const clientKeys = new Set<string>();
for (const string of defaults as { ru: string; en: string; client?: boolean }[]) {
	fromDefaults.en[string.ru] = string.en;
	if (string.client) clientKeys.add(string.ru);
}

async function load(lang: Lang): Promise<Strings> {
	try {
		const { data } = await fetch(withLang(lang, `${API_URL}/strings`)).then((r) => r.json());
		return data && !Array.isArray(data) ? data : {};
	} catch {
		return {};
	}
}
const edited: Record<string, Strings> = { ru: {}, en: await load("en") };

/**
 * An interface string in `lang`. The Russian text is the key: t(lang, "Ингредиенты").
 * {name} placeholders take `vars`: t(lang, "на {n} порции", { n: 4 }).
 */
export function t(lang: Lang, russian: string, vars: Record<string, string | number> = {}): string {
	const text = lang === DEFAULT_LANG ? russian : (edited[lang][russian] ?? fromDefaults[lang][russian] ?? russian);
	return text.replace(/\{(\w+)\}/g, (match, name) => (name in vars ? String(vars[name]) : match));
}

/** The strings of `lang` for the browser's scripts (see src/scripts/i18n.ts). */
export function clientStrings(lang: Lang): Strings {
	if (lang === DEFAULT_LANG) return {};
	return Object.fromEntries([...clientKeys].map((key) => [key, edited[lang][key] ?? fromDefaults[lang][key] ?? key]));
}

/** A word for a count in `lang`: pluralOf(lang, 5, ["отзыв", "отзыва", "отзывов"]) → "отзывов" / "reviews". */
export const pluralOf = (lang: Lang, n: number, forms: [string, string, string]): string => t(lang, pluralForm(lang, n, forms));

/** "рецепт" / "рецепта" / "рецептов" in `lang`. */
export const recipesWord = (lang: Lang, n: number): string => pluralOf(lang, n, ["рецепт", "рецепта", "рецептов"]);

/** Views of a page on the site: "1 234 просмотра", "" for none (a new page shows no counter). */
export const viewsText = (lang: Lang, n: number): string =>
	n > 0 ? `${n.toLocaleString(LOCALES[lang])} ${pluralOf(lang, n, ["просмотр", "просмотра", "просмотров"])}` : "";
