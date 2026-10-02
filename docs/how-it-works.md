# How it works

Helpdesk Hero connects many customer sites to one support hub. This page explains what travels between them and why it's safe.

## Two plugins, one connection

The **hub** runs on your team's site. The **customer plugin** runs on each customer's site. They pair with a one-time connection code:

1. You create a code in the hub. The code holds your hub's address, a random one-time token and your team name.
2. The customer pastes it. Their site introduces itself to your hub, and the hub answers with a shared secret only those two sites know.
3. From then on, every request between the two sites is signed with that secret (HMAC-SHA256), carries a timestamp and a one-time number, and is refused if anything was changed, replayed or is more than five minutes old.

Codes expire after 7 days and can be used once. You can disconnect a site at any time; the customer can disconnect too.

## What the customer sends

When a customer opens a ticket, their site collects what support usually has to ask for:

- WordPress, PHP, database and server versions, memory limits, HTTPS and debug settings
- Active and inactive plugins and the theme, with versions and available updates
- PHP fatal errors and JavaScript errors from the last few days, with the plugin or theme that caused them
- Recent changes: plugin and theme updates, activations, core updates, important settings
- A health check that flags likely causes: known conflicts, two plugins doing the same job, an error that started right after an update, out-of-date PHP, stuck scheduled tasks

Your [policy](policies.md) decides which of these are required, optional or never sent. The customer sees everything before it goes. Passwords, API keys, tokens and long card-like numbers are removed before anything leaves the site, and email addresses are masked (`n***@example.com`).

## What the hub sends back

The hub delivers replies, status changes, tags, dashboard messages, requests for more access time, and your policy and white label settings. The customer site fetches these every few minutes, and the hub also pushes them right away when it can.

Nothing is ever sent to us, the makers of Helpdesk Hero. There's no account, no tracking and no outside server in the middle: your hub and your customers' sites talk directly.

## Site access without passwords

If the customer allows it, their site creates a temporary account for your team:

- It has a role your policy allows (by default an administrator who can't manage users or edit code).
- It ends on its own at the time the customer chose, or when the ticket is closed.
- Support logs in with a **one-time link**. The hub asks the customer's site for a fresh link each time someone clicks **Log in**; each link works once, and only while access lasts. No password ever exists.
- Everything support does is logged on the customer's site: pages viewed, settings changed, plugins switched on or off, content edited.

See [Site access](site-access.md).

## Where tickets live

Free hub: tickets live in the hub's inbox, and new tickets and replies are emailed to your team.
Connections to **Help Scout** and **Zendesk** are coming soon with [Pro](pro.md). The hub will keep doing what a help desk can't: diagnostics, one-click login, access control and the activity log.
