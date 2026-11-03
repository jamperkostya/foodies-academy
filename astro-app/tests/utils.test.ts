import { describe, expect, it } from "vitest";
import { plural, recipesWord } from "../src/utils/plural";
import { initials } from "../src/utils/initials";
import { pageHref, pageNumber, pageParams } from "../src/utils/pagination";
import { jsonLd } from "../src/utils/jsonLd";
import { shortCount } from "../src/utils/shortCount";
import { findItems, normalize, type SearchItem } from "../src/scripts/search";
import { nextPath } from "../src/scripts/account";

describe("plural", () => {
	it("picks the Russian form", () => {
		const forms: [string, string, string] = ["отзыв", "отзыва", "отзывов"];
		expect([1, 2, 5, 11, 21, 22, 25, 111].map((n) => plural(n, forms))).toEqual(
			["отзыв", "отзыва", "отзывов", "отзывов", "отзыв", "отзыва", "отзывов", "отзывов"],
		);
		expect(recipesWord(640)).toBe("рецептов");
	});
});

describe("initials", () => {
	it("takes the first letters of the first two words", () => {
		expect(initials("Мария Котова")).toBe("МК");
	});
});

describe("pagination", () => {
	it("splits a list into pages of 24", () => {
		expect(pageParams(0)).toEqual([undefined]);
		expect(pageParams(49)).toEqual([undefined, "page/2", "page/3"]);
		expect(pageNumber(undefined)).toBe(1);
		expect(pageNumber("page/3")).toBe(3);
		expect(pageHref("/category/soups", 1)).toBe("/category/soups");
		expect(pageHref("/category/soups", 2)).toBe("/category/soups/page/2");
	});
});

describe("jsonLd", () => {
	it("can't end its <script> early", () => {
		const text = jsonLd({ name: "</script><script>alert(1)</script>" });
		expect(text).not.toContain("</script>");
		expect(JSON.parse(text).name).toBe("</script><script>alert(1)</script>");
	});
});

describe("shortCount (Нам доверяют)", () => {
	it("rounds down to what the block shows", () => {
		expect(shortCount(640)).toEqual({ count: 640, decimals: 0, suffix: "+" });
		expect(shortCount(313806)).toEqual({ count: 313, decimals: 0, suffix: " тыс.+" });
		expect(shortCount(1222040)).toEqual({ count: 1.2, decimals: 1, suffix: " млн+" });
		// Rounded down, never up: 1.99 million is not "2 млн".
		expect(shortCount(1999999).count).toBe(1.9);
	});
});

describe("search", () => {
	const items: SearchItem[] = [
		{ title: "Борщ украинский", meta: "Супы", icon: "🍲", href: "/recipe/borscht", ingredients: ["Свёкла"] },
		{ title: "Салат с ёжевикой", meta: "Салаты", icon: "🥗", href: "/recipe/salad" },
		{ title: "Винегрет", meta: "Салаты", icon: "🥗", href: "/recipe/vinegret", ingredients: ["Свекла", "Огурцы"] },
	];

	it("ignores case and ё", () => {
		expect(normalize("Ёжик ЁЛКА")).toBe("ежик елка");
		expect(findItems(items, normalize("ЕЖЕВИК")).map((i) => i.href)).toEqual(["/recipe/salad"]);
	});

	it("finds titles first, then recipes by ingredient", () => {
		const found = findItems(items, normalize("свекл"));
		expect(found.map((i) => i.href)).toEqual(["/recipe/borscht", "/recipe/vinegret"]);
		expect(found[0].meta).toBe("Супы · с ингредиентом «Свёкла»");
	});
});

describe("nextPath (?next= after log-in)", () => {
	it("keeps paths on this site only", () => {
		expect(nextPath("?next=%2Frecipe%2Fborscht")).toBe("/recipe/borscht");
		expect(nextPath("?next=%2Fprofile%23favorites")).toBe("/profile#favorites");
		expect(nextPath("")).toBe("/profile");
		expect(nextPath("?next=https%3A%2F%2Fevil.test")).toBe("/profile");
		expect(nextPath("?next=%2F%2Fevil.test")).toBe("/profile");
		expect(nextPath("?next=%2F%5Cevil.test")).toBe("/profile");
	});
});
