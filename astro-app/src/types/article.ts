// src/types/article.ts

import type { Review } from './review';
import type { Seo } from './seo';

// A news item (post type "news") or a blog post (ordinary posts) as the Laravel API returns it.
export interface Article {
  id: number;
  slug: string;
  title: string;
  excerpt: string;
  category: string;
  tags: string[];
  image: string; // "" when the post has no featured image
  date: string; // "2022-11-18"
  dateText: string; // "18 ноября 2022"
  author: { slug: string; name: string };
  readTime: number; // minutes
  commentCount: number;
  // Only in GET /api/{news|blog}/{slug}:
  content?: string; // HTML
  comments?: Review[];
  seoText?: string;
  seo?: Seo | null;
}
