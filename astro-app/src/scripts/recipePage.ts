// Recipe page interactivity: servings stepper, ingredient and step checklists,
// save / share / print, review form and the sidebar newsletter form.

const BASE_SERVINGS = 4;
const MIN_SERVINGS = 1;
const MAX_SERVINGS = 12;

const $ = <T extends HTMLElement>(selector: string) => document.querySelector<T>(selector);

// --- Toast -----------------------------------------------------------------

const toast = $(".toast");
let toastTimer: number | undefined;

function showToast(text: string) {
	if (!toast) return;
	toast.textContent = text;
	toast.classList.add("is-visible");
	clearTimeout(toastTimer);
	toastTimer = window.setTimeout(() => toast.classList.remove("is-visible"), 2200);
}

// --- Servings: rescale every ingredient quantity --------------------------

const servingsValue = $("#servingsValue");
const ingredientsServings = $("#ingredientsServings");
const ingredientsServingsWord = $("#ingredientsServingsWord");
const ingredientList = $("#ingredientList");
let servings = BASE_SERVINGS;

// Snap to quarters, so quantities read like a real recipe: 1.25, not 1.2333.
const formatQty = (n: number) => String(Math.round(n * 4) / 4);

function pluralizeServings(n: number) {
	const mod10 = n % 10;
	const mod100 = n % 100;
	if (mod10 === 1 && mod100 !== 11) return "порция";
	if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return "порции";
	return "порций";
}

function setServings(value: number) {
	servings = Math.min(MAX_SERVINGS, Math.max(MIN_SERVINGS, value));
	if (servingsValue) servingsValue.textContent = String(servings);
	if (ingredientsServings) ingredientsServings.textContent = String(servings);
	if (ingredientsServingsWord) ingredientsServingsWord.textContent = pluralizeServings(servings);

	ingredientList?.querySelectorAll<HTMLElement>("[data-base]").forEach((item) => {
		const qty = Number(item.dataset.base) * (servings / BASE_SERVINGS);
		const qtyEl = item.querySelector(".ingredient-list__qty");
		if (qtyEl) qtyEl.textContent = `${formatQty(qty)} ${item.dataset.unit ?? ""}`.trim();
	});
}

$("#servingsMinus")?.addEventListener("click", () => setServings(servings - 1));
$("#servingsPlus")?.addEventListener("click", () => setServings(servings + 1));

// --- Ingredient checklist: cross off while cooking ------------------------

ingredientList?.addEventListener("change", (event) => {
	const input = event.target as HTMLInputElement;
	input.closest(".ingredient-list__item")?.classList.toggle("is-checked", input.checked);
});

// --- Step checklist: click a step to mark it done -------------------------

const stepList = $("#stepList");
const stepsProgress = $("#stepsProgress");

function updateStepsProgress() {
	if (!stepList || !stepsProgress) return;
	const done = stepList.querySelectorAll(".is-done").length;
	stepsProgress.textContent = `${done} из ${stepList.children.length} шагов`;
}

stepList?.addEventListener("click", (event) => {
	const step = (event.target as HTMLElement).closest(".step-list__item");
	if (!step) return;
	step.classList.toggle("is-done");
	updateStepsProgress();
});
updateStepsProgress();

// --- Save / share / print / video -----------------------------------------

const saveBtn = $("#saveBtn");
saveBtn?.addEventListener("click", () => {
	const saved = saveBtn.classList.toggle("is-active");
	saveBtn.setAttribute("aria-pressed", String(saved));
	showToast(saved ? "Рецепт сохранён в избранное" : "Рецепт убран из избранного");
});

$("#shareBtn")?.addEventListener("click", async () => {
	const url = window.location.href;
	try {
		if (navigator.share) {
			await navigator.share({ title: document.title, url });
			return;
		}
		await navigator.clipboard.writeText(url);
		showToast("Ссылка скопирована");
	} catch {
		// The user closed the share sheet, or the clipboard is unavailable.
	}
});

$("#printBtn")?.addEventListener("click", () => window.print());

$("#playVideoBtn")?.addEventListener("click", () => showToast("Видео скоро будет доступно"));

// --- Review form: star rating input + prepend the new review --------------

const reviewForm = $<HTMLFormElement>("#reviewForm");
const ratingInput = $("#ratingInput");
const ratingStars = [...(ratingInput?.querySelectorAll<HTMLButtonElement>("button") ?? [])];
const reviewList = $("#reviewList");
const reviewTemplate = $<HTMLTemplateElement>("#reviewTemplate");
let rating = 0;

const paintStars = (value: number) =>
	ratingStars.forEach((star, i) => star.classList.toggle("is-filled", i < value));

ratingStars.forEach((star, i) => {
	star.addEventListener("mouseenter", () => paintStars(i + 1));
	star.addEventListener("click", () => {
		rating = i + 1;
		ratingStars.forEach((s, j) => s.setAttribute("aria-pressed", String(j === i)));
		paintStars(rating);
	});
});
ratingInput?.addEventListener("mouseleave", () => paintStars(rating));

reviewForm?.addEventListener("submit", (event) => {
	event.preventDefault();
	const data = new FormData(reviewForm);
	const name = String(data.get("name") ?? "").trim();
	const comment = String(data.get("comment") ?? "").trim();

	if (!name || !comment || !rating) {
		showToast("Заполните имя, отзыв и поставьте оценку");
		return;
	}
	if (!reviewList || !reviewTemplate) return;

	// Cloned from a <template>, so the scoped styles apply and user text only
	// ever goes in through textContent.
	const review = reviewTemplate.content.firstElementChild!.cloneNode(true) as HTMLElement;
	const initials = name.split(/\s+/).map((word) => word[0]).slice(0, 2).join("").toUpperCase();
	review.querySelector(".avatar")!.textContent = initials;
	review.querySelector(".review__name")!.textContent = name;
	review.querySelector(".review__text")!.textContent = comment;
	review.querySelector<HTMLElement>(".stars")?.style.setProperty("--stars-fill", `${(rating / 5) * 100}%`);
	reviewList.prepend(review);

	reviewForm.reset();
	rating = 0;
	ratingStars.forEach((s) => s.setAttribute("aria-pressed", "false"));
	paintStars(0);
	showToast("Спасибо за отзыв!");
});

// --- Sidebar newsletter ---------------------------------------------------

const newsletterForm = $<HTMLFormElement>("#sidebarNewsletterForm");
newsletterForm?.addEventListener("submit", (event) => {
	event.preventDefault();
	newsletterForm.hidden = true;
	$("#sidebarNewsletterSuccess")?.removeAttribute("hidden");
});
