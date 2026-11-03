// src/types/lifehack.ts

import type { Review } from "./review";
import type { Seo } from "./seo";

// A lifehack as GET /api/lifehacks returns it (WordPress post type "lifehack").
export interface Lifehack {
	id: number;
	slug: string;
	title: string;
	// Smaller second line of the title; "" when not set.
	titleAccent: string;
	category: string;
	// Emoji on the listing card.
	icon: string;
	image: string;
	description: string;
	intro: string;
	// Numbered points; `title` is the bold lead-in ("" when the line had none).
	points: { title: string; text: string }[];
	tip: { title: string; text: string };
	author: { slug: string; name: string; info: string };
	date: string;
	readTime: number;
	// Minutes; shown on the video button when set.
	videoMinutes: number | null;
	commentCount: number;
	// Only in GET /api/lifehacks/{slug}:
	comments?: Review[];
	seoText?: string;
	seo?: Seo | null;
}
