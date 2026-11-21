// src/types/category.ts

// A category as GET /api/categories returns it.
export interface Category {
  id: number;
  name: string; // "Вторые блюда"
  slug: string;
  type: "course" | "country";
  count: number; // published recipes
  // Slugs of the term in its languages; only on GET /api/categories/{slug}.
  translations?: Record<string, string>;
  image: string; // "" when not set in WordPress
  meta: Record<string, string>; // term meta, e.g. { _crb_category_color: "#6EAB7D" }
  // Filters of the home page section this card shows up under; the API doesn't send them yet.
  groups?: string[];
}
