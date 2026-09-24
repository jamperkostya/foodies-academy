import { experimental_AstroContainer as AstroContainer } from "astro/container";
import { describe, expect, it } from "vitest";
import Breadcrumbs from "../src/components/layout/Breadcrumbs.astro";

const render = async () => {
	const container = await AstroContainer.create();
	return container.renderToString(Breadcrumbs, {
		props: { items: [{ label: "Категории", href: "/categories" }, { label: "Борщ" }] },
	});
};

describe("Breadcrumbs", () => {
	it("is a labelled nav with an ordered list, starting at the home page", async () => {
		const html = await render();

		expect(html).toMatch(/<nav[^>]*aria-label="Хлебные крошки"/);
		expect(html).toContain("<ol");
		expect(html).toMatch(/<a class="breadcrumbs__link" href="\/"[^>]*>Главная<\/a>/);
	});

	it("links the steps in between and marks the last one as the current page", async () => {
		const html = await render();

		expect(html).toMatch(/<a class="breadcrumbs__link" href="\/categories"[^>]*>Категории<\/a>/);
		expect(html).toMatch(/<span class="breadcrumbs__current" aria-current="page"[^>]*>Борщ<\/span>/);
		expect(html.match(/aria-current="page"/g)).toHaveLength(1);
	});
});
