// Shared by every ".carousel" block on the site (Hero.astro, RecipeGrid.astro,
// …), each distinguished only by a BEM modifier (`carousel--hero`,
// `carousel--recipes`). Handles pointer-drag scrolling, prev/next arrows and
// the dot pagination that goes with a `.carousel__track`.

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

	get pageWidth() {
		return this.track.clientWidth;
	}
	get maxScroll() {
		return Math.max(0, this.track.scrollWidth - this.track.clientWidth);
	}

	pageCount() {
		if (this.pageWidth === 0) return 1;
		return Math.max(1, Math.round(this.track.scrollWidth / this.pageWidth));
	}

	buildDots() {
		if (!this.dotsEl) return;
		const count = this.pageCount();
		if (count <= 1) {
			this.dotsEl.innerHTML = "";
			this.dotsEl.style.display = "none";
			return;
		}
		this.dotsEl.style.display = "";
		this.dotsEl.innerHTML = Array.from({ length: count })
			.map((_, i) => `<span data-i="${i}"></span>`)
			.join("");
		this.dotsEl.querySelectorAll("span").forEach((dot) => {
			dot.addEventListener("click", () => {
				const i = Number((dot as HTMLElement).dataset.i);
				this.track.scrollTo({ left: i * this.pageWidth, behavior: "smooth" });
			});
		});
	}

	onScroll() {
		const idx = this.pageWidth ? Math.round(this.track.scrollLeft / this.pageWidth) : 0;
		if (this.dotsEl) {
			this.dotsEl.querySelectorAll("span").forEach((d, i) => d.classList.toggle("is-active", i === idx));
		}
		if (this.prevBtn) this.prevBtn.disabled = this.track.scrollLeft <= 4;
		if (this.nextBtn) this.nextBtn.disabled = this.track.scrollLeft >= this.maxScroll - 4;
	}

	scrollByPage(dir: number) {
		this.track.scrollTo({ left: this.track.scrollLeft + dir * this.pageWidth, behavior: "smooth" });
	}

	snapToNearest() {
		if (!this.pageWidth) return;
		const idx = Math.round(this.track.scrollLeft / this.pageWidth);
		this.track.scrollTo({ left: idx * this.pageWidth, behavior: "smooth" });
	}
}

// `.carousel__dots` sits as the next sibling of `.carousel`, not inside it,
// so both markup and this lookup keep it out of the track's own subtree.
export function initDragCarousel(carouselEl: HTMLElement) {
	const track = carouselEl.querySelector<HTMLElement>(".carousel__track");
	if (!track) return;

	const dotsSibling = carouselEl.nextElementSibling;
	const dotsEl = dotsSibling?.classList.contains("carousel__dots") ? (dotsSibling as HTMLElement) : null;

	new DragCarousel(track, {
		dotsEl,
		prevBtn: carouselEl.querySelector<HTMLButtonElement>(".carousel__arrow--prev"),
		nextBtn: carouselEl.querySelector<HTMLButtonElement>(".carousel__arrow--next"),
	});
}
