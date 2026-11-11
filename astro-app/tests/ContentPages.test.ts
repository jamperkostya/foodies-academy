import { experimental_AstroContainer as AstroContainer } from "astro/container";
import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import LifehacksPage from "../src/pages/lifehacks.astro";
import LifehackPage from "../src/pages/lifehack/[slug].astro";
import NotFoundPage from "../src/pages/404.astro";
import BlogPage from "../src/pages/blog.astro";
import BlogPostPage from "../src/pages/blog/[slug].astro";
import NewsPostPage from "../src/pages/news/[slug].astro";
import type { Lifehack } from "../src/types/lifehack";

const render = async (page: Parameters<AstroContainer["renderToString"]>[0], options = {}) =>
	(await AstroContainer.create()).renderToString(page, options);

const count = (html: string, re: RegExp) => html.match(re)?.length ?? 0;

// What GET /api/lifehacks returns (WordPress post type "lifehack").
const lifehack = (i: number, category: string): Lifehack => ({
	id: i, slug: `lifehack-${i}`, title: `Лайфхак ${i}`, titleAccent: "", category, icon: "🍝", image: "/img.jpg",
	description: `Описание ${i}`, intro: "Вступление", points: [{ title: "Мало воды.", text: "Литр на 100 г." }, { title: "", text: "Без заголовка" }],
	tip: { title: "Главное правило", text: "Солите воду" }, author: { slug: "admin", name: "Олег", info: "243 рецепта" },
	date: "3 августа 2026", readTime: 2, videoMinutes: null, commentCount: 1,
});
const lifehacks = [lifehack(1, "Хранение"), lifehack(2, "Сервировка"), ...Array.from({ length: 6 }, (_, i) => lifehack(i + 3, "Хранение"))];

