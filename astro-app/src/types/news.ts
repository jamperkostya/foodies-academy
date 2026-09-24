// src/types/news.ts

export interface News {
  id: string;
  slug: string;
  title: string;
  excerpt: string;
  category: string;
  tags: string[];
  // Only shown for the featured news; not filled in for any seed record yet.
  image?: string;
}