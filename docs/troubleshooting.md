# Troubleshooting

## The customer can't connect

**"This connection code is not valid or has expired."** Codes work once and for 7 days. Create a new one under **Sites → Connect a site**.

**"Could not reach the support hub."** The customer's site must reach your hub's REST API over HTTPS. Check that:

- your hub site is online and its address in the code is right (if your hub moved, create a new code);
- a security plugin or firewall on your hub isn't blocking `/wp-json/helpdesk-hero-hub/` requests;
- your hub isn't behind a password or maintenance mode.

## Sending a ticket fails

Open the customer's **Settings → Test connection**. It sends a signed request to the hub and explains what went wrong:

- **"The other site answered with HTTP 403 / 406 / 503"** and a mention of a firewall: a security plugin, Cloudflare or the host's firewall blocked the request. Allow requests to `/wp-json/helpdesk-hero-hub/` (on the hub) and `/wp-json/helpdesk-hero/` (on the customer site).
- **"Request is too old. Check that both servers have the correct time."**: the two servers' clocks differ by more than five minutes. Ask the host to fix the time.
- **"Request signature is not valid."**: the site was disconnected in the hub, or its connection was replaced. Connect again with a new code.

Failed requests are also listed in the customer's **Activity** as "Could not reach your support team", with the reason.

While the hub can't be reached, customers can still use **Email it myself instead** if your policy allows it.

## Replies don't show up on the customer's site

The customer site fetches updates every 10 minutes and whenever someone opens the help center; the hub also pushes them at once. If replies are stuck:

- open the site's panel under **Sites** and use **Check connection**;
- make sure WordPress's scheduled tasks run on the customer's site (a site with very little traffic may need a real cron job).

## "Log in" doesn't work

- **Access has ended:** the ticket shows no active access. Ask the customer to give access again, or use **Ask for more time** before it ends.
- **The link was already used:** each link works once. Click **Log in** again for a new one.
- **You land on the login page:** a security plugin may block logins from new links. Login links look like `wp-login.php?action=helpdesk_hero&token=…`; ask the customer to allow them in their security plugin.

## Still stuck?

Ask on the [support forum](https://wordpress.org/support/plugin/helpdesk-hero-hub/) or [open an issue on GitHub](https://github.com/george-stathopoulos/helpdesk-hero-hub/issues).
