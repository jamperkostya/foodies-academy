// Requests the build makes to the Laravel API. fetch has no timeout: one request that never got
// an answer (the API stuck on a locked database) kept the build running for an hour. Each try now
// gets a timeout, and network errors, timeouts and 5xx answers are tried again.
import type { AstroIntegration } from "astro";

interface Options {
	timeout?: number;
	retries?: number;
	delay?: number;
}

export function resilientFetch(fetchFn: typeof fetch, { timeout = 60_000, retries = 2, delay = 3_000 }: Options = {}): typeof fetch {
	return async (input, init) => {
		const url = input instanceof Request ? input.url : String(input);
		for (let attempt = 0; ; attempt++) {
			try {
				const response = await fetchFn(input, { ...init, signal: init?.signal ?? AbortSignal.timeout(timeout) });
				if (response.status < 500 || attempt >= retries) return response;
				await response.body?.cancel();
			} catch (error) {
				if (attempt >= retries) throw new Error(`${url}: ${(error as Error).message}`, { cause: error });
			}
			await new Promise((resolve) => setTimeout(resolve, delay * (attempt + 1)));
		}
	};
}

// Only for `astro build`: the dev server and the tests keep the plain fetch.
export const apiFetchIntegration = (): AstroIntegration => ({
	name: "api-fetch",
	hooks: {
		"astro:build:start": () => {
			globalThis.fetch = resilientFetch(globalThis.fetch);
		},
	},
});
