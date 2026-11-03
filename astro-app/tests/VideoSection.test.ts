import { experimental_AstroContainer as AstroContainer } from "astro/container";
import { describe, expect, it } from "vitest";
import VideoSection from "../src/components/sections/VideoSection.astro";
import type { Video } from "../src/types/video";

// What GET /api/videos returns for one video.
const video = (id: number): Video => ({
	id,
	slug: `slug-${id}`,
	title: `Title ${id}`,
	youtubeId: `video-${id}`,
	image: "/img.jpg",
	date: "1 апреля 2022",
	publishedAt: "2022-04-01T06:00:00+00:00",
	duration: "12:40",
	views: 3752,
	youtubeLikes: 76,
	likes: 5,
	comments: 2,
	rating: 4,
	ratingCount: 1,
});

const render = async () => {
	const container = await AstroContainer.create();
	return container.renderToString(VideoSection, { props: { videos: [video(1), video(2), video(3)], href: "/video" } });
};

describe("VideoSection", () => {
	it("has no filters and puts 'Смотреть все' on the title's row", async () => {
		const html = await render();

		expect(html).not.toContain('class="filters');
		expect(html).toMatch(/<a href="\/video" class="section-head__all[^"]*"[^>]*>Смотреть все</);
	});

	it("renders compact video cards linking to the video pages, with YouTube views", async () => {
		const html = await render();

		expect(html.match(/class="video-card video-card--compact carousel__item"/g)).toHaveLength(3);
		expect(html).toContain('href="/video/slug-2"');
		expect(html).toMatch(/#views"><\/use><\/svg>3752</);
	});

	it("uses a span for the play badge, since the card itself is a link", async () => {
		const html = await render();

		expect(html).toContain('data-video-id="video-1"');
		expect(html).not.toContain("<button type=\"button\" class=\"video-card");
		expect(html).not.toMatch(/<a [^>]*video-card[^>]*>(?:(?!<\/a>)[\s\S])*<button/);
	});
});
