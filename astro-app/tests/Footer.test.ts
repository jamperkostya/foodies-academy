import { experimental_AstroContainer as AstroContainer } from "astro/container";
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
		const img = html.match(/<img[^>]*logotype\.jpg[^>]*>/)?.[0];
		expect(img).toBeDefined();
		expect(img).toMatch(/alt="[^"]+"/);
		expect(img).not.toMatch(/alt=""/);
		expect(img).toContain('width="242"');
		expect(img).toContain('height="81"');
	});

	it("renders all 5 social links with an accessible name", () => {
		const socialLinks = [...html.matchAll(/<a[^>]*aria-label="[^"]*"[^>]*>\s*<svg[^>]*>\s*<use href="\/images\/icons\/sprite\.svg#social-/g)];
		expect(socialLinks).toHaveLength(5);
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
