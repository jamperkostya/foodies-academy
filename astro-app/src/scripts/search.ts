// Shared by the header search suggestions (SearchForm.astro) and the
// /search results page. The index is /search-index.json (see
// pages/search-index.json.ts).

export type SearchItem = {
	title: string;
	meta: string;
	icon: string;
	href: string;
	// Recipes only: also found by any of these.
	ingredients?: string[];
};

// Fetched once, on first use, then shared by every caller on the page.
let index: Promise<SearchItem[]> | undefined;
export const loadIndex = () =>
	(index ??= fetch("/search-index.json")
		.then((response) => response.json() as Promise<SearchItem[]>)
		.catch(() => []));

// Case- and ё-insensitive. Both are one-to-one character swaps, so match
// positions stay valid in the original title.
export const normalize = (text: string) => text.toLowerCase().replaceAll("ё", "е");

// Items whose title contains the (normalized) query, then recipes with a
// matching ingredient; their meta line says which ingredient matched.
export const findItems = (items: SearchItem[], query: string): SearchItem[] => {
	const byTitle = items.filter((item) => normalize(item.title).includes(query));
	const byIngredient = items.flatMap((item) => {
		if (byTitle.includes(item)) return [];
		const ingredient = item.ingredients?.find((name) => normalize(name).includes(query));
		return ingredient ? [{ ...item, meta: `${item.meta} · с ингредиентом «${ingredient}»` }] : [];
	});
	return [...byTitle, ...byIngredient];
};

export const el = <K extends keyof HTMLElementTagNameMap>(tag: K, className: string, text = "") =>
	Object.assign(document.createElement(tag), { className, textContent: text });

// The title with the matched part wrapped in <mark class={markClass}>. Built
// from text nodes, never innerHTML, so the data can't inject markup.
export const highlight = (title: string, query: string, markClass: string) => {
	const start = normalize(title).indexOf(query);
	if (start < 0) return [title];
	const end = start + query.length;
	return [title.slice(0, start), el("mark", markClass, title.slice(start, end)), title.slice(end)];
};
