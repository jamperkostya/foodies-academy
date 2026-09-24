import type { APIRoute } from "astro";
import { recipes } from "../data/recipes";
import { categories } from "../data/categories";
import { videos } from "../data/videos";
import { plural } from "../utils/plural";

// Built into a static /search-index.json that the header search fetches on
// first use, instead of embedding the list in every page.

// Emoji shown next to a suggestion, by dish group.
const GROUP_ICONS: Record<string, string> = {
	"Основные блюда": "🍖",
	"Супы": "🍲",
	"Салаты": "🥗",
	"Десерты": "🍰",
	"Выпечка": "🥐",
};
const icon = (group: string) => GROUP_ICONS[group] ?? "🍽️";

export const GET: APIRoute = () => {
	const index = [
		...recipes.map((recipe) => ({
			title: recipe.title,
			meta: `${recipe.time} мин · ${recipe.category}`,
			icon: icon(recipe.category),
			href: `/recipes/${recipe.slug}`,
		})),
		...categories.map((category) => ({
			title: category.title,
			meta: `Категория · ${category.recipesCount} ${plural(category.recipesCount, ["рецепт", "рецепта", "рецептов"])}`,
			icon: icon(category.groups[0]),
			href: `/category/${category.slug}`,
		})),
		...videos.map((video) => ({
			title: video.title,
			meta: `Видео · ${video.category}`,
			icon: "🎬",
			href: `/video/${video.slug}`,
		})),
	];

	return new Response(JSON.stringify(index), {
		headers: { "Content-Type": "application/json; charset=utf-8" },
	});
};
