// WordPress site pages are built at /{slug}. A slug that is already a section of the site
// (/blog, /search, /about with its own file…) or not a valid address is skipped, so an editor's
// page can't replace a section or break the build. Laravel's `php artisan slugs:check` reports them.
const sections = new Set(
	Object.keys(import.meta.glob("../pages/**/*.{astro,ts}"))
		.map((path) => path.replace("../pages/", "").split("/")[0].replace(/\.(astro|ts)$/, "").replace(/\.(xml|txt|json)$/, ""))
		.filter((name) => !name.startsWith("[") && name !== "index"),
);

export function isTakenAddress(slug: string): boolean {
	return sections.has(slug) || !/^[a-z0-9_-]+$/.test(slug);
}
