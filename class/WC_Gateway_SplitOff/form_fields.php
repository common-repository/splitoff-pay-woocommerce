<?php
/**
* Default values for the WooCommerce SplitOff Plugin Admin Form Fields
*/

# Process Region-based Assets
include('assets.php');
$currency				=	get_option('woocommerce_currency');

if (!empty($assets[strtolower($currency)])) {
	$region_assets		=	$assets[strtolower($currency)];
	$product_page_asset 	= 	$region_assets['product_page'];
	$product_variant_asset 	= 	$region_assets['product_variant'];
	$ctg_page_asset 	= 	$region_assets['category_page'];
	$cart_page_asset 	= 	$region_assets['cart_page'];
}
else {
	$product_page_asset 	= 	$assets['aud']['product_page'];
	$product_variant_asset 	= 	$assets['aud']['product_variant'];
	$ctg_page_asset 	= 	$assets['aud']['category_page'];
	$cart_page_asset 	= 	$assets['aud']['cart_page'];
}

$this->form_fields = array(
	'core-configuration-title' => array(
		'title'				=> __( 'Core Configuration', 'woo_splitoff' ),
		'type'				=> 'title'
	),
	'enabled' => array(
		'title'				=> __( 'Enable/Disable', 'woo_splitoff' ),
		'type'				=> 'checkbox',
		'label'				=> __( 'Enable TogetherPay', 'woo_splitoff' ),
		'default'			=> 'yes'
	),
	'title' => array(
		'title'				=> __( 'Title' ),
		'type'				=> 'text',
		'description'		=> __( 'This controls the payment method title which the user sees during checkout.', 'woo_splitoff' ),
		'default'			=> __( 'Pay with Friends', 'woo_splitoff' )
	),
	'testmode' => array(
		'title'				=> __( 'API Environment', 'woo_splitoff' ),
		'type'				=> 'select',
		'options'			=> wp_list_pluck( $this->environments, 'name' ),
		'default'			=> 'production',
		'description'		=> __( 'Note: Sandbox and Production API credentials are not interchangeable.', 'woo_splitoff' )
	),
	'api-version' => array(
		'title'				=> __( 'API Version', 'woo_splitoff' ),
		'type'				=> 'select',
		'options'			=>	array(
									'v1'	=> 'v1'
								),
		'default'			=> 'v1',
    ),
	'prod-id' => array(
		'title'				=> __( 'Merchant ID (Production)', 'woo_splitoff' ),
		'type'				=> 'text',
		'default'			=> ''
	),
	'prod-secret-key' => array(
		'title'				=> __( 'Secret Key (Production)', 'woo_splitoff' ),
		'type'				=> 'password',
		'default'			=> ''
	),
	'test-id' => array(
		'title'				=> __( 'Merchant ID (Sandbox)', 'woo_splitoff' ),
		'type'				=> 'text',
		'default'			=> ''
	),
	'test-secret-key' => array(
		'title'				=> __( 'Secret Key (Sandbox)', 'woo_splitoff' ),
		'type'				=> 'password',
		'default'			=> ''
	),
	'debug' => array(
		'title'				=> __( 'Debug Mode', 'woo_splitoff' ),
		'label'				=> __( 'Enable verbose debug logging', 'woo_splitoff' ),
		'type'				=> 'checkbox',
		'description'		=>
								__( 'The SplitOff log is in the ', 'woo_splitoff' ) .
								'<code>wc-logs</code>' .
								__( ' folder, which is accessible from the ', 'woo_splitoff' ) .
								'<a href="' . admin_url( 'admin.php?page=wc-status&tab=logs' ) . '">' .
									__( 'WooCommerce System Status', 'woo_splitoff' ) .
								'</a>' .
								__( ' page.', 'woo_splitoff' ),
		'default'			=> 'yes'
	),
	/*
	'pay-over-time-limit-min' => array(
		'title'				=> __( 'Minimum Payment Amount', 'woo_splitoff' ),
		'type'				=> 'input',
		'description'		=> __( 'This information is supplied by SplitOff and cannot be edited.', 'woo_splitoff' ),
		'custom_attributes'	=>	array(
									'readonly' => 'true'
								),
		'default'			=> ''
	),
	'pay-over-time-limit-max' => array(
		'title'				=> __( 'Maximum Payment Amount', 'woo_splitoff' ),
		'type'				=> 'input',
		'description'		=> __( 'This information is supplied by SplitOff and cannot be edited.', 'woo_splitoff' ),
		'custom_attributes'	=>	array(
									'readonly' => 'true'
								),
		'default'			=> ''
	),
	*/
	'presentational-customisation-title' => array(
		'title'				=> __( 'Customisation', 'woo_splitoff' ),
		'type'				=> 'title',
		'description'		=> __( 'Please feel free to customise the presentation of the SplitOff elements below to suit the individual needs of your web store.</p><p><em>Note: Advanced customisations may require the assistance of your web development team. <a id="reset-to-default-link" style="cursor:pointer;text-decoration:underline;">Restore Defaults</a></em>', 'woo_splitoff' )
	),
	'show-info-on-category-pages' => array(
		'title'				=> __( 'Payment Info on Category Pages', 'woo_splitoff' ),
		'label'				=> __( 'Enable', 'woo_splitoff' ),
		'type'				=> 'checkbox',
		'description'		=> __( 'Enable to display TogetherPay elements on category pages', 'woo_splitoff' ),
		'default'			=> 'yes'
	),
	'category-pages-info-text' => array(
		'type'				=> 'wysiwyg',
		'default'			=> $ctg_page_asset,
		'description'		=> __( '', 'woo_splitoff' )
	),
	'category-pages-hook' => array(
		'type'				=> 'text',
		'placeholder'		=> 'Enter hook name (e.g. woocommerce_after_shop_loop_item_title)',
		'default'			=> 'woocommerce_after_shop_loop_item_title',
		'description'		=> __( 'Set the hook to be used for Payment Info on Category Pages.', 'woo_splitoff' )
	),
	'category-pages-priority' => array(
		'type'				=> 'number',
		'placeholder'		=> 'Enter a priority number',
		'default'			=> 98,
		'description'		=> __( 'Set the hook priority to be used for Payment Info on Category Pages.', 'woo_splitoff' )
	),
	'show-info-on-product-pages' => array(
		'title'				=> __( 'Payment Info on Individual Product Pages', 'woo_splitoff' ),
		'label'				=> __( 'Enable', 'woo_splitoff' ),
		'type'				=> 'checkbox',
		'description'		=> __( 'Enable to display TogetherPay elements on individual product pages', 'woo_splitoff' ),
		'default'			=> 'yes'
	),
	'product-pages-info-text' => array(
		'type'				=> 'wysiwyg',
		'default'			=> $product_page_asset,
		'description'		=> __( '', 'woo_splitoff' )
	),
	'product-pages-hook' => array(
		'type'				=> 'text',
		'placeholder'		=> 'Enter hook name (e.g. woocommerce_single_product_summary)',
		'default'			=> 'woocommerce_single_product_summary',
		'description'		=> __( 'Set the hook to be used for Payment Info on Individual Product Pages.', 'woo_splitoff' )
	),
	'product-pages-priority' => array(
		'type'				=> 'number',
		'placeholder'		=> 'Enter a priority number',
		'default'			=> 14,
		'description'		=> __( 'Set the hook priority to be used for Payment Info on Individual Product Pages.', 'woo_splitoff' )
	),
	'product-pages-shortcode' => array(
		'type'				=> 'hidden',
		'description'		=> __( '<h3 class="wc-settings-sub-title">Page Builders</h3> If you use a page builder plugin, the above payment info can be placed using a shortcode instead of relying on hooks. Use [splitoff_paragraph] within a product page, or include the product ID to display the info for a specific product on any custom page. E.g.: [splitoff_paragraph id="99"]', 'woo_splitoff' )
	),
	'show-info-on-product-variant' => array(
		'title'				=> __( 'Payment Info Display for Product Variant', 'woo_splitoff' ),
		'label'				=> __( 'Enable', 'woo_splitoff' ),
		'type'				=> 'checkbox',
		'description'		=> __( 'Enable to display TogetherPay elements upon product variant selection', 'woo_splitoff' ),
		'default'			=> 'no'
	),
	'product-variant-info-text' => array(
		'type'				=> 'wysiwyg',
		'default'			=> $product_variant_asset,
		'description'		=> __( '', 'woo_splitoff' )
	),

	'show-info-on-cart-page' => array(
		'title'				=> __( 'Payment Info on Cart Page', 'woo_splitoff' ),
		'label'				=> __( 'Enable', 'woo_splitoff' ),
		'type'				=> 'checkbox',
		'description'		=> __( 'Enable to display TogetherPay elements on the cart page', 'woo_splitoff' ),
		'default'			=> 'yes'
	),
	'cart-page-info-text' => array(
		'type'				=> 'textarea',
		'default'			=> $cart_page_asset,
		'description'		=> __( '', 'woo_splitoff' )
	),
	'compatibility-mode-enabled' => array(
		'title'				=> __( 'Compatibility Mode', 'woo_splitoff' ),
		'type'				=> 'checkbox',
		'label'				=> __( 'Enable', 'woo_splitoff' ),
		'default'			=> 'no',
		'description'		=> __( 'Use this mode only if experiencing challenges with the display of order data within the WooCommerce admin views for TogetherPay orders.', 'woo_splitoff' ),
	),
	'splitoff-checkout-experience' => array(
		'type'				=> 'hidden',
		'default'			=> 'redirect',
	)
);
