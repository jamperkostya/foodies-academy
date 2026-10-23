// Short message at the bottom of the screen. The element is rendered once by
// components/ui/Toast.astro in BaseLayout.

let timer: number | undefined;

export function showToast(text: string) {
	const toast = document.querySelector<HTMLElement>(".toast");
	if (!toast) return;
	toast.textContent = text;
	toast.classList.add("is-visible");
	clearTimeout(timer);
	timer = window.setTimeout(() => toast.classList.remove("is-visible"), 2200);
}
