=== Cart Bridge JP – Migrate for WooCommerce ===
Contributors: TODO-set-wporg-username-before-submission
Tags: migration, import, export, woocommerce, japan
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Migrate between Color Me Shop and WooCommerce: import products, customers, and orders, or export products, customers, and stock.

== Description ==

Cart Bridge JP moves store data between Color Me Shop (カラーミーショップ), a Japanese e-commerce platform, and WooCommerce. Preview the whole migration first with a dry run that writes nothing, read why each item would be skipped or changed, then run it.

It is built for a one-way migration: from Color Me Shop to WooCommerce, or from WooCommerce to Color Me Shop. It is not a sync tool, and moving the same items back and forth is not supported. If that happens by mistake, items that were imported from Color Me Shop are not exported to Color Me Shop, and items that were created in Color Me Shop by an export are not overwritten by a later import, which keeps values from being changed in the usual cases.

= Import from Color Me Shop =

* Categories and groups (as product categories and product tags)
* Products, including options (as variations), prices including tax, the standard and reduced tax rates, and images
* Customers (members), including addresses
* Orders, with the shop's payment methods, shipping methods, and order statuses mapped to WooCommerce ones
* Stock
* Coupons (coupons whose restrictions WooCommerce cannot represent are skipped)

= Export to Color Me Shop =

* Products (including options), customers, and stock
* Orders – **Beta**: needs Color Me Shop's premium plan, has not been tested on a real premium-plan shop yet, and is off by default
* Product image upload – **Beta**: needs the premium plan, has not been tested on a real premium-plan shop yet, and is off by default

Color Me Shop cannot create categories through its API, so you map WooCommerce categories to existing Color Me Shop categories instead.

= Before and after the migration =

* **Dry run**: preview every item, in both directions, without writing anything. The dry run has no limit.
* **CSV report**: download the dry run's results, with a plain-language cause for each warning and, where possible, a fix.
* **Mappings**: map payment methods, shipping methods, order statuses, and (for export) categories.
* **Verification report**: after an import, compare item counts and order totals between the two stores.
* **Tools**: clean up the imported sample data, rebuild the links to the data that this plugin imported (for example after moving the database), and repair prefecture data saved by earlier versions.
* Works with WooCommerce's High-Performance Order Storage (HPOS). Orders are written only through WooCommerce's own APIs.
* Long migrations run in the background with Action Scheduler, wait automatically when the API's rate limit is reached, and can be retried or cancelled.

= Free version limits =

The dry run covers all of your data. A real import or export moves a sample, so you can check the result in your own store before the full migration:

* The sample starts from the latest 10 orders: those orders, their products (up to 50), and their customers (up to 10). Coupons are limited to 10. If there are fewer than 10 orders, other products and customers are added until the sample has 10 of each (the products from the orders are kept, up to 50). An export also adds them when the orders bring no products or no customers (for example, orders placed by guests).
* Categories and tags are not limited. Stock is limited to the sample products.
* The limits count every item linked to Color Me Shop, whether it was imported or exported, and every export whose result is still unconfirmed.
* Tools > Sample data cleanup removes the imported sample from WooCommerce. It deletes nothing in Color Me Shop: exported items stay there, and running the cleanup after an export removes the links to them, so exporting again creates them in Color Me Shop a second time.

= Requirements =

* WooCommerce 10.0 or later
* PHP's sodium extension, which encrypts the saved credentials (most PHP builds include it)
* A Color Me Shop store, and a developer app that you register yourself in Color Me Shop's developer center (the plugin shows the redirect URI to register)
* The shop owner's Color Me Shop account to authorize the app (Color Me Shop does not let sub-administrator accounts authorize apps)

= External services =

