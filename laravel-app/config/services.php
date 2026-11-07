<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'academy' => [
        'uploads_url' => env('ACADEMY_UPLOADS_URL', 'https://foodies.academy/wp-content/uploads'),
    ],

    // Video recipes: the channel youtube.com/foodies-academy. Without a Data API key only
    // the latest 15 videos (RSS feed) are imported; with it, the whole channel.
    'youtube' => [
        'channel_id' => env('YOUTUBE_CHANNEL_ID', 'UCR7s0mz-4hdJi_Y4BfiiogQ'),
        'key' => env('YOUTUBE_API_KEY', ''),
        // WordPress user the imported videos are published by.
        'author_id' => (int) env('YOUTUBE_AUTHOR_ID', 1),
    ],

    // WordPress as Laravel reaches it (in Docker: the "wordpress" service).
    // Static site builds: GitHub Actions workflow started with a repository_dispatch event.
    // The token needs the repository's "Contents: write" permission (fine-grained token).
    'github' => [
        'repo' => env('GITHUB_DEPLOY_REPO'),
        'token' => env('GITHUB_DEPLOY_TOKEN'),
        // Which site gets rebuilt: .github/workflows/deploy.yml builds production for its own type.
        'event' => env('GITHUB_DEPLOY_EVENT', 'content-changed'),
    ],

    'wordpress' => [
        'url' => env('WP_INTERNAL_URL', 'http://wordpress'),
        // Contact Form 7 forms the sign-ups go to, so CFDB7 keeps each list apart: the newsletter
        // and the free recipe book. Post IDs (not the hash of the shortcode); both must be set.
        'subscribe_forms' => [
            'newsletter' => (int) env('CF7_SUBSCRIBE_FORM_ID'),
            'book' => (int) env('CF7_BOOK_FORM_ID'),
        ],
        // Key of the user API (wp-content/mu-plugins/foodies-users.php), from the root .env.
        'api_secret' => env('FOODIES_API_SECRET', ''),
    ],

];
