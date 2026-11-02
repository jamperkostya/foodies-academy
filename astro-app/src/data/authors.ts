// src/data/authors.ts

// Mock authors of the mock blog posts. Recipe authors come from Laravel (see src/types/author.ts).
export interface Author {
	slug: string;
	// Matches Post.author, so the profile can list the author's posts.
	name: string;
	// Short, for the author card: "Шеф-повар".
	title: string;
	role: string;
	bio: string;
	// Site-wide totals, beyond the mock recipes and posts shown on the profile.
	stats: { recipes: number; posts: number; followers: string; rating: number };
}

// Mock data until authors come from the CMS.
export const authors: Author[] = [
	{
		slug: "salad-konstantin",
		name: "Salad Konstantin",
		title: "Шеф-повар",
		role: "Шеф-повар · Италия и Средиземноморье · на Foodies Academy с 2021 года",
		bio: "Готовлю профессионально 14 лет, из них 6 — в ресторанах Рима и Болоньи. На Foodies Academy делюсь рецептами итальянской и средиземноморской кухни без лишних сложностей: минимум ингредиентов, максимум техники. Верю, что карбонару можно приготовить идеально с первого раза, если понимать, что происходит на сковороде, а не просто следовать шагам.",
		stats: { recipes: 214, posts: 38, followers: "48k", rating: 4.9 },
	},
	{
		slug: "anna-sokolova",
		name: "Анна Соколова",
		title: "Редактор",
		role: "Редактор рубрики «Интервью» · на Foodies Academy с 2022 года",
		bio: "Пишу о людях, которые готовят: шеф-поварах, кондитерах, владельцах маленьких кафе. Больше всего люблю истории о том, как устроена кухня изнутри — от закупок до последней тарелки за вечер.",
		stats: { recipes: 12, posts: 54, followers: "21k", rating: 4.8 },
	},
	{
		slug: "denis-naydenov",
		name: "Денис Найдёнов",
		title: "Кулинарный журналист",
		role: "Кулинарный журналист · техника и лайфхаки · на Foodies Academy с 2023 года",
		bio: "Проверяю кухонные мифы на практике и тестирую технику, прежде чем её советовать. Если лайфхак не работает с третьей попытки — он не попадает на сайт.",
		stats: { recipes: 36, posts: 41, followers: "17k", rating: 4.7 },
	},
	{
		slug: "foodies-team",
		name: "Команда Foodies",
		title: "Редакция",
		role: "Новости и обновления Foodies Academy",
		bio: "Рассказываем, что нового на сайте и в приложении: функции, рубрики, языки и всё, что делает готовку по нашим рецептам удобнее.",
		stats: { recipes: 0, posts: 27, followers: "9k", rating: 4.8 },
	},
];
