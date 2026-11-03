<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;

/** Feature tests of the API: Laravel's own tables in SQLite memory, WordPress's in foodies_site_test. */
abstract class SiteTestCase extends TestCase
{
    use RefreshDatabase;
    use SiteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSiteDatabase();
    }
}
