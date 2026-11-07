import { describe, expect, it, vi } from "vitest";
import { resilientFetch } from "../src/build/apiFetch";

const answer = (status: number) => new Response("{}", { status });
const options = { timeout: 50, retries: 2, delay: 0 };

describe("resilientFetch", () => {
	it("tries a 5xx answer again", async () => {
		const fetchFn = vi.fn<typeof fetch>().mockResolvedValueOnce(answer(500)).mockResolvedValueOnce(answer(200));

		const response = await resilientFetch(fetchFn, options)("https://api/recipes");

		expect(response.status).toBe(200);
		expect(fetchFn).toHaveBeenCalledTimes(2);
	});

	it("returns a 4xx answer at once: asking again won't change it", async () => {
		const fetchFn = vi.fn<typeof fetch>().mockResolvedValue(answer(404));

		expect((await resilientFetch(fetchFn, options)("https://api/pages/x")).status).toBe(404);
		expect(fetchFn).toHaveBeenCalledTimes(1);
	});

	it("gives up after the retries with the address in the error", async () => {
		const fetchFn = vi.fn<typeof fetch>().mockRejectedValue(new TypeError("fetch failed"));

		await expect(resilientFetch(fetchFn, options)("https://api/stats")).rejects.toThrow("https://api/stats: fetch failed");
		expect(fetchFn).toHaveBeenCalledTimes(3);
	});

	it("stops waiting for an answer that never comes", async () => {
		// Like a request to an API stuck on a locked database: no answer until aborted.
		const hang: typeof fetch = (_input, init) =>
			new Promise((_resolve, reject) => init?.signal?.addEventListener("abort", () => reject(init.signal!.reason)));

		await expect(resilientFetch(hang, { ...options, retries: 0 })("https://api/recipes/x")).rejects.toThrow("https://api/recipes/x");
	});
});
