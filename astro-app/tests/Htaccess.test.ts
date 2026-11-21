import { describe, expect, it } from "vitest";
import { htaccess } from "../src/build/htaccess";

describe(".htaccess", () => {
	it("sends old blog addresses to /blog and leaves site pages alone", () => {
		const text = htaccess(["receipes", "about"], ["about"]);

		expect(text).toContain("RewriteRule ^receipes(/feed)?$ /blog/receipes [R=301,L]");
		expect(text).not.toContain("/blog/about");
	});

	it("moves old taxonomy addresses and drops the slash at the end", () => {
		const text = htaccess([]);

		// Old page numbers run past the new last page: to the first one.
		expect(text).toContain("RewriteRule ^(course|cuisine)/([^/]+)(/page/[0-9]+)?(/feed)?$ /category/$2 [R=301,L]");
		// The empty /course and /cuisine pages are gone: their lists.
		expect(text).toContain("RewriteRule ^course$ /categories [R=301,L]");
		expect(text).toContain("RewriteRule ^cuisine$ /kitchens [R=301,L]");
		expect(text).toContain("RewriteRule ^receipes$ /categories [R=301,L]");
		expect(text).toContain("RewriteRule ^ingredient/krevetki-langustinyi$ /ingredient/langustinyi [R=301,L]");
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

	it("sends a feed to its page: a blog post's in one step, before the rule for any feed", () => {
		const text = htaccess(["rizotto"]);

		expect(text).toContain("RewriteRule ^(.+)/feed$ /$1 [R=301,L]");
		expect(text).toContain("RewriteRule ^feed$ / [R=301,L]");
		expect(text.indexOf("^rizotto(/feed)?$")).toBeLessThan(text.indexOf("^(.+)/feed$"));
		// A recipe's feed goes with its other subpages to the recipe.
		expect(text.indexOf("^recipe/([^/]+)/.+$")).toBeLessThan(text.indexOf("^(.+)/feed$"));
	});

	it("answers the old like buttons with 410 Gone", () => {
		const text = htaccess([]);

		expect(text).toContain(
			"RewriteCond %{QUERY_STRING} (^|&)act=(un)?like(&|$)\nRewriteRule (^|/)index\\.php$ - [G]",
		);
	});

	it("leads shortlinks and changed recipe slugs to the pages, skipping the shortlinks for other addresses", () => {
		const text = htaccess([], [], {
			ids: [
				{ id: 159, to: "/recipe/tartar-iz-lososya" },
				{ id: 564, to: "/blog/rizotto" },
			],
			slugs: [
				{ from: "recipe/sous-chimichurri-chimichurri", to: "/recipe/sous-chimichurri" },
				{ from: "recipe/гаспачо", to: "/recipe/gaspacho" },
			],
		});

		expect(text).toContain("RewriteCond %{QUERY_STRING} !(^|&)p=[0-9]+(&|$)\nRewriteRule ^ - [S=2]");
		expect(text).toContain(
			"RewriteCond %{QUERY_STRING} (^|&)p=159(&|$)\nRewriteRule ^(index\\.php)?$ /recipe/tartar-iz-lososya [R=301,L,QSD]",
		);
		expect(text).toContain("RewriteRule ^recipe/sous-chimichurri-chimichurri$ /recipe/sous-chimichurri [R=301,L]");
		expect(text).toContain("RewriteRule ^recipe/гаспачо$ /recipe/gaspacho [R=301,L]");
		// [S=2] skips exactly the two shortlink rules.
		const skipped = text.split("[S=2]")[1].split("\n").filter((line) => line.startsWith("RewriteRule"));
		expect(skipped.slice(0, 2).every((line) => line.includes("[R=301,L,QSD]"))).toBe(true);
		expect(skipped[2]).not.toContain("QSD");
	});

	it("has no skip rule without shortlinks", () => {
		expect(htaccess([])).not.toContain("[S=");
	});
});
