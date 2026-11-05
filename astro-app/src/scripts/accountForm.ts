// Shared by the account forms: show the API's field errors under the fields.
import { ApiError } from "./account";
import { showToast } from "./toast";

export function clearErrors(form: HTMLFormElement) {
	form.querySelectorAll<HTMLElement>("[data-field]").forEach((field) => {
		field.classList.remove("is-invalid");
		field.querySelector<HTMLElement>(".form-field__error")!.hidden = true;
	});
}

export function showError(form: HTMLFormElement, error: unknown) {
	const fields = error instanceof ApiError ? Object.entries(error.errors) : [];
	let shown = false;
	for (const [name, messages] of fields) {
		const field = form.querySelector<HTMLElement>(`[data-field="${name}"]`);
		if (!field) continue;
		const text = field.querySelector<HTMLElement>(".form-field__error")!;
		text.textContent = messages[0];
		text.hidden = false;
		field.classList.add("is-invalid");
		shown = true;
	}
	if (!shown) showToast(error instanceof ApiError ? error.message : "Нет связи с сервером, проверьте интернет");
}

/** The form's fields as {name: trimmed value}. */
export const formValues = (form: HTMLFormElement) =>
	Object.fromEntries([...new FormData(form)].map(([name, value]) => [name, String(value).trim()]));
