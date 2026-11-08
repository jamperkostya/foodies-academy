// @ts-check
import { defineConfig } from 'astro/config';
import { htaccessIntegration } from './src/build/htaccess.ts';
import { apiFetchIntegration } from './src/build/apiFetch.ts';

// https://astro.build/config
export default defineConfig({
	// Public address of the site: canonical URLs, Open Graph, sitemap.
	site: process.env.SITE_URL ?? "https://foodies.academy",
	// Addresses without a slash at the end, as on the old site: recipe/x.html is /recipe/x.
	trailingSlash: "never",
	// Two pages at a time: most of a page's build time is waiting for the API, and the API and
	// WordPress share a small server (1 CPU, 1 GB) with the live site.
	build: { format: "file", concurrency: 2 },
	integrations: [apiFetchIntegration(), htaccessIntegration(process.env.API_URL ?? "http://localhost:8000/api")],
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
