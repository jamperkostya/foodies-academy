import { experimental_AstroContainer as AstroContainer } from "astro/container";
import { beforeAll, describe, expect, it } from "vitest";
import Header from "../src/components/layout/Header.astro";

describe("Header", () => {
	let html: string;

	beforeAll(async () => {
		const container = await AstroContainer.create();
		html = await container.renderToString(Header);
	});

	it("renders a single <header> landmark", () => {
		expect(html.match(/<header[ >]/g)).toHaveLength(1);
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

	it("exposes the search form as a search landmark with an accessible input", () => {
		expect(html).toMatch(/<form[^>]*role="search"/);
		expect(html).toMatch(/<input[^>]*aria-label="[^"]+"/);
	});

	it("marks the categories toggle as a collapsed disclosure for its panel", () => {
		const button = html.match(/<button[^>]*class="categories-menu__toggle[^>]*>/)?.[0];
		expect(button).toBeDefined();
		expect(button).toContain('aria-expanded="false"');
		expect(button).toContain('aria-controls="categories-menu-panel"');
		expect(html).toMatch(/id="categories-menu-panel"[^>]*hidden/);
	});

	it("gives every icon-only button an accessible name", () => {
		const iconButtons = [...html.matchAll(/<button[^>]*class="header__icon-btn[^>]*>/g)];
		expect(iconButtons.length).toBeGreaterThan(0);
		for (const [button] of iconButtons) {
			expect(button).toMatch(/aria-label="[^"]+"/);
		}
	});

	it("keeps the menu-arrow icon at its native 12x7 aspect ratio", () => {
		const useIndex = html.indexOf("sprite.svg#menu-arrow");
		expect(useIndex).toBeGreaterThan(-1);

		const svgOpenTag = html.lastIndexOf("<svg", useIndex);
		const svg = html.slice(svgOpenTag, html.indexOf(">", svgOpenTag) + 1);
		expect(svg).toContain('width="12"');
		expect(svg).toContain('height="7"');
	});
	it("opens the mobile menu from the burger", () => {
		expect(html).toMatch(/class="header__burger"[^>]*aria-expanded="false"[^>]*aria-controls="mobile-nav-panel"/);
		expect(html).toMatch(/<nav[^>]*id="mobile-nav-panel"[^>]*aria-label="[^"]+"/);
	});

	it("gives the mobile menu the site sections and a collapsed categories list", () => {
		expect(html).toMatch(/class="mobile-nav__link"[^>]*href="\/blog"/);
		expect(html).toMatch(/class="mobile-nav__link"[^>]*href="\/lifehacks"/);
		expect(html).toMatch(/aria-expanded="false"[^>]*aria-controls="mobile-nav-categories"/);
		expect(html).toMatch(/id="mobile-nav-categories"[^>]*hidden/);
	});

	it("keeps the two search forms' suggestion lists apart", () => {
		const ids = [...html.matchAll(/role="listbox"/g)].length;
		const listIds = [...html.matchAll(/class="search__list"[^>]*id="([^"]+)"/g)].map((m) => m[1]);
		expect(ids).toBe(2);
		expect(new Set(listIds).size).toBe(2);
	});
});
