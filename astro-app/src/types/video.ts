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
  likes: number;
  comments: number;
  rating: number;
  // Player id, set on the card's play badge as data-video-id.
  videoId: string;
}
