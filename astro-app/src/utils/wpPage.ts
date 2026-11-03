// A WordPress page for the Astro page built in its place: its SEO text and Yoast fields.
// Empty when Laravel doesn't answer: the page then keeps its own title and shows no SEO block.
import type { SitePage } from "../types/page";

const API_URL = import.meta.env.API_URL ?? "http://localhost:8000/api";

export async function wpPage(slug: string): Promise<Pick<SitePage, "seoText" | "seo">> {
	try {
		const response = await fetch(`${API_URL}/pages/${slug}`);
		if (!response.ok) return { seoText: "", seo: null };
		const { data } = await response.json();
		return { seoText: data?.seoText ?? "", seo: data?.seo ?? null };
	} catch {
		return { seoText: "", seo: null };
	}
}
