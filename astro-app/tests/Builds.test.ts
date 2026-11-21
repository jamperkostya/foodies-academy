import { afterEach, describe, expect, it, vi } from "vitest";
import { builds } from "../src/i18n/lang";

describe("builds", () => {
	afterEach(() => vi.unstubAllEnvs());

	it("builds every language unless BUILD_LANGS names some", () => {
		vi.stubEnv("BUILD_LANGS", "");
		expect([builds("ru"), builds("en")]).toEqual([true, true]);
		vi.stubEnv("BUILD_LANGS", "en");
		expect([builds("ru"), builds("en")]).toEqual([false, true]);
		vi.stubEnv("BUILD_LANGS", "ru,en");
		expect([builds("ru"), builds("en")]).toEqual([true, true]);
	});
});
