import { experimental_AstroContainer as AstroContainer } from "astro/container";
import { describe, expect, it } from "vitest";
import CategoryGrid from "../src/components/categories/CategoryGrid.astro";
import type { Category } from "../src/types/category";

const category = (id: string, groups: string[], recipesCount = 10): Category => ({
	id,
	slug: `slug-${id}`,
	title: `Title ${id}`,
	image: "/img.jpg",
	groups,
	recipesCount,
});

const render = async (categories: Category[], href?: string) => {
	const container = await AstroContainer.create();
	return container.renderToString(CategoryGrid, { props: { categories, href } });
};

describe("CategoryGrid", () => {
	it("renders an 'all' filter plus one filter per distinct group, in order of first appearance", async () => {
		const html = await render([category("1", ["Супы"]), category("2", ["Десерты", "Супы"]), category("3", ["Выпечка"])]);
		const labels = [...html.matchAll(/filters__btn[^>]*>([^<]+)</g)].map((m) => m[1]);

		expect(labels).toEqual(["Все", "Супы", "Десерты", "Выпечка"]);
	});

	it("starts with 'all' active and every card visible", async () => {
		const html = await render([category("1", ["Супы"]), category("2", ["Десерты"])]);

		expect(html).toMatch(/is-active"[^>]*aria-pressed="true"[^>]*data-group=""/);
		expect(html.match(/class="category-card"/g)).toHaveLength(2);
		expect(html).not.toMatch(/<a[^>]*category-card[^>]*hidden/);
	});

	it("tags each card with all of its groups so any of those filters can match it", async () => {
		const html = await render([category("1", ["Супы", "Десерты"])]);

		expect(html).toContain('data-groups="[&quot;Супы&quot;,&quot;Десерты&quot;]"');
	});

	it("pluralizes the recipe count in Russian", async () => {
		const html = await render([category("1", ["A"], 1), category("2", ["A"], 3), category("3", ["A"], 11)]);

		expect(html).toContain("1 рецепт<");
		expect(html).toContain("3 рецепта<");
		expect(html).toContain("11 рецептов<");
	});

	it("ends the filters row with a 'Смотреть все' link when href is given", async () => {
		const html = await render([category("1", ["Супы"])], "/categories");
		const filters = html.slice(html.indexOf('class="filters'), html.indexOf('class="carousel'));

		expect(filters).toMatch(/<a href="\/categories"[^>]*filters__all[^>]*>Смотреть все<\/a>\s*<\/div>/);
	});

	it("has no 'Смотреть все' link without href", async () => {
		const html = await render([category("1", ["Супы"])]);

		expect(html).not.toContain("Смотреть все");
	});
});
