<?php
/**
 * Plugin Name: Foodies user API
 * Description: Registration, log-in check and profile editing for the site (Astro → Laravel → here),
 *              so visitors become ordinary WordPress users (role "subscriber") and WordPress keeps
 *              hashing passwords and running its user hooks. Only Laravel may call it: every request
 *              must carry the X-Foodies-Secret header with the FOODIES_API_SECRET of docker-compose.
 */

if (!defined('ABSPATH')) {
	exit;
}

add_action('rest_api_init', function () {
	$routes = [
		['/register', 'POST', 'foodies_users_register'],
		['/login', 'POST', 'foodies_users_login'],
		['/users/(?P<id>\d+)', 'GET', 'foodies_users_get'],
		['/users/(?P<id>\d+)', 'POST', 'foodies_users_update'],
	];
	foreach ($routes as [$route, $method, $callback]) {
		register_rest_route('foodies/v1', $route, [
			'methods' => $method,
			'callback' => $callback,
			'permission_callback' => 'foodies_users_is_laravel',
		]);
	}
});

function foodies_users_is_laravel(WP_REST_Request $request): bool
{
	$secret = (string) getenv('FOODIES_API_SECRET');

	return strlen($secret) >= 32 && hash_equals($secret, (string) $request->get_header('x-foodies-secret'));
}

/** What the site gets about a user. */
function foodies_users_payload(WP_User $user): array
{
	return [
		'id' => $user->ID,
		'login' => $user->user_login,
		'email' => $user->user_email,
		'name' => $user->display_name,
		'firstName' => (string) $user->first_name,
		'lastName' => (string) $user->last_name,
		'description' => (string) $user->description,
		'registered' => mysql2date('c', $user->user_registered, false),
	];
}

function foodies_users_error(string $code, string $message, int $status = 422, string $field = ''): WP_Error
{
	return new WP_Error($code, $message, ['status' => $status, 'field' => $field]);
}

// POST {name, email, password} → the new subscriber.
function foodies_users_register(WP_REST_Request $request)
{
	$name = sanitize_text_field((string) $request['name']);
	$email = sanitize_email((string) $request['email']);
	$password = (string) $request['password'];

	if ($name === '') {
		return foodies_users_error('name_required', 'Укажите имя', 422, 'name');
	}
	if (!is_email($email)) {
		return foodies_users_error('email_invalid', 'Некорректный e-mail', 422, 'email');
	}
	if (email_exists($email)) {
		return foodies_users_error('email_exists', 'Этот e-mail уже зарегистрирован', 422, 'email');
	}
	if (strlen($password) < 8) {
		return foodies_users_error('password_short', 'Пароль должен быть не короче 8 символов', 422, 'password');
	}

	// Login from the e-mail: "maria.k@mail.ru" → "maria.k", "maria.k2"… if taken.
	$base = sanitize_user(strstr($email, '@', true), true) ?: 'user';
	$login = $base;
	for ($i = 2; username_exists($login); $i++) {
		$login = $base . $i;
	}

	$id = wp_insert_user([
		'user_login' => $login,
		'user_email' => $email,
		'user_pass' => $password,
		'display_name' => $name,
		'first_name' => $name,
		'nickname' => $name,
		'role' => get_option('default_role', 'subscriber'),
	]);
	if (is_wp_error($id)) {
		return foodies_users_error($id->get_error_code(), $id->get_error_message());
	}

	return foodies_users_payload(get_userdata($id));
}

// POST {login, password}: login or e-mail → the user, or 401.
function foodies_users_login(WP_REST_Request $request)
{
	$user = wp_authenticate((string) $request['login'], (string) $request['password']);
	if (is_wp_error($user)) {
		return foodies_users_error('invalid_credentials', 'Неверный e-mail или пароль', 401);
	}

	return foodies_users_payload($user);
}

function foodies_users_get(WP_REST_Request $request)
{
	$user = get_userdata((int) $request['id']);

	return $user ? foodies_users_payload($user) : foodies_users_error('not_found', 'Пользователь не найден', 404);
}

// POST {name?, firstName?, lastName?, email?, description?, password?, currentPassword?}
// Changing the e-mail or the password needs the current password.
function foodies_users_update(WP_REST_Request $request)
{
	$user = get_userdata((int) $request['id']);
	if (!$user) {
		return foodies_users_error('not_found', 'Пользователь не найден', 404);
	}

	$data = ['ID' => $user->ID];
	$fields = ['name' => 'display_name', 'firstName' => 'first_name', 'lastName' => 'last_name'];
	foreach ($fields as $param => $key) {
		if ($request->has_param($param)) {
			$data[$key] = sanitize_text_field((string) $request[$param]);
		}
	}
	if (isset($data['display_name']) && $data['display_name'] === '') {
		return foodies_users_error('name_required', 'Укажите имя', 422, 'name');
	}
	if ($request->has_param('description')) {
		$data['description'] = sanitize_textarea_field((string) $request['description']);
	}

	$email = $request->has_param('email') ? sanitize_email((string) $request['email']) : $user->user_email;
	$password = (string) $request['password'];
	if ($email !== $user->user_email || $password !== '') {
		if (!wp_check_password((string) $request['currentPassword'], $user->user_pass, $user->ID)) {
			return foodies_users_error('wrong_password', 'Неверный текущий пароль', 422, 'currentPassword');
		}
	}
	if ($email !== $user->user_email) {
		if (!is_email($email)) {
			return foodies_users_error('email_invalid', 'Некорректный e-mail', 422, 'email');
		}
		$owner = email_exists($email);
		if ($owner && $owner !== $user->ID) {
			return foodies_users_error('email_exists', 'Этот e-mail уже зарегистрирован', 422, 'email');
		}
		$data['user_email'] = $email;
	}
	if ($password !== '') {
		if (strlen($password) < 8) {
			return foodies_users_error('password_short', 'Пароль должен быть не короче 8 символов', 422, 'password');
		}
		$data['user_pass'] = $password;
	}

	$result = wp_update_user($data);
	if (is_wp_error($result)) {
		return foodies_users_error($result->get_error_code(), $result->get_error_message());
	}

	return foodies_users_payload(get_userdata($user->ID));
}
