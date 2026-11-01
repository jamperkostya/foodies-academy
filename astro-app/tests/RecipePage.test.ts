import { experimental_AstroContainer as AstroContainer } from "astro/container";
import { describe, expect, it } from "vitest";
import RecipePage from "../src/pages/recipe/[slug].astro";
import { recipes } from "../src/data/recipes";
import { jsonLd } from "../src/utils/jsonLd";

const renderRecipe = async (slug: string) => {
	const recipe = recipes.find((item) => item.slug === slug)!;
	const html = await (await AstroContainer.create()).renderToString(RecipePage, { props: { recipe }, params: { slug } });
	const json = html.match(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/)?.[1];
	return { recipe, html, schema: json && JSON.parse(json) };
};

describe("/recipe/[slug]", () => {
	it("shows the recipe's own title, rating and review count", async () => {
		const { recipe, html } = await renderRecipe("borscht");

		expect(html).toMatch(new RegExp(`<h1[^>]*>\\s*${recipe.title}`));
		expect(html.match(/<h1[^>]*>[\s\S]*?<\/h1>/)?.[0]).not.toMatch(/Карбонара/i);
		expect(html).toContain(`>${recipe.comments} отзыв`);
	});

	it("describes the recipe as schema.org Recipe structured data", async () => {
		const { recipe, schema } = await renderRecipe("borscht");

		expect(schema["@context"]).toBe("https://schema.org");
		expect(schema["@type"]).toBe("Recipe");
		expect(schema.name).toBe(recipe.title);
		expect(schema.image).toEqual([recipe.image]);
		expect(schema.totalTime).toBe(`PT${recipe.time}M`);
		expect(schema.aggregateRating).toMatchObject({ ratingValue: recipe.rating, ratingCount: recipe.comments });
		expect(schema.recipeIngredient.length).toBeGreaterThan(0);
		expect(schema.recipeInstructions[0]).toMatchObject({ "@type": "HowToStep" });
	});
});

describe("jsonLd", () => {
	it("can't close the script tag it is written into", () => {
		const text = jsonLd({ name: "</script><script>alert(1)</script>" });

		expect(text).not.toContain("</script>");
		expect(JSON.parse(text).name).toBe("</script><script>alert(1)</script>");
	});
});
