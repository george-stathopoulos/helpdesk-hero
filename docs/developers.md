# For developers

Helpdesk Hero is built to be extended. This page covers the WP-CLI commands, the main hooks, and how the two plugins talk.

## WP-CLI

On a customer site:

```bash
wp helpdesk-hero status                 # connection, open tickets, active access
wp helpdesk-hero diagnostics            # the diagnostics a ticket would include
wp helpdesk-hero connect <code>         # connect with a code from the hub
wp helpdesk-hero grant --hours=24       # give support access without a ticket
wp helpdesk-hero revoke --all           # end all support access
wp helpdesk-hero sync                   # fetch updates from the hub now
```

On the hub:

```bash
wp helpdesk-hero-hub status
wp helpdesk-hero-hub invite "Northwind Coffee" --email=nora@example.com
wp helpdesk-hero-hub sync               # push pending updates, sync help desks
```

## Hooks on the customer site

**Add your own plugin's details to tickets.** Values are redacted after this filter runs.

```php
add_filter( 'helpdesk_hero_diagnostics', function ( $data, $sections ) {
	$data['my_plugin'] = array( 'license' => my_plugin_license_status(), 'mode' => get_option( 'my_plugin_mode' ) );
	return $data;
}, 10, 2 );
```

**Teach the health check about a conflict you know.** Each rule lists plugin directory slugs that must all be active.

```php
add_filter( 'helpdesk_hero_known_conflicts', function ( $rules ) {
	$rules[] = array(
		'plugins' => array( 'my-plugin', 'some-cache-plugin' ),
		'level'   => 'warning',
		'title'   => 'My Plugin and Some Cache conflict',
		'detail'  => 'Exclude /my-endpoint/ from caching.',
	);
	return $rules;
} );
```

| Hook | Type | Use |
|---|---|---|
| `helpdesk_hero_diagnostics` | filter | Add or remove diagnostics |
| `helpdesk_hero_health_flags` | filter | Change the health check findings |
| `helpdesk_hero_known_conflicts` | filter | Add known conflicts |
| `helpdesk_hero_overlap_groups` | filter | Groups of plugins that do the same job |
| `helpdesk_hero_redact` | filter | Extra redaction on text leaving the site |
| `helpdesk_hero_access_roles` | filter | The roles that can be given to support |
| `helpdesk_hero_ticket_sent` | action | After a ticket is opened |
| `helpdesk_hero_access_granted` | action | After support access is granted |
| `helpdesk_hero_hub_update` | action | Handle your own update types from the hub |
| `helpdesk_hero_access_changed` | action | Support access was granted, extended, used or ended |

## Hooks on the hub

| Hook | Type | Use |
|---|---|---|
| `helpdesk_hero_hub_capability` | filter | Capability needed to use the hub (default `manage_options`) |
| `helpdesk_hero_hub_policy` | filter | Add your own keys to the support policy |
| `helpdesk_hero_hub_helpdesks` | filter | Register a help desk connector (a class extending `Helpdesk_Hero_Hub_Helpdesk`) |
| `helpdesk_hero_hub_branding` | filter | Branding sent to customer sites |
| `helpdesk_hero_hub_redact` | filter | Extra redaction on the hub |
| `helpdesk_hero_hub_ticket_received` | action | A ticket arrived (before it goes to a help desk) |
| `helpdesk_hero_hub_ticket_created` | action | A ticket was created |
| `helpdesk_hero_hub_ticket_rated` | action | A customer rated a ticket |
| `helpdesk_hero_hub_site_connected` | action | A site connected |
| `helpdesk_hero_hub_can_support` | filter | Whether a hub user may reply and log in to sites (Pro limits this to Supporters) |
| `helpdesk_hero_hub_supporter` | filter | A hub user's supporter identity (`id`, `name`) sent to customer sites |
| `helpdesk_hero_hub_login_same_tab` | filter | Open "Log in" in the same tab instead of a new one |
| `helpdesk_hero_hub_backup` | filter | Add your data to a hub backup (under `addons`) |
| `helpdesk_hero_hub_restore` | action | Restore your data from a hub backup |

Pro adds `helpdesk_hero_pro_test_site` (where the TEST license key works) and `helpdesk_hero_pro_helpdesks_ready` (switches the Help Scout and Zendesk connections on; off until they're released).

The hub's React app also has JavaScript filters (via `@wordpress/hooks`) for adding screens and panels: `helpdeskHeroHub.routes`, `helpdeskHeroHub.ticketPanels`, `helpdeskHeroHub.replyTools`, `helpdeskHeroHub.sitesPanels` and `helpdeskHeroHub.overviewPanels`. Helpdesk Hero Pro is built entirely on these.

## The protocol

Each site has its own shared secret. Requests carry four headers:

| Header | Value |
|---|---|
| `X-HDH-Id` | The site's ID in the hub |
| `X-HDH-Time` | Unix time |
| `X-HDH-Nonce` | A random single-use value |
| `X-HDH-Signature` | HMAC-SHA256 of `v1`, time, nonce, method, route and the SHA-256 of the body, joined with newlines |

Requests older than five minutes and repeated nonces are refused. Diagnostics travel gzipped and base64-encoded in a `bundle` field, so web application firewalls don't mistake them for an attack.

Routes:

- Hub: `/wp-json/helpdesk-hero-hub/v1/` (`pair`, `tickets`, `updates`, `ping`, `unpair`, and ticket actions)
- Customer site: `/wp-json/helpdesk-hero/v1/client/` (`push`, `login-link`, `activity`, `ping`)

## Source and contributions

The free plugins are on GitHub: [Helpdesk Hero](https://github.com/george-stathopoulos/helpdesk-hero) (the customer plugin) and [Helpdesk Hero Hub](https://github.com/george-stathopoulos/helpdesk-hero-hub). Issues, ideas and pull requests are welcome, preferably on the hub's repository.
