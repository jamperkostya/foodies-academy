// src/types/review.ts

// A recipe review or an article comment. Comments have no rating.
export interface Review {
	name: string;
	date: string;
	text: string;
	// Out of 5.
	rating?: number;
}
