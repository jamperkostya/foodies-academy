// /sitemap-recipes.xml, /sitemap-images.xml…: one sitemap per kind of page, listed in /sitemap.xml.
import type { APIRoute, GetStaticPaths } from "astro";
import { SITEMAPS, urlset } from "../sitemaps/sitemaps";

export const getStaticPaths: GetStaticPaths = () => Object.keys(SITEMAPS).map((name) => ({ params: { name } }));

export const GET: APIRoute = async ({ params, site }) =>
	urlset(await SITEMAPS[params.name as string](), site ?? new URL("https://foodies.academy"));
