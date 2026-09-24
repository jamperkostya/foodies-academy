import { experimental_AstroContainer as AstroContainer } from "astro/container";
import { beforeAll, describe, expect, it } from "vitest";
import SearchForm from "../src/components/layout/SearchForm.astro";
import { GET } from "../src/pages/search-index.json";
import { recipes } from "../src/data/recipes";
import { categories } from "../src/data/categories";
import { videos } from "../src/data/videos";

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
		expect(html).not.toContain(recipes[0].title);
	});
});

describe("/search-index.json", () => {
	type Item = { title: string; meta: string; icon: string; href: string };
	let index: Item[];

	beforeAll(async () => {
		const response = await GET({} as Parameters<typeof GET>[0]);
		index = await response.json();
	});

	it("lists every recipe, category and video", () => {
		expect(index).toHaveLength(recipes.length + categories.length + videos.length);
	});

	it("describes each suggestion with a meta line, an icon and a link", () => {
		const recipe = recipes[0];
		expect(index).toContainEqual(
			expect.objectContaining({ title: recipe.title, meta: `${recipe.time} мин · ${recipe.category}`, href: `/recipes/${recipe.slug}` })
		);
		expect(index).toContainEqual(expect.objectContaining({ title: videos[0].title, href: `/video/${videos[0].slug}`, icon: "🎬" }));
		for (const item of index) expect(item.icon).not.toBe("");
	});

	it("declines the recipe count for categories", () => {
		const category = categories.find((c) => c.recipesCount % 10 === 1 && c.recipesCount % 100 !== 11);
		if (category) {
			expect(index).toContainEqual(expect.objectContaining({ title: category.title, meta: `Категория · ${category.recipesCount} рецепт` }));
		}
	});
});
