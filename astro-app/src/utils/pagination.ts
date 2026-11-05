// Recipe lists are split into static pages: /category/soups, /category/soups/page/2, …
export const PER_PAGE = 24;

/** The [...page] params of a list with `count` items: undefined for the first page, then "page/2", "page/3"… */
export function pageParams(count: number): (string | undefined)[] {
	const pages = Math.max(1, Math.ceil(count / PER_PAGE));
	return Array.from({ length: pages }, (_, i) => (i === 0 ? undefined : `page/${i + 1}`));
}

/** "page/3" → 3, undefined → 1 */
export function pageNumber(param: string | undefined): number {
	return Number(param?.split("/")[1]) || 1;
}

/** URL of page `n` of a list that starts at `base` ("/category/soups"). */
export function pageHref(base: string, n: number): string {
	return n === 1 ? base : `${base}/page/${n}`;
}
