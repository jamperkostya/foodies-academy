// src/types/video.ts

import type { Review } from "./review";
import type { Seo } from "./seo";

// A video recipe as GET /api/videos returns it (WordPress post type "video", imported from YouTube).
export interface Video {
  id: number;
  slug: string;
  title: string;
  youtubeId: string;
  image: string; // YouTube thumbnail
  date: string; // "20 мая 2022", published on YouTube
  publishedAt: string; // ISO 8601
  duration: string; // "12:40", "" until the YouTube Data API filled it
  views: number; // YouTube views
  youtubeLikes: number;
  likes: number; // the site's likes (Posts Like Dislike)
  comments: number; // the site's approved comments
  rating: number; // average stars of the site's reviews, 0 while unrated
  ratingCount: number;
}

// GET /api/videos/{slug}
export interface VideoDetail extends Video {
  description: string; // HTML: the YouTube description, editable in WordPress
  reviews: Review[];
  votes: { like: number; dislike: number };
  seoText: string;
  seo: Seo | null; // Yoast SEO
}

// GET /api/videos?page=N
export interface VideoPage {
  videos: Video[];
  total: number;
  page: number;
  pages: number;
}
