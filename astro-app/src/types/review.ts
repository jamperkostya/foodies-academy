// src/types/review.ts

// A recipe review or an article comment. Comments have no rating.
export interface Review {
	name: string;
	date: string;
	text: string;
	// Out of 5.
	rating?: number;
	// Set on a reply: the name of the author it answers. Replies follow their thread.
	replyTo?: string;
}
