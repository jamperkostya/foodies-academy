import { initDragCarousel } from "./dragCarousel";

// Wires a `.carousel` to the optional <Filters> row that sits in the same
// `root`. Every carousel item carries `data-groups` (a JSON array); picking a
// filter hides the items outside that group and restarts the carousel from
// its first page. Without filters it's just a plain drag carousel.
export function initFilterableCarousel(root: HTMLElement) {
	const carousel = root.querySelector<HTMLElement>(".carousel");
	const track = carousel?.querySelector<HTMLElement>(".carousel__track");
	if (!carousel || !track) return;

	const slider = initDragCarousel(carousel);

	// The filters row scrolls sideways when it overflows. Touchpads and
	// touch screens do that natively; a plain mouse wheel only scrolls
	// vertically, so turn it into horizontal scroll while the row can move.
	const list = root.querySelector<HTMLElement>(".filters__list");
	list?.addEventListener(
		"wheel",
		(e) => {
			if (Math.abs(e.deltaY) <= Math.abs(e.deltaX)) return;
			const max = list.scrollWidth - list.clientWidth;
			const canScroll = e.deltaY < 0 ? list.scrollLeft > 0 : list.scrollLeft < max - 1;
			if (!canScroll) return;
			e.preventDefault();
			list.scrollLeft += e.deltaY;
		},
		{ passive: false }
	);

	const buttons = root.querySelectorAll<HTMLButtonElement>(".filters__btn");
	const items = [...track.querySelectorAll<HTMLElement>("[data-groups]")].map((el) => ({
		el,
		groups: JSON.parse(el.dataset.groups ?? "[]") as string[],
	}));

	buttons.forEach((button) => {
		button.addEventListener("click", () => {
			// "" is the "Все" button.
			const group = button.dataset.group ?? "";

			buttons.forEach((b) => {
				const isActive = b === button;
				b.classList.toggle("is-active", isActive);
				b.setAttribute("aria-pressed", String(isActive));
			});
			items.forEach((item) => {
				item.el.hidden = group !== "" && !item.groups.includes(group);
			});

			track.scrollTo({ left: 0, behavior: "instant" });
			slider?.refresh();
		});
	});
}
