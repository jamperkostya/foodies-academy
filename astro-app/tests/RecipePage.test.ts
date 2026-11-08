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
	author: { id: 18, slug: "mariana", name: "Mariana Demidova", avatar: "https://foodies.academy/mariana.jpg", recipes: 368 },
	tip: "<p>Свёклу добавляйте в самом конце.</p><ul><li>Подавайте с пампушками.</li></ul>",
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
	// The page also has BreadcrumbList data (from the layout): pick the Recipe.
	const schemas = [...html.matchAll(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/g)].map((m) => JSON.parse(m[1]));
	return { recipe, html, fetch, schema: schemas.find((data) => data["@type"] === "Recipe") };
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

	it("leaves out the rating summary when there are no reviews or star ratings", async () => {
		const { html: empty, schema } = await renderRecipe("borscht", { reviews: [], comments: 0 });
		expect(empty).not.toContain('class="rating-summary');
		expect(schema.aggregateRating).toBeUndefined();

		const { html } = await renderRecipe("borscht");
		expect(html).toContain('class="rating-summary');
	});

	it("describes the video recipe's video, uploaded when the recipe last changed", async () => {
		const { schema } = await renderRecipe("borscht", { youtubeId: "H89sF9Kdw_4", updatedAt: "2026-03-01T10:00:00+00:00" });

		expect(schema.video).toMatchObject({
			"@type": "VideoObject",
			name: apiRecipe.title,
			thumbnailUrl: ["https://i.ytimg.com/vi/H89sF9Kdw_4/hqdefault.jpg"],
			uploadDate: "2026-03-01T10:00:00+00:00",
			embedUrl: "https://www.youtube.com/embed/H89sF9Kdw_4",
		});
		expect(schema.video.description).toBeTruthy();

		const { schema: plain } = await renderRecipe("borscht", { youtubeId: null });
		expect(plain.video).toBeUndefined();
	});

	it("lists the focus keyword and the recipe's descriptors as keywords", async () => {
		const { schema } = await renderRecipe("borscht", { focusKeyword: "борщ с пампушками", minutes: 25, youtubeId: "H89sF9Kdw_4" });
		expect(schema.keywords).toBe("борщ с пампушками, Средне, быстрый рецепт, видеорецепт");

		const { schema: bare } = await renderRecipe("borscht", { focusKeyword: "", complexity: "", youtubeId: null });
		expect(bare.keywords).toBeUndefined();
	});

	it("leaves the rating out of the structured data when the reviews have no stars", async () => {
		// Google: "rating out of range" for ratingValue 0, and the recipe loses its rich result.
		const { schema } = await renderRecipe("borscht", { rating: 0, ratingCount: 0, comments: 5 });

		expect(schema.aggregateRating).toBeUndefined();
	});

	it("splits the stars by the reviews' own ratings, without bars when there are none", async () => {
		const reviews = [
			{ name: "А", date: "1 мая 2024", text: "…", rating: 5 },
			{ name: "Б", date: "1 мая 2024", text: "…", rating: 5 },
			{ name: "В", date: "1 мая 2024", text: "…", rating: 3 },
			{ name: "Г", date: "1 мая 2024", text: "…", rating: 4 },
		];
		const { html } = await renderRecipe("borscht", { reviews, comments: 4 });
		const bars = [...html.matchAll(/rating-bar__label[^>]*>(\d)<[\s\S]*?rating-bar__percent[^>]*>(\d+)%/g)].map((m) => [m[1], m[2]]);
		expect(bars).toEqual([["5", "50"], ["4", "25"], ["3", "25"], ["2", "0"], ["1", "0"]]);

		const { html: unrated } = await renderRecipe("borscht");
		expect(unrated).toContain('class="rating-summary');
		expect(unrated).not.toContain("rating-summary__bars");
	});

	it("invites the first comment when there are none yet", async () => {
		const { html: empty } = await renderRecipe("borscht", { reviews: [], comments: 0 });
		expect(empty).toContain("Станьте первым, кто оставит комментарий для этого рецепта");

		const { html } = await renderRecipe("borscht");
		expect(html).not.toContain("Станьте первым");
	});

	it("shows the recipe's author with their recipe count", async () => {
		const { html, schema } = await renderRecipe("borscht");

		expect(html).toMatch(/author-card__name[^>]*>Mariana Demidova</);
		// The photo, the name and the button all lead to the author's page.
		expect(html).toMatch(/<a href="\/author\/mariana" class="author-card__link"[^>]*>\s*<img[^>]*src="https:\/\/foodies\.academy\/mariana\.jpg"[^>]*>\s*<strong[^>]*>Mariana Demidova</);
		expect(html).toMatch(/<a href="\/author\/mariana" class="btn btn--secondary author-card__btn"[^>]*>Профиль автора</);
		expect(html).toMatch(/author-card__info[^>]*>368 рецептов</);
		expect(schema.author).toEqual({ "@type": "Person", name: "Mariana Demidova" });
	});

	it("shows the chef's tip from recipe_notes, and only when there is one", async () => {
		const { html } = await renderRecipe("borscht");

		expect(html).toContain("Совет от шефа");
		expect(html).toMatch(/tip-box__text[^>]*><p>Свёклу добавляйте в самом конце\.<\/p><ul><li>Подавайте с пампушками\.<\/li><\/ul>/);

		const { html: withoutTip } = await renderRecipe("borscht", { tip: null });
		expect(withoutTip).not.toContain("Совет от шефа");
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

	it("shows recipe_description under the title, nothing when it is empty", async () => {
		const { html } = await renderRecipe("borscht", { description: "Наваристый борщ на говяжьем бульоне." });
		expect(html).toMatch(/article-hero__desc[^>]*>Наваристый борщ на говяжьем бульоне\.</);

		const { html: empty } = await renderRecipe("borscht", { description: "" });
		expect(empty).not.toContain("article-hero__desc");
		expect(empty).not.toMatch(/Карбонара|римская паста/);
	});

	it("keeps a single cover photo without a carousel", async () => {
		const { html } = await renderRecipe("borscht", { gallery: ["https://foodies.academy/a.jpg"], youtubeId: null });

		expect(html).not.toContain("carousel--gallery");
		expect(html).not.toContain("data-hero-video");
		expect(html).toContain("article-hero__img");
	});

	it("puts two or more photos into a carousel", async () => {
		const gallery = ["https://foodies.academy/a.jpg", "https://foodies.academy/b.jpg"];
		const { html, schema } = await renderRecipe("borscht", { gallery, youtubeId: null });

		expect(html).toContain("carousel--gallery");
		expect(html.match(/class="carousel__photo"/g)).toHaveLength(2);
		expect(html).not.toContain("video-player");
		expect(schema.image).toEqual(gallery);
	});

	it("shows the video preview as the first slide and loads no player until clicked", async () => {
		const { html } = await renderRecipe("borscht", { gallery: ["https://foodies.academy/a.jpg"], youtubeId: "H89sF9Kdw_4" });

		const track = html.match(/carousel__track[\s\S]*$/)![0];
		expect(track.indexOf("video-player")).toBeLessThan(track.indexOf("carousel__photo"));
		expect(html).toContain('data-youtube-id="H89sF9Kdw_4"');
		expect(html).toContain("i.ytimg.com/vi/H89sF9Kdw_4/");
		expect(html).not.toContain("<iframe");
	});
});

describe("jsonLd", () => {
	it("can't close the script tag it is written into", () => {
		const text = jsonLd({ name: "</script><script>alert(1)</script>" });

		expect(text).not.toContain("</script>");
		expect(JSON.parse(text).name).toBe("</script><script>alert(1)</script>");
	});
});
