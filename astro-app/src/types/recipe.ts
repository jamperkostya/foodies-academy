// src/types/recipe.ts

export interface Recipe {
  id: string;
  slug: string;
  title: string;
  image: string;
  time: number;
  isNew: boolean;
  isTop: boolean;
  rating: number;
  ingredients: string[];
  comments: number;
}