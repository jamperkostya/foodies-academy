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
  views: number;
  likes: number;
}