// /robots.txt: account pages and search results stay out of the index; the sitemap is announced.
import type { APIRoute } from "astro";

export const GET: APIRoute = ({ site }) =>
	new Response(
		[
			"User-agent: *",
			"Disallow: /login",
			"Disallow: /register",
			"Disallow: /profile",
			"Disallow: /search",
			"",
			`Sitemap: ${new URL("/sitemap.xml", site).href}`,
			"",
		].join("\n"),
		{ headers: { "Content-Type": "text/plain; charset=utf-8" } },
	);
