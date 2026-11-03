// src/types/page.ts

import type { Seo } from "./seo";

// A WordPress page as GET /api/pages/{slug} returns it.
export interface SitePage {
  id: number;
  slug: string;
  title: string;
  excerpt: string; // short text, e.g. of a home page promo block; "" when empty
  content: string; // HTML
  image: string; // featured image, "" when none
  modified: string;
  seoText: string;
  seo: Seo | null; // Yoast SEO
}
