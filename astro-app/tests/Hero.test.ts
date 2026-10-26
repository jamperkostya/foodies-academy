import { experimental_AstroContainer as AstroContainer } from "astro/container";
import { describe, expect, it } from "vitest";
import Hero from "../src/components/sections/Hero.astro";
import { heroCards } from "../src/data/heroCards";
import { recipes } from "../src/data/recipes";

describe("Hero", () => {
	it("links every card to an existing recipe page", async () => {
		const html = await (await AstroContainer.create()).renderToString(Hero);
		const hrefs = [...html.matchAll(/<a href="([^"]+)"[^>]*class="video-card/g)].map((m) => m[1]);
		const pages = recipes.map((recipe) => `/recipe/${recipe.slug}`);

		expect(hrefs).toHaveLength(heroCards.length);
		for (const href of hrefs) expect(pages).toContain(href);
	});
});
