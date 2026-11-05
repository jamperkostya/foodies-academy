import { experimental_AstroContainer as AstroContainer } from "astro/container";
import { describe, expect, it } from "vitest";
import Hero from "../src/components/sections/Hero.astro";
import type { Recipe } from "../src/types/recipe";

// Recipes as GET /api/recipes/featured returns them.
const recipe = (slug: string): Recipe => ({
	id: 1,
	slug,
	title: `Рецепт ${slug}`,
	image: `https://foodies.academy/${slug}.jpg`,
	categories: { course: {}, country: {} },
	time: "35 мин",
	minutes: 35,
	isNew: false,
	isTop: true,
	rating: 5,
	servings: 2,
	ingredients: [],
	comments: 3,
	views: { count: 10800, short: "10.8K" },
	likes: { count: 0, short: "0" },
});
const recipes = ["borscht", "paella", "carbonara", "burger"].map(recipe);

describe("Hero", () => {
	it("shows a card per featured recipe, linked to its page", async () => {
		const html = await (await AstroContainer.create()).renderToString(Hero, { props: { recipes } });
		const hrefs = [...html.matchAll(/<a href="([^"]+)"[^>]*class="video-card/g)].map((m) => m[1]);

		expect(hrefs).toEqual(recipes.map((item) => `/recipe/${item.slug}`));
		expect(html).toContain("10.8K");
	});

	it("makes every third card a tall one", async () => {
		const html = await (await AstroContainer.create()).renderToString(Hero, { props: { recipes } });
		const sizes = [...html.matchAll(/class="video-card video-card--(\w+)/g)].map((m) => m[1]);

		expect(sizes).toEqual(["tall", "half", "half", "tall"]);
	});

	it("marks video recipes with the YouTube badge", async () => {
		const withVideo = [{ ...recipe("borscht"), youtubeId: "H89sF9Kdw_4" }, recipe("paella")];
		const html = await (await AstroContainer.create()).renderToString(Hero, { props: { recipes: withVideo } });

		expect(html.match(/video-card__play-badge/g)).toHaveLength(1);
		expect(html).toContain('data-video-id="H89sF9Kdw_4"');
	});
});
