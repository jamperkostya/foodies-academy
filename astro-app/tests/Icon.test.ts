import { experimental_AstroContainer as AstroContainer } from "astro/container";
import { describe, expect, it } from "vitest";
import Icon from "../src/components/Icon.astro";

describe("Icon", () => {
	it("points at the shared sprite by symbol id", async () => {
		const container = await AstroContainer.create();
		const html = await container.renderToString(Icon, {
			props: { name: "search" },
		});

		expect(html).toContain('href="/images/icons/sprite.svg#search"');
	});

	it("is hidden from assistive tech (decorative by default)", async () => {
		const container = await AstroContainer.create();
		const html = await container.renderToString(Icon, {
			props: { name: "search" },
		});

		expect(html).toContain('aria-hidden="true"');
	});

	it("falls back to a 24x24 box when no size is given", async () => {
		const container = await AstroContainer.create();
		const html = await container.renderToString(Icon, {
			props: { name: "star" },
		});

		expect(html).toContain('width="24"');
		expect(html).toContain('height="24"');
	});

	it("respects explicit width/height", async () => {
		const container = await AstroContainer.create();
		const html = await container.renderToString(Icon, {
			props: { name: "menu-arrow", width: 12, height: 7 },
		});

		expect(html).toContain('width="12"');
		expect(html).toContain('height="7"');
	});
});
