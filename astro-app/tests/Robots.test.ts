import { describe, expect, it } from "vitest";
import { GET } from "../src/pages/robots.txt";

const robots = async (site: string) => (await GET({ site: new URL(site) } as Parameters<typeof GET>[0])).text();

describe("/robots.txt", () => {
	it("lets search engines into the production site and announces the sitemap", async () => {
		const text = await robots("https://foodies.academy");

		expect(text).toContain("Disallow: /login");
		expect(text).not.toMatch(/^Disallow: \/$/m);
		expect(text).toContain("Sitemap: https://foodies.academy/sitemap.xml");
	});

	it("closes copies of the site on other hosts", async () => {
		const text = await robots("https://dev.foodies.academy");

		expect(text).toMatch(/^Disallow: \/$/m);
		expect(text).not.toContain("Sitemap");
	});
});
