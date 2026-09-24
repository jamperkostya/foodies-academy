const rules = new Intl.PluralRules("ru-RU");

// Russian plural form for a count: plural(3, ["рецепт", "рецепта", "рецептов"]) → "рецепта".
export function plural(count: number, [one, few, many]: [string, string, string]) {
	const form = rules.select(count);
	return form === "one" ? one : form === "many" ? many : few;
}
