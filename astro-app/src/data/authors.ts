// src/data/authors.ts

import type { Author } from "../types/author";

// Mock data until authors come from the CMS.
export const authors: Author[] = [
	{
		slug: "salad-konstantin",
		name: "Salad Konstantin",
		role: "Шеф-повар · Италия и Средиземноморье · на Foodies Academy с 2021 года",
		bio: "Готовлю профессионально 14 лет, из них 6 — в ресторанах Рима и Болоньи. На Foodies Academy делюсь рецептами итальянской и средиземноморской кухни без лишних сложностей: минимум ингредиентов, максимум техники. Верю, что карбонару можно приготовить идеально с первого раза, если понимать, что происходит на сковороде, а не просто следовать шагам.",
		stats: { recipes: 214, posts: 38, followers: "48k", rating: 4.9 },
	},
];
