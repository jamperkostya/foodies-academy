<?php

namespace App\Http\Controllers\Api;

use App\Services\Blog;

class BlogController extends ArticlesController
{
    protected const NAME = 'blog';

    public function __construct(Blog $blog)
    {
        parent::__construct($blog);
    }
}
