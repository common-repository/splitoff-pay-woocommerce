# Official SplitOff Plugin for WooCommerce

## Pre-requisites

This plugin extends the Payment Gateway functionality provided by WooCommerce. Please confirm the following dependencies are met before commencing installation of the plugin.

* WordPress 3.5+
* WooCommerce 2.6+
* jQuery 1.7+ (Included with WordPress since WordPress 3.3)

### Theme Support

The following are not mandatory. However, if the website theme does not implement these WooCommerce hooks, some features of the plugin may not be supported.

1. In order for the SplitOff "Payment Info" to display on individual product pages, the active WordPress theme must implement the `woocommerce_single_product_summary` action hook.
    * _Note: The "Payment Info on Individual Product Pages" setting must also be enabled, and the product must be eligible._

2. In order for the SplitOff "Payment Info" to display on category pages and search results, the active WordPress theme must implement the `woocommerce_after_shop_loop_item_title` action hook.
    * _Note: The "Payment Info on Category Pages" setting must also be enabled, and the product must be eligible._

3. In order for the SplitOff "Payment Info" to display on the cart page, the active WordPress theme must implement the `woocommerce_cart_totals_after_order_total` action hook.
    * _Note: The "Payment Info on Cart Page" setting must also be enabled, and all the cart items must be eligible._

## Installation and Activation

This section outlines the steps to install the SplitOff plugin.

If you are upgrading to a new version of the SplitOff plugin, it is always a good practice to backup your existing plugin before you commence the installation steps.

