=== Edit Orders for WooCommerce ===
Contributors: akshayaswaroop, wpheka
Tags: edit order, change order, cancel order, order editing, woocommerce
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Edit WooCommerce orders after payment and settle the difference correctly. Customers can change or cancel their own orders within a time you choose.

== Description ==

WooCommerce locks an order once it is paid. Changing a size, adding an item or fixing an address then means cancelling, refunding by hand, working out tax and shipping, and sending a new invoice.

**Edit Orders for WooCommerce** lets you edit a paid order in a few clicks, and it handles the money for you:

* **Order costs less?** It refunds the difference through your payment gateway, with the items put back in stock.
* **Order costs more?** It creates a small linked order for the difference and emails your customer a link to pay it. The change is made when it is paid; until then the order ships as it was.
* **Nothing changes in price?** The change is made straight away.

You always see a preview first: what changes, what is refunded, what the customer pays and what happens to stock.

= Store owners can =

* Change quantities, remove items and add products to Processing and On hold orders, with an optional price for added products
* Swap a variation, such as a size or colour, with the price difference worked out from what the customer actually paid (coupons included)
* Change the shipping or billing address, with shipping re-rated from your shipping zones and tax worked out again for the new address
* See linked balance orders on the order screen, copy the pay link, send it again or cancel it
* Approve or decline cancellation requests in **WooCommerce > Edit Orders**, next to a log of every change

= Customers can =

Within the time you allow (one hour by default), from the thank-you page, My Account or the link in their order email:

* Cancel the order, with a reason. Cancel straight away with a refund, or send you a request to approve.
* Change the delivery address, with any difference in shipping or tax shown before they confirm
* Change a size or colour to another option that is in stock
* Edit their order note
* Say "All good, no changes needed" to close the window early

Changes stop as soon as the order ships: when it is completed, a tracking number is added (WooCommerce Shipment Tracking, Advanced Shipment Tracking, AfterShip) or WooCommerce marks it fulfilled. Customers with an account make changes while logged in. Guests use a link that carries their order key, the same way WooCommerce's own thank-you page works.

= The money is always handled correctly =

* A paid order's totals are never changed directly. Decreases are real WooCommerce refunds, with tax split per tax rate, so your reports stay right.
* If your payment method can't refund automatically, the refund is still recorded with stock restored, the order is flagged "Manual refund needed" and you get an email. It never claims a refund happened when it didn't.
* Cash on delivery: changes adjust the amount to collect instead of moving money.
* Orders paid by authorization only are left alone until they are captured.

= Emails =

Seven WooCommerce emails, which you can turn on or off and reword in WooCommerce > Settings > Emails: order updated, balance due (with the pay link), manual refund needed, cancellation requested, order cancelled, cancellation declined and customer changed an order. The order confirmation email gets a "change or cancel" link while changes are allowed.

= Built for WooCommerce today =

* Works with High-Performance Order Storage (HPOS) on or off, and with the Cart and Checkout blocks
* Works with classic and block themes
* Templates and emails can be overridden in your theme; developer hooks are documented
* No tracking and no notices outside its own screens

== Installation ==

1. Install and activate WooCommerce.
2. Install "Edit Orders for WooCommerce" from Plugins > Add New, or upload the zip, then activate it.
3. Go to WooCommerce > Settings > Edit Orders to choose what customers may change and for how long.
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

== Screenshots ==

1. Preview before you apply a change: what changes, the refund and the stock moves.
2. The "Edit order" box on the order screen, with a balance order and its pay link.
3. The customer's panel on the thank-you page, with the time left to make changes.
4. A customer confirming an address change that costs more.
5. Cancellation requests and the activity log in WooCommerce > Edit Orders.
6. Settings in WooCommerce > Settings > Edit Orders.

== Changelog ==

= 0.1.0 =
* First release.

== Upgrade Notice ==

= 0.1.0 =
First release.
