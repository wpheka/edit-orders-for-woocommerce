=== WPHEKA Edit Orders for WooCommerce ===
Contributors: wpheka, akshayaswaroop
Tags: edit order, change order, cancel order, order editing, woocommerce
Requires at least: 6.6
Tested up to: 7.1.3
Requires PHP: 7.4
Stable tag: 1.2.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Edit WooCommerce orders after payment and settle the difference correctly. Customers can change or cancel their own orders within a time you choose.

== Description ==

WooCommerce locks an order once it is paid. Changing a size, adding an item or fixing an address then means cancelling, refunding by hand, working out tax and shipping, then sending a new invoice.

**WPHEKA Edit Orders for WooCommerce** lets you edit a paid order in a few clicks and handles the money for you:

* **Order costs less?** It refunds the difference through your payment gateway, with the items put back in stock.
* **Order costs more?** It creates a small linked order for the difference and emails your customer a link to pay it. The change is made when it is paid; until then the order ships as it was.
* **Nothing changes in price?** The change is made straight away.

You always see a preview first: what changes, what is refunded, what the customer pays and what happens to stock.

= Store owners can =

* Change quantities, remove items and add products to Processing and On hold orders, with an optional price for added products
* Swap a variation, such as a size or colour, with the price difference worked out from what the customer actually paid (coupons included)
* Change the shipping or billing address, with shipping re-rated from your shipping zones and tax worked out again for the new address
* See linked balance orders on the order screen, copy the pay link, send it again or cancel it
* Approve or decline cancellation requests in **WPHEKA > Edit Orders**, next to a log of every change

= Customers can =

Within the time you allow (one hour by default), from the thank-you page, My Account or the link in their order email:

* Cancel the order, with a reason. Cancel straight away with a refund or send you a request to approve.
* Change the delivery address, with any difference in shipping or tax shown before they confirm
* Change a size or colour to another option that is in stock
* Edit their order note
* Say "All good, no changes needed" to close the window early

Changes stop as soon as the order ships: when it is completed, a tracking number is added (WooCommerce Shipment Tracking, Advanced Shipment Tracking, AfterShip) or WooCommerce marks it fulfilled. Customers with an account make changes while logged in. Guests use the link in their order email. As on WooCommerce's own order pages, a guest who comes back later from another browser first confirms the email address on the order, so a forwarded link alone can't redirect a parcel.

= The money is always handled correctly =

* A paid order's totals are never changed directly. Decreases are real WooCommerce refunds, with tax split per tax rate, so your reports stay right.
* If your payment method can't refund automatically, the refund is still recorded with stock restored, the order is flagged "Manual refund needed" and you get an email. It never claims a refund happened when it didn't.
* Cash on delivery: changes adjust the amount to collect instead of moving money.
* Orders paid by authorization only are left alone until they are captured.

= Emails =

Seven WooCommerce emails, which you can turn on or off in WPHEKA > Edit Orders > Settings and reword in WooCommerce > Settings > Emails: order updated, balance due (with the pay link), manual refund needed, cancellation requested, order cancelled, cancellation declined and customer changed an order. The order confirmation email gets a "change or cancel" link while changes are allowed.

= Built for WooCommerce today =

* Works with High-Performance Order Storage (HPOS) on or off and with the Cart and Checkout blocks
* Works with classic and block themes
* Templates and emails can be overridden in your theme; developer hooks are documented
* A debug log you can share with support, with no names, emails or addresses in it
* No tracking and no notices outside its own screens

== Installation ==

1. Install and activate WooCommerce.
2. Install "WPHEKA Edit Orders for WooCommerce" from Plugins > Add New (or upload the zip), then activate it.
3. Go to WPHEKA > Edit Orders > Settings to choose what customers may change and for how long.
4. Open any paid Processing or On hold order and use **Edit items or address** in the "Edit order" box.

== Frequently Asked Questions ==

= Which payment methods can refund automatically? =

Any WooCommerce payment method that supports refunds, such as Stripe, WooPayments and PayPal Payments. For methods that can't (for example bank transfer), the refund is recorded and you are reminded to send the money yourself.

= How does the customer pay when an order costs more? =

They get an email with a link to pay the difference, using any payment method your store offers. When the customer makes the change themselves, they are taken straight to that page.

= Can customers cancel without my approval? =

Only if you choose "Cancel and refund straight away" in the settings. By default each request comes to you to approve or decline.

= Does it change my tax reports? =

Refunds and balance orders are ordinary WooCommerce records with tax split per rate, so WooCommerce Analytics reports them correctly. When an address change moves an order to a different tax rate, one refund can hold both the tax given back and the tax charged. A few third-party invoice or tax-export plugins read WooCommerce's per-rate refund figures without their sign, so they may show the new rate's tax as refunded. Totals and Analytics are not affected.

