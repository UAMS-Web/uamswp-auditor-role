<?php

/**
 * The WordPress database object, typed.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole;

use wpdb;

defined('ABSPATH') || exit;

final class Database
{
    /**
     * The `$wpdb` global. WordPress creates it before any plugin loads, and
     * static analysis types every global as `mixed`, so the one annotation that
     * says what it is lives here rather than at each use. The native return type
     * stays `object`: the Unit suite installs a stand-in that is not a `wpdb`.
     *
     * @return wpdb
     */
    public static function wpdb(): object
    {
        global $wpdb;

        /** @var wpdb $wpdb */
        return $wpdb;
    }
}
