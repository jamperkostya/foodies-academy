import { getViteConfig } from "astro/config";

export default getViteConfig({
	test: {
		include: ["tests/**/*.test.ts"],
		setupFiles: ["tests/setup.ts"],
	},
});
