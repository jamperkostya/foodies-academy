import { experimental_AstroContainer as AstroContainer } from "astro/container";
import { describe, expect, it } from "vitest";
import CategoriesPage from "../src/pages/categories.astro";
import CategoryPage, { getStaticPaths } from "../src/pages/category/[slug].astro";
import { categories } from "../src/data/categories";
import { recipes } from "../src/data/recipes";

describe("/categories", () => {
	it("shows a card for every category", async () => {
		const container = await AstroContainer.create();
		const html = await container.renderToString(CategoriesPage);

		expect(html.match(/class="category-card"/g)).toHaveLength(categories.length);
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

		expect(html).toContain(`data-recipes-count="${categories[0].recipesCount}"`);
	});

	it("opens with a single h1 and the recipe total from the data", async () => {
		const container = await AstroContainer.create();
		const html = await container.renderToString(CategoriesPage);
		const total = categories.reduce((sum, category) => sum + category.recipesCount, 0);

		expect(html.match(/<h1/g)).toHaveLength(1);
		expect(html).toMatch(/class="page-hero__title"[^>]*>\s*Все <span[^>]*>категории<\/span>/);
		expect(html).toContain(`${total.toLocaleString("ru-RU")} рецептов`);
	});
});

describe("/category/[slug]", () => {
	it("builds a page for every category", () => {
		const slugs = getStaticPaths().map((path) => path.params.slug);

		expect(slugs).toEqual(categories.map((category) => category.slug));
	});

	it("lists the recipes from the category's dish groups", async () => {
		const category = categories.find((c) => c.groups.includes("Супы"))!;
		const container = await AstroContainer.create();
		const html = await container.renderToString(CategoryPage, { props: { category }, params: { slug: category.slug } });
		const expected = recipes.filter((recipe) => category.groups.includes(recipe.category));

		expect(expected.length).toBeGreaterThan(0);
		expect(html.match(/class="recipe-card"/g)).toHaveLength(expected.length);
		expect(html).toContain(`${category.title} — Foodies.academy`);
	});
});
