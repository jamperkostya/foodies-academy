// Wires the <Filters> row inside `root`: marks the picked button active and
// calls `onChange` with its group ("" is "Все"). What to hide is up to the
// caller (a carousel, a paged grid).
export function initFilters(root: HTMLElement, onChange: (group: string) => void) {
	const buttons = root.querySelectorAll<HTMLButtonElement>(".filters__btn");
	if (!buttons.length) return;

	buttons.forEach((button) => {
		button.addEventListener("click", () => {
			buttons.forEach((b) => {
				const isActive = b === button;
				b.classList.toggle("is-active", isActive);
				b.setAttribute("aria-pressed", String(isActive));
			});
			onChange(button.dataset.group ?? "");
		});
	});

	// The row scrolls sideways when it overflows. Touchpads and touch screens do
	// that natively; a plain mouse wheel only scrolls vertically, so turn it into
	// horizontal scroll while the row can move.
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
}

// Filter groups of an item: its `data-groups` JSON array.
export const groupsOf = (el: HTMLElement) => JSON.parse(el.dataset.groups ?? "[]") as string[];
