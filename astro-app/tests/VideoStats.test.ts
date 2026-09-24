import { experimental_AstroContainer as AstroContainer } from "astro/container";
import { describe, expect, it } from "vitest";
import VideoStats from "../src/components/videos/VideoStats.astro";

const render = async (views?: number) => {
	const container = await AstroContainer.create();
	return container.renderToString(VideoStats, {
		props: { views, likes: 7, comments: 3, rating: 4 },
	});
};

describe("VideoStats", () => {
	it("shows the views stat when there is a value", async () => {
		const html = await render(120);

		expect(html).toContain("sprite.svg#views");
		expect(html).toContain(">120<");
	});

	it("hides the views stat when there is no value", async () => {
		expect(await render()).not.toContain("sprite.svg#views");
	});

	it("hides the views stat for 0 instead of printing a bare 0", async () => {
		const html = await render(0);

		expect(html).not.toContain("sprite.svg#views");
		expect(html).not.toMatch(/>0</);
	});

	it("drops the views/likes row entirely when both are empty", async () => {
		const container = await AstroContainer.create();
		const html = await container.renderToString(VideoStats, { props: { rating: 4 } });

		expect(html).not.toContain("sprite.svg#views");
		expect(html).not.toContain("sprite.svg#like");
		// Only the stats wrapper and the rating row are left.
		expect(html.match(/<div/g)).toHaveLength(2);
	});

	it("keeps the row when only likes are set", async () => {
		const container = await AstroContainer.create();
		const html = await container.renderToString(VideoStats, { props: { likes: 5, rating: 4 } });

		// Stats wrapper, views/likes row and rating row.
		expect(html.match(/<div/g)).toHaveLength(3);
		expect(html).toContain("sprite.svg#like");
	});
});
