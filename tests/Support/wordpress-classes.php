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

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_data(): mixed
        {
            return $this->data;
        }
    }
}

if (! class_exists('WP_Post')) {
    class WP_Post
    {
        public int $ID = 0;

        public function __construct(object $post)
        {
            $this->ID = isset($post->ID) && is_int($post->ID) ? $post->ID : 0;
        }
    }
}
