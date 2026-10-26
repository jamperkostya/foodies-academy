import { experimental_AstroContainer as AstroContainer } from "astro/container";
import { describe, expect, it } from "vitest";
import LifehacksPage from "../src/pages/lifehacks.astro";
import BlogPage from "../src/pages/blog.astro";
import AuthorPage, { getStaticPaths as authorPaths } from "../src/pages/author/[slug].astro";
import NotFoundPage from "../src/pages/404.astro";
import { lifehacks } from "../src/data/lifehacks";
import { posts } from "../src/data/posts";
import { authors } from "../src/data/authors";

const render = async (page: Parameters<AstroContainer["renderToString"]>[0], options = {}) =>
	(await AstroContainer.create()).renderToString(page, options);

const count = (html: string, re: RegExp) => html.match(re)?.length ?? 0;

describe("/lifehacks", () => {
	it("lists every lifehack, showing the first six", async () => {
		const html = await render(LifehacksPage);

		expect(count(html, /class="lifehack-card"/g)).toBe(lifehacks.length);
		expect(count(html, /class="lifehack-card"[^>]*hidden/g)).toBe(lifehacks.length - 6);
		expect(html).toContain(`href="/lifehack/${lifehacks[0].slug}"`);
	});

	it("has one filter per category", async () => {
		const html = await render(LifehacksPage);
		const categories = new Set(lifehacks.map((lifehack) => lifehack.category));

		// +1 for "Все".
		expect(count(html, /class="filters__btn/g)).toBe(categories.size + 1);
		expect(html.match(/<h1/g)).toHaveLength(1);
	});
});

describe("/blog", () => {
	it("features the newest post and lists the rest", async () => {
		const html = await render(BlogPage);

		expect(html).toContain(`href="/blog/${posts[0].slug}" class="blog-featured"`);
		expect(count(html, /class="post-card"/g)).toBe(posts.length - 1);
		expect(html.match(/<h1/g)).toHaveLength(1);
	});
});

describe("/author/[slug]", () => {
	it("builds a page for every author", () => {
		expect(authorPaths().map((path) => path.params.slug)).toEqual(authors.map((author) => author.slug));
	});

	it("shows the author's posts in the second tab", async () => {
		const author = authors[0];
		const html = await render(AuthorPage, { props: { author }, params: { slug: author.slug } });
		const own = posts.filter((post) => post.author === author.name);

		expect(html).toMatch(/<h1[^>]*>Salad Konstantin<\/h1>/);
		expect(count(html, /class="post-card"/g)).toBe(own.length);
		expect(html).toMatch(/role="tab"[^>]*aria-selected="true"/);
		expect(html).toMatch(/id="panel-posts"[^>]*hidden/);
	});
});

describe("404", () => {
	it("offers search, the home page and recipe suggestions", async () => {
		const html = await render(NotFoundPage);

		expect(html.match(/<h1/g)).toHaveLength(1);
		expect(html).toContain('class="search"');
		expect(count(html, /class="recipe-card[" ]/g)).toBe(4);
	});
});
