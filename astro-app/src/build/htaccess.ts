// dist/.htaccess, written after the build. The site is served by Apache (Hestia, behind nginx):
// pages are built as files (recipe/x.html) and served at addresses without a slash at the end,
// the same as on the old WordPress site, whose addresses search engines know. Its addresses that
// changed get a 301 to the new ones.
import { writeFile } from "node:fs/promises";
import type { AstroIntegration } from "astro";

// Old WordPress addresses → new ones. Patterns are Apache's, without the leading slash.
const MOVED: [string, string][] = [
	// Without the page: the old site had fewer recipes on a page, its numbers run past the new last page.
	["^(course|cuisine)/([^/]+)(/page/[0-9]+)?(/feed)?$", "/category/$2"],
	// The lists of all dish types and countries.
	["^course$", "/categories"],
	["^cuisine$", "/kitchens"],
	["^category/novosti$", "/news"],
	["^category/zdorovoe-pitanie$", "/blog"],
	["^category/video$", "/video"],
	["^recipe(/page/[0-9]+)?$", "/categories"],
	// Print versions and attachment pages of a recipe's photos.
	["^recipe/([^/]+)/.+$", "/recipe/$1"],
	["^kuhni-mira$", "/kitchens"],
	// The old theme's page of all recipes.
	["^receipes$", "/categories"],
	["^registration$", "/register"],
	["^my-account$", "/profile"],
	["^(cart|checkout)$", "/"],
	// Yoast SEO sitemaps, as Search Console knows them: sitemap_index.xml, recipe-sitemap2.xml…
	["^(sitemap_index|[a-z_]+-sitemap[0-9]*)\\.xml$", "/sitemap.xml"],
	["^feed$", "/"],
];

// The site has no RSS: a feed of a page (/ingredient/x/feed…) goes to the page itself.
const FEED: [string, string] = ["^(.+)/feed$", "/$1"];

/** Old WordPress addresses the API knows: shortlinks /?p=ID and recipe slugs changed in the admin. */
export interface Redirects {
	ids: { id: number; to: string }[];
	slugs: { from: string; to: string }[];
}

const escape = (slug: string) => slug.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");

/**
 * Blog posts lived at /{slug} and are now at /blog/{slug}. A site page with the same slug keeps
 * its address.
 */
export function htaccess(
	blogSlugs: string[],
	pageSlugs: string[] = [],
	redirects: Redirects = { ids: [], slugs: [] },
): string {
	const pages = new Set(pageSlugs);
	const rule = ([from, to]: [string, string]) => `RewriteRule ${from} ${to} [R=301,L]`;
	const shortlink = ({ id, to }: { id: number; to: string }) =>
		`RewriteCond %{QUERY_STRING} (^|&)p=${id}(&|$)\nRewriteRule ^(index\\.php)?$ ${to} [R=301,L,QSD]`;
	return [
		"ErrorDocument 404 /404.html",
		// The English site's own "page not found".
		'<If "%{REQUEST_URI} =~ m#^/en(/|$)#">',
		"\tErrorDocument 404 /en/404.html",
		"</If>",
		// /category/x is both a page (category/x.html) and the folder of its page 2+: no slash added.
		"DirectorySlash Off",
		"RewriteEngine On",
		"",
		"# One address per page: without the slash at the end.",
		"RewriteRule ^(.+)/$ /$1 [R=301,L]",
		"",
		"# Like buttons of the old theme: actions, not pages.",
		"RewriteCond %{QUERY_STRING} (^|&)act=(un)?like(&|$)",
		"RewriteRule (^|/)index\\.php$ - [G]",
		"",
		...(redirects.ids.length
			? [
					"# Shortlinks /?p=ID. There are hundreds: any other address skips them in one step.",
					"RewriteCond %{QUERY_STRING} !(^|&)p=[0-9]+(&|$)",
					`RewriteRule ^ - [S=${redirects.ids.length}]`,
					...redirects.ids.map(shortlink),
					"",
				]
			: []),
		"# Addresses of the old WordPress site.",
		...MOVED.map(rule),
		...redirects.slugs.map(({ from, to }) => rule([`^${escape(from)}$`, to])),
		// The feed of a post too, in one step and not through /{slug}.
		...blogSlugs.filter((slug) => !pages.has(slug)).map((slug) => rule([`^${escape(slug)}(/feed)?$`, `/blog/${slug}`])),
		rule(FEED),
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
				const [blog, pages, redirects] = await Promise.all([load("/blog/slugs"), load("/pages"), load("/redirects")]);
				const text = htaccess(blog, pages.map((page: { slug: string }) => page.slug), redirects);
				await writeFile(new URL(".htaccess", dir), text);
			},
		},
	};
}
