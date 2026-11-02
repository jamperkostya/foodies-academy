import { experimental_AstroContainer as AstroContainer } from "astro/container";
import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import CategoriesPage from "../src/pages/categories.astro";
import CategoryPage, { getStaticPaths } from "../src/pages/category/[slug]/[...page].astro";
import type { Recipe } from "../src/types/recipe";
import type { Category } from "../src/types/category";

// What GET /api/categories?type=course returns.
const term = (id: number, name: string, count: number): Category =>
	({ id, name, slug: `slug-${id}`, type: "course", count, image: "/img.jpg", meta: {} });
const categories = [term(1, "Супы", 79), term(2, "Закуски", 131), term(3, "Десерты", 40)];

describe("/categories", () => {
	beforeAll(() => vi.stubGlobal("fetch", async () => new Response(JSON.stringify({ data: categories }))));
	afterAll(() => vi.unstubAllGlobals());

	it("shows a card for every category", async () => {
		const container = await AstroContainer.create();
		const html = await container.renderToString(CategoriesPage);

		expect(html.match(/class="category-card[" ]/g)).toHaveLength(categories.length);
	});

	it("shows how many categories there are and the sort options", async () => {
		const container = await AstroContainer.create();
		const html = await container.renderToString(CategoriesPage);
		const options = [...html.matchAll(/<option value="([^"]+)"[^>]*>([^<]+)</g)].map((m) => [m[1], m[2]]);

		expect(html).toMatch(new RegExp(`sort-bar__value[^>]*>${categories.length}</strong> категори`));
		expect(options).toEqual([
			["popular", "По популярности"],
			["az", "По алфавиту"],
			["count", "По числу рецептов"],
		]);
	});

	it("gives each card its recipe count for sorting", async () => {
		const container = await AstroContainer.create();
		const html = await container.renderToString(CategoriesPage);

		expect(html).toContain(`data-recipes-count="${categories[0].count}"`);
	});

	it("opens with a single h1 and the recipe total from the data", async () => {
		const container = await AstroContainer.create();
		const html = await container.renderToString(CategoriesPage);
		const total = categories.reduce((sum, category) => sum + category.count, 0);

		expect(html.match(/<h1/g)).toHaveLength(1);
		expect(html).toMatch(/class="page-hero__title"[^>]*>\s*Все <span[^>]*>категории<\/span>/);
		expect(html).toContain(`${total.toLocaleString("ru-RU")} рецептов`);
	});
});

describe("/category/[slug]", () => {
	const recipe = (slug: string): Recipe => ({
		id: 1, slug, title: `Рецепт ${slug}`, image: "/img.jpg",
		categories: { course: { 1: "Супы" }, country: { 2: "Франция" } },
		time: "1 ч", minutes: 60, isNew: false, isTop: false, rating: 5, servings: 2, ingredients: [], comments: 0,
		views: { count: 0, short: "0" }, likes: { count: 0, short: "0" },
	});
	// The second of 3 pages: 50 recipes, 24 a page.
	const france = {
		...term(9, "Франция", 50),
		type: "country" as const,
		recipes: [recipe("ratatouille"), recipe("onion-soup")],
		total: 50,
		page: 2,
		pages: 3,
	};
	const requested: string[] = [];

	beforeAll(() =>
		vi.stubGlobal("fetch", async (url: string) => {
			requested.push(url);
			if (url.includes("type=course")) return new Response(JSON.stringify({ data: categories }));
			if (url.includes("type=country")) return new Response(JSON.stringify({ data: [france] }));
			return new Response(JSON.stringify({ data: france }));
		}),
	);
	afterAll(() => vi.unstubAllGlobals());

	it("builds every page of 24 recipes for every dish type and country", async () => {
		const paths = (await getStaticPaths()).map((path) => path.params);

		expect([...new Set(paths.map((params) => params.slug))]).toEqual([...categories, france].map((category) => category.slug));
		// "Супы": 79 recipes → 4 pages; the first one has no /page/N.
		expect(paths.filter((params) => params.slug === "slug-1").map((params) => params.page)).toEqual([undefined, "page/2", "page/3", "page/4"]);
	});

	it("lists the category's recipes from Laravel, under 'Кухни мира' for a country", async () => {
		const html = await (await AstroContainer.create()).renderToString(CategoryPage, { params: { slug: france.slug, page: "page/2" } });

		expect(requested).toContainEqual(expect.stringMatching(new RegExp(`/categories/${france.slug}\\?page=2$`)));
		expect(html).toContain('href="/recipe/ratatouille"');
		expect(html).toContain('href="/recipe/onion-soup"');
		expect(html).toContain("Франция — страница 2 — Foodies.academy");
		expect(html).toMatch(/href="\/kitchens"[^>]*>Кухни мира</);
	});

	it("links the pages and loads the next one with 'Показать ещё'", async () => {
		const html = await (await AstroContainer.create()).renderToString(CategoryPage, { params: { slug: france.slug, page: "page/2" } });
		const base = `/category/${france.slug}`;

		expect(html).toMatch(new RegExp(`href="${base}/page/3"[^>]*data-load-more[^>]*>Показать ещё<`));
		expect(html).toMatch(new RegExp(`href="${base}"[^>]*rel="prev"`));
		expect(html).toMatch(/pagination__link--current[^>]*>2</);
	});
});
