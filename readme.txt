=== PressPass SES for Newspack Newsletters ===
Contributors: spmartin823
Tags: newsletter, amazon ses, newspack, email
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Requires Plugins: newspack-newsletters

Send Newspack Newsletters through your own Amazon SES account, with a MailPoet-style subscriber screen.

== Description ==

PressPass SES for Newspack Newsletters adds Amazon SES as a service provider for the Newspack Newsletters plugin. Newsletters are sent through your own SES account, and subscribers are managed from a MailPoet-style screen inside WordPress, so a newsroom does not need a paid email service provider.

Admin screens live under the Newsletters menu: Subscribers, Lists, SES Sends and SES Settings.

= Subscribers and lists =

* Subscribers are stored in your WordPress database, with the statuses subscribed, unconfirmed, unsubscribed and bounced.
* Add, edit, search, filter by list and status, and use bulk actions (add to list, remove from list, unsubscribe, delete).
* Export the subscribers shown on the screen, with the current filters and search, as a CSV file.
* Visitors sign up with Newspack's Subscribe block. New people receive a confirmation email and stay unconfirmed until they open its link and click the Confirm subscription button on the page it opens. At most one confirmation email is sent to an address every 10 minutes.
* Every email has its own unsubscribe link. It opens a page with a button, so mail scanners that follow links cannot unsubscribe people. One-click unsubscribe from mail clients (the List-Unsubscribe header) works directly. Unsubscribing applies to all lists.

= Import from MailPoet =

If MailPoet is installed, the Subscribers screen has an "Import from MailPoet" button. It copies MailPoet's lists and subscribers with their statuses, and can be run again at any time. The import mirrors MailPoet: people trashed there are deleted here, MailPoet's status wins when the two differ, and an address changed in MailPoet replaces the old one. List membership is mirrored both ways: Import makes each person's lists here match their MailPoet lists, and while MailPoet's tables exist, status changes, list additions and removals, and deletes made here are written back to MailPoet, so a MailPoet send cannot reach someone you removed here.

= Sending =

Newsletters are sent in the background by WP-Cron, at 10 emails per second. A worker runs once a minute for up to 20 seconds, which is about 200 emails a minute.

* One email per recipient, each with its own unsubscribe link, the List-Unsubscribe and one-click headers, and a footer with your postal address.
* A person is marked sent just before their email goes out, so the queue is designed so that an interrupted run resumes without sending anyone the same newsletter twice.
* If SES cannot be reached, rejects the login, throttles, or refuses the message for an account or sender reason, sending pauses, the error is shown on every admin screen, and the worker retries every minute. The SES Sends page's "Failed or skipped" count covers recipients the server refused and people who were no longer subscribed when their turn came.
* To stop a send, move the newsletter to Trash. Unsent recipients are dropped, and restoring the newsletter does not resume the send.
* Send and test-send refuse a From address that is not on the same domain as the sender address in SES Settings.
* Sending only works on the site address the SES settings were saved on, including any port or path. A staging or local copy of the site is paused until the settings are saved there, which also discards that copy's unsent queue.
* Newspack's open-tracking pixel works. Ad-click tracking is not supported for this provider.

= Requirements =

Newspack Newsletters must be installed and active. You need an Amazon SES account with a verified sending domain.

== Installation ==

1. Install and activate Newspack Newsletters, then install and activate this plugin.
2. Go to Newsletters > SES Settings. Enter your SES region, SMTP username and SMTP password, the sender name and address, an optional reply-to address, and your postal address (shown in every email footer). Save.
3. Click "Send Newspack Newsletters with Amazon SES" on the same screen. Newspack's own Settings screen cannot select third-party providers, so this button turns the provider on.
4. Use the test email form on SES Settings to confirm that SES accepts your credentials.
5. Optional: click "Import from MailPoet" on Newsletters > Subscribers.

== Frequently Asked Questions ==

= How do I set up Amazon SES? =

1. In the SES console, verify your sending domain with Easy DKIM and add the DNS records SES gives you.
2. Request production access, so you can send to addresses you have not verified.
3. Create SMTP credentials for your region. Limit the IAM user to the `ses:SendRawEmail` action.
4. Publish a DMARC record for your domain.
5. Bounces and complaints: turn on the SES account-level suppression list so SES stops sending to addresses that bounced or complained. They are not shown in the Bounced tab automatically.

The sender address you enter in SES Settings must be on the verified domain.

= How fast does it send? =

About 200 emails a minute: a WP-Cron worker runs once a minute for up to 20 seconds at 10 emails a second. A list of 5,000 people takes roughly 25 minutes. WP-Cron runs when your site gets visits, so a real server cron calling wp-cron.php every minute keeps it on time.

= Why does sending pause on my staging site? =

Sending only works on the site address the SES settings were saved on, so a copy of your database on a staging or local site cannot email your subscribers by accident. To send from the copy, save the SES settings there. That also discards the copy's unsent queue.

= Are ad clicks tracked? =

No. Newspack's open-tracking pixel works, but ad-click tracking is not supported for this provider.

= Can I deactivate or delete the plugin? =

Yes, but unsubscribe links in emails you have already sent only work while the plugin is active. Keep it active for at least 30 days after your last send.

= What happens to my subscribers if I delete the plugin? =

Your subscribers are kept. Deleting the plugin removes the SES settings and the scheduled send worker, but leaves the four tables `{prefix}presspass_ses_subscribers`, `{prefix}presspass_ses_lists`, `{prefix}presspass_ses_list_subscribers` and `{prefix}presspass_ses_queue`, and the `presspass_ses_db_version` and `presspass_ses_secret` options. To remove everything, drop the four tables from your database (for example with phpMyAdmin or `wp db query "DROP TABLE ..."`) and run `wp option delete presspass_ses_db_version presspass_ses_secret`.

= Importing a very large MailPoet list =

If the request times out, run Import from MailPoet again. It can be re-run safely and continues where the data differs.

= Where is my SMTP password stored? =

In the WordPress options table, as part of the `presspass_ses_settings` option. It is removed when you delete the plugin.

= How do I stop a newsletter that is sending? =

Move the newsletter to Trash. Unsent recipients are dropped. Restoring the newsletter does not resume sending.

== External services ==

This plugin sends email through Amazon Simple Email Service (SES), a service of Amazon Web Services, using the SMTP credentials you provide.

* What is sent: the recipient email addresses, the sender and reply-to addresses, and the newsletter content (including your postal address and unsubscribe links), each time a newsletter, confirmation email or test email is sent.
* When: only when a newsletter is sent, a signup confirmation is sent, or you send a test email.
* Amazon Web Services Service Terms: https://aws.amazon.com/service-terms/
* Amazon Web Services Privacy Notice: https://aws.amazon.com/privacy/

== Screenshots ==

1. The Subscribers screen, with status counts, list filter and bulk actions.
2. The Send modal in the newsletter editor, using Amazon SES.

== Changelog ==

= 0.1.0 =
Initial release.

* Subscribers and lists screens under the Newsletters menu.
* Import from MailPoet, with write-back of removals.
* Background sending through Amazon SES with per-recipient unsubscribe links.
* Signup confirmation and unsubscribe pages.
