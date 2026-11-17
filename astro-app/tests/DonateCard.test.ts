import { experimental_AstroContainer as AstroContainer } from "astro/container";
import { describe, expect, it } from "vitest";
import DonateCard from "../src/components/article/DonateCard.astro";

describe("DonateCard", () => {
	it("links to Buy Me a Coffee and the monobank jar in a new tab and is hidden in print", async () => {
		const container = await AstroContainer.create();
		const html = await container.renderToString(DonateCard);
		expect(html).toMatch(/<a href="https:\/\/buymeacoffee\.com\/jamper"[^>]*target="_blank"[^>]*rel="noopener"/);
		expect(html).toMatch(/<a href="https:\/\/send\.monobank\.ua\/jar\/8NjMxea8qQ"[^>]*target="_blank"/);
		expect(html).toContain("no-print");
	});
});
