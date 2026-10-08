=== APSemplice ===
Contributors: wegastudios
Tags: nonprofit, members, membership, accounting, association
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Members, activities, cash book and financial statements for small Italian non-profit associations (APS and other third-sector entities).

== Description ==

APSemplice helps a small association keep members, activities and accounts in order directly from its own WordPress site. Optional features are off at first: a guided setup asks a few questions and turns on only what is needed.

The plugin is built around Italian third-sector rules (APS, ETS, registers, receipts, tax rules), so its interface and texts are in Italian. An English text pack is included and can be selected in the settings, and further language packs can be uploaded as CSV, Excel or JSON files; the plugin does not use the WordPress translation files.

* **Member register**: member records, fees, QR membership cards, guests, import from CSV or Excel files.
* **Courses and events**: registrations, limited seats with a waiting list, a payment grace period, participant lists.
* **Money and accounting**: income, expenses, transfers, cash book, PDF receipts, financial statement, exports.
* **Members' area** on the site, with pages and blocks (also for Elementor) to place wherever you like.
* **Roles**: president, secretary, treasurer, volunteers, each with separate permissions.
* **Privacy**: privacy notice, consents and data anonymisation.

== External services ==

The plugin does not contact any external service until the matching feature is turned on.

= Stripe (card payments) =
If you enable Stripe payments, the plugin sends the amount, the description and the payer's email address to Stripe (api.stripe.com) to create and check the payment. Terms: https://stripe.com/legal - Privacy: https://stripe.com/privacy

= PayPal =
If you enable PayPal, the plugin sends the amount and the description of the payment to PayPal (api-m.paypal.com) to create and check it. Terms: https://www.paypal.com/legalhub - Privacy: https://www.paypal.com/privacy

= Google Wallet (membership card on the phone) =
If you enable the Google Wallet card, a member who asks for it is sent to pay.google.com with a signed link containing the card number and the name on the card. Terms: https://payments.developers.google.com/terms/sellertos - Privacy: https://policies.google.com/privacy

= Push notifications (browser) =
If you enable notifications, the plugin sends the text of the notice to the address (endpoint) that the subscriber's browser provided at subscription time. The endpoint depends on the browser (for example Google, Mozilla or Apple) and the respective terms and privacy policies apply.

== Installation ==

1. Upload the `apsemplice` folder to `/wp-content/plugins/`, or install the plugin from the Plugins screen.
2. Activate it from the Plugins screen.
3. Follow the guided setup that opens on first activation (it can be started again from the Tools menu).

== Frequently Asked Questions ==

= What happens to the data if I delete the plugin? =
By default it stays. In the settings you can choose to delete it permanently when the plugin is deleted.

= Does it need WooCommerce? =
No. Online payments also work directly with Stripe and PayPal; WooCommerce is an optional alternative.

= Can I import my existing member list? =
Yes, from a CSV or Excel file, with a preview before confirming.

== Changelog ==

= 0.1.0 =
* First release.
