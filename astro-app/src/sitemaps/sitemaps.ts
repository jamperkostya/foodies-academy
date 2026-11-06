// The sitemaps: /sitemap.xml lists one sitemap per kind of page (sitemap-recipes.xml…), so each
// stays small and Search Console shows how many pages of each kind are indexed. Left out: log-in,
// profile, search, page 2+ of lists, and authors whose page Yoast SEO marks noindex.
const API_URL = import.meta.env.API_URL ?? "http://localhost:8000/api";

const load = async <T>(path: string): Promise<T> => (await fetch(`${API_URL}${path}`).then((r) => r.json())).data;

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

const terms = async (path: string, prefix: string): Promise<Entry[]> =>
	(await load<{ slug: string }[]>(path)).map(({ slug }) => ({ path: `${prefix}/${slug}` }));

const paths = (prefix: string, slugs: string[]): Entry[] => slugs.map((slug) => ({ path: `${prefix}/${slug}` }));

export const SITEMAPS: Record<string, () => Promise<Entry[]>> = {
	pages: async () => [
		...["/", "/categories", "/kitchens", "/authors", "/news", "/video", "/blog", "/lifehacks"].map((path) => ({ path })),
		...(await terms("/pages", "")),
	],
	recipes: async () =>
		(await load<RecipeEntry[]>("/recipes/sitemap")).map(({ slug, modified }) => ({ path: `/recipe/${slug}`, modified })),
	// Google Images: each recipe page with its photos.
	images: async () =>
		(await load<RecipeEntry[]>("/recipes/sitemap"))
			.filter(({ images }) => images.length > 0)
			.map(({ slug, images }) => ({ path: `/recipe/${slug}`, images })),
	categories: async () => [...(await terms("/categories?type=course", "/category")), ...(await terms("/categories?type=country", "/category"))],
	ingredients: () => terms("/ingredients", "/ingredient"),
	collections: () => terms("/collections", "/collections"),
	authors: async () => {
		const authors = await load<{ slug: string }[]>("/authors");
		const pages = await Promise.all(authors.map(({ slug }) => load<{ slug: string; seo?: { index?: boolean } | null }>(`/authors/${slug}`)));
		return pages.filter((author) => author.seo?.index !== false).map(({ slug }) => ({ path: `/author/${slug}` }));
	},
	articles: async () => [...paths("/news", await load<string[]>("/news/slugs")), ...paths("/blog", await load<string[]>("/blog/slugs"))],
	videos: async () => paths("/video", await load<string[]>("/videos/slugs")),
	lifehacks: () => terms("/lifehacks", "/lifehack"),
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
