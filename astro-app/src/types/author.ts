// src/types/author.ts

import type { News } from './news';
import type { Recipe } from './recipe';
import type { Seo } from './seo';

// A recipe author (WordPress user) as GET /api/authors returns it.
export interface AuthorSummary {
	id: number;
	slug: string; // user_nicename, e.g. "mariana"
	name: string;
	avatar: string; // "" when there is no photo
	recipes: number; // published recipes
}

// GET /api/authors/{slug}?page=N: the author with one page of recipes.
export interface AuthorProfile extends Omit<AuthorSummary, 'recipes'> {
	bio: string; // HTML, "" when not filled in WordPress
	since: number; // registration year
	rating: number | null; // visitors' stars; average rating of their recipes until someone rates
	ratingCount: number; // visitors who rated the author
	news: News[];
	posts: News[]; // blog posts
	seo: Seo | null; // Yoast SEO
	recipes: Recipe[];
	total: number; // all their recipes
	page: number;
	pages: number;
}
