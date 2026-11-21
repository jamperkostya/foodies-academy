// /sitemap-recipes.xml, /sitemap-images.xml…: one sitemap per kind of page, listed in /sitemap.xml.
import type { APIRoute, GetStaticPaths } from "astro";
import { SITEMAPS, urlset } from "../sitemaps/sitemaps";
import { builds } from "../i18n/lang";

// The sitemaps of the languages this build makes (sitemap-en-…: English).
export const getStaticPaths: GetStaticPaths = () =>
	Object.keys(SITEMAPS)
		.filter((name) => builds(name.startsWith("en-") ? "en" : "ru"))
		.map((name) => ({ params: { name } }));

export const GET: APIRoute = async ({ params, site }) =>
	urlset(await SITEMAPS[params.name as string](), site ?? new URL("https://foodies.academy"));
