Written against v0.3.0.

A deployment changes the role through these filters, from a small must-use file of the install's own rather than by editing the plugin. Each guard layer stays default-deny, so a filter widens only what it names.

| Filter | Changes |
| --- | --- |
| `uamswp_auditor_role_capabilities` | The capabilities the role holds. A never-granted one is dropped. |
| `uamswp_auditor_role_denied_caps` | Adds capabilities denied to the role. |
| `uamswp_auditor_role_hidden_screens` | The hidden screens: `page:<slug>`, `page:<prefix>*` (every page slug that starts with the prefix) or `<script>.php[?param=value]`. |
| `uamswp_auditor_role_hidden_dashboard_widgets` | Dashboard widgets removed for the role. |
| `uamswp_auditor_role_activation_flags` | Adds plugin activation-redirect flags that read as unset for the role: `option:<name>` or `transient:<name>`. |
| `uamswp_auditor_role_ajax_actions` | The allowlisted AJAX actions. Add one only after reading what it does. |
| `uamswp_auditor_role_get_actions` | The `action` values an admin GET may carry. |
| `uamswp_auditor_role_allow_query` | Lets one refused statement through; see [Allowing a refused write](#allowing-a-refused-write). |
| `uamswp_auditor_role_exempt_login_actions` | The `wp-login.php` actions that are not an Auditor session. |
| `uamswp_auditor_role_logins` | The multisite logins, after `UAMSWP_AUDITOR_ROLE_LOGINS`. |

A changed capability list rewrites the stored role on each site at that site's next request. `wp auditor refresh-role` (`--network` on a multisite network) rewrites it at once.

## Example: let the role see a plugin's post type

A plugin's custom post types stay hidden from the role until their `edit_*` capability is added:

```php
add_filter('uamswp_auditor_role_capabilities', static function (array $caps): array {
    $caps[] = 'edit_<post type>s';

    return $caps;
});
```

The capability opens the list screen; the guard still refuses every save.

## Allowing a refused write

A deployment that decides a refused write is safe allows it with `uamswp_auditor_role_allow_query`, which receives `false` and the statement. Allow by the narrowest match that works, an option name for example, and record the decision in [`docs/WRITES-ON-LOAD.md`](https://github.com/UAMS-Web/uamswp-auditor-role/blob/main/docs/WRITES-ON-LOAD.md):

```php
add_filter('uamswp_auditor_role_allow_query', static function (bool $allow, string $query): bool {
    return $allow || str_contains($query, "'_transient_timeout_example'");
}, 10, 2);
```
