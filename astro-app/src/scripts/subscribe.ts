// Newsletter forms (SubscribeSection, NewsletterCard): <form data-subscribe={url}> with
// "name" and "email" fields. Laravel passes them to Contact Form 7, and CFDB7 saves them.
// On success the form gets .is-done, and its [data-subscribe-done] sibling is shown.
import { showToast } from "./toast";

document.querySelectorAll<HTMLFormElement>("form[data-subscribe]").forEach((form) => {
	form.addEventListener("submit", async (event) => {
		event.preventDefault();
		const data = new FormData(form);
		const name = String(data.get("name") ?? "").trim();
		const email = String(data.get("email") ?? "").trim();
		if (!name || !email || !form.checkValidity()) {
			showToast("Укажите имя и e-mail");
			return;
		}

		const button = form.querySelector<HTMLButtonElement>("[type=submit]");
		if (button) button.disabled = true;
		try {
			const response = await fetch(form.dataset.subscribe!, {
				method: "POST",
				headers: { "Content-Type": "application/json", Accept: "application/json" },
				body: JSON.stringify({ name, email }),
			});
			if (!response.ok) {
				showToast(
					response.status === 422 ? "Проверьте имя и e-mail"
					: response.status === 429 ? "Слишком много попыток, попробуйте через минуту"
					: "Не удалось оформить подписку, попробуйте позже",
				);
				return;
			}
			form.reset();
			const done = form.parentElement?.querySelector<HTMLElement>("[data-subscribe-done]");
			if (done) {
				form.hidden = true;
				done.hidden = false;
			} else {
				showToast("Готово! Вы подписаны на рассылку");
			}
		} catch {
			showToast("Не удалось оформить подписку, проверьте интернет");
		} finally {
			if (button) button.disabled = false;
		}
	});
});
