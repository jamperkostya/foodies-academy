import { experimental_AstroContainer as AstroContainer } from "astro/container";
import { beforeAll, describe, expect, it } from "vitest";
import CategoriesMenu from "../src/components/layout/CategoriesMenu.astro";
import { categories } from "../src/data/categories";

describe("CategoriesMenu", () => {
	let html: string;

	beforeAll(async () => {
		const container = await AstroContainer.create();
		html = await container.renderToString(CategoriesMenu);
	});

	it("has one column per category group, in the filters' order", () => {
		const groups = [...new Set(categories.flatMap((category) => category.groups))];
		const titles = [...html.matchAll(/categories-menu__title[^>]*>([^<]+)</g)].map((m) => m[1]);

		expect(titles).toEqual(groups);
	});

	it("links every category, once per group it belongs to", () => {
		const links = html.match(/class="categories-menu__link"/g) ?? [];
		const expected = categories.reduce((sum, category) => sum + category.groups.length, 0);

		expect(links).toHaveLength(expected);
		expect(html).toContain(`href="/category/${categories[0].slug}"`);
	});

	it("ends the panel with an 'all categories' link", () => {
		expect(html).toMatch(/categories-menu__all[^>]*>Все категории</);
	});
});