= What happens if I uninstall the plugin? =

Your settings and activity log are kept, unless you tick "Delete data on uninstall" in the settings. Balance orders are ordinary orders and are always kept.

= How do I send a log to support? =

Go to WPHEKA > Edit Orders > Settings, turn on **Debug log** and save. Repeat what went wrong. Then open WooCommerce > Status > Logs, choose the source `wpheka-edit-orders-for-woocommerce`, download the log and attach it to your support request.

The plugin does not write names, emails, phone numbers or addresses to the log. It records order numbers, amounts, payment method IDs and country and state codes. Errors and warnings are recorded even with Debug log off. The plugin sends nothing anywhere: you decide what to share. Turn Debug log off again when you are done.

== Screenshots ==

1. Preview before you apply a change: what changes, the refund and the stock moves.
2. The "Edit order" box on the order screen, with a balance order and its pay link.
3. The customer's panel on the thank-you page, with the time left to make changes.
4. A customer confirming an address change that costs more.
5. Cancellation requests and the activity log in WPHEKA > Edit Orders.
6. Settings in WPHEKA > Edit Orders.
7. Turn each of the seven Edit Orders emails on or off, with a link to change its wording.

== Changelog ==

= 1.2.1 =
* Fix: on sites in a right-to-left language (such as Arabic or Hebrew) the Edit Orders page sat 20px off the edge of the window and scrolled sideways. The page now mirrors fully: switches, arrows, the save bar and the plugin row.
* Fix: the "Customers can" option cards now show a focus ring when you reach them with the keyboard.
* Fix: closing "Getting started" is remembered even if you leave the page straight away.
* The old WooCommerce > Settings > Edit Orders tab no longer shows an unused Save button.

= 1.2.0 =
* New: an Edit Orders page under the WPHEKA menu, with Settings, Activity and Help tabs. It replaces WooCommerce > Edit Orders. The settings moved here from WooCommerce > Settings > Edit Orders, which now links to the new page. Your settings are kept as they are.
* New: the last 30 days at a glance: orders edited, amount refunded, pay links sent and paid. It also counts the changes customers made themselves.
* New: turn each Edit Orders email on or off from the Settings tab.
* New: a getting-started checklist, a search box for settings and a warning before you leave with unsaved changes.
* The Settings tab needs the same permission as WooCommerce's own settings (manage_woocommerce). Staff who can edit orders without it see Activity and Help. They can still answer cancellation requests.
* Tested with WooCommerce 11.2.1.

= 1.1.0 =
* New: a Debug log setting in WooCommerce > Settings > Edit Orders. With it on, every step of each change is recorded in WooCommerce > Status > Logs, to share with support. Errors and warnings are always recorded. The log has no names, emails or addresses. Nothing is sent anywhere.
* New: one ID per change in the log, from the preview to the payment of a balance order and its refunds.
* New: refunds in the log name the payment method and, when the payment gateway keeps one, its refund ID, to find them in the gateway's dashboard.
* New: an Edit Orders section in WooCommerce > Status > System Status with the plugin's settings, tax and payment set-up and counts of orders that need attention.
* Developers: the class Edit_Orders_For_WooCommerce_Log and the filters edit_orders_for_woocommerce_debug_log and edit_orders_for_woocommerce_refund_transaction_meta_keys.

= 1.0.1 =
* New: a line with extra units or a price difference paid on a balance order can now be decreased, removed or swapped. The refund includes what was paid on the balance order.
* Fix: after a swap to a dearer option, extra units now pay the price difference too.
* Fix: a swap to a cheaper option after a dearer one now refunds the price difference that was paid.
* Fix: units on an unpaid cash on delivery balance order now count when the line is changed.
* Fix: with tax rounded at subtotal, refunded tax now matches the tax charged and the last line can always be removed.
* Fix: if applying a paid change stops part way, it is never applied twice and the order notes say what to check. A slow payment gateway is no longer mistaken for a stop.
* Fix: wrong order keys are now limited per order as well as per visitor. They never lock out the order's own customer.
* Tested with WooCommerce 11.2.0.

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.2.1 =
Fixes the Edit Orders page on right-to-left sites and adds a keyboard focus ring to the option cards.

= 1.2.0 =
A redesigned Edit Orders page under the WPHEKA menu. Settings moved there and are kept as they are.

= 1.1.0 =
Adds a Debug log to share with support and an Edit Orders section in System Status.

= 1.0.1 =
Edit lines partly paid on a balance order, plus fixes to refund and tax amounts. Recommended for all stores.

= 1.0.0 =
First release.
