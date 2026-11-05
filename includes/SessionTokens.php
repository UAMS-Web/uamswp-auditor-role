<?php

/**
 * Core's user-meta session tokens, with the token write allowed past the
 * database guard. Signing out and revoking a session are the only writes an
 * Auditor session makes on its own behalf.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole;

use WP_User_Meta_Session_Tokens;

defined('ABSPATH') || exit;

final class SessionTokens extends WP_User_Meta_Session_Tokens
{
    /**
     * Every token write in the parent class goes through here.
     *
     * @param  array<string, mixed>  $sessions
     */
    protected function update_sessions($sessions): void
    {
        DatabaseGuard::bypass(fn () => parent::update_sessions($sessions));
    }
}