This plugin connects to the Color Me Shop API (`https://api.shop-pro.jp`), operated by GMO Pepabo, Inc., only while you use it: when you connect your shop, test the connection, open the Mappings or Import tab (to list the shop's categories, payment methods, and shipping methods), or run a dry run, an import, an export, or a tool that checks data against the shop.

* **What is sent**: your developer app's client ID and client secret, the authorization code, and your site's callback URL (to `https://api.shop-pro.jp/oauth/token`, to connect); the access token with every request; and, when you export, the data you choose to export: products (such as names, prices, descriptions, options, and stock), customers (such as names and their phonetic readings, company names and departments, email addresses, phone numbers, postal addresses, birthdays, notes, and newsletter consent), stock levels, and – if you turn on the Beta features – orders and product images.
* **What is read**: your shop's settings (plan, tax settings), products, categories, groups, customers, orders (sales), stock, coupons, payment methods, and shipping methods.
* **Images**: when you import products, categories, and groups, the plugin downloads their images from the image URLs that the Color Me Shop API returns.
* The plugin sends no data to the plugin's author or to any other service, and has no tracking.

Color Me Shop API terms of use: https://api.shop-pro.jp/developers/tos
Color Me Shop terms of service: https://shop-pro.jp/terms/colorme-terms/
GMO Pepabo privacy policy: https://pepabo.com/company/privacy/

= Trademarks =

WooCommerce and its associated designs are trademarks of Automattic Inc. Color Me Shop (カラーミーショップ) is a trademark of GMO Pepabo, Inc. This plugin is not affiliated with or endorsed by Automattic Inc. or GMO Pepabo, Inc.

== Installation ==

1. Install and activate WooCommerce, then install and activate this plugin.
2. Go to WooCommerce > Cart Bridge JP > Connections. Copy the callback URL shown there.
3. In Color Me Shop's developer center, register a developer app and set its redirect URI to that callback URL.
4. Enter the app's client ID and client secret, save the settings, and click "Connect to Color Me Shop".
5. In the Mappings tab, map payment methods, shipping methods, and order statuses (and categories, if you export).
6. In the Import or Export tab, choose what to migrate and run a dry run first. Read the warnings in the CSV report, then run the real migration.

== Frequently Asked Questions ==

= Can I keep the two stores in sync, or import and export the same items back and forth? =

No. The plugin is built for a one-way migration. Items imported from Color Me Shop are skipped when you export to Color Me Shop (warning `linked_by_import_not_exported`), and items exported to Color Me Shop are not overwritten when you import from it (warning `linked_by_export_not_imported`). This is decided per platform, not per shop. It keeps values from being changed by a round trip in the usual cases, but a round trip is not supported otherwise: for example, if the link to an exported item is removed, importing creates the product or order again in WooCommerce, and a customer is matched by email address and updated.

= What does the free version migrate? =

The dry run covers everything. A real import or export moves a sample that starts from the latest 10 orders: those orders, their products (up to 50), their customers (up to 10), and up to 10 coupons. Categories and tags are not limited. Use the sample to check the result in your store before the full migration. Tools > Sample data cleanup removes an imported sample from WooCommerce; it does not delete anything in Color Me Shop, so delete exported sample items in Color Me Shop's admin if you do not need them. Do not run the cleanup between exports: it removes the links to the exported items, and the next export creates them in Color Me Shop again.

= What are the Beta features? =

Exporting orders and uploading product images use parts of the Color Me Shop API that are available only on the premium plan. They have been tested with mock data but not yet on a real premium-plan shop, so they are marked Beta and are off by default: the "Orders (Beta)" checkbox is unchecked, and "Upload product images (Beta)" is off. They appear only when the connected shop is on the premium plan. Turning on image upload replaces the shop's existing images at the same positions.

= The export skipped a product, a stock row, or an order with a warning. How do I fix it? =

The dry run's CSV report lists the warnings it finds for each item, with the cause and, where possible, a fix. These are the most common warnings that stop an item from being exported:

* `variation_stock_management_mixed` – The variations’ stock settings are mixed: some manage stock and others do not, or variations that do not manage stock differ in stock status (in stock and out of stock). A platform that manages stock per product cannot represent this, so the product and its stock are not exported. **Fix**: Turn on stock management for all variations, or turn it off for all variations and give them all the same stock status (all in stock or all out of stock).
* `variation_any_attribute_unsupported` – A variation uses “Any” for an attribute, which the platform cannot represent, so the product is not exported. **Fix**: Replace the “Any” variation with one variation for each value of the attribute.
* `tax_class_unsupported` – The product’s tax class has no Japanese tax rate of 10% (standard) or 8% (reduced), so the product is not exported (it would be sold with the wrong tax). **Fix**: Change the product’s tax class to the standard or reduced rate, or give the tax class a Japanese tax rate: 10% (standard) or 8% (reduced). (The tax settings and the product’s tax fields are shown only when tax calculation is turned on in WooCommerce > Settings > General.)
* `variation_tax_class_unsupported` – The tax class of a published variation has no Japanese tax rate of 10% (standard) or 8% (reduced), so the product is not exported. **Fix**: Change the variation’s tax class (or the product’s, if the variation uses the same as its parent) to the standard or reduced rate, or give the tax class a Japanese tax rate: 10% (standard) or 8% (reduced). (The tax settings and the product’s tax fields are shown only when tax calculation is turned on in WooCommerce > Settings > General.)
* `tax_status_not_taxable` – The product’s tax status is “Shipping only” or “None”, which the platform cannot represent, so the product is not exported. **Fix**: If the product is taxed, set its tax status to “Taxable”. Otherwise, add the product on the platform by hand. (The tax settings and the product’s tax fields are shown only when tax calculation is turned on in WooCommerce > Settings > General.)
* `price_tax_basis_unresolved` – The price cannot be converted to a price including tax: the tax class has tax rates, but none of them applies to the store’s address. The product is not exported. **Fix**: Add a tax rate that applies to the store’s address (or to all locations) to the tax class in WooCommerce > Settings > Tax, or correct the store address.
* `product_price_invalid` – The product has no valid price, or its price cannot be converted to a price including tax, so it is not exported. A simple product needs a regular price; a variable product needs at least one enabled variation with a price that is shown in the store. **Fix**: Set a regular price. For a variable product, enable a variation with a price; if “Hide out of stock items” is on, at least one variation must be in stock. If the product also has a warning that its price cannot be converted to a price including tax, fix the tax rate as that warning describes.
* `all_variations_excluded` – None of the product’s variations can be exported (they are not enabled or have no valid price, or the product has no variations), so the product is not exported. **Fix**: Enable at least one variation with a valid price. The other warnings for this product show why each variation was left out.
* `variation_axis_limit_exceeded` – The product uses three or more attributes for variations, but the platform supports at most two, so the product is not exported. **Fix**: Use at most two attributes for variations (combine attributes, or turn off “Used for variations” on the others).
* `stock_product_not_exported` – The product or variation for this stock has not been exported to the platform yet, so the stock is not exported. A full export sends products before stock. **Fix**: Include products in the export. If the product or this variation is not exported because of other warnings, fix those first.
* `push_outcome_unconfirmed` – An earlier export of this item ended without confirming whether it was created on the platform, so it is not sent again until you check. **Fix**: In the Export tab, check whether the item exists on the platform, then use “Link and resolve” or “Mark as not created”.
* `currency_mismatch` – The currency is not Japanese yen, so the item is not exported (the platform would treat the amounts as yen).
* `order_refunded` – The order has been refunded (fully or partly), and refunds cannot be sent to the platform, so the order is not exported. **Fix**: Create the order on the platform by hand if you need it.
* `order_line_variation_unresolved` – The variation of an order line cannot be identified (it was deleted, it uses “Any” for an attribute, or its product uses three or more attributes for variations), so the order is not exported. **Fix**: If the product uses three or more attributes for variations, reduce them to two. Otherwise, create the order on the platform by hand.

= In the CSV report, one warning says an item is exported and another says it is not. Which is right? =

The report has one row for each warning, so an item with several warnings has several rows, and each warning describes only its own check. The item's result is in the `operation` column (`created`, `updated`, or `skipped`), which is the same on all of the item's rows. Warnings whose `severity` is `blocking` are the ones that stop an item; fix those first.

= How are product names with HTML handled? =

Color Me Shop shows product names as HTML on the storefront. When you import, the plugin saves the name as the storefront shows it: tags are removed and character references (such as `&amp;`) are turned back into characters. When you export, the WooCommerce name is sent as it is, so a name that contains `<…>` is shown as HTML by Color Me Shop.

= Does it work with High-Performance Order Storage (HPOS)? =

Yes. The plugin declares HPOS compatibility and reads and writes orders only through WooCommerce's APIs.

= What happens to my data when I uninstall the plugin? =

The products, customers, and orders that were migrated stay in WooCommerce. By default, the plugin's own data (the links between the stores, the job history, the logs, and the saved connection) is also kept, so you can reinstall the plugin and continue.

== Screenshots ==

1. Connections: connect your Color Me Shop with your own developer app.
2. Import: the dry run's results, with counts and a CSV report for each kind of data.
3. Mappings: map payment methods, shipping methods, order statuses, and categories.
4. Export: choose what to export. Order export and image upload are Beta and off by default.
5. Tools: clean up the sample data, rebuild links, and repair prefecture data.

== Changelog ==

= 1.0.0 =
* First release on WordPress.org: import from Color Me Shop, and export products, customers, and stock to Color Me Shop. Order export and product image upload are Beta.
* If you used a 0.x version from GitHub: imported product names are now saved as Color Me Shop's storefront shows them. A previously imported product whose name contains tags or character references (such as `<br>` or `&amp;`) may be updated once by the next import, which also replaces changes you made to that product in WooCommerce with the values from Color Me Shop.
