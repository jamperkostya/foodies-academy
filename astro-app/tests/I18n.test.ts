import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";
import { langOf, localize, pluralForm, unlocalize, withLang } from "../src/i18n/lang";
import strings from "../../wp-content/mu-plugins/foodies-strings.json";

describe("languages", () => {
	it("reads the language from the address and builds addresses in it", () => {
		expect(langOf(new URL("https://foodies.academy/en/recipe/x"))).toBe("en");
		expect(langOf("/en")).toBe("en");
		expect(langOf("en/recipe/x.html")).toBe("en");
		expect(langOf("/entrees")).toBe("ru");
		expect(localize("en", "/recipe/x")).toBe("/en/recipe/x");
		expect(localize("en", "/")).toBe("/en");
		expect(localize("en", "/en/recipe/x")).toBe("/en/recipe/x");
		expect(localize("en", "https://youtube.com")).toBe("https://youtube.com");
		// Files are the same for every language, but the search index.
		expect(localize("en", "/images/icons/sprite.svg#clock")).toBe("/images/icons/sprite.svg#clock");
		expect(localize("en", "/favicon.ico")).toBe("/favicon.ico");
		expect(localize("en", "/search-index.json")).toBe("/en/search-index.json");
		expect(localize("en", "/search?q=borscht.html")).toBe("/en/search?q=borscht.html");
		expect(localize("ru", "/recipe/x")).toBe("/recipe/x");
		expect(unlocalize("/en/recipe/x")).toBe("/recipe/x");
		expect(unlocalize("/en")).toBe("/");
		expect(withLang("en", "http://api/recipes?limit=5")).toBe("http://api/recipes?limit=5&lang=en");
		expect(withLang("ru", "http://api/recipes")).toBe("http://api/recipes");
	});

	it("picks plural forms", () => {
		const forms: [string, string, string] = ["отзыв", "отзыва", "отзывов"];
		expect([1, 3, 5, 11, 21].map((n) => pluralForm("ru", n, forms))).toEqual(["отзыв", "отзыва", "отзывов", "отзывов", "отзыв"]);
		expect([1, 3, 21].map((n) => pluralForm("en", n, forms))).toEqual(["отзыв", "отзывов", "отзывов"]);
	});
});

describe("interface strings", () => {
	const files = (dir: string): string[] =>
		readdirSync(dir, { withFileTypes: true }).flatMap((entry) =>
			entry.isDirectory() ? files(join(dir, entry.name)) : /\.(astro|ts)$/.test(entry.name) ? [join(dir, entry.name)] : [],
		);
	const known = new Set((strings as { ru: string; en: string }[]).map((string) => string.ru));

	it("every string of t() and tc() has a translation in foodies-strings.json", () => {
		const missing = new Set<string>();
		for (const file of files("src")) {
			const code = readFileSync(file, "utf8");
			for (const match of code.matchAll(/\b(?:t\(lang, |tc\()"([^"]*[А-Яа-яЁё][^"]*)"/g)) {
				if (!known.has(match[1])) missing.add(match[1]);
			}
		}
		expect([...missing]).toEqual([]);
	});

	it("translations keep the placeholders", () => {
		for (const string of strings as { ru: string; en: string }[]) {
			const names = (text: string) => [...text.matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort();
			expect(names(string.en), string.ru).toEqual(names(string.ru));
		}
	});
});
