import { experimental_AstroContainer as AstroContainer } from "astro/container";
import { describe, expect, it } from "vitest";
import AdSlot from "../src/components/ui/AdSlot.astro";

const render = async (props: { unit: string; host?: string }) => (await AstroContainer.create()).renderToString(AdSlot, { props });

describe("AdSlot", () => {
	it("renders the AdSense unit on foodies.academy", async () => {
		const html = await render({ unit: "1234567890", host: "foodies.academy" });

		expect(html).toContain('class="adsbygoogle');
		expect(html).toContain('data-ad-client="ca-pub-5409964786388188"');
		expect(html).toContain('data-ad-slot="1234567890"');
	});

	it("renders nothing on foodies.academy until the unit has an ID", async () => {
		expect(await render({ unit: "", host: "foodies.academy" })).not.toContain("ad-slot");
	});

	it("shows only a placeholder on dev and local builds", async () => {
		for (const host of ["dev.foodies.academy", "localhost", undefined]) {
			const html = await render({ unit: "1234567890", host });
			expect(html).toContain("ad-slot--placeholder");
			expect(html).not.toContain("adsbygoogle");
		}
	});
});
