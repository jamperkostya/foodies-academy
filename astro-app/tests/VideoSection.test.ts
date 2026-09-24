import { experimental_AstroContainer as AstroContainer } from "astro/container";
import { describe, expect, it } from "vitest";
import VideoSection from "../src/components/sections/VideoSection.astro";
import type { Video } from "../src/types/video";

const video = (id: string, category: string): Video => ({
	id,
	slug: `slug-${id}`,
	title: `Title ${id}`,
	image: "/img.jpg",
	category,
	views: 10,
	likes: 5,
	comments: 2,
	rating: 4,
	videoId: `video-${id}`,
});

const render = async () => {
	const container = await AstroContainer.create();
	return container.renderToString(VideoSection, {
		props: { videos: [video("1", "Супы"), video("2", "Десерты"), video("3", "Супы")], href: "/video" },
	});
};

describe("VideoSection", () => {
	it("builds one filter per video category, then 'Смотреть все'", async () => {
		const html = await render();
		const labels = [...html.matchAll(/filters__btn[^>]*>([^<]+)</g)].map((m) => m[1]);

		expect(labels).toEqual(["Все", "Супы", "Десерты"]);
		expect(html).toMatch(/filters__all[^>]*>Смотреть все</);
	});

	it("renders compact video cards tagged with their category", async () => {
		const html = await render();

		expect(html.match(/class="video-card video-card--compact"/g)).toHaveLength(3);
		expect(html).toContain('data-groups="[&quot;Десерты&quot;]"');
	});

	it("uses a span for the play badge, since the card itself is a link", async () => {
		const html = await render();

		expect(html).toContain('data-video-id="video-1"');
		expect(html).not.toContain("<button type=\"button\" class=\"video-card");
		expect(html).not.toMatch(/<a [^>]*video-card[^>]*>(?:(?!<\/a>)[\s\S])*<button/);
	});
});
