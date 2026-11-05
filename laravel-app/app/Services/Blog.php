<?php

namespace App\Services;

/** The blog: ordinary WordPress posts. */
class Blog extends Articles
{
    protected const POST_TYPE = 'post';

    protected const CATEGORY = 'category';

    protected const REST_BASE = 'posts';
}