1. Download a [ZIP archive](https://downloads.wordpress.org/plugin/splitoff-pay-woocommerce.1.0.0.zip) of the latest release of this plugin (v1.0.0).
2. Login to your WordPress admin. Use the left side menu panel to navigate to "Plugins > Add New", then click the "Upload Plugin" button (usually next to the "Add Plugins" heading).
3. Upload the "splitoff-woocommerce-2.0.0.zip" file that was downloaded in step 1.
4. After the upload completes and the plugin is installed, click the "Activate Plugin" button.  
   _Alternatively, navigate to "Plugins > Installed Plugins", find the "SplitOff Pay for WooCommerce" plugin in the list, then click the "Activate" link._
5. Navigate to the "WooCommerce > Settings" page; select the "Checkout" tab, and the "SplitOff" sub-tab.  
   _Alternatively, navigate to "Plugins > Installed Plugins", find the "SplitOff Pay for WooCommerce" plugin in the list, then click the "Settings" link._
6. Enter the Merchant ID and Secret Key that SplitOff has provided you for Production use.
7. Click "Save changes".

### Wordpress Installation Folder

The following structure assumes the default naming of the "wp-content" folder. If this has been changed, please use the value of the `WP_CONTENT_FOLDERNAME` constant defined in wp-settings.php.

```
├── wp-content/
│   ├── plugins/
│   │   ├── woocommerce/
│   │   ├── splitoff-gateway-for-woocommerce/
│   │   │   ├── class/
│   │   │   │   ├── Cron/
│   │   │   │   │   ├── SplitOff_Plugin_Cron.php
│   │   │   │   ├── Merchant/
│   │   │   │   │   ├── SplitOff_Plugin_Merchant.php
│   │   │   │   │   ├── endpoints.ini
│   │   │   │   ├── WC_Gateway_SplitOff/
│   │   │   │   │   ├── splitoff_js_init.html.php
│   │   │   │   │   ├── assets.ini
│   │   │   │   │   ├── environments.ini
│   │   │   │   │   ├── form_fields.php
│   │   │   │   │   ├── instalments.html.php
│   │   │   │   │   ├── wysiwyg.html.php
│   │   │   │   ├── WC_Gateway_SplitOff.php
│   │   │   ├── css/
│   │   │   │   ├── splitoff.css
│   │   │   ├── js/
│   │   │   │   ├── splitoff-admin.js
│   │   │   │   ├── splitoff.js
│   │   │   ├── splitoff-gateway-for-woocommerce.php
│   │   │   ├── README.MD
│   │   │   ├── readme.txt
```

## Advanced Configuration

### Hooks

#### Product Eligibility

The SplitOff plugin runs a series of checks to determine whether SplitOff should be an available payment option for each individual product. Third-party plugins can exclude SplitOff from products that would otherwise be considered supported. This can be done by attaching to the `splitoff_is_product_supported` filter hook.

Example:
```PHP
function splitoff_ips_callback( $bool_result, $product ) {
    # My custom products don't support SplitOff.
    if ($product->get_type() == 'my-custom-product-type') {
        $bool_result = false;
    }
    return $bool_result;
}
add_filter( 'splitoff_is_product_supported', 'splitoff_ips_callback', 10, 2 );
```

#### Display on Individual Product Pages

Third-party plugins can also filter the HTML content rendered on individual product pages using the `splitoff_html_on_individual_product_pages` filter hook.

*Note: This is intended for altering the HTML based on custom, varying criteria. For setting the default HTML, use the admin interface under "WooCommerce > Settings > Checkout > SplitOff". For hiding the HTML for a subset of products, consider using the `splitoff_is_product_supported` filter hook, described above.*

Example:
```PHP
function splitoff_hoipp_callback( $str_html, $product, $price ) {
    # Show a different message for products below a custom threshold.
    # Note that this will only be called if the product price is within
    # the payment limits defined at the account level.
    if ($price < 10.00) {
        $str_html = "<p>Pay with Friends.</p>";
    }
    return $str_html;
}
add_filter( 'splitoff_html_on_individual_product_pages', 'splitoff_hoipp_callback', 10, 3 );
```

#### Display on Category Pages and Search Results

To filter the HTML content rendered on category pages and search results, use the `splitoff_html_on_product_thumbnails` filter hook.

*Note: This is intended for altering the HTML based on custom, varying criteria. For setting the default HTML, use the admin interface under "WooCommerce > Settings > Checkout > SplitOff". For hiding the HTML for a subset of products, consider using the `splitoff_is_product_supported` filter hook, described above.*

#### Display on the Cart Page

To filter the HTML content rendered on the cart page, use the `splitoff_html_on_cart_page` filter hook.

#### Display at the Checkout

To filter the HTML content rendered at the checkout, use the `splitoff_html_at_checkout` filter hook.

### Customising Hooks & Priorities

As discussed in the section entitled "Theme Support" above, various WooCommerce hooks are assumed to be implemented by the active WordPress theme. SplitOff methods can be detached from their default hooks and reattached to different hooks, or to the same hooks with different priorities.

Example 1 - Increase the priority of display on category pages:
```PHP
remove_action( 'woocommerce_after_shop_loop_item_title', array(WC_Gateway_SplitOff::getInstance(), 'print_info_for_listed_products'), 15, 0 );
add_action( 'woocommerce_after_shop_loop_item_title', array(WC_Gateway_SplitOff::getInstance(), 'print_info_for_listed_products'), 5, 0 );
```

Example 2 - Attach the SplitOff elements to a custom hook used on individual product pages:
```PHP
remove_action( 'woocommerce_single_product_summary', array(WC_Gateway_SplitOff::getInstance(), 'print_info_for_product_detail_page'), 15, 0 );
add_action( 'single_product_after_price', array(WC_Gateway_SplitOff::getInstance(), 'print_info_for_product_detail_page'), 10, 0 );
```

### Shortcodes

The following shortcodes are provided for convenience.

#### SplitOff Product Logo

This is provided for rendering an advanced `img` tag for displaying the SplitOff logo on individual product pages. The `img` tag uses the `srcset` attribute to include 3 different resolutions of the logo for screens with varying pixel density ratios.

```HTML
Split this payment between mates with [splitoff_product_logo]
```

There are 3 different versions of the logo to chose from:
* `colour`
* `black`
* `white`

The default theme is `colour`. This can be overridden by including a `theme` attribute inside the shortcode. For example, if you have a dark themed website and wish to use the white mono version of the SplitOff logo:

```HTML
Split this payment between mates with [splitoff_product_logo theme="white"]
```
