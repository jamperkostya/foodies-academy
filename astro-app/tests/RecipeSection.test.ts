import { experimental_AstroContainer as AstroContainer } from "astro/container";
import { describe, expect, it } from "vitest";
import RecipeSection from "../src/components/sections/RecipeSection.astro";
import type { Recipe } from "../src/types/recipe";

const recipe = (id: string, category: string, ingredients: string[]): Recipe => ({
	id,
	slug: `slug-${id}`,
	title: `Title ${id}`,
	image: "/img.jpg",
	category,
	time: 10,
	isNew: true,
	isTop: true,
	rating: 4,
	ingredients,
	comments: 0,
	views: 0,
	likes: 0,
});

const recipes = [recipe("1", "Супы", ["Курица", "Овощи"]), recipe("2", "Салаты", ["Овощи"])];

const render = async (props: Record<string, unknown>) => {
	const container = await AstroContainer.create();
	return container.renderToString(RecipeSection, { props: { recipes, ...props } });
};

const filterLabels = (html: string) => [...html.matchAll(/filters__btn[^>]*>([^<]+)</g)].map((m) => m[1]);

describe("RecipeSection", () => {
	it("renders no filters by default", async () => {
		const html = await render({});

		expect(html).not.toContain('class="filters');
		expect(html).not.toContain("data-groups");
	});

	it("builds filters from groupsOf and tags each card with its groups", async () => {
		const html = await render({ groupsOf: (r: Recipe) => r.ingredients, href: "/recipes" });

		expect(filterLabels(html)).toEqual(["Все", "Курица", "Овощи"]);
		expect(html).toContain('data-groups="[&quot;Курица&quot;,&quot;Овощи&quot;]"');
		expect(html).toContain("Смотреть все");
	});
});
