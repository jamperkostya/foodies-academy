<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Sign-ups (the newsletter, the free recipe book), each sent to its own Contact Form 7 form through
 * its REST API: CF7 validates them and sends its mail, and Contact Form CFDB7 saves the entry
 * under that form (WordPress admin → Contact Forms → CFDB7).
 */
class Subscriptions
{
    /**
     * Returns null on success, or [field => message] when CF7 rejects the data.
     *
     * @throws RuntimeException when the list has no form, or WordPress can't be reached or refuses the submission
     */
    public const LISTS = ['newsletter', 'book'];

    public function subscribe(string $list, string $name, string $email): ?array
    {
        $formId = (int) config("services.wordpress.subscribe_forms.$list");
        if ($formId <= 0) {
            throw new RuntimeException("No Contact Form 7 form for the \"$list\" sign-ups: set CF7_SUBSCRIBE_FORM_ID / CF7_BOOK_FORM_ID in .env");
        }
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
