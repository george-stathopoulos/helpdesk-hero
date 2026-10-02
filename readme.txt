=== Helpdesk Hero ===
Contributors: mindanticipation
Tags: support, helpdesk, temporary login, diagnostics, troubleshooting
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Get help from your WordPress support team: tickets with diagnostics attached, one-time expiring logins for support, and a log of what they changed.

== Description ==

Helpdesk Hero connects your site to the team that supports it: your developer, agency, host or plugin vendor. They use the free **Helpdesk Hero Hub** plugin, and give you a connection code. Paste it in **Get Help** and you're set.

Your support team decides how support works (how long access lasts, what access level they use, which site details they need). You see exactly what that means before anything is sent, and you can end access at any time.

= Open a ticket in one step =

* **Get help** in the admin bar opens a ticket about the page you're on.
* Your site's details are attached for you: WordPress, PHP and server versions, plugins and themes with pending updates, recent PHP and JavaScript errors, and what changed in the last 30 days. Preview everything before it's sent.
* Emails, passwords and API keys are removed from logs automatically.
* Read replies and answer them in wp-admin. There's no limit on tickets.

= A health check before support even looks =

The health check lists what support should look at first:

* errors that started right after a plugin or theme was updated;
* two plugins doing the same job (caching, SEO, firewall, email…);
* old PHP, low memory, stuck scheduled tasks, errors shown to visitors;
* known conflicts that plugin and theme vendors can add with a filter.

= Safe, temporary access for support =

* A temporary account with a **login link that works once**. Links open a confirmation page first, so email security scanners can't use them up.
* Access ends on its own, and the account is deleted. End it early with one click.
* If your support team names its supporters, each person gets their own account (for example "Sam (Acme Support)"), so you always know who did what. Every account is deleted when access ends.
* Support accounts can't log in with a password, manage users, edit code or switch Helpdesk Hero off, and their login never outlives the access you gave.
* You get an email when support logs in. A badge in the admin bar shows while access is active.
* While logged in, support sees a notice on every screen saying the session is remote and recorded, and can't open tickets or reply in your name.
* Support can ask for more time; depending on their policy you approve it, or it's added within their limit and you're told.

= See what support did =

Every page support visited (if their policy logs it), every setting they changed with old and new values, content edited, plugins and themes switched or updated. On WordPress 7.0 with an AI provider connected, **Explain in plain English** summarises a support session.

= Troubleshooting mode =

Support can switch plugins off, or use a default theme, for their own browser session only, while visitors keep seeing your site as it is. It's the safest way to find a plugin conflict on a live site.

= AI help (optional) =

With WordPress 7.0's AI Client and a provider connected under Settings › Connectors, **Help me describe this** turns rough notes into a clear report. Only your text and the health check titles are sent, and only when you press the button.

== External services ==

Helpdesk Hero talks to one outside service: **your support team's hub**, the WordPress site at the address in the connection code you paste. It never contacts anything until you connect.

* When you open a ticket or reply, the ticket text, your name and email address, the site details you chose (and that your support team's policy asks for), and whether support access is active (never a password) are sent to the hub.
* Every 10 minutes, and when you open Tickets, the site asks the hub for replies, messages and policy changes.
* While you allow access, the hub can ask the site for a new one-time login link for a support agent, and for the support activity log of a ticket.
* Every request is signed with a key unique to your site. Disconnecting stops all of it.

The hub is run by your support team, under their terms and privacy policy; they may pass tickets on to their help desk (such as Help Scout or Zendesk).

If an AI provider is connected to WordPress (7.0+), **Help me describe this** sends your ticket text and health check titles, and **Explain in plain English** sends the support activity log, to that provider through WordPress, only when you press the button.

== Installation ==

1. Install and activate Helpdesk Hero.
2. Open **Get Help** and paste the connection code from your support team.
3. Open a ticket from Get Help › New ticket, or from **Get help** in the admin bar.

Support teams: install the free **Helpdesk Hero Hub** on your own site to create connection codes and set your support policy.

== Frequently Asked Questions ==

= Where do I get a connection code? =

From the company that supports your site, if they use Helpdesk Hero Hub. Without a code, the plugin has nothing to connect to.

= Is it safe to give support a login link? =

Safer than sharing a password. The link works once, ends on its own, and the account behind it is deleted when access ends. It can't create or edit users, edit code, or switch Helpdesk Hero off, and everything it does is logged for you.

= Why can't I choose the access level or length? =

Your support team's policy decides what they can work with. If something doesn't suit you, tell them; they can change the policy for your site. You can always end access early.

= What happens if I deactivate the plugin or disconnect? =

All support access ends immediately and the support accounts are deleted. Your tickets stay until you delete the plugin.

= Does it slow down my site? =

No. It records a few events (updates, settings changes, errors) as they happen, and logs support activity only while a support account is logged in.

== Screenshots ==

1. Your tickets, with replies, tags and support access at a glance.
2. New ticket, shaped by your support team's policy, with the health check and the site details that will be sent.
3. A ticket with replies, support access and a rating.
4. Support access: what support can do, until when, and one click to end it.
5. Activity: everything support did on your site.

== Changelog ==

= 2.0.0 =
* Connects to your support team's Helpdesk Hero Hub, which now sets the support policy.
* New dashboard design.
* Email a ticket yourself, then confirm, and it still reaches your support team.
* Ticket tags and ratings; your support team's branding.
* Personal accounts for named supporters; every support account is deleted when access ends.
* Support sessions: support sees a read-only Support session page instead of your help center (no tickets or replies on your behalf), and a notice on every screen about what is recorded.
* White label from your support team: help center name, logo, colour, links and text, including the admin bar and footer.
* Confirmations open in the dashboard instead of browser pop-ups.
* The writing assistant works with the local AI Provider for WebLLM, says what to set up when AI isn't ready, and explains when the local model is still loading.

= 1.0.0 =
* First release.
