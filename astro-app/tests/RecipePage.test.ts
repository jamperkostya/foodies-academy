import { experimental_AstroContainer as AstroContainer } from "astro/container";
import { afterEach, describe, expect, it, vi } from "vitest";
import RecipePage from "../src/pages/recipe/[slug].astro";
import { jsonLd } from "../src/utils/jsonLd";
import type { Recipe } from "../src/types/recipe";

// A recipe as GET /api/recipes/{slug} returns it.
const apiRecipe: Recipe = {
	id: 1,
	slug: "borscht",
	title: "Украинский борщ",
	image: "https://foodies.academy/wp-content/uploads/borscht-601x601.jpg",
	categories: { course: { 267: "Супы" }, country: { 326: "Украина" } },
	time: "1 ч 40 мин",
	minutes: 100,
	isNew: false,
	isTop: true,
	rating: 4.8,
	servings: 6,
	ingredients: [
		{ name: "Телятина", amount: "1", quantity: 1, unit: "кг", notes: "(голяшка, ребра)", group: "Для бульона" },
		{ name: "Свёкла", amount: "2", quantity: 2, unit: "шт", notes: "", group: "Для зажарки" },
		{ name: "Соль", amount: "по вкусу", quantity: null, unit: "", notes: "", group: "Для зажарки" },
	],
	comments: 12,
	reviews: [{ name: "Аня", date: "30 ноября 2018", text: "Классический рецепт, спасибо" }],
	prepTime: "20 мин",
	cookTime: "1 ч 20 мин",
	complexity: "Средне",
	calories: null,
	steps: [
		{ text: "Сварить бульон.", group: "Бульон" },
		{ text: "Снять пену.", group: "Бульон" },
		{ text: "Обжарить свёклу.", group: "Зажарка" },
	],
	history: '<div class="wp-block-group origin-story"><p class="origin-story__lead">Борщ известен с XVI века.</p></div>',
	views: { count: 3512, short: "3.5K" },
	likes: { count: 940, short: "940" },
};

afterEach(() => vi.unstubAllGlobals());

const renderRecipe = async (slug: string, changes: Partial<Recipe> = {}) => {
	const fetch = vi.fn(async () => new Response(JSON.stringify({ data: { ...apiRecipe, ...changes } })));
	vi.stubGlobal("fetch", fetch);
	const recipe = apiRecipe;
	const html = await (await AstroContainer.create()).renderToString(RecipePage, { params: { slug } });
	const json = html.match(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/)?.[1];
	return { recipe, html, fetch, schema: json && JSON.parse(json) };
};

describe("/recipe/[slug]", () => {
	it("loads the recipe from Laravel by its slug", async () => {
		const { fetch, html } = await renderRecipe("borscht");

		expect(fetch).toHaveBeenCalledWith(expect.stringMatching(/\/recipes\/borscht$/));
		expect(html).toContain(">Супы<");
		expect(html).toContain("Украина");
		expect(html).toContain("1 ч 40 мин");
	});

	it("shows the recipe's own title, rating and review count", async () => {
		const { recipe, html } = await renderRecipe("borscht");

		expect(html).toMatch(new RegExp(`<h1[^>]*>\\s*${recipe.title}`));
		expect(html.match(/<h1[^>]*>[\s\S]*?<\/h1>/)?.[0]).not.toMatch(/Карбонара/i);
		expect(html).toContain(`>${recipe.comments} отзыв`);
	});

	it("lists the ingredients by group for the recipe's servings", async () => {
		const { html, schema } = await renderRecipe("borscht");

		expect(html).toContain("на 6 порций");
		expect(html).toMatch(/ingredient-list__group[^>]*>Для бульона</);
		expect(html).toMatch(/data-base="1"[^>]*data-unit="кг"/);
		// "по вкусу" isn't a number, so it is shown but not rescaled
		expect(html).not.toMatch(/data-base="[^"]*"[^>]*data-unit=""/);
		expect(schema.recipeIngredient).toEqual(["1 кг Телятина (голяшка, ребра)", "2 шт Свёкла", "по вкусу Соль"]);
	});

	it("shows the recipe's comments and posts new ones to Laravel", async () => {
		const { html } = await renderRecipe("borscht");

		expect(html).toContain("Классический рецепт, спасибо");
		expect(html).toMatch(/data-action="[^"]*\/recipes\/borscht\/comments"/);
	});

	it("shows the recipes sharing the most ingredients as similar ones", async () => {
		const similar = (slug: string): Recipe => ({ ...apiRecipe, slug, title: `Похожий ${slug}`, similar: undefined });
		const { html } = await renderRecipe("borscht", { similar: [similar("shchi"), similar("rassolnik")] });

		expect(html).toContain('href="/recipe/shchi"');
		expect(html.indexOf("Похожий shchi")).toBeLessThan(html.indexOf("Похожий rassolnik"));
	});

	it("renders the origin story from the CMS markup, and only when there is one", async () => {
		const { html } = await renderRecipe("borscht");

		expect(html).toContain('id="origin"');
		expect(html).toContain("Борщ известен с XVI века.");

		const { html: withoutStory } = await renderRecipe("borscht", { history: null });
		expect(withoutStory).not.toContain('id="origin"');
	});

	it("shows the steps with their group names and only the filled-in quick facts", async () => {
		const { html, schema } = await renderRecipe("borscht");

		expect(html).toContain("0 из 3 шагов");
		expect(html).toMatch(/<strong[^>]*>Бульон<\/strong>\s*Сварить бульон\./);
		expect(html).toMatch(/<strong[^>]*>Зажарка<\/strong>\s*Обжарить/);
		expect(html.match(/<strong[^>]*>Бульон<\/strong>/g)).toHaveLength(1);
		for (const value of ["20 мин", "1 ч 20 мин", "Средне"]) expect(html).toContain(`>${value}<`);
		expect(html).not.toContain("ккал");
		expect(schema.nutrition).toBeUndefined();
	});

	it("describes the recipe as schema.org Recipe structured data", async () => {
		const { recipe, schema } = await renderRecipe("borscht");

		expect(schema["@context"]).toBe("https://schema.org");
		expect(schema["@type"]).toBe("Recipe");
		expect(schema.name).toBe(recipe.title);
		expect(schema.image).toEqual([recipe.image]);
		expect(schema.totalTime).toBe(`PT${recipe.minutes}M`);
		expect(schema).toMatchObject({ recipeCategory: "Супы", recipeCuisine: "Украина" });
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
