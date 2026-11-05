// /robots.txt: account pages and search results stay out of the index; the sitemap is announced.
// Copies of the site on other hosts (dev.foodies.academy) are closed to search engines entirely.
import type { APIRoute } from "astro";

const PRODUCTION_HOST = "foodies.academy";

export const GET: APIRoute = ({ site }) => {
	const lines =
		site?.hostname === PRODUCTION_HOST
			? [
					"User-agent: *",
					"Disallow: /login",
					"Disallow: /register",
					"Disallow: /profile",
					"Disallow: /search",
					"",
					`Sitemap: ${new URL("/sitemap.xml", site).href}`,
				]
			: ["User-agent: *", "Disallow: /"];

	return new Response([...lines, ""].join("\n"), { headers: { "Content-Type": "text/plain; charset=utf-8" } });
};
