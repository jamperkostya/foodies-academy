// src/types/category.ts

export interface Category {
  id: string;
  slug: string;
  title: string;
  image: string;
  // Filters of the "BEST categories" section this card shows up under
  // (e.g. ["Выпечка", "Десерты"]); a card can belong to several.
  groups: string[];
  recipesCount: number;
}
