import { experimental_AstroContainer as AstroContainer } from "astro/container";
import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import AuthorsPage from "../src/pages/authors.astro";
import AuthorPage, { getStaticPaths } from "../src/pages/author/[slug]/[...page].astro";
import type { AuthorProfile, AuthorSummary } from "../src/types/author";
import type { News } from "../src/types/news";
import type { Recipe } from "../src/types/recipe";

// What GET /api/authors and /api/authors/{slug}?page=N return.
const authors: AuthorSummary[] = [
	{ id: 18, slug: "mariana", name: "Mariana Demidova", avatar: "https://foodies.academy/mariana.jpg", recipes: 50 },
	{ id: 4, slug: "re_editor", name: "Платон Щукин", avatar: "", recipes: 3 },
];
const recipe = (slug: string): Recipe => ({
	id: 1, slug, title: `Рецепт ${slug}`, image: "/img.jpg",
	categories: { course: {}, country: {} },
	time: "1 ч", minutes: 60, isNew: false, isTop: false, rating: 5, servings: 2, ingredients: [], comments: 0,
	views: { count: 0, short: "0" }, likes: { count: 0, short: "0" },
});
const news: News = { id: 7, slug: "krevetki", title: "Что полезно знать о креветках", excerpt: "…", category: "Новости", tags: [], image: "", date: "2022-05-23" };
const profile = (author: AuthorSummary, extra: Partial<AuthorProfile>): AuthorProfile => ({
	...author, bio: "", since: 2019, rating: 4.8, news: [], recipes: [recipe("borscht")], total: author.recipes, page: 1, pages: 1, ...extra,
});
const mariana = profile(authors[0], { pages: 3, recipes: [recipe("borscht"), recipe("paella")] });
const platon = profile(authors[1], { bio: "Пишу о кухне.", rating: null, news: [news] });

const requested: string[] = [];
beforeAll(() =>
	vi.stubGlobal("fetch", async (url: string) => {
		requested.push(url);
		const data = url.endsWith("/authors") ? authors : url.includes("/authors/re_editor") ? platon : mariana;
		return new Response(JSON.stringify({ data }));
	}),
);
afterAll(() => vi.unstubAllGlobals());

const render = async (slug: string, page?: string) =>
	(await AstroContainer.create()).renderToString(AuthorPage, { params: { slug, page } });

describe("/authors", () => {
	it("lists every author with a photo or initials and their recipe count", async () => {
		const html = await (await AstroContainer.create()).renderToString(AuthorsPage);

		expect(html).toContain('href="/author/mariana"');
		expect(html).toMatch(/<img class="avatar[^"]*"[^>]*src="https:\/\/foodies\.academy\/mariana\.jpg"/);
		expect(html).toMatch(/author-tile__count[^>]*>50 рецептов</);
		expect(html).toMatch(/<span class="avatar[^"]*"[^>]*>ПЩ</);
	});
});

describe("/author/[slug]", () => {
	it("builds every page of 24 recipes for every author", async () => {
		const paths = (await getStaticPaths()).map((path) => path.params);

		expect(paths).toEqual([
			{ slug: "mariana", page: undefined },
			{ slug: "mariana", page: "page/2" },
			{ slug: "mariana", page: "page/3" },
			{ slug: "re_editor", page: undefined },
		]);
	});

	it("shows the author from Laravel with their recipes and pages", async () => {
		const html = await render("mariana", "page/2");

		expect(requested).toContainEqual(expect.stringMatching(/\/authors\/mariana\?page=2$/));
		expect(html).toMatch(/<h1[^>]*>Mariana Demidova<\/h1>/);
		expect(html).toContain("На Foodies Academy с 2019 года");
		expect(html).toContain('href="/recipe/paella"');
		expect(html).toMatch(/href="\/author\/mariana\/page\/3"[^>]*data-load-more/);
	});

	it("shows only what WordPress has: no news tab, rating or bio when they are empty", async () => {
		const html = await render("mariana");

		expect(html).not.toContain('id="panel-posts"');
		expect(html).not.toContain('class="author-bio');
		expect(html).toMatch(/author-hero__stat-value[^>]*>4\.8</);
		expect(html).not.toContain("Подписчиков");
	});

	it("adds the news tab and the bio when the author has them", async () => {
		const html = await render("re_editor");

		expect(html).toMatch(/role="tab"[^>]*>\s*Новости/);
		expect(html).toContain('href="/news/krevetki"');
		expect(html).toContain("Пишу о кухне.");
		expect(html).not.toContain("средний рейтинг");
	});
});
