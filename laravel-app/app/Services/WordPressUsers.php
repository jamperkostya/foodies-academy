<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Site visitors as WordPress users, through the user API of wp-content/mu-plugins/foodies-users.php. */
class WordPressUsers
{
    public function register(string $name, string $email, string $password): array
    {
        return $this->call('register', compact('name', 'email', 'password'));
    }

    /** The user, or null for a wrong e-mail/password. */
    public function login(string $login, string $password): ?array
    {
        $response = $this->send('login', compact('login', 'password'));

        // 401 is also WordPress refusing the API key: only a wrong password means null.
        return $response->json('code') === 'invalid_credentials' ? null : $this->result($response);
    }

    public function get(int $id): ?array
    {
        $response = $this->request()->get($this->url("users/$id"));

        return $response->status() === 404 ? null : $this->result($response);
    }

    public function update(int $id, array $data): array
    {
        return $this->call("users/$id", $data);
    }

    private function call(string $path, array $data): array
    {
        return $this->result($this->send($path, $data));
    }

    private function send(string $path, array $data): Response
    {
        return $this->request()->post($this->url($path), $data);
    }

    // WordPress's 422 {message, data.field} → a Laravel validation error on that field.
    private function result(Response $response): array
    {
        if ($response->status() === 422) {
            throw ValidationException::withMessages([$response->json('data.field') ?: 'form' => $response->json('message')]);
        }
        if (! $response->successful()) {
            throw new RuntimeException("WordPress user API: HTTP {$response->status()} ".$response->json('message'));
        }

        return $response->json();
    }

    private function request()
    {
        return Http::timeout(15)->acceptJson()->withHeaders(['X-Foodies-Secret' => config('services.wordpress.api_secret')]);
    }

    private function url(string $path): string
    {
        return rtrim(config('services.wordpress.url'), '/')."/wp-json/foodies/v1/$path";
    }
}
