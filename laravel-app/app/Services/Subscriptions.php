<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Newsletter sign-ups, sent to the Contact Form 7 form "Подписка на рассылку" through its
 * REST API: CF7 validates them and sends its mail, and Contact Form CFDB7 saves the entry
 * (WordPress admin → Contact Forms → CFDB7).
 */
class Subscriptions
{
    /**
     * Returns null on success, or [field => message] when CF7 rejects the data.
     *
     * @throws RuntimeException when WordPress can't be reached or refuses the submission
     */
    public function subscribe(string $name, string $email): ?array
    {
        $formId = (int) config('services.wordpress.subscribe_form_id');
        $url = rtrim(config('services.wordpress.url'), '/')."/wp-json/contact-form-7/v1/contact-forms/$formId/feedback";

        $response = Http::asMultipart()->timeout(15)->post($url, [
            // Field names of the CF7 form.
            'username' => $name,
            'email' => $email,
            // CF7 rejects submissions without the form's unit tag.
            '_wpcf7' => $formId,
            '_wpcf7_unit_tag' => "wpcf7-f$formId-o1",
            '_wpcf7_container_post' => 0,
            '_wpcf7_locale' => 'ru_RU',
        ]);

        $status = $response->json('status');
        if ($status === 'validation_failed') {
            return collect($response->json('invalid_fields', []))
                ->mapWithKeys(fn ($field) => [$field['field'] => $field['message']])
                ->all();
        }
        // CFDB7 saves the entry before CF7 sends mail, so a failed mail still counts as saved.
        if ($status === 'mail_failed') {
            Log::warning('Contact Form 7 could not send the subscription mail', ['email' => $email]);

            return null;
        }
        if ($status !== 'mail_sent') {
            throw new RuntimeException("Contact Form 7 answered HTTP {$response->status()}, status \"$status\"");
        }

        return null;
    }
}
