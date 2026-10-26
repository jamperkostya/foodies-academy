// src/types/lifehack.ts

import type { Review } from "./review";

export interface Lifehack {
	slug: string;
	title: string;
	// Smaller second line of the title.
	titleAccent?: string;
	category: string;
	// Emoji on the listing card.
	icon: string;
	image: string;
	description: string;
	intro: string;
	// Numbered points; `title` is the bold lead-in.
	points: { title: string; text: string }[];
	tip: { title: string; text: string };
	author: { slug: string; name: string; info: string };
	date: string;
	readTime: number;
	// Minutes; shown on the video button when set.
	videoMinutes?: number;
	comments: Review[];
}
