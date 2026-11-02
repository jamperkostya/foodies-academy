// src/types/author.ts

import type { News } from './news';
import type { Recipe } from './recipe';

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
	bio: string; // "" when not filled in WordPress
	since: number; // registration year
	rating: number | null; // average rating of their recipes
	news: News[];
	recipes: Recipe[];
	total: number; // all their recipes
	page: number;
	pages: number;
}
