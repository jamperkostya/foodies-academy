// src/types/post.ts

import type { Review } from "./review";

export interface Post {
	slug: string;
	title: string;
	excerpt: string;
	tag: string;
	image: string;
	// Author.name; every post author has a profile in data/authors.ts.
	author: string;
	date: string;
	readTime: number;
	intro: string;
	// Headed parts of the article body.
	sections: { title: string; text: string }[];
	// News posts have no advice to give.
	tip?: { title: string; text: string };
	comments: Review[];
}
