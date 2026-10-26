// src/types/author.ts

export interface Author {
	slug: string;
	// Matches Post.author, so the profile can list the author's posts.
	name: string;
	role: string;
	bio: string;
	// Site-wide totals, beyond the mock recipes and posts shown on the profile.
	stats: { recipes: number; posts: number; followers: string; rating: number };
}
