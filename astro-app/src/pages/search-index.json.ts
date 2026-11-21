import type { APIRoute } from "astro";
import type { AuthorSummary } from "../types/author";
import type { Category } from "../types/category";
import type { SearchItem } from "../scripts/search";
import { langOf, localize, recipesWord, t, withLang, type Lang } from "../i18n";

// Built into a static /search-index.json that the header search fetches on
// first use, instead of embedding the list in every page. Searched by:
// recipes — title and ingredients; categories and cuisines — name; authors — name.
// One per language: /search-index.json, /en/search-index.json.

const API_URL = import.meta.env.API_URL ?? "http://localhost:8000/api";
const load = async <T>(lang: Lang, path: string): Promise<T> => (await fetch(withLang(lang, `${API_URL}${path}`)).then((r) => r.json())).data;

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
	"Main Dishes": "🍖",
	"Soups": "🍲",
	"Salads": "🥗",
	"Desserts": "🍰",
	"Baking": "🥐",
	"Snacks": "🍤",
	"Breakfasts": "🍳",
	"Drinks": "🍹",
	"Burgers": "🍔",
	"Sauces": "🥫",
	"Noodles": "🍜",
};
const icon = (course: string) => COURSE_ICONS[course] ?? "🍽️";

export const GET: APIRoute = async ({ url }) => {
	const lang = langOf(url);
	const [recipes, courses, countries, authors] = await Promise.all([
		load<RecipeEntry[]>(lang, "/recipes/search-index"),
		load<Category[]>(lang, "/categories?type=course"),
		load<Category[]>(lang, "/categories?type=country"),
		load<AuthorSummary[]>(lang, "/authors"),
	]);

	const index: SearchItem[] = [
		...recipes.map((recipe) => ({
			title: recipe.title,
			meta: [recipe.time, recipe.course].filter(Boolean).join(" · ") || t(lang, "Рецепт"),
			icon: icon(recipe.course),
			href: localize(lang, `/recipe/${recipe.slug}`),
			ingredients: recipe.ingredients,
		})),
		...[...courses, ...countries].map((category) => ({
			title: category.name,
			meta: `${category.type === "country" ? t(lang, "Кухня мира") : t(lang, "Категория")} · ${category.count} ${recipesWord(lang, category.count)}`,
			icon: category.type === "country" ? "🌍" : icon(category.name),
			href: localize(lang, `/category/${category.slug}`),
		})),
		...authors.map((author) => ({
			title: author.name,
			meta: `${t(lang, "Автор")} · ${author.recipes} ${recipesWord(lang, author.recipes)}`,
			icon: "👨‍🍳",
			href: localize(lang, `/author/${author.slug}`),
		})),
	];

	return new Response(JSON.stringify(index), {
		headers: { "Content-Type": "application/json; charset=utf-8" },
	});
};
