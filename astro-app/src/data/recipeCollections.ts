// src/data/recipeCollections.ts

import type { RecipeCollection } from '../types/recipeCollection';

// Mock data until collections come from the CMS. Images are placeholders.
export const recipeCollections: RecipeCollection[] = [
	{
		id: '1',
		slug: 's-kuricey',
		title: 'С курицей',
		image: 'https://foodies.academy/wp-content/uploads/2018/10/burgers.jpg',
	},
	{
		id: '2',
		slug: 's-myasom',
		title: 'С мясом',
		image: 'https://foodies.academy/wp-content/uploads/2018/10/burgers.jpg',
	},
	{
		id: '3',
		slug: 's-myasom-2',
		title: 'С мясом 2',
		image: 'https://foodies.academy/wp-content/uploads/2018/10/burgers.jpg',
	},
	{
		id: '4',
		slug: 's-myasom-3',
		title: 'С мясом 3',
		image: 'https://foodies.academy/wp-content/uploads/2018/10/burgers.jpg',
	},
];
