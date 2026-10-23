// Recipe page: the servings stepper rescales ingredient quantities, and
// ingredients can be crossed off while cooking.

const BASE_SERVINGS = 4;
const MIN_SERVINGS = 1;
const MAX_SERVINGS = 12;

const servingsValue = document.getElementById("servingsValue");
const ingredientsServings = document.getElementById("ingredientsServings");
const ingredientList = document.getElementById("ingredientList");
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
	if (ingredientsServings) ingredientsServings.textContent = `на ${servings} ${pluralizeServings(servings)}`;

	ingredientList?.querySelectorAll<HTMLElement>("[data-base]").forEach((item) => {
		const qty = Number(item.dataset.base) * (servings / BASE_SERVINGS);
		const qtyEl = item.querySelector(".ingredient-list__qty");
		if (qtyEl) qtyEl.textContent = `${formatQty(qty)} ${item.dataset.unit ?? ""}`.trim();
	});
}

document.getElementById("servingsMinus")?.addEventListener("click", () => setServings(servings - 1));
document.getElementById("servingsPlus")?.addEventListener("click", () => setServings(servings + 1));

ingredientList?.addEventListener("change", (event) => {
	const input = event.target as HTMLInputElement;
	input.closest(".ingredient-list__item")?.classList.toggle("is-checked", input.checked);
});
