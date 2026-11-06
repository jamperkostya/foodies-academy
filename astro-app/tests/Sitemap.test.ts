import { afterEach, describe, expect, it, vi } from "vitest";
import { GET as index } from "../src/pages/sitemap.xml";
import { GET as child } from "../src/pages/sitemap-[name].xml";

const site = new URL("https://foodies.academy");
type Context = Parameters<typeof child>[0];
const read = async (name: string) => (await child({ params: { name }, site } as unknown as Context)).text();

const API: Record<string, unknown> = {
	"/recipes/sitemap": [
		{ slug: "borscht", modified: "2026-03-01T10:00:00+00:00", images: ["https://foodies.academy/wp-content/uploads/a&b.jpg"] },
		{ slug: "salad", modified: null, images: [] },
	],
	"/authors": [{ slug: "mariana" }, { slug: "admin" }],
	"/authors/mariana": { slug: "mariana", seo: { index: true } },
	"/authors/admin": { slug: "admin", seo: { index: false } },
};

describe("sitemaps", () => {
	afterEach(() => vi.unstubAllGlobals());

	it("/sitemap.xml lists the sitemap of each kind", async () => {
		const text = await (await index({ site } as unknown as Context)).text();

		expect(text).toContain("<sitemapindex");
		for (const name of ["pages", "recipes", "images", "categories", "ingredients", "authors", "articles", "videos", "lifehacks", "collections"]) {
			expect(text).toContain(`<loc>https://foodies.academy/sitemap-${name}.xml</loc>`);
		}
	});

	it("recipes with their last change, photos in the image sitemap, only indexable authors", async () => {
		vi.stubGlobal("fetch", async (url: string) => {
			const path = new URL(url).pathname.replace(/^\/api/, "");
			return new Response(JSON.stringify({ data: API[path] ?? [] }));
		});

		const recipes = await read("recipes");
		expect(recipes).toContain("<url><loc>https://foodies.academy/recipe/borscht</loc><lastmod>2026-03-01T10:00:00+00:00</lastmod></url>");
		expect(recipes).toContain("<url><loc>https://foodies.academy/recipe/salad</loc></url>");

		const images = await read("images");
		expect(images).toContain('xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"');
		expect(images).toContain("<image:loc>https://foodies.academy/wp-content/uploads/a&amp;b.jpg</image:loc>");
		expect(images).not.toContain("salad");

		const authors = await read("authors");
		expect(authors).toContain("/author/mariana");
		expect(authors).not.toContain("/author/admin");
	});
});
