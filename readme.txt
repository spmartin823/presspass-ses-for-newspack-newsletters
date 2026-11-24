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

PressPass SES for Newspack Newsletters adds Amazon SES as a service provider for the Newspack Newsletters plugin. Newsletters are sent through your own SES account, and subscribers are managed from a MailPoet-style screen inside WordPress.

= Sending =

Newsletters are sent in the background by WP-Cron, one recipient at a time, at about 200 emails per minute (each run sends for up to 20 seconds at 10 emails per second, and runs repeat every minute). An interrupted send resumes by itself within a minute. Each recipient is marked sent just before their email goes out, so a crash can drop at most the one email in flight; nobody ever receives the same newsletter twice. Progress is shown under Newsletters > SES Sends.

== Changelog ==

= 0.1.0 =
* Initial scaffold.
