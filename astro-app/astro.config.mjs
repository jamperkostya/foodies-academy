// @ts-check
import { defineConfig } from 'astro/config';

// https://astro.build/config
export default defineConfig({
	// Fetch a page as soon as its link is hovered or focused, so the click
	// opens it almost instantly. "viewport" would fetch every card on screen.
	prefetch: {
		prefetchAll: true,
		defaultStrategy: "hover",
	},
	vite: {
		css: {
			preprocessorOptions: {
				less: { additionalData: `@import "/src/styles/media.less";` },
			},
		},
	},
});