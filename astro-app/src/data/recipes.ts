// src/data/recipes.ts

import type { Recipe } from '../types/recipe';

export const recipes: Recipe[] = [
	{
		id: '1',
		slug: 'pasta-carbonara',
		title: 'Pasta Carbonara',
		image: '/images/carbonara.jpg',
		time: 25,
		isNew: true,
		isTop: true,
		rating: 1.5,
		ingredients: ['pasta', 'egg', 'parmesan'],
	},
	{
		id: '2',
		slug: 'pasta-carbonara',
		title: 'Pasta Carbonara 2',
		image: '/images/carbonara.jpg',
		time: 125,
		isNew: true,
		isTop: true,
		rating: 3.1,
		ingredients: ['pasta', 'egg', 'parmesan'],
	},
	{
		id: '3',
		slug: 'pasta-carbonara',
		title: 'Свинные крылышки',
		image: '/images/carbonara.jpg',
		time: 25,
		isNew: true,
		isTop: true,
		rating: 4.1,
		ingredients: ['pasta', 'egg', 'parmesan'],
	},
	{
		id: '4',
		slug: 'pasta-carbonara',
		title: 'Куриные крылышки',
		image: '/images/carbonara.jpg',
		time: 125,
		isNew: true,
		isTop: true,
		rating: 4.1,
		ingredients: ['pasta', 'egg', 'parmesan'],
	},
	
  // ...
];