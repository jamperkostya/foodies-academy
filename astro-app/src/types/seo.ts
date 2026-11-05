// src/types/seo.ts

// Yoast SEO fields of a WordPress object, as Laravel sends them in `seo` (null if WordPress didn't answer).
export interface Seo {
  title: string | null;
  description: string | null;
  index: boolean;
  follow: boolean;
  og: {
    title: string | null;
    description: string | null;
    type: string | null;
    image: { url: string; width: number | null; height: number | null; type: string | null } | null;
    publishedTime: string | null;
    modifiedTime: string | null;
    siteName: string | null;
  };
  twitter: { card: string; title: string | null; description: string | null; image: string | null };
}
