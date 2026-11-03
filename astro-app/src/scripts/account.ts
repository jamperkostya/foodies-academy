// The visitor's account in the browser: the log-in token (localStorage), calls to the
// Laravel account API with it, and the header/menu links that depend on being logged in.

export type User = {
	id: number;
	login: string;
	email: string;
	name: string;
	firstName: string;
	lastName: string;
	description: string;
	registered: string;
};
export type Me = { user: User; favorites: number[]; follows: number[] };

const API = import.meta.env.PUBLIC_API_URL ?? "http://localhost:8000/api";
const TOKEN_KEY = "foodies:token";

export class ApiError extends Error {
	constructor(public status: number, message: string, public errors: Record<string, string[]> = {}) {
		super(message);
	}
}

const readToken = () => {
	try {
		return localStorage.getItem(TOKEN_KEY);
	} catch {
		return null;
	}
};

export const isLoggedIn = () => Boolean(readToken());

export async function api<T>(path: string, options: { method?: string; body?: unknown } = {}): Promise<T> {
	const token = readToken();
	const response = await fetch(`${API}${path}`, {
		method: options.method ?? "GET",
		headers: {
			Accept: "application/json",
			...(options.body !== undefined && { "Content-Type": "application/json" }),
			...(token && { Authorization: `Bearer ${token}` }),
		},
		body: options.body === undefined ? undefined : JSON.stringify(options.body),
	});
	if (response.status === 204) return undefined as T;
	const json = await response.json().catch(() => ({}));
	if (!response.ok) {
		// The token expired or was revoked: forget it.
		if (response.status === 401 && token) forgetToken();
		const message = response.status === 429 ? "Слишком много попыток, попробуйте через минуту" : json.message ?? "Что-то пошло не так, попробуйте ещё раз";
		throw new ApiError(response.status, message, json.errors);
	}
	return json.data as T;
}

function forgetToken() {
	try {
		localStorage.removeItem(TOKEN_KEY);
	} catch {
		// Nothing stored.
	}
	me = undefined;
}

/** Saves the token of a fresh log-in or sign-up. */
export function startSession(token: string) {
	localStorage.setItem(TOKEN_KEY, token);
	me = undefined;
}

export async function logout() {
	await api("/auth/logout", { method: "POST" }).catch(() => {});
	forgetToken();
}

// One /me request per page, shared by every caller.
let me: Promise<Me | null> | undefined;
export const loadMe = (): Promise<Me | null> =>
	(me ??= isLoggedIn() ? api<Me>("/me").catch(() => null) : Promise.resolve(null));

/** Updates the cached /me after a change (favorites, follows, profile). */
export async function patchMe(change: Partial<Me>) {
	const current = await loadMe();
	if (current) me = Promise.resolve({ ...current, ...change });
	renderAccountLinks();
}

/** Sends a logged-out visitor to log in and come back here. */
export function goToLogin() {
	location.href = `/login?next=${encodeURIComponent(location.pathname + location.hash)}`;
}

/** ?next= of a query string if it's a path on this site, so it can't send a visitor elsewhere. */
export function nextPath(search: string, fallback = "/profile") {
	const next = new URLSearchParams(search).get("next") ?? "";
	return next.startsWith("/") && !next.startsWith("//") && !next.startsWith("/\\") ? next : fallback;
}

export const safeNext = (fallback = "/profile") => nextPath(location.search, fallback);

// Header and mobile menu: [data-account-link] (name → /profile, or "Регистрация"),
// [data-account-profile] (/profile or /login), [data-account-favorites] (/profile#favorites)
// with [data-account-count].
export async function renderAccountLinks() {
	const current = await loadMe();
	document.querySelectorAll<HTMLAnchorElement>("[data-account-link]").forEach((link) => {
		link.textContent = current ? current.user.name : "Регистрация";
		link.href = current ? "/profile" : "/register";
	});
	document.querySelectorAll<HTMLAnchorElement>("[data-account-profile]").forEach((link) => {
		link.href = current ? "/profile" : "/login";
		link.setAttribute("aria-label", current ? "Профиль" : "Войти");
	});
	document.querySelectorAll<HTMLAnchorElement>("[data-account-favorites]").forEach((link) => {
		link.href = current ? "/profile#favorites" : "/login?next=%2Fprofile%23favorites";
	});
	document.querySelectorAll<HTMLElement>("[data-account-count]").forEach((count) => {
		const n = current?.favorites.length ?? 0;
		count.textContent = String(n);
		count.hidden = n === 0;
	});
}
