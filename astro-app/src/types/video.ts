// src/types/video.ts

export interface Video {
  id: string;
  slug: string;
  title: string;
  image: string;
  category: string;
  // Length as shown on the card, e.g. "12:40".
  duration: string;
  views: number;
}
