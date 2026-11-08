import { describe, expect, it } from "vitest";
import { htaccess } from "../src/build/htaccess";

describe(".htaccess", () => {
	it("sends old blog addresses to /blog and leaves site pages alone", () => {
		const text = htaccess(["receipes", "about"], ["about"]);

		expect(text).toContain("RewriteRule ^receipes$ /blog/receipes [R=301,L]");
		expect(text).not.toContain("/blog/about");
	});

	it("moves old taxonomy addresses and drops the slash at the end", () => {
		const text = htaccess([]);

		expect(text).toContain("RewriteRule ^(course|cuisine)/([^/]+)(/page/[0-9]+)?$ /category/$2$3 [R=301,L]");
		expect(text).toContain("RewriteRule ^recipe(/page/[0-9]+)?$ /categories [R=301,L]");
		// The recipe list's pages go to /categories before the rule for a recipe's subpages takes them.
		expect(text.indexOf("^recipe(/page")).toBeLessThan(text.indexOf("^recipe/([^/]+)/.+$"));
		expect(text).toContain("RewriteRule ^(.+)/$ /$1 [R=301,L]");
		expect(text).toContain("RewriteRule ^(sitemap_index|[a-z_]+-sitemap[0-9]*)\\.xml$ /sitemap.xml [R=301,L]");
		// The slash rule comes before the one that serves the .html files.
		expect(text.indexOf("^(.+)/$")).toBeLessThan(text.indexOf("/$1.html"));
	});

	it("checks the .html file of the whole address, so /news/feed is a 404 and not a rewrite loop", () => {
		const text = htaccess([]);

		expect(text).toContain("RewriteCond %{DOCUMENT_ROOT}/$1.html -f\nRewriteRule ^(.+)$ /$1.html [L]");
		expect(text).not.toContain("%{REQUEST_FILENAME}");
	});
});
