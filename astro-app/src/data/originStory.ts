// Mock until recipes come from the CMS: the rendered HTML of the
// "История происхождения" block pattern, as WordPress returns it in content.rendered.
const p = (text: string, className?: string) => (className ? `<p class="${className}">${text}</p>` : `<p>${text}</p>`);
const group = (className: string, inner: string) =>
	`<div class="wp-block-group ${className} is-layout-flow wp-block-group-is-layout-flow">${inner}</div>`;
const h = (level: number, text: string) => `<h${level} class="wp-block-heading">${text}</h${level}>`;

const fact = (value: string, label: string) =>
	group("origin-story__fact", p(value, "origin-story__fact-value") + p(label, "origin-story__fact-label"));
const theory = (tag: string, title: string, text: string) =>
	group("origin-story__theory", p(tag, "origin-story__tag") + h(4, title) + p(text));
const item = (year: string, title: string, text: string) =>
	group("origin-story__item", p(year, "origin-story__year") + h(4, title) + p(text));

export const originStoryHtml = group(
	"origin-story",
	p(
		"У карбонары нет одного автора и точной даты рождения — зато есть несколько версий, и все они спорят друг с другом. Одно известно точно: это молодое блюдо. До Второй мировой войны его не найти ни в одной итальянской кулинарной книге.",
		"origin-story__lead",
	) +
		group("origin-story__facts", fact("1950", "первое упоминание") + fact("5", "ингредиентов") + fact("0 мл", "сливок")) +
		h(3, "Откуда название") +
		group(
			"origin-story__theories",
			theory("Легенда", "Пища угольщиков", "Пасту с яйцом, салом и сыром готовили карбонарии — угольщики Апеннин. Чёрный перец на тарелке напоминал угольную пыль.") +
				theory("Версия", "Паёк союзников", "В 1944 году солдаты союзников приносили в Италию бекон и яичный порошок. Из них римские повара и собрали новую пасту.") +
				theory("Версия", "Неаполитанский предок", "Ещё в 1837 году Ипполито Кавальканти описал пасту с яйцом и сыром. Карбонара могла вырасти из этого рецепта."),
		) +
		h(3, "Как менялся рецепт") +
		group(
			"origin-story__timeline",
			item("1837", "Паста с яйцом и сыром", "Неаполитанская кулинарная книга Кавальканти — самый ранний похожий рецепт.") +
				item("1944", "Американский паёк", "Повар Ренато Гуаланди вспоминал, что приготовил пасту из бекона и яичного порошка для офицеров союзников в Риччоне.") +
				item("1950", "Первое упоминание", "Газета La Stampa пишет о «спагетти алла карбонара», которые ищут в римском Трастевере американские офицеры.") +
				item("1954", "Первый рецепт", "Журнал La Cucina Italiana публикует рецепт — с панчеттой и грюйером. До канона ещё далеко.") +
				item("Сегодня", "Римская классика", "Гуанчале, пекорино романо, желтки и перец — без сливок и чеснока. 6 апреля отмечают Международный день карбонары."),
		) +
		group(
			"origin-story__note",
			h(4, "А вы знали?") +
				p("Гуанчале — это свиная щека, а не грудинка. В ней больше нежного жира: он и делает соус карбонары таким бархатным."),
		) +
		`<blockquote class="wp-block-quote origin-story__quote is-layout-flow wp-block-quote-is-layout-flow">${p(
			"Карбонара — не древний рецепт, а история послевоенного Рима: простые продукты, голодное время и гениальная техника.",
		)}<cite>Редакция Foodies</cite></blockquote>`,
);
