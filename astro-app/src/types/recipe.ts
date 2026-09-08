// src/types/recipe.ts

export interface Recipe {
  id: string;
  slug: string;
  title: string;
  image: string;
  category: string;
  time: number;
  isNew: boolean;
  isTop: boolean;
  rating: number;
  ingredients: string[];
  comments: number;
  // Not every seed recipe has these yet (see src/data/recipes.ts) — optional
  // until the data is filled in for all of them.
  views?: number;
  likes?: number;
}