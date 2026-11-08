import { experimental_AstroContainer as AstroContainer } from "astro/container";
import { describe, expect, it } from "vitest";
import SiteCounters from "../src/components/layout/SiteCounters.astro";

const render = async (host?: string) => (await AstroContainer.create()).renderToString(SiteCounters, { props: { host } });

describe("SiteCounters", () => {
	it("adds Yandex.Metrika, Google Analytics and the ownership tags on foodies.academy", async () => {
		const html = await render("foodies.academy");

		expect(html).toContain('name="google-site-verification"');
		expect(html).toContain('name="yandex-verification"');
		expect(html).toContain('ym(51455426,"init"');
		expect(html).toContain("https://mc.yandex.ru/watch/51455426");
		expect(html).toContain("https://www.googletagmanager.com/gtag/js?id=G-B1B19LSS7V");
		expect(html).toContain('gtag("config","G-B1B19LSS7V")');
	});

	it("leaves dev and local builds out of the statistics", async () => {
		for (const host of ["dev.foodies.academy", "localhost", undefined]) {
			expect((await render(host)).trim()).toBe("");
		}
	});
});
