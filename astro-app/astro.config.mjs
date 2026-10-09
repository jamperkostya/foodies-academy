// @ts-check
import { defineConfig } from 'astro/config';

// https://astro.build/config
export default defineConfig({
	vite: {
		css: {
			preprocessorOptions: {
				less: { additionalData: `@import "/src/styles/media.less";` },
			},
		},
	},
});