import { experimental_AstroContainer as AstroContainer } from "astro/container";
import { readFileSync } from "node:fs";
import { beforeAll, describe, expect, it } from "vitest";
import Footer from "../src/components/layout/Footer.astro";

describe("Footer", () => {
	let html: string;

	beforeAll(async () => {
		const container = await AstroContainer.create();
		html = await container.renderToString(Footer);
	});

	it("renders a single <footer> landmark", () => {
		expect(html.match(/<footer[ >]/g)).toHaveLength(1);
	});

	it("gives the logo a meaningful alt text and explicit dimensions (no CLS)", () => {
		// Optimised by astro:assets, so the file name keeps "logotype" but not ".jpg".
		const img = html.match(/<img[^>]*logotype[^>]*>/)?.[0];
		expect(img).toBeDefined();
		expect(img).toMatch(/alt="[^"]+"/);
		expect(img).not.toMatch(/alt=""/);
		expect(img).toContain('width="242"');
		expect(img).toContain('height="81"');
	});

	it("links to the site's social networks, each with a name and an icon from the sprite", () => {
		const sprite = readFileSync(new URL("../public/images/icons/sprite.svg", import.meta.url), "utf8");
		const links = [...html.matchAll(/<a href="([^"]+)" class="footer__social-link" aria-label="([^"]+)"([^>]*)>[\s\S]*?sprite\.svg#([\w-]+)"/g)];

		expect(links.map((m) => [m[2], m[1]])).toEqual([
			["Facebook", "https://www.facebook.com/Foodies.Academy"],
			["Instagram", "https://www.instagram.com/foodies.academy/"],
			["Telegram", "https://t.me/foodies_academy"],
			["YouTube", "https://www.youtube.com/foodies-academy"],
		]);
		for (const [, , , attrs, icon] of links) {
			expect(attrs).toContain('target="_blank"');
			expect(attrs).toContain('rel="noopener"');
			expect(sprite).toContain(`<symbol id="${icon}"`);
		}
	});

	it("has no placeholder links or commented-out markup", () => {
		expect(html).not.toMatch(/href="#?"/);
		expect(html).not.toContain("<!--");
	});

	it("labels the secondary navigation columns as a nav landmark", () => {
		expect(html).toMatch(/<nav[^>]*aria-label="[^"]+"/);
	});

	it("does not repeat the same column title twice", () => {
		const titles = [...html.matchAll(/class="footer__col-header"[^>]*>([^<]+)</g)].map((m) => m[1].trim());
		expect(titles.length).toBeGreaterThan(0);
		expect(new Set(titles).size).toBe(titles.length);
	});

	it("gives every column title a real, non-placeholder href", () => {
		const hrefs = [...html.matchAll(/<a href="([^"]*)"[^>]*class="footer__col-header"/g)].map((m) => m[1]);
		expect(hrefs.length).toBeGreaterThan(0);
		for (const href of hrefs) {
			expect(href).not.toBe("#");
			expect(href).not.toBe("");
		}
	});
});
