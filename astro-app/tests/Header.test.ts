import { experimental_AstroContainer as AstroContainer } from "astro/container";
import { beforeAll, describe, expect, it } from "vitest";
import Header from "../src/components/Header.astro";

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
		const img = html.match(/<img[^>]*logotype\.jpg[^>]*>/)?.[0];
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

	it("marks the categories toggle as a collapsed popup trigger", () => {
		const button = html.match(/<button[^>]*id="categoriesBtn"[^>]*>/)?.[0];
		expect(button).toBeDefined();
		expect(button).toContain('aria-haspopup="true"');
		expect(button).toContain('aria-expanded="false"');
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
});
