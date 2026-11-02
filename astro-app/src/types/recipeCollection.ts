// src/types/recipeCollection.ts

import type { Recipe } from './recipe';

// A collection as GET /api/collections returns it.
export interface RecipeCollection {
  slug: string; // "s-kuricey"
  title: string; // "С курицей"
  count: number; // recipes in the collection
  image: string; // cover of its most viewed recipe
  recipes?: Recipe[]; // one page of them, only in GET /api/collections/{slug}?page=N
}