describe("/lifehacks", () => {
	beforeAll(() => vi.stubGlobal("fetch", async () => new Response(JSON.stringify({ data: lifehacks }))));
	afterAll(() => vi.unstubAllGlobals());

	it("lists every lifehack from WordPress, showing the first five and an in-feed ad", async () => {
		const html = await render(LifehacksPage);
		const grid = [...html.matchAll(/class="(lifehack-card|feed-ad no-print)"([^>]*)>/g)].map(([, cls, attrs]) => (cls === "lifehack-card" ? "l" : "A") + (/\bhidden\b/.test(attrs) ? "-" : ""));

		expect(grid.join(" ")).toBe("l l l l l A l- l- l-");
		expect(html).toContain('href="/lifehack/lifehack-1"');
	});

	it("has one filter per category", async () => {
		const html = await render(LifehacksPage);

		// "Все", "Хранение", "Сервировка".
		expect(count(html, /class="filters__btn/g)).toBe(3);
		expect(html.match(/<h1/g)).toHaveLength(1);
	});
});

describe("/lifehack/[slug]", () => {
	const detail = { ...lifehacks[0], comments: [{ name: "Мария", date: "4 августа 2026", text: "Спасибо!" }], seoText: "<p>О лайфхаках</p>", seo: null };
	beforeAll(() => vi.stubGlobal("fetch", async (url: string) => new Response(JSON.stringify({ data: url.endsWith("/lifehacks") ? lifehacks : detail }))));
	afterAll(() => vi.unstubAllGlobals());

	it("shows the points, the tip, comments posted to Laravel and the SEO text", async () => {
		const html = await render(LifehackPage, { params: { slug: "lifehack-1" } });

		expect(html.match(/<h1/g)).toHaveLength(1);
		expect(html).toContain("Мало воды.");
		expect(html).toContain("Без заголовка");
		expect(html).toContain("Солите воду");
		expect(html).toContain("Спасибо!");
		expect(html).toMatch(/data-action="[^"]*\/lifehacks\/lifehack-1\/comments"/);
		expect(html).toContain("<p>О лайфхаках</p>");
		// Ad placeholders (not a production build) right before and after the comments.
		const comments = html.indexOf('id="comments"');
		expect(count(html, /ad-slot--placeholder/g)).toBe(2);
		expect(html.indexOf("ad-slot--placeholder")).toBeLessThan(comments);
		expect(html.lastIndexOf("ad-slot--placeholder")).toBeGreaterThan(html.indexOf("</section>", comments));
		// Similar lifehacks: same category first.
		expect(html).toMatch(/Похожие лайфхаки[\s\S]*?Лайфхак 3/);
	});
});

describe("404", () => {
	// GET /api/recipes/new?limit=12 and /api/recipes/slugs (for the random recipe button).
	const recipe = (i: number) => ({
		id: i, slug: `recipe-${i}`, title: `Рецепт ${i}`, image: "/img.jpg",
		categories: { course: { 1: "Супы" }, country: {} }, time: "1 ч", minutes: 60, isNew: true, isTop: false,
		rating: 5, servings: 2, ingredients: [], comments: 0, views: { count: 0, short: "0" }, likes: { count: 0, short: "0" },
	});
	beforeAll(() => vi.stubGlobal("fetch", async (url: string) => new Response(JSON.stringify({
		data: url.includes("/recipes/slugs") ? ["a", "b"] : Array.from({ length: 12 }, (_, i) => recipe(i + 1)),
	}))));
	afterAll(() => vi.unstubAllGlobals());

	it("offers search, the home page and recipe suggestions", async () => {
		const html = await render(NotFoundPage);

		expect(html.match(/<h1/g)).toHaveLength(1);
		expect(html).toContain('class="search"');
		// The 12 newest recipes from Laravel.
		expect(count(html, /class="recipe-card[" ]/g)).toBe(12);
	});
});

// GET /api/blog and /api/news (ordinary WordPress posts and post type "news").
const article = (i: number, category: string) => ({
	id: i, slug: `post-${i}`, title: `Статья ${i}`, excerpt: `Анонс ${i}`, category, tags: [], image: "", date: "2026-01-01",
	dateText: "1 января 2026", author: { slug: "oleg", name: "Олег" }, readTime: 3, commentCount: 0,
});

describe("/blog", () => {
	const posts = [article(1, "Новости"), article(2, "Видео"), article(3, "Новости")];
	beforeAll(() => vi.stubGlobal("fetch", async () => new Response(JSON.stringify({ data: posts }))));
	afterAll(() => vi.unstubAllGlobals());

	it("features the newest post and lists the rest with rubric filters", async () => {
		const html = await render(BlogPage);

		expect(html).toContain('href="/blog/post-1" class="blog-featured"');
		expect(count(html, /class="post-card"/g)).toBe(2);
		// "Все", "Видео", "Новости".
		expect(count(html, /class="filters__btn/g)).toBe(3);
		expect(html.match(/<h1/g)).toHaveLength(1);
	});

	it("puts an in-feed ad after every 5th card, hidden with the cards past the first page", async () => {
		vi.stubGlobal("fetch", async () => new Response(JSON.stringify({ data: Array.from({ length: 12 }, (_, i) => article(i + 1, "Новости")) })));
		const html = await render(BlogPage);
		const grid = [...html.matchAll(/class="(post-card|feed-ad no-print)"([^>]*)>/g)].map(([, cls, attrs]) => (cls === "post-card" ? "p" : "A") + (/\bhidden\b/.test(attrs) ? "-" : ""));

		expect(grid.join(" ")).toBe("p p p p p A p- p- p- p- p- A- p-");
		// Not a production build: placeholders in the in-feed size.
		expect(count(html, /ad-slot--placeholder/g)).toBe(2);
		expect(html).toContain("ad-slot--feed");
	});
});

describe("/blog/[slug] and /news/[slug]", () => {
	const detail = { ...article(1, "Новости"), content: "<p>Текст статьи</p>", comments: [{ name: "Мария", date: "2 января 2026", text: "Спасибо!" }], seoText: "", seo: null };
	beforeAll(() => vi.stubGlobal("fetch", async (url: string) => new Response(JSON.stringify({ data: /\/(blog|news)\?/.test(url) ? [article(1, "Новости"), article(2, "Новости")] : detail }))));
	afterAll(() => vi.unstubAllGlobals());

	it("shows the text, the author and comments posted to Laravel", async () => {
		const html = await render(BlogPostPage, { params: { slug: "post-1" } });

		expect(html.match(/<h1/g)).toHaveLength(1);
		expect(html).toContain("<p>Текст статьи</p>");
		expect(html).toContain("Спасибо!");
		expect(html).toContain('href="/author/oleg"');
		expect(html).toMatch(/data-action="[^"]*\/blog\/post-1\/comments"/);
		expect(html).toContain('href="/blog/post-2"');
	});

	it("links news inside the news section", async () => {
		const html = await render(NewsPostPage, { params: { slug: "post-1" } });

		expect(html).toMatch(/data-action="[^"]*\/news\/post-1\/comments"/);
		expect(html).toContain('href="/news/post-2"');
	});
});
