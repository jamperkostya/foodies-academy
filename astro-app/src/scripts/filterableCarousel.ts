import { initDragCarousel } from "./dragCarousel";
import { initFilters, groupsOf } from "./filters";

// A `.carousel` plus the optional <Filters> row in the same `root`: picking a
// filter hides the cards outside that group and restarts the carousel from
// its first page. Without filters it's just a plain drag carousel.
export function initFilterableCarousel(root: HTMLElement) {
	const carousel = root.querySelector<HTMLElement>(".carousel");
	const track = carousel?.querySelector<HTMLElement>(".carousel__track");
	if (!carousel || !track) return;

	const slider = initDragCarousel(carousel);
	const items = [...track.querySelectorAll<HTMLElement>("[data-groups]")].map((el) => ({ el, groups: groupsOf(el) }));

	initFilters(root, (group) => {
		items.forEach((item) => {
			item.el.hidden = group !== "" && !item.groups.includes(group);
		});
		track.scrollTo({ left: 0, behavior: "instant" });
		slider?.refresh();
	});
}
