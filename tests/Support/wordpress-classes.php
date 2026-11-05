<?php

/**
 * The few WordPress classes the Unit suite needs by name, for instanceof checks
 * and type declarations. Only what the plugin touches; nothing here behaves
 * like WordPress.
 */

declare(strict_types=1);

if (! class_exists('WP_User')) {
    class WP_User
    {
        /** @var list<string> */
        public array $roles = [];

        public int $ID = 0;

        public string $user_login = '';
    }
}

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(public string $code = '', public string $message = '', public mixed $data = '') {}
    }
}
