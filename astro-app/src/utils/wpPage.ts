// A WordPress page for the Astro page built in its place: its SEO text and Yoast fields.
// Empty when Laravel doesn't answer: the page then keeps its own title and shows no SEO block.
import type { SitePage } from "../types/page";
import { withLang, type Lang } from "../i18n/lang";

const API_URL = import.meta.env.API_URL ?? "http://localhost:8000/api";

// A page not translated into `lang` has neither.
export async function wpPage(slug: string, lang: Lang = "ru"): Promise<Pick<SitePage, "seoText" | "seo">> {
	try {
		const response = await fetch(withLang(lang, `${API_URL}/pages/${slug}`));
		if (!response.ok) return { seoText: "", seo: null };
		const { data } = await response.json();
		return { seoText: data?.seoText ?? "", seo: data?.seo ?? null };
	} catch {
		return { seoText: "", seo: null };
	}
}
