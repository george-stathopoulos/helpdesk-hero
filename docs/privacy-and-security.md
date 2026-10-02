# Privacy and security

Helpdesk Hero moves support data between your customers' sites and your hub. This page explains exactly what is stored, where, and how it's protected.

## No middleman

There's no Helpdesk Hero server, account or tracking. Your hub and your customers' sites talk to each other directly. The only other services involved are the ones you choose: Help Scout or Zendesk (Pro), the AI provider connected in WordPress (optional), and the license server for Pro.

## What is stored where

| On the customer's site | On your hub |
|---|---|
| Their tickets, messages and ratings | Tickets from every site, with diagnostics and health checks |
| Support access grants and the temporary account | Site records, the shared secret per site, your policies and templates |
| The activity log (changes, errors, support sessions) | A history of replies, status changes and messages sent |
| The connection: hub address and shared secret | Team settings and tags |

Uninstalling either plugin removes its tables, settings and scheduled tasks. Uninstalling the customer plugin also deletes any support accounts.

## What leaves the customer's site

Only what the customer sends with a ticket, after seeing it. Before anything leaves the site:

- passwords, API keys, tokens, authorization headers and long hashes are replaced with `[redacted]`;
- long card-like numbers are removed;
- email addresses are masked (`n***@example.com`), keeping the domain so support can still see "which mail provider".

The customer's wp-config secrets, database password and user list are never collected.

## How connections are protected

- Sites pair with a one-time code that expires after 7 days.
- Each site gets its own random secret. Every request is signed (HMAC-SHA256) with a timestamp and a single-use number, and is refused if it's changed, replayed or more than five minutes old.
- The hub rejects requests from unknown or disconnected sites, and each site rejects requests that aren't signed by its hub.
- Your policy is enforced on both sides: a customer site refuses access settings your policy doesn't allow, even if asked.

## How site access is protected

- No passwords. Support logs in with one-time links; the site stores only a hash of each link.
- Opening a link shows a confirmation page first, so link scanners in email can't use it up.
- The default access level can't manage users, change roles or edit code.
- Access ends on its own and the account is deleted.
- Every session is logged and visible to the customer.

## Personal data and GDPR

Both plugins work with WordPress's **Export Personal Data** and **Erase Personal Data** tools and add suggested text to **Settings → Privacy → Policy Guide**. Your team is the data processor for your customers' support data; describe Helpdesk Hero in your agreement with them like any other support tool.

## Reporting a security issue

Please report security issues privately through [GitHub's private vulnerability reporting](https://github.com/george-stathopoulos/helpdesk-hero-hub/security/advisories/new), not in public.
