// src/data/menu.ts

// Header, mobile nav and footer menus, loaded once from Laravel for every language.
import { LANGS, localize, t, withLang, type Lang } from "../i18n";

const API_URL = import.meta.env.API_URL ?? 'http://localhost:8000/api';

interface Term {
	id?: number;
	name: string;
	slug: string;
}

interface MenuColumn {
	group: string;
	items: { title: string; href: string }[];
	// Link under the column to the whole list.
	all: { label: string; href: string };
}

interface FooterColumn {
	title: string;
	// Where the column title leads; none for "Рецепты по ингредиентам".
	href?: string;
	items: { title: string; href: string }[];
}

interface Menu {
	columns: MenuColumn[];
	footer: FooterColumn[];
	// Shown after the category columns.
	links: { label: string; href: string }[];
	// Under the footer: privacy policy, terms, contacts — those published in this language.
	legal: { title: string; href: string }[];
}

const load = async (lang: Lang, path: string): Promise<Term[]> =>
	(await fetch(withLang(lang, `${API_URL}${path}`)).then((r) => r.json())).data;

// Russian names of the footer's countries and dish types: picked from the original terms, shown
// under their name in the page's language.
const FOOTER_COUNTRIES = ['Украина', 'Россия', 'Франция', 'Испания', 'Италия'];
const FOOTER_COURSES = ['Супы', 'Вторые блюда', 'Соусы', 'Уличная еда'];

async function build(lang: Lang, ru: { courses: Term[]; countries: Term[] } | null): Promise<Menu> {
	const [courses, allCountries, countries, ingredients, collections, pages] = await Promise.all([
		load(lang, '/categories?type=course'),
		load(lang, '/categories?type=country'),
		// 54 countries are too many for a menu: the most popular ones, the rest on /kitchens.
		load(lang, '/categories/popular?type=country&limit=12'),
		load(lang, '/ingredients/popular?limit=5'),
		load(lang, '/collections'),
		load(lang, '/pages'),
	]);
	// Russian slugs: a translation has the same public slug (Translations::publicSlug()).
	const legal = ['privacy-policy', 'terms', 'contacts']
		.map((slug) => pages.find((page) => page.slug === slug) as unknown as { slug: string; title: string } | undefined)
		.filter((page) => page !== undefined)
		.map((page) => ({ title: page.title, href: localize(lang, `/${page.slug}`) }));
	const links = (terms: Term[]) => terms.map((term) => ({ title: term.name, href: localize(lang, `/category/${term.slug}`) }));
	// Picked by Russian name, in this order; a name missing in WordPress (or untranslated) is skipped.
	// Categories carry the Russian term's id in every language.
	const pick = (terms: Term[], russian: Term[] | undefined, names: string[]) =>
		links(names
			.map((name) => (russian ?? terms).find((term) => term.name === name)?.id)
			.map((id) => terms.find((term) => term.id === id))
			.filter((term): term is Term => term !== undefined));

	// Sections with nothing in this language yet get no link.
	const sections = lang === 'ru'
		? [
				{ title: 'Новости', href: '/news' },
				{ title: 'Лайфхаки', href: '/lifehacks' },
				{ title: 'Видеорецепты', href: '/video' },
			]
		: [];

	return {
		columns: [
			{ group: t(lang, 'Типы блюд'), items: links(courses), all: { label: t(lang, 'Все категории'), href: localize(lang, '/categories') } },
			{ group: t(lang, 'Кухни мира'), items: links(countries), all: { label: t(lang, 'Все кухни'), href: localize(lang, '/kitchens') } },
		],
		footer: [
			{ title: t(lang, 'Кухни мира'), href: localize(lang, '/kitchens'), items: pick(allCountries, ru?.countries, FOOTER_COUNTRIES) },
			{ title: t(lang, 'Типы блюд'), href: localize(lang, '/categories'), items: pick(courses, ru?.courses, FOOTER_COURSES) },
			{
				title: t(lang, 'Рецепты по ингредиентам'),
				items: ingredients.map((ingredient) => ({ title: ingredient.name, href: localize(lang, `/ingredient/${ingredient.slug}`) })),
			},
			{
				title: t(lang, 'Подборки рецептов'),
				items: (collections as unknown as { title: string; slug: string }[]).map((collection) => ({
					title: collection.title,
					href: localize(lang, `/collections/${collection.slug}`),
				})),
			},
			{
				title: t(lang, 'Информация'),
				items: [
					...(lang === 'ru' ? [{ title: 'О проекте', href: '/about' }] : []),
					{ title: t(lang, 'Авторы'), href: localize(lang, '/authors') },
					...sections.slice(0, 1),
					// The blog is linked from the footer only.
					...(lang === 'ru' ? [{ title: 'Блог', href: '/blog' }] : []),
					...sections.slice(1),
				],
			},
		],
		links: sections.map(({ title, href }) => ({ label: title, href })),
		legal,
	};
}

const ruMenu = await build('ru', null);
const [ruCourses, ruCountries] = await Promise.all([load('ru', '/categories?type=course'), load('ru', '/categories?type=country')]);
const menus: Record<Lang, Menu> = { ru: ruMenu } as Record<Lang, Menu>;
for (const lang of LANGS.filter((lang) => lang !== 'ru')) {
	menus[lang] = await build(lang, { courses: ruCourses, countries: ruCountries });
}

export const menuOf = (lang: Lang): Menu => menus[lang];

// The Russian menus, as before languages.
export const menuColumns = ruMenu.columns;
export const footerColumns = ruMenu.footer;
export const menuLinks = ruMenu.links;
