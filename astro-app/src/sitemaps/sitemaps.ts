// The sitemaps: /sitemap.xml lists one sitemap per kind of page (sitemap-recipes.xml…), so each
// stays small and Search Console shows how many pages of each kind are indexed. Left out: log-in,
// profile, search, page 2+ of lists, authors whose page Yoast SEO marks noindex and thin ingredients.
// English pages have their own sitemaps (sitemap-en-recipes.xml…): only what is translated.
import { localize, withLang, type Lang } from "../i18n/lang";

const API_URL = import.meta.env.API_URL ?? "http://localhost:8000/api";

const load = async <T>(lang: Lang, path: string): Promise<T> => (await fetch(withLang(lang, `${API_URL}${path}`)).then((r) => r.json())).data;

/** A page in a sitemap: its path, last change and photos (full URLs). */
export interface Entry {
	path: string;
	modified?: string | null;
	images?: string[];
}

interface RecipeEntry {
	slug: string;
	modified: string | null;
	images: string[];
}

/** The sitemaps of one language; paths are under its prefix (/en/recipe/…). */
function sitemapsOf(lang: Lang): Record<string, () => Promise<Entry[]>> {
	const at = (path: string) => localize(lang, path);
	const terms = async (path: string, prefix: string): Promise<Entry[]> =>
		(await load<{ slug: string }[]>(lang, path)).map(({ slug }) => ({ path: at(`${prefix}/${slug}`) }));
	const paths = (prefix: string, slugs: string[]): Entry[] => slugs.map((slug) => ({ path: at(`${prefix}/${slug}`) }));
	const recipes = () => load<RecipeEntry[]>(lang, "/recipes/sitemap");

	const common = {
		recipes: async () => (await recipes()).map(({ slug, modified }) => ({ path: at(`/recipe/${slug}`), modified })),
		// Google Images: each recipe page with its photos.
		images: async () =>
			(await recipes()).filter(({ images }) => images.length > 0).map(({ slug, images }) => ({ path: at(`/recipe/${slug}`), images })),
		categories: async () => [...(await terms("/categories?type=course", "/category")), ...(await terms("/categories?type=country", "/category"))],
		ingredients: async () =>
			// Thin ones (Laravel's `indexed`: fewer than 3 recipes, no text of their own) are noindex.
			(await load<{ slug: string; indexed: boolean }[]>(lang, "/ingredients"))
				.filter(({ indexed }) => indexed)
				.map(({ slug }) => ({ path: at(`/ingredient/${slug}`) })),
		collections: () => terms("/collections", "/collections"),
	};
	if (lang !== "ru") {
		// News, videos, the blog and kitchen hacks are not translated yet; site pages only those
		// with a translation (/api/pages lists them).
		return {
			pages: async () => [...["/", "/categories", "/kitchens", "/authors"].map((path) => ({ path: at(path) })), ...(await terms("/pages", ""))],
			...common,
			authors: () => terms("/authors", "/author"),
		};
	}

	return {
		pages: async () => [
			...["/", "/categories", "/kitchens", "/authors", "/news", "/video", "/blog", "/lifehacks"].map((path) => ({ path })),
			...(await terms("/pages", "")),
		],
		...common,
		authors: async () => {
			const authors = await load<{ slug: string }[]>(lang, "/authors");
			const pages = await Promise.all(authors.map(({ slug }) => load<{ slug: string; seo?: { index?: boolean } | null }>(lang, `/authors/${slug}`)));
			return pages.filter((author) => author.seo?.index !== false).map(({ slug }) => ({ path: `/author/${slug}` }));
		},
		articles: async () => [...paths("/news", await load<string[]>(lang, "/news/slugs")), ...paths("/blog", await load<string[]>(lang, "/blog/slugs"))],
		videos: async () => paths("/video", await load<string[]>(lang, "/videos/slugs")),
		lifehacks: () => terms("/lifehacks", "/lifehack"),
	};
}

export const SITEMAPS: Record<string, () => Promise<Entry[]>> = {
	...sitemapsOf("ru"),
	...Object.fromEntries(Object.entries(sitemapsOf("en")).map(([name, build]) => [`en-${name}`, build])),
};

const escape = (text: string) => text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");

const xml = (body: string) =>
	new Response(`<?xml version="1.0" encoding="UTF-8"?>\n${body}\n`, { headers: { "Content-Type": "application/xml; charset=utf-8" } });

export function sitemapIndex(site: URL): Response {
	const items = Object.keys(SITEMAPS).map((name) => `\t<sitemap><loc>${new URL(`/sitemap-${name}.xml`, site).href}</loc></sitemap>`);
	return xml(`<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${items.join("\n")}\n</sitemapindex>`);
}

export function urlset(entries: Entry[], site: URL): Response {
	const seen = new Set<string>();
	const items = entries
		.filter(({ path }) => !seen.has(path) && seen.add(path))
		.map(({ path, modified, images = [] }) => {
			const parts = [`<loc>${escape(new URL(path, site).href)}</loc>`];
			if (modified) parts.push(`<lastmod>${modified}</lastmod>`);
			for (const image of images) parts.push(`<image:image><image:loc>${escape(image)}</image:loc></image:image>`);
			return `\t<url>${parts.join("")}</url>`;
		});
	const image = entries.some(({ images }) => images?.length) ? ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"' : "";
	return xml(`<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"${image}>\n${items.join("\n")}\n</urlset>`);
}
