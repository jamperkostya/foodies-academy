// dist/.htaccess, written after the build. The site is served by Apache (Hestia, behind nginx):
// pages are built as files (recipe/x.html) and served at addresses without a slash at the end,
// the same as on the old WordPress site, whose addresses search engines know. Its addresses that
// changed get a 301 to the new ones.
import { writeFile } from "node:fs/promises";
import type { AstroIntegration } from "astro";

// Old WordPress addresses → new ones. Patterns are Apache's, without the leading slash.
const MOVED: [string, string][] = [
	["^(course|cuisine)/([^/]+)(/page/[0-9]+)?$", "/category/$2$3"],
	["^category/novosti$", "/news"],
	["^category/zdorovoe-pitanie$", "/blog"],
	["^category/video$", "/video"],
	["^recipe(/page/[0-9]+)?$", "/categories"],
	// Print versions and attachment pages of a recipe's photos.
	["^recipe/([^/]+)/.+$", "/recipe/$1"],
	["^kuhni-mira$", "/kitchens"],
	["^registration$", "/register"],
	["^my-account$", "/profile"],
	["^(cart|checkout)$", "/"],
	// Yoast SEO sitemaps, as Search Console knows them: sitemap_index.xml, recipe-sitemap2.xml…
	["^(sitemap_index|[a-z_]+-sitemap[0-9]*)\\.xml$", "/sitemap.xml"],
];

const escape = (slug: string) => slug.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");

/**
 * Blog posts lived at /{slug} and are now at /blog/{slug}. A site page with the same slug keeps
 * its address.
 */
export function htaccess(blogSlugs: string[], pageSlugs: string[] = []): string {
	const pages = new Set(pageSlugs);
	const rule = ([from, to]: [string, string]) => `RewriteRule ${from} ${to} [R=301,L]`;
	return [
		"ErrorDocument 404 /404.html",
		// /category/x is both a page (category/x.html) and the folder of its page 2+: no slash added.
		"DirectorySlash Off",
		"RewriteEngine On",
		"",
		"# One address per page: without the slash at the end.",
		"RewriteRule ^(.+)/$ /$1 [R=301,L]",
		"",
		"# Addresses of the old WordPress site.",
		...MOVED.map(rule),
		...blogSlugs.filter((slug) => !pages.has(slug)).map((slug) => rule([`^${escape(slug)}$`, `/blog/${slug}`])),
		"",
		"# /recipe/x is served from recipe/x.html.",
		// Not %{REQUEST_FILENAME}: for /news/feed it is …/news (the rest goes to PATH_INFO), news.html
		// exists, and /news/feed.html is rewritten again and again until Apache gives up with a 500.
		"RewriteCond %{DOCUMENT_ROOT}/$1.html -f",
		"RewriteRule ^(.+)$ /$1.html [L]",
		"",
	].join("\n");
}

export function htaccessIntegration(apiUrl: string): AstroIntegration {
	const load = async (path: string) => (await fetch(`${apiUrl}${path}`).then((r) => r.json())).data;
	return {
		name: "foodies-htaccess",
		hooks: {
			"astro:build:done": async ({ dir }) => {
				const [blog, pages] = await Promise.all([load("/blog/slugs"), load("/pages")]);
				await writeFile(new URL(".htaccess", dir), htaccess(blog, pages.map((page: { slug: string }) => page.slug)));
			},
		},
	};
}
