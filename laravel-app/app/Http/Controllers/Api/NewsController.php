<?php

namespace App\Http\Controllers\Api;

use App\Services\News;

class NewsController extends ArticlesController
{
    protected const NAME = 'news';

    public function __construct(News $news)
    {
        parent::__construct($news);
    }
}
