// src/types/news.ts

// A news item as the Laravel API returns it (WordPress posts).
export interface News {
  id: number;
  slug: string;
  title: string;
  excerpt: string;
  category: string;
  tags: string[];
  image: string; // "" when the post has no featured image
  date: string; // "2022-11-18"
  content?: string; // HTML, only in GET /api/news/{slug}
}
