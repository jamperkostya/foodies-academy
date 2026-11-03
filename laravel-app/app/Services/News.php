<?php

namespace App\Services;

/** News: WordPress post type "news" (wp-content/mu-plugins/foodies-news.php). */
class News extends Articles
{
    protected const POST_TYPE = 'news';

    protected const CATEGORY = 'news_category';

    protected const REST_BASE = 'news';
}
