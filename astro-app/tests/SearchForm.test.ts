import { experimental_AstroContainer as AstroContainer } from "astro/container";
import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import SearchForm from "../src/components/layout/SearchForm.astro";
import { GET } from "../src/pages/search-index.json";
import { findItems, normalize, type SearchItem } from "../src/scripts/search";

describe("SearchForm", () => {
	let html: string;

	beforeAll(async () => {
		const container = await AstroContainer.create();
		html = await container.renderToString(SearchForm);
	});

	it("is an ARIA combobox wired to its listbox", () => {
		const input = html.match(/<input[^>]*class="search__input"[^>]*>/)?.[0] ?? "";

		expect(input).toContain('role="combobox"');
		expect(input).toContain('aria-autocomplete="list"');
		expect(input).toContain('aria-expanded="false"');
		expect(input).toContain('aria-controls="search-suggestions"');
		expect(input).toContain('autocomplete="off"');
		expect(html).toMatch(/id="search-suggestions"[^>]*role="listbox"/);
	});

	it("doesn't embed the suggestions in the page", () => {
		expect(html).not.toContain("search__suggestion-item");
		expect(html).not.toContain("/recipe/");
	});
});

describe("/search-index.json", () => {
	// What the Laravel API returns for the index.
	const api: Record<string, unknown> = {
		"/recipes/search-index": [
			{ slug: "borscht", title: "Украинский борщ", time: "3 ч", course: "Супы", ingredients: ["Свёкла", "Говядина"] },
			{ slug: "olivier", title: "Салат Оливье", time: "", course: "", ingredients: ["Рябчики*"] },
		],
		"/categories?type=course": [{ id: 1, name: "Супы", slug: "soups", type: "course", count: 21, image: "", meta: {} }],
		"/categories?type=country": [{ id: 2, name: "Италия", slug: "italiya", type: "country", count: 67, image: "", meta: {} }],
		"/authors": [{ id: 18, slug: "mariana", name: "Mariana Demidova", avatar: "", recipes: 366 }],
	};
	let index: SearchItem[];

	beforeAll(async () => {
		vi.stubGlobal("fetch", async (url: string) => {
			const path = Object.keys(api).find((key) => url.endsWith(key))!;
			return new Response(JSON.stringify({ data: api[path] }));
		});
		index = await (await GET({} as Parameters<typeof GET>[0])).json();
	});
	afterAll(() => vi.unstubAllGlobals());

	it("lists the recipes with their ingredients, the categories, the cuisines and the authors", () => {
		expect(index.map((item) => item.href)).toEqual(["/recipe/borscht", "/recipe/olivier", "/category/soups", "/category/italiya", "/author/mariana"]);
		expect(index[0]).toEqual({ title: "Украинский борщ", meta: "3 ч · Супы", icon: "🍲", href: "/recipe/borscht", ingredients: ["Свёкла", "Говядина"] });
		expect(index[1].meta).toBe("Рецепт");
	});

	it("searches categories and authors by name only", () => {
		expect(index.find((item) => item.href === "/category/soups")).toEqual({ title: "Супы", meta: "Категория · 21 рецепт", icon: "🍲", href: "/category/soups" });
		expect(index.find((item) => item.href === "/category/italiya")?.meta).toBe("Кухня мира · 67 рецептов");
		expect(index.find((item) => item.href === "/author/mariana")).toEqual({ title: "Mariana Demidova", meta: "Автор · 366 рецептов", icon: "👨‍🍳", href: "/author/mariana" });
	});
});

describe("findItems", () => {
	const items: SearchItem[] = [
		{ title: "Говядина по-бургундски", meta: "3 ч", icon: "🍖", href: "/recipe/beef", ingredients: ["Вино"] },
		{ title: "Украинский борщ", meta: "3 ч · Супы", icon: "🍲", href: "/recipe/borscht", ingredients: ["Свёкла", "Говядина"] },
		{ title: "Говядина", meta: "Категория", icon: "🍖", href: "/category/beef" },
	];
	const find = (query: string) => findItems(items, normalize(query));

	it("finds by title first, then recipes by ingredient, saying which ingredient matched", () => {
		const found = find("говяд");

		expect(found.map((item) => item.href)).toEqual(["/recipe/beef", "/category/beef", "/recipe/borscht"]);
		expect(found[2].meta).toBe("3 ч · Супы · с ингредиентом «Говядина»");
	});

	it("ignores case and ё", () => {
		expect(find("СВЕКЛА").map((item) => item.href)).toEqual(["/recipe/borscht"]);
	});

	it("doesn't list a recipe twice when both its title and an ingredient match", () => {
		expect(find("борщ")).toHaveLength(1);
	});
});
