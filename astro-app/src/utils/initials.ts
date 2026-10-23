// "Мария Котова" → "МК". Used for avatars on the server and in the browser.
export const initials = (name: string) =>
	name
		.split(/\s+/)
		.filter(Boolean)
		.map((word) => word[0])
		.slice(0, 2)
		.join("")
		.toUpperCase();
