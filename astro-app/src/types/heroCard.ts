// src/types/heroCard.ts

export interface HeroCard {
  href: string;
  // tall: a full-height card; half: stacked two per column.
  size: "tall" | "half";
  image: string;
  title: string;
  views?: number;
  likes?: number;
  comments?: number;
  rating: number;
  videoId?: string;
}
