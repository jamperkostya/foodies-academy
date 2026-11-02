<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Image URLs of WordPress attachments (media library) on foodies.academy. */
class Attachments
{
    /** attachment_id => URL of the 601px "medium" size (or the original) */
    public function urls(array $attachmentIds): array
    {
        if (! $attachmentIds) {
            return [];
        }
        $base = rtrim(config('services.academy.uploads_url'), '/');

        return DB::connection('site')->table('postmeta')
            ->whereIn('post_id', $attachmentIds)
            ->where('meta_key', '_wp_attachment_metadata')
            ->pluck('meta_value', 'post_id')
            ->map(function ($value) use ($base) {
                $data = @unserialize($value, ['allowed_classes' => false]);
                if (! is_array($data) || empty($data['file'])) {
                    return '';
                }
                $dir = dirname($data['file']);
                $file = $data['sizes']['medium']['file'] ?? basename($data['file']);

                return $base.'/'.($dir === '.' ? '' : $dir.'/').rawurlencode($file);
            })
            ->all();
    }
}
