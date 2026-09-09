// src/types/news.ts

export interface News {
  id: string;
  slug: string;
  title: string;
  excerpt: string;
  image: string;
  category: string;
  tags: string[];
}