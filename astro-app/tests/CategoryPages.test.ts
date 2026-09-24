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
