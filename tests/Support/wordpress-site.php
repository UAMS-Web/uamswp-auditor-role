<?php

/**
 * A stand-in for WordPress' WP_Site, for the Unit suite: only the field the
 * plugin reads. Core stores `blog_id` as a string, so the stand-in does too.
 */

declare(strict_types=1);

if (! class_exists('WP_Site')) {
    final class WP_Site
    {
        public string $blog_id = '0';
    }
}
