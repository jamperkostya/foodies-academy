const rules = new Intl.PluralRules("ru-RU");

// Russian plural form for a count: plural(3, ["категория", "категории", "категорий"]) → "категории".
export function plural(count: number, [one, few, many]: [string, string, string]) {
	const form = rules.select(count);
	return form === "one" ? one : form === "many" ? many : few;
}

// "рецепт" / "рецепта" / "рецептов" for a count.
export const recipesWord = (count: number) => plural(count, ["рецепт", "рецепта", "рецептов"]);
