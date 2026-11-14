<?php

/**
 * Turns site inventories (from tests/Walk/enumerate.php) into the requests the
 * walk makes: each admin menu slug on its own site, then each script entry of
 * the hidden-screen list that the menu did not already give, and each distinct
 * admin-ajax action once, on the first site that registers it.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole\Walk;

final class Targets
{
    /**
     * @param  list<array<mixed>>  $inventories  Each with `admin_url`, `menu`, `hidden` and `ajax`; anything else is ignored.
     * @return list<array{kind: string, label: string, url: string, method: string, body: array<string, string>}>
     */
    public static function from_inventories(array $inventories): array
    {
        $targets = [];
        $seen = [];
        $ajax_seen = [];

        foreach ($inventories as $inventory) {
            $admin = is_string($inventory['admin_url'] ?? null) ? rtrim($inventory['admin_url'], '/').'/' : '';

            if ($admin === '') {
                continue;
            }

            foreach (is_array($inventory['menu'] ?? null) ? $inventory['menu'] : [] as $slug) {
                $url = is_string($slug) ? self::screen_url($admin, $slug) : null;

                if ($url !== null && ! in_array($url, $seen, true)) {
                    $seen[] = $url;
                    $targets[] = ['kind' => 'screen', 'label' => $url, 'url' => $url, 'method' => 'GET', 'body' => []];
                }
            }

            // The hidden list's script entries, so a hidden screen with no menu
            // item on this site is requested too. A `page:` entry is left out:
            // a page no plugin registered gets core's refusal, which says
            // nothing about the guard.
            foreach (is_array($inventory['hidden'] ?? null) ? $inventory['hidden'] : [] as $entry) {
                $url = is_string($entry) ? self::hidden_url($admin, $entry) : null;

                if ($url !== null && ! in_array($url, $seen, true)) {
                    $seen[] = $url;
                    $targets[] = ['kind' => 'screen', 'label' => $url, 'url' => $url, 'method' => 'GET', 'body' => []];
                }
            }

            foreach (is_array($inventory['ajax'] ?? null) ? $inventory['ajax'] : [] as $action) {
                if (is_string($action) && $action !== '' && ! in_array($action, $ajax_seen, true)) {
                    $ajax_seen[] = $action;
                    $targets[] = ['kind' => 'ajax', 'label' => 'ajax:'.$action.' @ '.$admin, 'url' => $admin.'admin-ajax.php', 'method' => 'POST', 'body' => ['action' => $action]];
                }
            }
        }

        return $targets;
    }

    /**
     * The admin URL a hidden-list entry opens, or null for one that is not a
     * script entry (`<script>.php`, with an optional query).
     */
    public static function hidden_url(string $admin, string $entry): ?string
    {
        return preg_match('#^[\w\-]+\.php(\?.*)?$#', $entry) === 1 ? $admin.$entry : null;
    }

    /**
     * The admin URL a menu slug opens, or null for one the walk does not
     * request: an external link, or an empty slug.
     *
     * A slug that names a script opens that script; any other slug is a page
     * WordPress serves as `admin.php?page=<slug>`.
     */
    public static function screen_url(string $admin, string $slug): ?string
    {
        $slug = trim(html_entity_decode($slug, ENT_QUOTES));

        if ($slug === '' || preg_match('#^[a-z][a-z0-9+.\-]*:#i', $slug) === 1) {
            return null;
        }

        if (preg_match('#^[\w\-]+\.php(\?.*)?$#', $slug) === 1) {
            return $admin.$slug;
        }

        return $admin.'admin.php?page='.rawurlencode($slug);
    }
}
