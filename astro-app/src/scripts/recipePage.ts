// Recipe page: the servings stepper rescales ingredient quantities, and
// ingredients can be crossed off while cooking. Both are remembered per
// recipe in localStorage, so a page reload keeps them.

import { pluralc, tc } from "./i18n";
import { fraction, toUs, usLabel, type UnitSystem } from "../utils/units";

const MIN_SERVINGS = 1;
const MAX_SERVINGS = 12;

const servingsValue = document.getElementById("servingsValue");
const ingredientsServings = document.getElementById("ingredientsServings");
const ingredientList = document.getElementById("ingredientList");
// The servings the recipe's amounts are written for.
const BASE_SERVINGS = Number(ingredientList?.dataset.servings) || 4;
let servings = BASE_SERVINGS;

// By the original recipe's id: the same servings and ticks on the page in every language.
const STORAGE_KEY = `recipe:${ingredientList?.dataset.recipe ?? location.pathname}`;
// Saved by address before languages.
const OLD_KEY = `recipe:${location.pathname}`;

type SavedState = { servings?: number; checked?: number[] };

function loadState(): SavedState {
	try {
		return JSON.parse(localStorage.getItem(STORAGE_KEY) ?? localStorage.getItem(OLD_KEY) ?? "{}") ?? {};
	} catch {
		return {};
	}
}

function saveState() {
	const checked: number[] = [];
	checkboxes.forEach((box, i) => box.checked && checked.push(i));
	const state: SavedState = {};
	if (servings !== BASE_SERVINGS) state.servings = servings;
	if (checked.length) state.checked = checked;
	try {
		if (Object.keys(state).length) localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
		else localStorage.removeItem(STORAGE_KEY);
		if (OLD_KEY !== STORAGE_KEY) localStorage.removeItem(OLD_KEY);
	} catch {
		// Storage can be unavailable (private mode, quota) — just don't persist.
	}
}

const checkboxes = Array.from(
	ingredientList?.querySelectorAll<HTMLInputElement>(".ingredient-list__checkbox") ?? [],
);

function setChecked(box: HTMLInputElement, checked: boolean) {
	box.checked = checked;
	box.closest(".ingredient-list__item")?.classList.toggle("is-checked", checked);
}

// Snap to quarters, so quantities read like a real recipe: 1.25, not 1.2333.
const formatQty = (n: number) => String(Math.round(n * 4) / 4);

// Metric or US units on the English page (the switch is there only); one choice for every recipe.
const UNITS_KEY = "foodies:units";
const unitButtons = Array.from(document.querySelectorAll<HTMLButtonElement>("[data-units]"));
let units: UnitSystem = "metric";
try {
	if (unitButtons.length && localStorage.getItem(UNITS_KEY) === "us") units = "us";
} catch {
	// No storage: metric, as written.
}

function amountText(qty: number, item: HTMLElement): string {
	const us = units === "us" && item.dataset.code ? toUs(qty, item.dataset.code) : null;
	if (us) return `${fraction(us.quantity)} ${usLabel(us.unit, us.quantity)}`;
	// American recipes write every amount in fractions: "2 ¾", not "2.75".
	const amount = units === "us" ? fraction(Math.round(qty * 4) / 4) : formatQty(qty);
	return `${amount} ${item.dataset.unit ?? ""}`.trim();
}

function setServings(value: number) {
	servings = Math.min(MAX_SERVINGS, Math.max(MIN_SERVINGS, value));
	if (servingsValue) servingsValue.textContent = String(servings);
	if (ingredientsServings) ingredientsServings.textContent = tc("на {n} {word}", { n: servings, word: pluralc(servings, ["порцию", "порции", "порций"]) });

	ingredientList?.querySelectorAll<HTMLElement>("[data-base]").forEach((item) => {
		const qty = Number(item.dataset.base) * (servings / BASE_SERVINGS);
		const qtyEl = item.querySelector(".ingredient-list__qty");
		if (qtyEl) qtyEl.textContent = amountText(qty, item);
	});
	unitButtons.forEach((button) => button.setAttribute("aria-pressed", String(button.dataset.units === units)));
}

unitButtons.forEach((button) =>
	button.addEventListener("click", () => {
		units = button.dataset.units === "us" ? "us" : "metric";
		try {
			localStorage.setItem(UNITS_KEY, units);
		} catch {
			// Not remembered.
		}
		setServings(servings);
	}),
);

// Restore what was saved on the previous visit.
const saved = loadState();
if (typeof saved.servings === "number" || units !== "metric") setServings(saved.servings ?? BASE_SERVINGS);
saved.checked?.forEach((i) => checkboxes[i] && setChecked(checkboxes[i], true));

document.getElementById("servingsMinus")?.addEventListener("click", () => {
	setServings(servings - 1);
	saveState();
});
document.getElementById("servingsPlus")?.addEventListener("click", () => {
	setServings(servings + 1);
	saveState();
});

ingredientList?.addEventListener("change", (event) => {
	const input = event.target as HTMLInputElement;
	if (!input.classList.contains("ingredient-list__checkbox")) return;
	setChecked(input, input.checked);
	saveState();
});
