<?php
/**
 * Plugin Name: TogetherPay for WooCommerce
 * Description: Provide TogetherPay as a payment option for WooCommerce orders.
 * Author: SplitOff
 * Author URI:https://www.splitoff.io/
 * Version: 1.0.10
 * Text Domain: splitoff-pay-for-woocommerce
 * WC requires at least: 2.6.0
 * Tested up to: 6.1
 *
 * Copyright: (c) 2020 SplitOff
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

if (!defined('ABSPATH')) {
	exit;
}

if (!class_exists('SplitOff_Plugin')) {
	class SplitOff_Plugin
	{
		/**
		 * @var		SplitOff_Plugin		$instance	A static reference to an instance of this class.
		 */
		protected static $instance;

		/**
		 * @var		int					$version	A reference to the plugin version, which will match
		 *											the value in the comments above.
		 */
		public static $version = '1.0.10';

		/**
		 * Import required classes.
		 *
		 * @since	1.0.0
		 * @used-by	self::init()
		 * @used-by	self::deactivate_plugin()
		 */
		public static function load_classes()
		{
			//require_once dirname(__FILE__) . '/class/Cron/SplitOff_Plugin_Cron.php';
			require_once dirname(__FILE__) . '/class/Merchant/SplitOff_Plugin_Merchant.php';
			if (class_exists('WC_Payment_Gateway')) {
				require_once dirname(__FILE__) . '/class/WC_Gateway_SplitOff.php';
			}
		}

		/**
		 * Class constructor. Called when an object of this class is instantiated.
		 *
		 * @since	1.0.0
		 * @see		SplitOff_Plugin::init()						For where this class is instantiated.
		 * @see		WC_Settings_API::process_admin_options()
		 * @uses	version_compare()							Available in PHP since 4.1
		 * @uses	self::generate_product_hooks()
		 * @uses	self::generate_category_hooks()
		 * @uses	WC_Gateway_SplitOff::getInstance()
		 */
		public function __construct()
		{
			$gateway = WC_Gateway_SplitOff::getInstance();

			/**
			 * Actions.
			 */
			add_action( 'init', array($gateway, 'register_post_types'), 10, 0 );
			add_action( 'admin_notices', array($gateway, 'render_admin_notices'), 10, 0 );
			add_action( 'admin_enqueue_scripts', array($this, 'init_admin_assets'), 10, 0 );
			//add_action( 'splitoff_do_cron_jobs', array('SplitOff_Plugin_Cron', 'fire_jobs'), 10, 0 );
			add_action( "woocommerce_update_options_payment_gateways_{$gateway->id}", array($gateway, 'process_admin_options'), 10, 0 ); # process_admin_options() is defined in WC_Gateway_SplitOff's grandparent class: WC_Settings_API.
			add_action( "woocommerce_update_options_payment_gateways_{$gateway->id}", array($gateway, 'refresh_cached_configuration'), 11, 0 ); # Refresh cached configuration after our gateway settings are saved, but before the cron jobs run.
			//add_action( "woocommerce_update_options_payment_gateways_{$gateway->id}", array('SplitOff_Plugin_Cron', 'fire_jobs'), 12, 0 ); # Manually fire the cron jobs after our gateway settings are saved, and after cached configuration is refreshed.
			add_action( "woocommerce_receipt_{$gateway->id}", array($gateway, 'receipt_page'), 10, 1 );
			// add_action( 'woocommerce_single_product_summary', array($gateway, 'print_info_for_product_detail_page'), 15, 0 );
			// add_action( 'woocommerce_after_shop_loop_item_title', array($gateway, 'print_info_for_listed_products'), 15, 0 );
			add_action( 'woocommerce_cart_totals_after_order_total', array($gateway, 'render_schedule_on_cart_page'), 10, 0 );
			add_action( 'template_redirect', array($gateway, 'override_single_post_template_for_splitoff_quotes'), 10, 0 );
			add_action( 'template_redirect', array($gateway, 'splitoff_check_for_cancelled_payment'), 10, 0);
			add_action( 'wp_head', array($gateway, 'inject_preauth_html'), 10, 0 );
			add_action( 'wp_footer', array($gateway, 'inject_preauth_html'), 10, 0 );
			add_action( 'shutdown', array($gateway, 'inject_preauth_html'), 10, 0 );
			add_action( 'wp_enqueue_scripts', array($this, 'init_website_assets'), 10, 0 );

			add_action( 'wp_ajax_splitoff_action', array($gateway, 'reset_settings_api_form_fields'),10,0 );
			add_action( 'wp_ajax_splitoff_notice_dismiss_action', array($gateway, 'splitoff_notice_dismiss'),10,0 );
			add_action( 'upgrader_process_complete', array($this, 'save_rating_notification_timestamp'),10, 2);
			/**
			 * Filters.
			 */
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array($this, 'filter_action_links'), 10, 1 );
			//add_filter( 'cron_schedules', array('SplitOff_Plugin_Cron', 'edit_cron_schedules'), 10, 1 );
			add_filter( 'woocommerce_payment_gateways', array($gateway, 'add_splitoff_gateway'), 10, 1 );
			add_filter( 'woocommerce_available_payment_gateways', array($gateway, 'check_cart_within_limits'), 99, 1 );
			add_filter( 'woocommerce_get_price_html', array($gateway, 'filter_woocommerce_get_price_html'), 10, 2 );
			if (version_compare( WC_VERSION, '3.0.0', '<' )) {
				add_filter( 'woocommerce_variation_price_html', array($gateway, 'filter_woocommerce_variation_price_html'), 10, 2);
				add_filter( 'woocommerce_variation_sale_price_html', array($gateway, 'filter_woocommerce_variation_price_html'), 10, 2);
			}
			add_filter( 'woocommerce_gateway_icon', array($gateway, 'filter_woocommerce_gateway_icon'), 10, 2 );
			if (version_compare( WC_VERSION, '3.6', '<' )) {
				add_filter( 'woocommerce_create_order', array($gateway, 'override_order_creation'), 10, 2 );
			} else {
				add_filter( 'woocommerce_create_order', array($gateway, 'override_order_creation_3_6'), 10, 2 );
			}
			add_filter( 'woocommerce_new_order_data', array($gateway, 'filter_woocommerce_new_order_data'), 10, 1 );
			add_filter( 'woocommerce_thankyou_order_id', array($gateway, 'payment_callback'), 10, 1 );

			/**
			 * Shortcodes.
			 */
			add_shortcode( 'splitoff_product_logo', array($this, 'shortcode_splitoff_product_logo') );
			add_shortcode( 'splitoff_paragraph', array($gateway, 'shortcode_splitoff_paragraph') );

			$this->generate_product_hooks($gateway);
			$this->generate_category_hooks($gateway);
		}

		/**
		 * Note: Perform dynamic Product Page Assets hooks processing
		 *
		 * @since	1.0.0
		 * @param	WC_Gateway_SplitOff		$gateway
		 * @uses	WC_Gateway_SplitOff::getSettings
		 * @return	bool
		 */
		public function generate_product_hooks($gateway)
		{
			$settings = $gateway->getSettings();
			if (!empty($settings['product-pages-hook'])) {
				$product_pages_hook = $settings['product-pages-hook'];

				if (!empty($settings['product-pages-priority'])) {
					$product_pages_priority = (int) $settings['product-pages-priority'];
				}
				else {
					$product_pages_priority = 15;
				}

				//add the adjusted Product Single Page action
				add_action( $product_pages_hook, array($gateway, 'print_info_for_product_detail_page'), $product_pages_priority, 0 );
			}

			return true;
		}

		/**
		 * Note: Perform dynamic Category Page Assets hooks processing
		 *
		 * @since	1.0.0
		 * @param	WC_Gateway_SplitOff		$gateway
		 * @uses	WC_Gateway_SplitOff::getSettings()
		 * @return	bool
		 */
		public function generate_category_hooks($gateway)
		{
			$settings = $gateway->getSettings();
			if (!empty($settings['category-pages-hook'])) {
				$category_pages_hook = $settings['category-pages-hook'];

				if (!empty($settings['category-pages-priority'])) {
					$category_pages_priority = (int) $settings['category-pages-priority'];
				}
				else {
					$category_pages_priority = 99;
				}

				//add the adjusted Product Single Page action
				add_action( $category_pages_hook, array($gateway, 'print_info_for_listed_products'), $category_pages_priority, 0 );
			}

			return true;
		}

		/**
		 * Note: Hooked onto the "plugin_action_links_woocommerce-gateway-splitoff/woocommerce-splitoff.php" Action.
		 *
		 * @since	1.0.0
		 * @see		self::__construct()		For hook attachment.
		 * @param	array	$links
		 * @return	array
		 */
		public function filter_action_links($links)
		{
			$additional_links = array(
				'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=splitoff' ) . '">' . __( 'Settings', 'woo_splitoff' ) . '</a>',
			);

			return array_merge($additional_links, $links);
		}

		/**
		 * Note: Hooked onto the "wp_enqueue_scripts" Action to avoid the Wordpress Notice warnings
		 *
		 * @since	1.0.0
		 * @see		self::__construct()		For hook attachment.
		 */
		public function init_website_assets()
		{
			$instance = WC_Gateway_SplitOff::getInstance();

			if ($instance->settings['enabled'] == 'yes' &&
				function_exists('is_woocommerce') &&
				(is_woocommerce() || is_cart() || is_checkout())
			) {
				/**
				 * Register & Enqueue JS.
				 * Note: Admin assets are registered in self::init_admin_assets()
				 */

				wp_enqueue_script( 'splitoff_js', plugins_url( 'js/splitoff.js', __FILE__ ), array('jquery'), '1.1');


				/**
				 * Register & Enqueue CSS.
				 */
				wp_enqueue_style( 'splitoff_css', plugins_url( 'css/splitoff.css', __FILE__ ), array(), '1.1');
			}
		}

		/**
		 * Note: Hooked onto the "admin_enqueue_scripts" Action.
		 *
		 * @since	1.0.0
		 * @see		self::__construct()		For hook attachment.
		 */
		public function init_admin_assets()
		{
			wp_enqueue_script( 'splitoff_admin_js', plugins_url( 'js/splitoff-admin.js', __FILE__ ) );
			wp_localize_script( 'splitoff_admin_js', 'splitoff_ajax_object', array( 'ajax_url' => admin_url( 'admin-ajax.php' )) );
		}

		/**
		 * Provide a shortcode for rendering the standard SplitOff logo on individual product pages.
		 *
		 * E.g.:
		 * 	- [splitoff_product_logo] OR [splitoff_product_logo theme="colour"]
		 * 	- [splitoff_product_logo theme="black"]
		 * 	- [splitoff_product_logo theme="white"]
		 *
		 * @since	1.0.0
		 * @see		self::__construct()		For shortcode definition.
		 * @param	array	$atts			Array of shortcode attributes.
		 * @uses	shortcode_atts()
		 * @return	string
		 */
		public function shortcode_splitoff_product_logo($atts) {
			$atts = shortcode_atts( array(
				'theme' => 'colour'
			), $atts );

			if (!in_array($atts['theme'], array('colour', 'black', 'white'))) {
				$atts['theme'] = 'colour';
			}

            $url = esc_url(plugin_dir_url( __FILE__ ) . 'class/splitoff-logo');
			ob_start();

			?>
			<img style="vertical-align:middle; width:100px;"  class="splitoff-popup"  src="<?php echo $url; ?>-<?php echo $atts['theme']; ?>.png"  alt="TogetherPay" />
			<?php

			return ob_get_clean();
		}

		/**
		 * Initialise the class and return an instance.
		 *
		 * Note:	Hooked onto the "plugins_loaded" Action.
		 *
		 * @since	1.0.0
		 * @uses	self::load_classes()
		 * @return	SplitOff_Plugin
		 * @used-by	self::activate_plugin()
		 */
		public static function init()
		{
			self::load_classes();
			if (!class_exists('WC_Gateway_SplitOff')) {
				return false;
			}
			if (is_null(self::$instance)) {
				self::$instance = new self;
			}
			return self::$instance;
		}

		/**
		 * Callback for when this plugin is activated. Schedules the cron jobs.
		 *
		 * @since	1.0.0
		 * @uses	set_transient()							Available in WordPress core since 2.8.0
		 * @uses	self::init()
		 * @uses	SplitOff_Plugin_Cron::create_jobs()
		 */
		public static function activate_plugin()
		{
			if (!current_user_can( 'activate_plugins' )) {
				return;
			}

			if (function_exists('set_transient')) {
				set_transient( 'splitoff-admin-activation-notice', true, 300 );
			}

			self::init(); # Can't just use load_classes() here because the cron schedule is setup in the filter, which attaches inside the class constructor. Have to do a full init.
			//SplitOff_Plugin_Cron::create_jobs();
			self::save_rating_notification_timestamp();
		}

		/**
		 * Callback for when this plugin is deactivated. Deletes the scheduled cron jobs.
		 *
		 * @since	1.0.0
		 * @uses	self::load_classes()
		 * @uses	SplitOff_Plugin_Cron::delete_jobs()
		 */
		public static function deactivate_plugin()
		{
			if (!current_user_can( 'activate_plugins' )) {
				return;
			}

			self::load_classes();
			//SplitOff_Plugin_Cron::delete_jobs();
		}

		/**
		 * Callback for when the plugin is uninstalled. Remove all of its data.
		 *
		 * Note:	This function is called when this plugin is uninstalled.
		 *
		 * @since	1.0.0
		 */
		public static function uninstall_plugin()
		{
			if (!current_user_can( 'activate_plugins' )) {
				return;
			}
		}

		public static function save_rating_notification_timestamp(){
			if(!get_option('splitoff_rating_notification_timestamp')){
				add_option('splitoff_rating_notification_timestamp',time());
			}
		}
	}

	register_activation_hook( __FILE__, array('SplitOff_Plugin', 'activate_plugin') );
	register_deactivation_hook( __FILE__, array('SplitOff_Plugin', 'deactivate_plugin') );
	register_uninstall_hook( __FILE__, array('SplitOff_Plugin', 'uninstall_plugin') );

	add_action( 'plugins_loaded', array('SplitOff_Plugin', 'init'), 10, 0 );
}
