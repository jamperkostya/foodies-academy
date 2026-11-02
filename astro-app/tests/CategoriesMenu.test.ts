import { experimental_AstroContainer as AstroContainer } from "astro/container";
import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";

// What GET /api/categories?type=course and /api/categories/popular?type=country return.
const courses = [
	{ name: "Супы", slug: "soups" },
	{ name: "Закуски", slug: "snacks" },
];
const countries = [
	{ name: "Италия", slug: "italiya" },
	{ name: "Франция", slug: "frantsiya" },
];

describe("CategoriesMenu", () => {
	let html: string;
	const requested: string[] = [];

	beforeAll(async () => {
		vi.stubGlobal("fetch", async (url: string) => {
			requested.push(url);
			return new Response(JSON.stringify({ data: url.includes("type=country") ? countries : courses }));
		});
		// The menu data is loaded when its module is imported, so import it after stubbing fetch.
		const { default: CategoriesMenu } = await import("../src/components/layout/CategoriesMenu.astro");
		html = await (await AstroContainer.create()).renderToString(CategoriesMenu);
	});

	afterAll(() => vi.unstubAllGlobals());

	it("has a dish types column and a world cuisines column", () => {
		const titles = [...html.matchAll(/categories-menu__title[^>]*>([^<]+)</g)].map((m) => m[1]);

		expect(titles).toEqual(["Типы блюд", "Кухни мира"]);
	});

	it("links the course categories and the most popular countries", () => {
		for (const term of [...courses, ...countries]) expect(html).toContain(`href="/category/${term.slug}"`);
		expect(requested).toContainEqual(expect.stringMatching(/\/categories\/popular\?type=country&limit=12$/));
	});

	it("ends each column with a link to the whole list", () => {
		expect(html).toMatch(/href="\/categories"[^>]*>Все категории</);
		expect(html).toMatch(/href="\/kitchens"[^>]*>Все кухни</);
	});

	it("links lifehacks and video recipes after the columns", () => {
		expect(html).toMatch(/href="\/lifehacks"[^>]*>Лайфхаки</);
		expect(html).toMatch(/href="\/video"[^>]*>Видеорецепты</);
	});
});
