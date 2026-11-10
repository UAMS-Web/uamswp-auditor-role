# Pages that write on load

The database guard refuses every statement that is not a read during an Auditor session (#9). Some screens write on an ordinary page load, so for an auditor those writes fail. Each one found is listed here with the decision for it. The `db_refused` rows of the activity log name the table and, for options, the option, so a new case can be read from the log.

Found on the local multisite (WordPress 6.8.8, the uamshealth.com plugin set) by signing in as an auditor and opening the dashboard, the post and page lists, Settings, the profile, and a subsite dashboard.

| Write | Where | Decision |
| --- | --- | --- |
| Quick Draft auto-draft (`INSERT posts`) | Dashboard | **Widget removed for the role.** The refused insert made the widget fail with a critical error. |
| WP Mail SMTP report widget | Dashboard | **Widget removed for the role.** It summarizes the hidden email log. |
| Transients (`_transient_*`, `_site_transient_*`), such as `_site_transient_update_core` and `_transient_wp_mail_smtp_wp_mail_logging_importer_count` | Most admin screens | **Refused.** Caches only; the page renders and the value is computed again on the next load. |
| `_asp_media_service_data` (`UPDATE options`) | Every request, Ajax Search Pro, while the plugin file loads | **Refused** since v0.2.2. Found by the screen walk ([`WALK.md`](WALK.md)): it ran before the guard could read the login cookie, so it passed, and every Auditor request rewrote the option's license-check time. The guard now refuses writes while plugins load when a login cookie names an Auditor. |
| `acf_pro_license`, `acf_pro_license_status` (`UPDATE options`) | Any admin screen, ACF Pro | **Refused.** A license revalidation that changes a live setting. An administrator's request is unaffected. |
| `genesis_expiring_setting_update*` | Admin screens, Genesis theme | **Refused.** An update-check cache. |
| User settings (`INSERT usermeta`, such as `wp_user-settings`) | Admin screens | **Refused.** Screen preferences (column and panel state) are not kept for the role. |
| Post lock (`_edit_lock`), autosave | Post editor (read from core's code; the role cannot open another user's post) | **Refused, and taken out of Heartbeat** for the role, so the allowlisted action stays a read. |
| ShortPixel processor (`admin-ajax.php?action=shortpixel_image_processing`) | Media and editor screens, ShortPixel Image Optimizer, every few seconds | **Processor turned off for the role** through `shortpixel/processorjs/disable`, and the settings and Bulk screens hidden. The refused request showed as a ShortPixel fatal error. |
| Session token (`session_tokens` usermeta) | Sign-out, session end | **Allowed.** The plugin's session manager writes it past the guard. |
| Activity log rows | Every Auditor request | **Allowed.** The plugin's own write. |
| Adding a configured login to a site | Multisite, on visit or creation | **Allowed.** The plugin's own write. |

## Allowing a case

A deployment that decides a refused write is safe can allow it with the `uamswp_auditor_role_allow_query` filter, which receives `false` and the statement. Allow by the narrowest match that works, an option name for example, and record the decision here:

```php
add_filter('uamswp_auditor_role_allow_query', static function (bool $allow, string $query): bool {
    return $allow || str_contains($query, "'_transient_timeout_example'");
}, 10, 2);
```

## Not covered

- A plugin that writes through its own database connection instead of `$wpdb` passes the `query` filter untouched. This plugin set has not been checked for one.
- Side effects outside the database, such as an outbound request on load (core's update checks, license checks), are out of scope for the guard.
- With a persistent object cache, a refused option write never reaches the cache either, because core updates the cache only after the database write succeeds. A transient stored only in the object cache is not a database write and is not refused.
