import { experimental_AstroContainer as AstroContainer } from "astro/container";
import { describe, expect, it } from "vitest";
import CategorySection from "../src/components/sections/CategorySection.astro";
import type { Category } from "../src/types/category";

const category = (id: string, groups: string[], count = 10): Category => ({
	id: Number(id),
	name: `Title ${id}`,
	slug: `slug-${id}`,
	type: "course",
	count,
	image: "/img.jpg",
	meta: {},
	groups,
});

const render = async (categories: Category[], href?: string) => {
	const container = await AstroContainer.create();
	return container.renderToString(CategorySection, { props: { categories, href } });
};

describe("CategorySection", () => {
	it("has no filters: the row of categories is shown as is", async () => {
		const html = await render([category("1", ["Супы"]), category("2", ["Десерты"])]);

		expect(html).not.toContain('class="filters');
		expect(html.match(/class="category-card carousel__item"/g)).toHaveLength(2);
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

	it("puts the 'Смотреть все' link on the title's row when href is given", async () => {
		const html = await render([category("1", ["Супы"])], "/categories");
		const head = html.slice(html.indexOf('class="section-head"'), html.indexOf("data-filterable-carousel"));

		expect(head).toMatch(/<a href="\/categories" class="section-head__all[^"]*"[^>]*>Смотреть все<\/a>/);
	});

	it("has no 'Смотреть все' link without href", async () => {
		const html = await render([category("1", ["Супы"])]);

		expect(html).not.toContain("Смотреть все");
	});
});
