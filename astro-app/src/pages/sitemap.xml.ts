// /sitemap.xml for search engines: every page meant to be indexed. Left out: log-in, profile,
// search, page 2+ of lists, and author pages (Yoast SEO marks author archives noindex).
import type { APIRoute } from "astro";

const API_URL = import.meta.env.API_URL ?? "http://localhost:8000/api";

const load = async <T>(path: string): Promise<T> => (await fetch(`${API_URL}${path}`).then((r) => r.json())).data;

export const GET: APIRoute = async ({ site }) => {
	const [recipes, courses, countries, videos, news, blog, collections, ingredients, pages, lifehacks] = await Promise.all([
		load<string[]>("/recipes/slugs"),
		load<{ slug: string }[]>("/categories?type=course"),
		load<{ slug: string }[]>("/categories?type=country"),
		load<string[]>("/videos/slugs"),
		load<string[]>("/news/slugs"),
		load<string[]>("/blog/slugs"),
		load<{ slug: string }[]>("/collections"),
		load<{ slug: string }[]>("/ingredients/popular?limit=5"),
		load<{ slug: string }[]>("/pages"),
		load<{ slug: string }[]>("/lifehacks"),
	]);

	const paths = [
		"/",
		"/categories",
		"/kitchens",
		"/authors",
		"/news",
		"/video",
		"/blog",
		"/lifehacks",
		...pages.map((page) => `/${page.slug}`),
		...courses.map((term) => `/category/${term.slug}`),
		...countries.map((term) => `/category/${term.slug}`),
		...collections.map((collection) => `/collections/${collection.slug}`),
		...ingredients.map((ingredient) => `/ingredient/${ingredient.slug}`),
		...recipes.map((slug) => `/recipe/${slug}`),
		...videos.map((slug) => `/video/${slug}`),
		...news.map((slug) => `/news/${slug}`),
		...blog.map((slug) => `/blog/${slug}`),
		...lifehacks.map((lifehack) => `/lifehack/${lifehack.slug}`),
	];

	const urls = [...new Set(paths)].map((path) => `\t<url><loc>${new URL(path, site).href}</loc></url>`).join("\n");
	const xml = `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${urls}\n</urlset>\n`;

	return new Response(xml, { headers: { "Content-Type": "application/xml; charset=utf-8" } });
};
