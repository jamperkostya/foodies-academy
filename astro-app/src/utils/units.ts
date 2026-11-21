// Ingredient amounts in US units for the English recipe page, as App\Support\Units converts them in
// Laravel (config/units.php): grams → ounces/pounds, millilitres and cups → teaspoons, tablespoons,
// cups, centimetres → inches. Spoons, pieces and "to taste" stay as they are.

export type UnitSystem = "metric" | "us";

type Kind = "mass" | "volume" | "length";

// Size in the base unit (g, ml, cm) of the units a recipe is written in.
const UNITS: Record<string, [Kind, number]> = {
	g: ["mass", 1],
	kg: ["mass", 1000],
	ml: ["volume", 1],
	l: ["volume", 1000],
	// A Russian "стакан" is 250 ml, a "чашка" a US cup.
	glass: ["volume", 250],
	cup: ["volume", 240],
	cm: ["length", 1],
	m: ["length", 100],
};

// English labels, singular and plural.
const LABELS: Record<string, [string, string]> = {
	oz: ["oz", "oz"],
	lb: ["lb", "lb"],
	tsp: ["tsp", "tsp"],
	tbsp: ["tbsp", "tbsp"],
	cup: ["cup", "cups"],
	in: ["inch", "inches"],
	g: ["g", "g"],
};

/** `value` rounded to 1/`per` or to thirds, whichever is closer: 0.33 → 1/3, 0.24 → 1/4. */
function nice(value: number, per: number): number {
	const quarter = Math.round(value * per) / per;
	const third = Math.round(value * 3) / 3;
	const best = Math.abs(value - third) < Math.abs(value - quarter) - 0.01 ? third : quarter;
	return best > 0 ? best : 1 / per;
}

/** `quantity` of `code` in US units, or null when it stays as written (spoons, pieces, unknown). */
export function toUs(quantity: number, code: string): { quantity: number; unit: string } | null {
	const unit = UNITS[code];
	if (!unit) return null;
	const [kind, size] = unit;
	const base = quantity * size;
	if (kind === "mass") {
		// A few grams of a spice say more than "0.18 oz".
		if (base < 15) return null;
		return base >= 450 ? { quantity: nice(base / 453.592, 4), unit: "lb" } : { quantity: nice(base / 28.3495, base < 113 ? 4 : 2), unit: "oz" };
	}
	if (kind === "volume") {
		if (base < 15) return { quantity: nice(base / 5, 4), unit: "tsp" };
		if (base < 60) return { quantity: nice(base / 15, 2), unit: "tbsp" };
		return { quantity: nice(base / 240, 4), unit: "cup" };
	}
	return { quantity: nice(base / 2.54, 2), unit: "in" };
}

export const usLabel = (unit: string, quantity: number): string => (LABELS[unit] ?? [unit, unit])[quantity > 1 ? 1 : 0];

const FRACTIONS: [number, string][] = [
	[1 / 4, "¼"],
	[1 / 3, "⅓"],
	[1 / 2, "½"],
	[2 / 3, "⅔"],
	[3 / 4, "¾"],
];

/** 1.5 → "1 ½", 0.333 → "⅓", 2 → "2": how American recipes write amounts. */
export function fraction(value: number): string {
	const whole = Math.floor(value + 0.01);
	const rest = value - whole;
	const part = FRACTIONS.find(([size]) => Math.abs(rest - size) < 0.02)?.[1];
	if (rest < 0.02) return String(whole);
	if (!part) return String(Math.round(value * 100) / 100);
	return whole ? `${whole} ${part}` : part;
}
