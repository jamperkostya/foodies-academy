// /sitemap.xml: the index of the sitemaps of each kind (src/sitemaps/sitemaps.ts).
import type { APIRoute } from "astro";
import { sitemapIndex } from "../sitemaps/sitemaps";

export const GET: APIRoute = ({ site }) => sitemapIndex(site ?? new URL("https://foodies.academy"));
