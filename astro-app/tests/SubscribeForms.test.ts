import { experimental_AstroContainer as AstroContainer } from "astro/container";
import { describe, expect, it } from "vitest";
import SubscribeSection from "../src/components/sections/SubscribeSection.astro";
import NewsletterCard from "../src/components/article/NewsletterCard.astro";

// Each sign-up form names its list, so Laravel saves it under its own Contact Form 7 form.
const form = (html: string) => html.match(/<form[^>]*data-subscribe=[^>]*>/)?.[0] ?? "";

describe("sign-up forms", () => {
	it("sends the recipe book sign-ups to the book list", async () => {
		const html = await (await AstroContainer.create()).renderToString(SubscribeSection);

		expect(form(html)).toMatch(/data-subscribe="[^"]*\/subscribe"/);
		expect(form(html)).toContain('data-list="book"');
	});

	it("sends the sidebar sign-ups to the newsletter list", async () => {
		const html = await (await AstroContainer.create()).renderToString(NewsletterCard, { props: { title: "Рассылка" } });

		expect(form(html)).toMatch(/data-subscribe="[^"]*\/subscribe"/);
		expect(form(html)).toContain('data-list="newsletter"');
	});
});
