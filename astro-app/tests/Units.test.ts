import { describe, expect, it } from "vitest";
import { fraction, toUs, usLabel } from "../src/utils/units";

// The same cases as laravel-app/tests/Unit/UnitsTest.php: the page and the API convert alike.
describe("US units", () => {
	it("converts weights, volumes and lengths, and leaves the rest", () => {
		expect(toUs(100, "g")).toEqual({ quantity: 3.5, unit: "oz" });
		expect(toUs(1, "kg")).toEqual({ quantity: 2.25, unit: "lb" });
		expect(toUs(5, "g")).toBeNull();
		expect(toUs(1, "glass")).toEqual({ quantity: 1, unit: "cup" });
		expect(toUs(80, "ml")).toEqual({ quantity: 1 / 3, unit: "cup" });
		expect(toUs(30, "ml")).toEqual({ quantity: 2, unit: "tbsp" });
		expect(toUs(24, "cm")).toEqual({ quantity: 9.5, unit: "in" });
		expect(toUs(1, "tsp")).toBeNull();
		expect(toUs(3, "clove")).toBeNull();
	});

	it("writes amounts and labels as American recipes do", () => {
		expect([1.5, 1 / 3, 2, 0.25, 2.75].map(fraction)).toEqual(["1 ½", "⅓", "2", "¼", "2 ¾"]);
		expect(usLabel("cup", 1)).toBe("cup");
		expect(usLabel("cup", 1.5)).toBe("cups");
		expect(usLabel("oz", 3.5)).toBe("oz");
	});
});
