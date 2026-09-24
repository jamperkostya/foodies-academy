// Shared by the header search suggestions (SearchForm.astro) and the
// /search results page. The index is /search-index.json (see
// pages/search-index.json.ts).

export type SearchItem = { title: string; meta: string; icon: string; href: string };

// Fetched once, on first use, then shared by every caller on the page.
let index: Promise<SearchItem[]> | undefined;
export const loadIndex = () =>
	(index ??= fetch("/search-index.json")
		.then((response) => response.json() as Promise<SearchItem[]>)
		.catch(() => []));

// Case- and ё-insensitive. Both are one-to-one character swaps, so match
// positions stay valid in the original title.
export const normalize = (text: string) => text.toLowerCase().replaceAll("ё", "е");

// Items whose title contains the (normalized) query.
export const findItems = (items: SearchItem[], query: string) =>
	items.filter((item) => normalize(item.title).includes(query));

export const el = <K extends keyof HTMLElementTagNameMap>(tag: K, className: string, text = "") =>
	Object.assign(document.createElement(tag), { className, textContent: text });

// The title with the matched part wrapped in <mark>. Built from text nodes,
// never innerHTML, so the data can't inject markup.
export const highlight = (title: string, query: string) => {
	const start = normalize(title).indexOf(query);
	if (start < 0) return [title];
	const end = start + query.length;
	return [title.slice(0, start), el("mark", "", title.slice(start, end)), title.slice(end)];
};
