import { experimental_AstroContainer as AstroContainer } from "astro/container";
import { afterEach, describe, expect, it, vi } from "vitest";
import BaseLayout from "../src/layouts/BaseLayout.astro";
import SEOTextSection from "../src/components/sections/SEOTextSection.astro";
import ReviewItem from "../src/components/article/ReviewItem.astro";
import TrustSection from "../src/components/sections/TrustSection.astro";
import PromoSection from "../src/components/sections/PromoSection.astro";
import RecipeLikes from "../src/components/article/RecipeLikes.astro";
import AuthorRating from "../src/components/article/AuthorRating.astro";
import VideoPlayer from "../src/components/videos/VideoPlayer.astro";
import ArticleHero from "../src/components/article/ArticleHero.astro";
import Section from "../src/components/layout/Section.astro";
import type { Seo } from "../src/types/seo";
import type { SitePage } from "../src/types/page";

const render = async (component: Parameters<AstroContainer["renderToString"]>[0], props = {}, request?: Request) =>
	(await AstroContainer.create()).renderToString(component, { props, request });

afterEach(() => vi.unstubAllGlobals());

const yoast: Seo = {
	title: "Борщ — рецепт - Foodies.Academy",
	description: "Как сварить борщ",
	index: true,
	follow: true,
	og: { title: "Борщ", description: "Описание для соцсетей", type: "article", image: { url: "https://img.test/b.jpg", width: 1200, height: 630, type: "image/jpeg" }, publishedTime: null, modifiedTime: "2023-02-13T20:28:17+00:00", siteName: "Foodies.Academy" },
	twitter: { card: "summary_large_image", title: null, description: null, image: null },
};

