// src/types/recipe.ts

import type { AuthorSummary } from './author';
import type { Review } from './review';
import type { Seo } from './seo';

/** term_id => name, e.g. { 257: "Вторые блюда" } */
export type Terms = Record<number, string>;

/** Exact number and its short form for display: { count: 3512, short: "3.5K" } */
export interface Counter {
  count: number;
  short: string;
}

/** One line of a recipe's ingredient list. */
export interface Ingredient {
  name: string; // "Телятина"
  amount: string; // as written: "1", "1/2", "по вкусу"
  quantity: number | null; // amount as a number for rescaling, null when it isn't one
  unit: string; // "кг"
  notes: string; // "(голяшка, ребра)"
  group: string; // "ДЛЯ МЯСНОГО БУЛЬОНА", or "" when the recipe has no groups
}

export interface Recipe {
  id: number;
  slug: string;
  title: string;
  image: string;
  categories: {
    course: Terms;
    country: Terms;
  };
  time: string; // "1 ч 40 мин"
  minutes: number; // total time as a number, e.g. for schema.org "PT100M"
  isNew: boolean;
  isTop: boolean;
  rating: number; // average stars of approved reviews; WP Ultimate Recipe rating when nobody rated yet
  ratingCount?: number; // approved reviews with stars
  servings: number; // the ingredient amounts are for this many servings, 0 if unknown
  ingredients: Ingredient[]; // only in GET /api/recipes/{slug}, empty in lists
  groups?: string[]; // carousel filters, only in GET /api/recipes/by-ingredients
  reviews?: Review[]; // approved WordPress comments, only in GET /api/recipes/{slug}
  votes?: { like: number; dislike: number }; // Posts Like Dislike counts, only in GET /api/recipes/{slug}
  // The rest is only in GET /api/recipes/{slug}; "" / null / [] when not filled in WordPress.
  prepTime?: string; // "15 мин"
  cookTime?: string; // "1 ч 5 мин"
  complexity?: string; // "Очень легко"
  calories?: number | null; // per serving
  steps?: { text: string; group: string }[];
  history?: string | null; // HTML of the "История происхождения" block
  tip?: string | null; // "Совет от шефа": HTML of recipe_notes
  description?: string; // recipe_description: plain text under the title
  gallery?: string[]; // photo URLs, the cover first (cover + "gallery" field)
  seoText?: string; // "SEO-текст" HTML from the WordPress admin, "" when empty
  // Dish types and countries with their /category/{slug} pages, only in GET /api/recipes/{slug}.
  seo?: Seo | null; // Yoast SEO, only in GET /api/recipes/{slug}
  categoryLinks?: { course: { name: string; slug: string }[]; country: { name: string; slug: string }[] };
  author?: AuthorSummary | null; // posts.post_author
  similar?: Recipe[]; // recipes sharing the most ingredients, most first
  youtubeId?: string | null; // video recipe from recipe_video_embed
  updatedAt?: string; // ISO date of the last change, only in GET /api/recipes/{slug}: the video's uploadDate
  focusKeyword?: string; // Yoast SEO focus keyword, "" when not set, only in GET /api/recipes/{slug}
  comments: number;
  views: Counter;
  likes: Counter;
}
