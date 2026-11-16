<?php
/**
 * Plugin Name: Foodies Revisions
 * Description: Keeps the 10 newest revisions of a post; WordPress deletes older ones on the next save.
 */

// Unlimited by default: WP Ultimate Recipe copies all recipe fields into every revision, so a
// recipe edited a few hundred times carried thousands of postmeta rows.
add_filter('wp_revisions_to_keep', fn () => 10);
