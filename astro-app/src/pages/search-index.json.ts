import type { APIRoute } from "astro";
import type { AuthorSummary } from "../types/author";
import type { Category } from "../types/category";
import type { SearchItem } from "../scripts/search";
import { recipesWord } from "../utils/plural";

// Built into a static /search-index.json that the header search fetches on
// first use, instead of embedding the list in every page. Searched by:
// recipes — title and ingredients; categories and cuisines — name; authors — name.

const API_URL = import.meta.env.API_URL ?? "http://localhost:8000/api";
const load = async <T>(path: string): Promise<T> => (await fetch(`${API_URL}${path}`).then((r) => r.json())).data;

interface RecipeEntry {
	slug: string;
	title: string;
	time: string;
	course: string;
	ingredients: string[];
}

// Emoji shown next to a suggestion, by dish type.
const COURSE_ICONS: Record<string, string> = {
	"Вторые блюда": "🍖",
	"Супы": "🍲",
	"Салаты": "🥗",
	"Десерты": "🍰",
	"Выпечка": "🥐",
	"Закуски": "🍤",
	"Завтраки": "🍳",
	"Напитки": "🍹",
	"Бургеры": "🍔",
	"Соусы": "🥫",
	"Лапша": "🍜",
};
const icon = (course: string) => COURSE_ICONS[course] ?? "🍽️";

export const GET: APIRoute = async () => {
	const [recipes, courses, countries, authors] = await Promise.all([
		load<RecipeEntry[]>("/recipes/search-index"),
		load<Category[]>("/categories?type=course"),
		load<Category[]>("/categories?type=country"),
		load<AuthorSummary[]>("/authors"),
	]);

	const index: SearchItem[] = [
		...recipes.map((recipe) => ({
			title: recipe.title,
			meta: [recipe.time, recipe.course].filter(Boolean).join(" · ") || "Рецепт",
			icon: icon(recipe.course),
			href: `/recipe/${recipe.slug}`,
			ingredients: recipe.ingredients,
		})),
		...[...courses, ...countries].map((category) => ({
			title: category.name,
			meta: `${category.type === "country" ? "Кухня мира" : "Категория"} · ${category.count} ${recipesWord(category.count)}`,
			icon: category.type === "country" ? "🌍" : icon(category.name),
			href: `/category/${category.slug}`,
		})),
		...authors.map((author) => ({
			title: author.name,
			meta: `Автор · ${author.recipes} ${recipesWord(author.recipes)}`,
			icon: "👨‍🍳",
			href: `/author/${author.slug}`,
		})),
	];

	return new Response(JSON.stringify(index), {
		headers: { "Content-Type": "application/json; charset=utf-8" },
	});
};
