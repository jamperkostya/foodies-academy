// src/data/menu.ts

// Header, mobile nav and footer menus, loaded once from Laravel.
const API_URL = import.meta.env.API_URL ?? 'http://localhost:8000/api';

interface Term {
	name: string;
	slug: string;
}

interface MenuColumn {
	group: string;
	items: { title: string; href: string }[];
	// Link under the column to the whole list.
	all: { label: string; href: string };
}

const load = async (path: string): Promise<Term[]> => (await fetch(`${API_URL}${path}`).then((r) => r.json())).data;

const [courses, allCountries, countries, ingredients, collections] = await Promise.all([
	load('/categories?type=course'),
	load('/categories?type=country'),
	// 54 countries are too many for a menu: the most popular ones, the rest on /kitchens.
	load('/categories/popular?type=country&limit=12'),
	load('/ingredients/popular?limit=5'),
	load('/collections'),
]);

const links = (terms: Term[]) => terms.map((term) => ({ title: term.name, href: `/category/${term.slug}` }));

export const menuColumns: MenuColumn[] = [
	{ group: 'Типы блюд', items: links(courses), all: { label: 'Все категории', href: '/categories' } },
	{ group: 'Кухни мира', items: links(countries), all: { label: 'Все кухни', href: '/kitchens' } },
];

// Picked by name, in this order; a name missing in WordPress is skipped.
const pick = (terms: Term[], names: string[]) =>
	links(names.map((name) => terms.find((term) => term.name === name)).filter((term) => term !== undefined));

interface FooterColumn {
	title: string;
	// Where the column title leads; none for "Рецепты по ингредиентам".
	href?: string;
	items: { title: string; href: string }[];
}

export const footerColumns: FooterColumn[] = [
	{ title: 'Кухни мира', href: '/kitchens', items: pick(allCountries, ['Украина', 'Россия', 'Франция', 'Испания', 'Италия']) },
	{ title: 'Типы блюд', href: '/categories', items: pick(courses, ['Супы', 'Вторые блюда', 'Соусы', 'Уличная еда']) },
	{
		title: 'Рецепты по ингредиентам',
		items: ingredients.map((ingredient) => ({ title: ingredient.name, href: `/ingredient/${ingredient.slug}` })),
	},
	{
		title: 'Подборки рецептов',
		items: (collections as unknown as { title: string; slug: string }[]).map((collection) => ({
			title: collection.title,
			href: `/collections/${collection.slug}`,
		})),
	},
	{
		title: 'Информация',
		items: [
			{ title: 'О проекте', href: '/about' },
			// There is no authors list yet: the first author's page.
			{ title: 'Авторы', href: '/author/salad-konstantin' },
			{ title: 'Новости', href: '/news' },
			{ title: 'Лайфхаки', href: '/lifehacks' },
			{ title: 'Видеорецепты', href: '/video' },
		],
	},
];

// Shown after the category columns.
export const menuLinks = [
	{ label: 'Лайфхаки', href: '/lifehacks' },
	{ label: 'Видеорецепты', href: '/video' },
];
