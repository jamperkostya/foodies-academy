// src/types/news.ts

export interface News {
  id: string;
  slug: string;
  title: string;
  excerpt: string;
  category: string;
  tags: string[];
  // Not filled in yet for any seed record (see src/data/news.ts) and not
  // read anywhere yet — optional until both are true.
  image?: string;
}