// src/types/recipe.ts

import type { Review } from './review';

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
  rating: number;
  servings: number; // the ingredient amounts are for this many servings, 0 if unknown
  ingredients: Ingredient[]; // only in GET /api/recipes/{slug}, empty in lists
  groups?: string[]; // carousel filters, only in GET /api/recipes/by-ingredients
  reviews?: Review[]; // approved WordPress comments, only in GET /api/recipes/{slug}
  // The rest is only in GET /api/recipes/{slug}; "" / null / [] when not filled in WordPress.
  prepTime?: string; // "15 мин"
  cookTime?: string; // "1 ч 5 мин"
  complexity?: string; // "Очень легко"
  calories?: number | null; // per serving
  steps?: { text: string; group: string }[];
  history?: string | null; // HTML of the "История происхождения" block
  similar?: Recipe[]; // recipes sharing the most ingredients, most first
  comments: number;
  views: Counter;
  likes: Counter;
}
