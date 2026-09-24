// Shared by every ".carousel" block on the site (Hero.astro, Carousel.astro),
// each distinguished only by a BEM modifier (`carousel--hero`,
// `carousel--recipes`, …). Handles pointer-drag scrolling, prev/next arrows
// and the dot pagination that goes with a `.carousel__track`.

class DragCarousel {
	track: HTMLElement;
	dotsEl: HTMLElement | null;
	prevBtn: HTMLButtonElement | null;
	nextBtn: HTMLButtonElement | null;
	isDown = false;
	dragged = false;
	startX = 0;
	startScroll = 0;

	constructor(
		track: HTMLElement,
		{
			dotsEl,
			prevBtn,
			nextBtn,
		}: {
			dotsEl?: HTMLElement | null;
			prevBtn?: HTMLButtonElement | null;
			nextBtn?: HTMLButtonElement | null;
		} = {}
	) {
		this.track = track;
		this.dotsEl = dotsEl ?? null;
		this.prevBtn = prevBtn ?? null;
		this.nextBtn = nextBtn ?? null;

		this.track.addEventListener("scroll", () => this.onScroll(), { passive: true });
		this.prevBtn?.addEventListener("click", () => this.scrollByPage(-1));
		this.nextBtn?.addEventListener("click", () => this.scrollByPage(1));

		this.bindDrag();
		window.addEventListener("resize", () => this.refresh());
		this.refresh();
	}

	bindDrag() {
		const t = this.track;
		t.classList.add("is-draggable");

		t.addEventListener("pointerdown", (e) => {
			if (e.pointerType !== "mouse" || e.button !== 0) return;
			this.isDown = true;
			this.dragged = false;
			this.startX = e.clientX;
			this.startScroll = t.scrollLeft;
			t.classList.add("is-dragging");
			t.setPointerCapture(e.pointerId);
		});

		t.addEventListener("pointermove", (e) => {
			if (!this.isDown) return;
			const dx = e.clientX - this.startX;
			if (Math.abs(dx) > 4) this.dragged = true;
			t.scrollLeft = this.startScroll - dx;
		});

		const end = () => {
			if (!this.isDown) return;
			this.isDown = false;
			t.classList.remove("is-dragging");
			this.snapToNearest();
		};
		t.addEventListener("pointerup", end);
		t.addEventListener("pointerleave", end);
		t.addEventListener("pointercancel", end);

		t.addEventListener(
			"click",
			(e) => {
				if (this.dragged) {
					e.preventDefault();
					e.stopPropagation();
				}
			},
			true
		);
	}

	refresh() {
		this.buildDots();
		this.onScroll();
	}

	// One "page" is a viewport of cards plus the gap before the next one. The
	// track's side padding (full-bleed rows) isn't part of the viewport.
	get pageStep() {
		const style = getComputedStyle(this.track);
		const padding = parseFloat(style.paddingLeft) + parseFloat(style.paddingRight);
		return this.track.clientWidth - padding + (parseFloat(style.columnGap) || 0);
	}
	get maxScroll() {
		return Math.max(0, this.track.scrollWidth - this.track.clientWidth);
	}

	// Scroll offset of every page. The last one is clamped to the end, so a
	// partial last page (e.g. 9 cards at 8 per view) still gets its own page.
	pagePositions() {
		const step = this.pageStep;
		if (!step) return [0];
		// Small tolerance so sub-pixel rounding doesn't add an empty page.
		const count = Math.ceil(this.maxScroll / step - 0.05) + 1;
		return Array.from({ length: count }, (_, i) => Math.min(i * step, this.maxScroll));
	}

	nearestPage(positions: number[]) {
		const x = this.track.scrollLeft;
		let best = 0;
		positions.forEach((pos, i) => {
			if (Math.abs(pos - x) < Math.abs(positions[best] - x)) best = i;
		});
		return best;
	}

	buildDots() {
		if (!this.dotsEl) return;
		const positions = this.pagePositions();
		if (positions.length <= 1) {
			this.dotsEl.innerHTML = "";
			this.dotsEl.style.display = "none";
			return;
		}
		this.dotsEl.style.display = "";
		this.dotsEl.innerHTML = positions.map((_, i) => `<span class="carousel__dot" data-i="${i}"></span>`).join("");
		this.dotsEl.querySelectorAll("span").forEach((dot) => {
			dot.addEventListener("click", () => {
				const i = Number((dot as HTMLElement).dataset.i);
				this.track.scrollTo({ left: this.pagePositions()[i], behavior: "smooth" });
			});
		});
	}

	onScroll() {
		if (this.dotsEl) {
			const idx = this.nearestPage(this.pagePositions());
			this.dotsEl.querySelectorAll("span").forEach((d, i) => d.classList.toggle("is-active", i === idx));
		}
		if (this.prevBtn) this.prevBtn.disabled = this.track.scrollLeft <= 4;
		if (this.nextBtn) this.nextBtn.disabled = this.track.scrollLeft >= this.maxScroll - 4;
	}

	scrollByPage(dir: number) {
		const positions = this.pagePositions();
		const idx = Math.max(0, Math.min(positions.length - 1, this.nearestPage(positions) + dir));
		this.track.scrollTo({ left: positions[idx], behavior: "smooth" });
	}

	snapToNearest() {
		const positions = this.pagePositions();
		this.track.scrollTo({ left: positions[this.nearestPage(positions)], behavior: "smooth" });
	}
}

// `.carousel__dots` sits as the next sibling of `.carousel`, not inside it,
// so both markup and this lookup keep it out of the track's own subtree.
export function initDragCarousel(carouselEl: HTMLElement) {
	const track = carouselEl.querySelector<HTMLElement>(".carousel__track");
	if (!track) return;

	const dotsSibling = carouselEl.nextElementSibling;
	const dotsEl = dotsSibling?.classList.contains("carousel__dots") ? (dotsSibling as HTMLElement) : null;

	return new DragCarousel(track, {
		dotsEl,
		prevBtn: carouselEl.querySelector<HTMLButtonElement>(".carousel__arrow--prev"),
		nextBtn: carouselEl.querySelector<HTMLButtonElement>(".carousel__arrow--next"),
	});
}