describe("SEO head (BaseLayout)", () => {
	const page = new Request("https://foodies.academy/recipe/borscht?utm=x");

	it("uses Yoast's fields and the site's own canonical", async () => {
		const html = await render(BaseLayout, { title: "Борщ", seo: yoast, breadcrumbs: [{ label: "Рецепты", href: "/categories" }, { label: "Борщ" }] }, page);

		expect(html).toContain("<title>Борщ — рецепт - Foodies.Academy</title>");
		expect(html).toContain('<meta name="description" content="Как сварить борщ">');
		expect(html).toContain('<link rel="canonical" href="https://foodies.academy/recipe/borscht">');
		expect(html).toContain('<meta property="og:image" content="https://img.test/b.jpg">');
		expect(html).toContain('<meta property="og:locale" content="ru_RU">');
		expect(html).toContain('<meta property="article:modified_time" content="2023-02-13T20:28:17+00:00">');
		expect(html).toMatch(/<meta name="robots" content="index, follow, max-snippet/);
		// Twitter falls back to Open Graph.
		expect(html).toContain('<meta name="twitter:title" content="Борщ">');
		const crumbs = JSON.parse(html.match(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/)![1]);
		expect(crumbs.itemListElement.map((item: { name: string }) => item.name)).toEqual(["Главная", "Рецепты", "Борщ"]);
	});

	it("falls back to the page's own title and picture, and honours noindex", async () => {
		const html = await render(BaseLayout, { title: "Вход", description: "Войдите", noindex: true, image: "/img/a.jpg" }, page);

		expect(html).toContain("<title>Вход — Foodies.academy</title>");
		expect(html).toContain('<meta name="robots" content="noindex, nofollow">');
		expect(html).toContain('<meta property="og:image" content="https://foodies.academy/img/a.jpg">');
		expect(html).toContain('<meta property="og:type" content="website">');
	});

	it("follows Yoast's noindex", async () => {
		const html = await render(BaseLayout, { seo: { ...yoast, index: false } }, page);

		expect(html).toContain('<meta name="robots" content="noindex, follow">');
	});
});

describe("SEO text block", () => {
	it("shows the HTML from the admin, and nothing without it", async () => {
		expect(await render(SEOTextSection, { html: "<p>О борще</p>" })).toContain("<p>О борще</p>");
		expect(await render(SEOTextSection, { html: "" })).not.toContain("seo-text");
	});
});

describe("review replies", () => {
	it("marks a reply and says whom it answers", async () => {
		const reply = await render(ReviewItem, { review: { name: "Олег", date: "1 мая 2024", text: "Спасибо!", replyTo: "Анна" } });
		const review = await render(ReviewItem, { review: { name: "Анна", date: "1 мая 2024", text: "Вкусно" } });

		expect(reply).toContain("review--reply");
		expect(reply).toMatch(/review__reply-to[^>]*>↳ ответ для Анна/);
		expect(review).not.toContain("review--reply");
	});
});

describe("Нам доверяют", () => {
	it("shows the totals from the database in Russian", async () => {
		vi.stubGlobal("fetch", async () => new Response(JSON.stringify({ data: { recipes: 640, ratings: 313806, views: 1222040, comments: 108 } })));
		const html = await render(TrustSection);
		const stats = [...html.matchAll(/trust__value[^>]*>([^<]+)<\/strong>\s*<span class="trust__label"[^>]*>([^<]+)</g)].map((m) => `${m[1]} ${m[2]}`);

		expect(stats).toEqual(["640+ рецептов", "313 тыс.+ оценок", "1,2 млн+ просмотров", "108+ комментариев"]);
	});
});

describe("home promo block (WordPress page)", () => {
	it("shows the page's title and excerpt and links to it", async () => {
		const page: SitePage = { id: 1, slug: "get-started", title: "Get Started", excerpt: "Десять ингредиентов", content: "", image: "https://img.test/p.jpg", modified: "", seoText: "", seo: null };
		const html = await render(PromoSection, { page });

		expect(html).toContain('href="/get-started"');
		expect(html).toContain("Get Started");
		expect(html).toContain("Десять ингредиентов");
		expect(html).toContain("https://img.test/p.jpg");
	});
});

describe("section heading", () => {
	it("puts 'Смотреть все' next to the title when given a link", async () => {
		const html = await (await AstroContainer.create()).renderToString(Section, { props: { href: "/video" }, slots: { title: "Видео" } });

		expect(html).toMatch(/section-head[\s\S]*<h2[^>]*>Видео<\/h2>[\s\S]*href="\/video"[^>]*>Смотреть все/);
	});
});

describe("likes and ratings widgets", () => {
	it("shows build-time like counts and the endpoint to vote at", async () => {
		const html = await render(RecipeLikes, { slug: "borscht", votes: { like: 649, dislike: 474 }, action: "https://api.test/recipes/borscht/likes" });

		expect(html).toContain('data-action="https://api.test/recipes/borscht/likes"');
		expect([...html.matchAll(/recipe-likes__count[^>]*>(\d+)</g)].map((m) => m[1])).toEqual(["649", "474"]);
	});

	it("offers five stars for an author", async () => {
		const html = await render(AuthorRating, { slug: "mariana", action: "https://api.test/authors/mariana/rating" });

		expect(html.match(/class="author-rating__star"/g)).toHaveLength(5);
	});
});

describe("video player", () => {
	it("shows only the preview until play is clicked", async () => {
		const html = await render(VideoPlayer, { youtubeId: "abc123", title: "Том Ям", image: "https://i.ytimg.com/vi/abc123/maxresdefault.jpg", duration: "12:40" });

		expect(html).toContain('src="https://i.ytimg.com/vi/abc123/maxresdefault.jpg"');
		expect(html).toContain('data-youtube-id="abc123"');
		expect(html).not.toContain("<iframe");
		expect(html).toContain("12:40");
	});
});

describe("recipe hero", () => {
	it("links the dish type and the country to their category pages", async () => {
		const html = await render(ArticleHero, {
			badge: "Супы", label: "Украина", title: "Борщ", description: "", image: "/img.jpg", anchor: { href: "#ingredients", label: "К рецепту" },
			badgeLinks: [{ name: "Супы", href: "/category/soups" }], labelLinks: [{ name: "Украина", href: "/category/ukraina" }],
			favorite: { slug: "borscht", id: 7 },
		});

		expect(html).toMatch(/<a class="badge badge--static article-hero__link" href="\/category\/soups"[^>]*>Супы</);
		expect(html).toMatch(/<a class="article-hero__label article-hero__link" href="\/category\/ukraina"[^>]*>Украина</);
		expect(html).toMatch(/data-hero-save="borscht" data-recipe-id="7"/);
	});
});
