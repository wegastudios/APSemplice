=== APSemplice ===
Contributors: wegastudios
Tags: nonprofit, members, membership, association, accounting
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.12
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Members, events and courses, and a cash book for small Italian non-profit associations (APS and other third-sector entities).

== Description ==

APSemplice helps a small association keep members, activities and accounts in order directly from its own WordPress site. Optional features are off at first: a guided setup asks a few questions and turns on only what is needed.

The plugin is built around Italian third-sector rules (APS, ETS, registers, tax rules), so its interface and texts are in Italian. An English text pack is included and can be selected in the settings, and further language packs can be uploaded as CSV, Excel or JSON files; the plugin does not use the WordPress translation files.

* **Member register**: member records, one membership fee (with an option for founding members), QR membership cards, guests, import from CSV or Excel files, export of the register.
* **Courses and events**: registrations, limited seats with a waiting list, a payment grace period for bank transfer and cash, participant lists, a calendar.
* **Cash book**: income and expenses in a single cash account, bank transfer details for members, export of the cash book.
* **Registers and privacy**: minutes book, privacy notice, consents, anonymisation, personal data download.
* **Members' area** on the site, with notices, the rules to accept, the card and the events and courses, through shortcodes, blocks and Elementor widgets.
* **Roles**: president, vice president, treasurer, councillors and secretary, with separate permissions.
* **Donations with PayPal**: a form that takes the donor to PayPal's own donation page.
* **Backup and reset**: download a full copy of the data; reset the data to start over.
* Works with WP All Import and Elementor if you use them.

Advanced features (online payments, several accounts and funds, accounting and reports, mass emails, app and wallet cards, receipts, VAT) are in the separate APSemplice Pro add-on.

== External services ==

The plugin does not contact any external service on its own.

= PayPal donations (optional) =
If you turn on donations, your website shows a form whose button opens PayPal's donation page (www.paypal.com/donate) in the visitor's browser, with the PayPal account, the purpose and the chosen amount you set. Nothing is sent from your server to PayPal. Terms: https://www.paypal.com/legalhub - Privacy: https://www.paypal.com/privacy

== Installation ==

1. Upload the `apsemplice` folder to `/wp-content/plugins/`, or install the plugin from the Plugins screen.
2. Activate it from the Plugins screen.
3. Follow the guided setup that opens on first activation (it can be started again from the Tools menu).

== Frequently Asked Questions ==

= What happens to the data if I delete the plugin? =
By default it stays. In the settings you can choose to delete it permanently when the plugin is deleted.

= Can I import my existing member list? =
Yes, from a CSV or Excel file, with a preview before confirming.

= Where do the donations go? =
Straight to the PayPal account you enter in the settings. They are recorded in the cash book by hand.

== Changelog ==

= 1.1.12 =
* The membership card is white and printable, with the logo and text in the primary colour.

= 1.1.11 =
* Dashboard notices (backup, insurance, 5 per mille, setup) can be closed and come back after a few days if still relevant.

= 1.1.10 =
* APSemplice and APSemplice Pro can be installed and updated in any order: the Pro explains what to do instead of failing.

= 1.1.9 =
* APSemplice Pro of a different version is no longer loaded: a notice asks to update it instead of an error.

= 1.1.8 =
* New Appearance tab: logo, primary and secondary colour taken from the site (Elementor or theme) by default, customisable.

= 1.1.7 =
* PDF receipts belong to the accounting level of Pro (they double as event tickets); the fiscal level keeps VAT, 5 per mille and calendar years.

= 1.1.6 =
* Two Pro license levels (accounting and fiscal) and an informational "Discover Pro" page.
* Card and buttons take their colour from Elementor or the theme when no colour is chosen.

= 1.1.5 =
* Bank transfer details can be set in the free edition (Settings → Money → Bank transfer); lists no longer offer views that need Pro features.

= 1.1.4 =
* The free edition no longer shows links to features it does not include.

= 1.1.3 =
* With an expired Pro license the plugin goes back to the base features and explains what is suspended.

= 1.1.2 =
* Free and Pro editions: online payments, several accounts and funds, accounting and reports, mass emails, app, wallet cards, receipts, VAT and member levels now live in APSemplice Pro.
* Donations with PayPal.
* Resetting the data no longer keeps a copy on the site: you download one first.

= 1.1.1 =
* Guided setup, member register with QR cards and guests, courses and events with waiting list and payment grace period, cash book, privacy tools, backup and reset, donations with PayPal, and a members' area.
* Uninstall keeps your data unless you choose to delete it.
