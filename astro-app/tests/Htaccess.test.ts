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

		expect(text).toContain("RewriteRule ^course/([^/]+)$ /category/$1 [R=301,L]");
		expect(text).toContain("RewriteRule ^(.+)/$ /$1 [R=301,L]");
		expect(text).toContain("RewriteRule ^(sitemap_index|[a-z_]+-sitemap[0-9]*)\\.xml$ /sitemap.xml [R=301,L]");
		// The slash rule comes before the one that serves the .html files.
		expect(text.indexOf("^(.+)/$")).toBeLessThan(text.indexOf("/$1.html"));
	});
});
