<?php
/**
 * This is the SplitOff - WooCommerce Payment Gateway Class.
 */
if (!class_exists('WC_Gateway_SplitOff')) {
	class WC_Gateway_SplitOff extends WC_Payment_Gateway
	{
		/**
		 * Private variables.
		 *
		 * @var		string	$include_path			Path to where this class's includes are located. Populated in the class constructor.
		 * @var		array	$environments			Keyed array containing the name and API/web URLs for each environment. Populated in the
		 *											class constructor by parsing the values in "environments.ini".
		 * @var		string	$token					The token to render on the preauth page. This is populated by the
		 *											self::override_single_post_template_for_splitoff_quotes() method,
		 *											only if validated. If populated, it will be used to render the SplitOff JS
		 *											somewhere on the page:
		 *												- on "wp_head" (inside the <head></head> block),
		 *												- on "wp_footer" (inside the theme's footer),
		 *												- or if all else fails, on "shutdown" (after the closing </html> tag).
		 * @var		bool	$compatibility_mode		If enabled, SplitOff will not override WooCommerce's standard order creation.
		 *											Although this should make it more compatible with third-party plugins, it reintroduces
		 *											many of the fundamential issues present in API v0, which were resolved by API v1.
		 */
		private $include_path, $environments, $token, $compatibility_mode;

		/**
		 * Public variables.
		 *
		 * @var		string	$id							Inherited from WC_Settings_API. Important: The Admin JS assumes this string to be "splitoff".
		 * @var		string	$description				Inherited from WC_Payment_Gateway.
		 * @var		string	$method_title				Inherited from WC_Payment_Gateway.
		 * @var		string	$method_description			Inherited from WC_Payment_Gateway.
		 * @var		string	$icon						Inherited from WC_Payment_Gateway.
		 * @var		array	$supports					Inherited from WC_Payment_Gateway.
		 * @var		array	$form_fields				Inherited from WC_Settings_API.
		 * @var		string	$title						Inherited from WC_Payment_Gateway.
		 * @var		string	$order_button_text			Inherited from WC_Payment_Gateway.
		 */
		public $id, $description, $method_title, $method_description, $icon, $supports, $form_fields, $title, $supported_currencies,$customer_service_number;

		/**
		 * Protected static variables.
		 *
		 * @var		WC_Gateway_SplitOff	$instance		A static reference to a singleton instance of this class.
		 */
		protected static $instance = null;

		/**
		 * Public static variables.
		 *
		 * @var		bool|null			$log_enabled	Whether or not logging is enabled. Defaults to null.
		 * @var		WC_Logger|null		$log			An instance of the WC_Logger class. Defaults to null.
		 */
		public static $log_enabled = null, $log = null;

		/**
		 * Class constructor. Called when an object of this class is instantiated.
		 *
		 * @since	2.0.0
		 * @uses	plugin_basename()					Available as part of the WordPress core since 1.5.
		 * @uses	WC_Payment_Gateway::init_settings()	If the user has not yet saved their settings, it will extract the
		 *												default values from $this->form_fields defined in an ancestral class
		 *												and overridden below.
		 */
		public function __construct() {
			$this->include_path			= dirname(__FILE__) . '/WC_Gateway_SplitOff';
			include("{$this->include_path}/environments.php");

			$this->id					= 'splitoff';
			$this->description			= __( 'Credit cards accepted: Visa, Mastercard', 'woo_splitoff' );
			$this->method_title			= __( 'TogetherPay', 'woo_splitoff' );
			$this->method_description	= __( 'Use TogetherPay as a credit card processor for WooCommerce.', 'woo_splitoff' );
			//$this->icon; # Note: This URL is ignored; the WC_Gateway_SplitOff::filter_woocommerce_gateway_icon() method fires on the "woocommerce_gateway_icon" Filter hook and generates a complete HTML IMG tag.
			$this->supports				= array('products', 'refunds');
			$this->supported_currencies = array('AUD', 'NZD', 'USD', 'CAD');
			$this->customer_service_number = array('AUD'=>'', 'NZD'=>'', 'USD'=>'', 'CAD'=>'');

			include "{$this->include_path}/form_fields.php";

			$this->init_settings();
			$this->refresh_cached_configuration();
		}

		/**
		 * Refresh cached configuration. This method updates the properties of the class instance.
		 * Called from the constructor and after settings are saved. As an extension of WC_Payment_Gateway,
		 * `$this->settings` is automatically refreshed when settings are saved, but our custom properties
		 * are not. So this method is attached to a WooCommerce hook to ensure properties are up to date
		 * when the cron jobs run.
		 *
		 * Note:	Hooked onto the "woocommerce_update_options_payment_gateways_splitoff" Action.
		 *
		 * @since	2.1.0
		 */
		public function refresh_cached_configuration() {
			if (array_key_exists('title', $this->settings)) {
				$this->title = $this->settings['title'];
			}
			if (array_key_exists('debug', $this->settings)) {
				self::$log_enabled = ($this->settings['debug'] == 'yes');
			}
			if (array_key_exists('compatibility-mode-enabled', $this->settings)) {
				$this->compatibility_mode = ($this->settings['compatibility-mode-enabled'] == 'yes');
			} else {
				$this->compatibility_mode = false;
			}
			//if (array_key_exists('api-version', $this->settings) && $this->settings['api-version'] == 'v1' && !$this->compatibility_mode) {
				$this->order_button_text = __( 'Proceed to TogetherPay' );
			//}
		}

		/**
		 * Logging method. Using this to log a string will store it in a file that is accessible
		 * from "WooCommerce > System Status > Logs" in the WordPress admin. No FTP access required.
		 *
		 * @param 	string	$message	The message to log.
		 * @uses	WC_Logger::add()
		 */
		public static function log($message) {
			if (is_null(self::$log_enabled)) {
				# Get the settings key for the plugin
				$gateway = new WC_Gateway_SplitOff;
				$settings_key = $gateway->get_option_key();
				$settings = get_option( $settings_key );

				if (array_key_exists('debug', $settings)) {
					self::$log_enabled = ($settings['debug'] == 'yes');
				} else {
					self::$log_enabled = false;
				}
			}
			if (self::$log_enabled) {
				if (is_null(self::$log)) {
					self::$log = new WC_Logger;
				}
				if (is_array($message)) {
					/**
					 * @since 2.1.0
					 * Properly expand Arrays in logs.
					 */
					$message = print_r($message, true);
				} elseif(is_object($message)) {
					/**
					 * @since 2.1.0
					 * Properly expand Objects in logs.
					 *
					 * Only use the Output Buffer if it's not currently active,
					 * or if it's empty.
					 *
					 * Note:	If the Output Buffer is active but empty, we write to it,
					 * 			read from it, then discard the contents while leaving it active.
					 *
					 * Otherwise, if $message is an Object, it will be logged as, for example:
					 * (foo Object)
					 */
					$ob_get_length = ob_get_length();
					if (!$ob_get_length) {
						if ($ob_get_length === false) {
							ob_start();
						}
						var_dump($message);
						$message = ob_get_contents();
						if ($ob_get_length === false) {
							ob_end_clean();
						} else {
							ob_clean();
						}
					} else {
						$message = '(' . get_class($message) . ' Object)';
					}
				}
				self::$log->add( 'splitoff', $message );
			}
		}

		/**
		 * Instantiate the class if no instance exists. Return the instance.
		 *
		 * @since	2.0.0
		 * @return	WC_Gateway_SplitOff
		 */
		public static function getInstance()
		{
			if (is_null(self::$instance)) {
				self::$instance = new self;
			}
			return self::$instance;
		}

		/**
		 * Register our custom post types. This will automatically create new top-level menu items
		 * in the admin interface.
		 *
		 * Note:	Hooked onto the "init" Action.
		 *
		 * Note:	The names are limited to 20 characters.
		 * @see		https://codex.wordpress.org/Function_Reference/register_post_type
		 *
		 * @since	2.0.0
		 * @see		SplitOffPlugin::__construct()	For hook attachment.
		 * @uses	register_post_type
		 */
		public function register_post_types() {
			register_post_type( 'splitoff_quote', array(
				'labels' => array(
					'name' => __( 'SplitOff Quotes' ),
					'singular_name' => __( 'SplitOff Quote' ),
					'not_found' => __( 'No quotes found.' ),
					'all_items' => __( 'View All' )
				),
				'supports' => array(
					'custom-fields'
				),
				'public' => true,
				'publicly_queriable' => false,
				'show_ui' => false, # Set to true to render Admin UI for this post type.
				'can_export' => false,
				'exclude_from_search' => true,
				'show_in_nav_menus' => false,
				'has_archive' => false,
				'rewrite' => false
			));
		}

		/**
		 * Is the gateway configured? This method returns true if any of the credentials fields are not empty.
		 *
		 * @since	2.0.0
		 * @return	bool
		 * @used-by	self::render_admin_notices()
		 */
		private function is_configured() {
			if (!empty($this->settings['prod-id'])) return true;
			if (!empty($this->settings['prod-secret-key'])) return true;
			if (!empty($this->settings['test-id'])) return true;
			if (!empty($this->settings['test-secret-key'])) return true;
			return false;
		}

		/**
		 * Add the TogetherPay gateway to WooCommerce.
		 *
		 * Note:	Hooked onto the "woocommerce_payment_gateways" Filter.
		 *
		 * @since	2.0.0
		 * @see		SplitOffPlugin::__construct()	For hook attachment.
		 * @param	array	$methods				Array of Payment Gateways.
		 * @return	array							Array of Payment Gateways, with SplitOff added.
		 **/
		public function add_splitoff_gateway($methods) {
			$methods[] = 'WC_Gateway_SplitOff';
			return $methods;
		}

		/**
		 * Check whether the gateway is enabled and the cart amount is within the payment limits for this merchant.
		 * If admin is logged in, this check will be skipped.
		 *
		 * Note:	Hooked onto the "woocommerce_available_payment_gateways" Filter.
		 *
		 * @since	2.0.0
		 * @see		SplitOff_Plugin::__construct()	For hook attachment.
		 * @param	array	$gateways				List of enabled gateways.
		 * @uses	WC()							Available in WooCommerce core since 2.1.0.
		 * @return	array							List of enabled gateways, possibly with SplitOff removed.
		 */
		public function check_cart_within_limits($gateways) {
			/*if (isset($gateways[$this->id]) && !is_admin()) {
				if (
					!array_key_exists('enabled', $this->settings) || $this->settings['enabled'] != 'yes' ||
					($this->settings['pay-over-time-limit-min'] == 'N/A' && $this->settings['pay-over-time-limit-max'] == 'N/A') ||
					get_option('woocommerce_currency') != get_woocommerce_currency()
				) {
					unset($gateways[$this->id]);
				}
				elseif (!is_null(WC()->cart)) {
					$total = WC()->cart->total;
					if (
						$total < floatval($this->settings['pay-over-time-limit-min']) ||
						$total > floatval($this->settings['pay-over-time-limit-max'])
					) {
						unset($gateways[$this->id]);
					}
					else {

						foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
						    $product = $cart_item['data'];
						    if (!$this->is_product_supported($product)) {
						        unset($gateways[$this->id]);
								break;
						    }
						}
					}
				}
			}*/
            if (!is_null(WC()->cart)) {
                $total = WC()->cart->total;
                foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
                    $product = $cart_item['data'];
                    if (!$this->is_product_supported($product)) {
                        unset($gateways[$this->id]);
                        break;
                    }
                }
            }

			return $gateways;
		}

		/**
		 * Display SplitOff Assets on Normal Products
		 * Note:	Hooked onto the "woocommerce_get_price_html" Filter.
		 *
		 * @since	2.0.0
		 * @see		SplitOff_Plugin::__construct()	For hook attachment.
		 * @param 	float $price
		 * @param 	WC_Product $product
		 * @uses	self::print_info_for_listed_products()
		 * @return	string
		 */
		function filter_woocommerce_get_price_html($price, $product) {
			if (is_object($product) && $product instanceof WC_Product_Variation) {
				ob_start();
				$this->print_info_for_listed_products($product);
				$splitoff_html = ob_get_clean();

				return $price . $splitoff_html;
			}
			return $price;
		}

		/**
		 * Display SplitOff Assets on Variable Products' Variations
		 *
		 * Note:	Hooked onto the "woocommerce_variation_price_html" Filter.
		 * Note:	Hooked onto the "woocommerce_variation_sale_price_html" Filter.
		 *
		 * @since	2.0.0
		 * @see		SplitOff_Plugin::__construct()	For hook attachment.
		 * @param	float					$price
		 * @param	WC_Product_Variation	$variation
		 * @uses	self::print_info_for_listed_products()
		 * @return	string
		 */
		function filter_woocommerce_variation_price_html($price, $variation) {
			if (is_object($variation)) {
				ob_start();
				$this->print_info_for_listed_products($variation);
				$splitoff_html = ob_get_clean();

				return $price . $splitoff_html;
			}
			return $price;
		 }

		/**
		 * The WC_Payment_Gateway::$icon property only accepts a string for the image URL. Since we want
		 * to support high pixel density screens and specifically define the width and height attributes,
		 * this method attaches to a Filter hook so we can build our own HTML markup for the IMG tag.
		 *
		 * Note:	Hooked onto the "woocommerce_gateway_icon" Filter.
		 *
		 * @since	2.0.0
		 * @see		SplitOff_Plugin::__construct()	For hook attachment.
		 * @param	string 	$icon_html		Icon HTML
		 * @param	string 	$gateway_id		Payment Gateway ID
		 * @return	string
		 */
		public function filter_woocommerce_gateway_icon($icon_html, $gateway_id) {
			if ($gateway_id != 'splitoff') {
				return $icon_html;
			}

            $imgUrl = plugin_dir_url( __FILE__ ) . 'splitoff-logo-colour.png';
			ob_start();
            //print_r(plugin_dir_url(__FILE__ ));
            $imgUrl = plugin_dir_url( __FILE__ ) . 'splitoff-logo-colour.png';
            ?>
				<img src="<?php echo esc_url($imgUrl);?>" style="width: 100px; vertical-align: middle;" class="splitoff-popup" style="cursor: pointer;" alt="TogetherPay">

            <?php
            return ob_get_clean();
		}

		/**
		 * Render admin notices if applicable. This will print an error on every page of the admin if the cron failed to
		 * authenticate on its last attempt.
		 *
		 * Note:	Hooked onto the "admin_notices" Action.
		 * Note:	This runs BEFORE WooCommerce fires its "woocommerce_update_options_payment_gateways_<gateway_id>" actions.
		 *
		 * @since	2.0.0
		 * @uses	get_transient()			Available in WordPress core since 2.8.0
		 * @uses	delete_transient()		Available in WordPress core since 2.8.0
		 * @uses	admin_url()				Available in WordPress core since 2.6.0
		 * @uses	delete_option()
		 * @uses	self::is_configured()
		 */
		public function render_admin_notices() {
			/**
			 * Also change the activation message to include a link to the plugin settings.
			 *
			 * Note:	We didn't add the "is-dismissible" class here because we continually show another
			 *			message similar to this until the API credentials are entered.
			 *
			 * @see		./wp-admin/plugins.php	For the markup that this replaces.
			 * @uses	get_transient()			Available in WordPress core since 2.8.0
			 * @uses	delete_transient()		Available in WordPress core since 2.8.0
			 */
			if (function_exists('get_transient') && function_exists('delete_transient')) {
				if (get_transient( 'splitoff-admin-activation-notice' )) {
					?>
					<div class="updated notice">
						<p><?php _e( 'Plugin <strong>activated</strong>.' ) ?></p>
						<p><?php _e( 'Thank you for choosing SplitOff.', 'woo_splitoff' ); ?> <a href="<?php echo admin_url( 'admin.php?page=wc-settings&tab=checkout&section=splitoff' ); ?>"><?php _e( 'Configure Settings.', 'woo_splitoff' ); ?></a></p>
						<p><?php _e( 'Don&rsquo;t have an SplitOff Merchant account yet?', 'woo_splitoff' ); ?> <a href="https://togetherpay.io" target="_blank"><?php _e( 'Apply online today!', 'woo_splitoff' ); ?></a></p>
					</div>
					<?php
					if (array_key_exists('activate', $_GET) && $_GET['activate'] == 'true') {
						unset($_GET['activate']); # Prevent the default "Plugin *activated*." notice.
					}
					delete_transient( 'splitoff-admin-activation-notice' );
					# No need to decide whether to render any API errors. We've only just activated the plugin.
					return;
				}
			}

			if (array_key_exists('woocommerce_splitoff_enabled', $_POST)) {
				# Since this runs before we handle the POST, we can clear any stored error here.
				delete_option( 'woocommerce_splitoff_api_error' );

				# If we're posting changes to the SplitOff settings, don't pull anything out of the database just yet.
				# This runs before the POST gets handled by WooCommerce, so we can wait until later.
				# If the updated settings fail, that will trigger its own error later.
				return;
			}

			$show_link = true;
			if (array_key_exists('page', $_GET) && array_key_exists('tab', $_GET) && array_key_exists('section', $_GET)) {
				if ($_GET['page'] == 'wc-settings' && $_GET['tab'] == 'checkout' && $_GET['section'] == 'splitoff') {
					# We're already on the TogetherPay gateway's settings page. No need for the circular link.
					$show_link = false;
				}
			}

			$error = get_option( 'woocommerce_splitoff_api_error' );
			if (is_object($error) && $this->settings['enabled'] == 'yes') {
				?>
				<div class="error notice">
					<p>
						<strong><?php _e( "SplitOff API Error #{$error->code}:", 'woo_splitoff' ); ?></strong>
						<?php _e( $error->message, 'woo_splitoff' ); ?>
						<?php if (property_exists($error, 'id') && $error->id): ?>
							<em><?php _e( "(Error ID: {$error->id})", 'woo_splitoff' ); ?></em>
						<?php endif; ?>
						<?php if ($show_link): ?>
							<a href="<?php echo admin_url( 'admin.php?page=wc-settings&tab=checkout&section=splitoff' ); ?>"><?php _e( 'Please check your SplitOff Merchant settings here.', 'woo_splitoff' ); ?></a>
						<?php endif; ?>
					</p>
				</div>
				<?php
				return;
			}

			# Also include a link to the plugin settings if they haven't been saved yet,
			# unless they have unchecked the Enabled checkbox in the settings.
			if (!$this->is_configured() && $this->settings['enabled'] == 'yes' && $show_link) {
				?>
				<div class="updated notice">
					<p><?php _e( 'Thank you for choosing SplitOff.', 'woo_splitoff' ); ?> <a href="<?php echo admin_url( 'admin.php?page=wc-settings&tab=checkout&section=splitoff' ); ?>"><?php _e( 'Configure Settings.', 'woo_splitoff' ); ?></a></p>
					<p><?php _e( 'Don&rsquo;t have an SplitOff Merchant account yet?', 'woo_splitoff' ); ?> <a href="https://togetherpay.io/" target="_blank"><?php _e( 'Apply online today!', 'woo_splitoff' ); ?></a></p>
				</div>
				<?php
				return;
			}
			if(isset($this->settings['splitoff-plugin-version']) && $this->settings['splitoff-plugin-version'] != SplitOff_Plugin::$version){
					?>
					<div class='updated notice'>
					<p>SplitOff Pay for WooCommerce has updated from <?php echo $this->settings['splitoff-plugin-version']?> to <?php echo SplitOff_Plugin::$version?>. Please review and re-save your settings <?php if ($show_link){ ?><a href="<?php echo admin_url( 'admin.php?page=wc-settings&tab=checkout&section=splitoff' ); ?>"><?php _e( 'here', 'woo_splitoff' ); ?></a><?php } else { _e( 'below', 'woo_splitoff' );} ?>.</p>
					</div>
					<?php
			}
			else if(!isset($this->settings['splitoff-plugin-version'])){
				?>
				<div class='updated notice'><p>SplitOff Pay for WooCommerce has updated to version <?php echo SplitOff_Plugin::$version?>. Please review and re-save your settings <?php if ($show_link){ ?> <a href="<?php echo admin_url( 'admin.php?page=wc-settings&tab=checkout&section=splitoff' ); ?>"><?php _e( 'here', 'woo_splitoff' ); ?></a><?php } else { _e( 'below', 'woo_splitoff' );} ?>.</p></div>
				<?php
			}

			if(!get_option('splitoff_rate_notice_dismiss') || (get_option('splitoff_rate_notice_dismiss') && get_option('splitoff_rate_notice_dismiss')!='yes')){
				if(get_option('splitoff_rating_notification_timestamp')){
					$changeDate = date_create(date("Y-m-d",get_option('splitoff_rating_notification_timestamp')));
					$dateDiff   = date_diff($changeDate,date_create());
					if($dateDiff->format("%a") >= 14){
					?>
					<div class="notice notice-warning splitoff-rating-notice">
						<a class="notice-dismiss splitoff-notice-dismiss" style="position:relative;float:right;padding:9px 0px 9px 9px 9px;text-decoration:none;"></a>
						<p><?php _e( 'What do you think of the SplitOff plugin? Share your thoughts and experience to help improve future plugin releases.', 'woo_splitoff' ); ?></p><p> <a href="https://wordpress.org/support/plugin/splitoff-pay-for-woocommerce/reviews/" class="splitoff_rate_redirect splitoff-notice-dismiss button button-primary"><?php _e( 'Rate now', 'woo_splitoff' ); ?></a></p>
					</div>
					<?php
					}
				}
			}
		}

		/**
		 * Admin Panel Options. Overrides the method defined in the parent class.
		 *
		 * @since	2.0.0
		 * @see		WC_Payment_Gateway::admin_options()			For the method that this overrides.
		 * @uses	WC_Settings_API::generate_settings_html()
		 */
		public function admin_options() {
			?>
			<h3><?php _e( 'TogetherPay Gateway', 'woo_splitoff' ); ?></h3>

			<table class="form-table">
				<?php $this->generate_settings_html(); ?>
			</table>
			<?php
		}

		/**
		 * Generate WYSIWYG input field. This is a pseudo-magic method, called for each form field with a type of "wysiwyg".
		 *
		 * @since	2.0.0
		 * @see		WC_Settings_API::generate_settings_html()	For where this method is called from.
		 * @param	mixed		$key
		 * @param	mixed		$data
		 * @uses	esc_attr()									Available in WordPress core since 2.8.0.
		 * @uses	wp_editor()									Available in WordPress core since 3.3.0.
		 * @return	string										The HTML for the table row containing the WYSIWYG input field.
		 */
		public function generate_wysiwyg_html($key, $data) {
			$html = '';

			$id = str_replace('-', '', $key);
			$class = array_key_exists('class', $data) ? $data['class'] : '';
			$css = array_key_exists('css', $data) ? ('<style>' . $data['css'] . '</style>') : '';
			$name = "{$this->plugin_id}{$this->id}_{$key}";
			$title = array_key_exists('title', $data) ? $data['title'] : '';
			$value = array_key_exists($key, $this->settings) ? esc_attr( $this->settings[$key] ) : '';
			$description = array_key_exists('description', $data) ? $data['description'] : '';

			ob_start();

			include "{$this->include_path}/wysiwyg.html.php";

			$html = ob_get_clean();

			return $html;
		}

		/**
		 * Get the current API URL based on our user settings. Defaults to the Sandbox URL.
		 *
		 * @since	2.0.0
		 * @return	string
		 * @uses 	get_option('woocommerce_currency')
		 * @used-by	SplitOff_Plugin_Merchant::__construct()
		 */
		public function get_api_url() {

			$currency = get_option('woocommerce_currency');
			$target_mode = 'api_url';

			if ($currency == "USD" || $currency == "CAD") {
				$target_mode = 'api_us_url';
			}

			$api_url = $this->environments[$this->settings['testmode']][$target_mode];

			if (empty($api_url)) {
				$api_url = $this->environments['sandbox'][$target_mode];
			}

			return $api_url;
		}

		/**
		 * Get the current web URL based on our user settings. Defaults to the Sandbox URL.
		 *
		 * @since	2.0.0
		 * @return	string
		 * @uses 	get_option('woocommerce_currency')
		 * @used-by	self::render_js()
		 */
		public function get_web_url() {

			$currency = get_option('woocommerce_currency');
			$target_mode = 'web_url';

			if ($currency == "USD" || $currency == "CAD") {
				$target_mode = 'web_us_url';
			}

			$web_url = $this->environments[$this->settings['testmode']][$target_mode];

			if (empty($web_url)) {
				$web_url = $this->environments['sandbox'][$target_mode];
			}

			return $web_url;
		}

		/**
		 * Get the current static URL based on our user settings. Defaults to the Sandbox URL.
		 *
		 * @since	2.1.7
		 * @return	string
		 */
		public function get_static_url() {
			$static_url = $this->environments[$this->settings['testmode']]['static_url'];

			if (empty($static_url)) {
				$static_url = $this->environments['sandbox']['static_url'];
			}

			return $static_url;
		}

		/**
		 * Get the Merchant ID from our user settings. Uses the Sandbox account for all environments except Production.
		 *
		 * @since	2.0.0
		 * @return	string
		 * @used-by	SplitOff_Plugin_Merchant::get_authorization_header()
		 */
		public function get_merchant_id() {

			if ($this->settings['testmode'] == 'production') {
				return $this->settings['prod-id'];
			}

			return $this->settings['test-id'];
		}

		/**
		 * Get the Secret Key from our user settings. Uses the Sandbox account for all environments except Production.
		 *
		 * @since	2.0.0
		 * @return	string
		 * @used-by	SplitOff_Plugin_Merchant::get_authorization_header()
		 */
		public function get_secret_key() {
			if ($this->settings['testmode'] == 'production') {
				return $this->settings['prod-secret-key'];
			}
			return $this->settings['test-secret-key'];
		}

		/**
		 * Get the API version from our user settings.
		 *
		 * @since	2.0.0
		 * @return	string
		 * @used-by	SplitOff_Plugin_Merchant::__construct()
		 */
		public function get_api_version() {
			return $this->settings['api-version'];
		}

		/**
		 * Get API environment based on our user settings.
		 *
		 * @since 2.2.0
		 * @return string
		 */
		public function get_api_env() {
			return $this->settings['testmode'];
		}

		/**
		 * Get locale based on currency.
		 *
		 * @since 2.2.0
		 * @return string
		 */
		public function get_js_locale() {
			$locale_by_currency = array(
				'AUD' => 'en_AU',
				'CAD' => 'en_CA',
				'NZD' => 'en_NZ',
				'USD' => 'en_US',
			);
			$currency = get_option('woocommerce_currency');
			return $locale_by_currency[$currency];
		}

		/**
		 * Get whether or not Compatibility Mode is Enabled in our user settings.
		 *
		 * @since	2.1.0
		 * @return	bool
		 * @used-by	SplitOff_Plugin_Merchant::build_user_agent_header
		 */
		public function get_compatibility_mode() {
			return $this->compatibility_mode;
		}

		/**
		 * Convert the global $post object to a WC_Product instance.
		 *
		 * @since	2.0.0
		 * @global	WP_Post	$post
		 * @uses	wc_get_product()	Available as part of the WooCommerce core plugin since 2.2.0.
		 *								Also see:	WC()->product_factory->get_product()
		 *								Also see:	WC_Product_Factory::get_product()
		 * @return	WC_Product
		 * @used-by self::process_and_print_SplitOff_paragraph()
		 */
		private function get_product_from_the_post() {
			global $post;

			if (function_exists('wc_get_product')) {
				$product = wc_get_product( $post->ID );
			} else {
				$product = new WC_Product( $post->ID );
			}

			return $product;
		}

		/**
		 * Is the given product supported by the TogetherPay?
		 *
		 * Note:	Some products may not be allowed to be purchased with SplitOff unless
		 *			combined with other products to lift the cart total above the merchant's
		 *			minimum. By default, this function will not check the merchant's
		 *			minimum. Set $alone to true to check if the product can be
		 *			purchased on its own.
		 *
		 * @since	2.0.0
		 * @param	WC_Product	$product									The product in question, in the form of a WC_Product object.
		 * @param	bool		$alone										Whether to view the product on its own.
		 *																	This affects whether the minimum setting is considered.
		 * @uses	WC_Product::get_type()									Possibly available as part of the WooCommerce core plugin since 2.6.0.
		 * @uses	WC_Product::get_price()									Possibly available as part of the WooCommerce core plugin since 2.6.0.
		 * @uses	apply_filters()											Available in WordPress core since 0.17.
		 * @return	bool													Whether or not the given product is eligible for SplitOff.
		 * @used-by self::process_and_print_splitoff_paragraph()
		 */
		private function is_product_supported($product, $alone = false) {
			if (!isset($this->settings['enabled']) || $this->settings['enabled'] != 'yes') {
				return false;
			}

			if (!is_object($product)) {
				return false;
			}

			$product_type = $product->get_type();
			if (preg_match('/subscription/', $product_type)) {
				# Subscription products are not supported by SplitOff.
				return false;
			}

			# Allow other plugins to exclude SplitOff from products that would otherwise be supported.
			return (bool)apply_filters( 'splitoff_is_product_supported', true, $product, $alone );
		}

		/**
		 * Is the given product within Payment Limits?
		 *
		 *
		 * @since	2.0.0
		 * @param	WC_Product	$product									The product in question, in the form of a WC_Product object.
		 * @param	bool		$alone										Whether to view the product on its own.
		 *																	This affects whether the minimum setting is considered.
		 * @return	bool													Whether or not the given product is eligible for SplitOff.
		 * @used-by self::process_and_print_splitoff_paragraph()
		 */
		private function is_product_within_limits($product, $alone = false) {

			$price= $this->get_product_final_price($product);

			/* Check for API Failure */
			/*if( $this->settings['pay-over-time-limit-min'] == 'N/A' && $this->settings['pay-over-time-limit-max'] == 'N/A' ) {
				return false;
			}*/

			if ( $price < 0.04 /*|| $price > floatval( $this->settings['pay-over-time-limit-max'] )*/ ) {
				# Free items are not supported by SplitOff.
				# If the price exceeds the maximum for this merchant, the product is not supported.
				return false;
			}

			if ( $alone && $price < floatval( 0.01 ) ) {
				# If the product is viewed as being on its own and priced lower that the merchant's minimum, it will be considered as not supported.
				return false;
			}

			return true;
		}
		/**
		 * Get Minimum Child Product Price within the SplitOff Limit
		 *
		 *
		 * @since	2.1.2
		 * @param	$child_product_ids										The child product ids of the product.
		 *																	This affects whether the minimum setting is considered.
		 * @return	string													The minimum product variant price within limits.
		 * @used-by self::process_and_print_splitoff_paragraph()
		 */
		private function get_child_product_price_within_limits($child_product_ids) {

			$min_child_product_price = NAN;

			foreach ($child_product_ids as $child_product_id) {
				$child_product = wc_get_product($child_product_id );

				$child_product_price= $this->get_product_final_price($child_product);

				if ($this->is_price_within_limits($child_product_price)) {
					if (is_nan($min_child_product_price) || $child_product_price < $min_child_product_price) {
						$min_child_product_price = $child_product_price;
					}
				}
			}
			return $min_child_product_price;
		}
		/**
		 * Is Price within the SplitOff Limit?
		 *
		 *
		 * @since	2.1.2
		 * @param	$amount													The price to be checked.
		 * @return	bool													Whether or not the given price is ithin the SplitOff Limits.
		 * @used-by self::process_and_print_splitoff_paragraph()
		 */
		private function is_price_within_limits($amount) {

			/* Check for API Failure */

			/*if(($this->settings['pay-over-time-limit-min'] == 'N/A' && $this->settings['pay-over-time-limit-max'] == 'N/A')
				|| (empty($this->settings['pay-over-time-limit-min']) && empty($this->settings['pay-over-time-limit-max']))) {
				return false;
			}*/

			if ($amount >= 0.01 /*&& $amount >= floatval($this->settings['pay-over-time-limit-min']) && $amount <= floatval($this->settings['pay-over-time-limit-max'])*/){
				return true;
			}
			else{
				return false;
			}
		}
		/**
		 * Is the the website currency supported by the TogetherPay?
		 *
		 * Note:	Some products may not be allowed to be purchased with SplitOff unless
		 *			combined with other products to lift the cart total above the merchant's
		 *			minimum. By default, this function will not check the merchant's
		 *			minimum. Set $alone to true to check if the product can be
		 *			purchased on its own.
		 *
		 * @since	2.0.0
		 * @uses	get_option('woocommerce_currency')								Available in WooCommerce core since 2.6.0.
		 * @used-by self::process_and_print_splitoff_paragraph()
		 */
		private function is_currency_supported() {
			$store_currency = strtoupper(get_option('woocommerce_currency'));
			return in_array($store_currency, $this->supported_currencies);
		}

		/**
		 * Process the HTML for the SplitOff Modal Window
		 *
		 * @since	2.0.0-rc3
		 * @param	string	$html
		 * @return	string
		 * @uses	get_option('woocommerce_currency')	determine website currency
		 * @used-by	process_and_print_splitoff_paragraph()
		 * @used-by	render_schedule_on_cart_page()
		 * @used-by	payment_fields()
		 */
		private function apply_modal_window($html) {
			$currency				=	get_option('woocommerce_currency');

			$modal_window_asset		=	"<span style='display:none' id='modal-window-currency' currency='" . $currency . "'></span>";

			return $html . $modal_window_asset;
		}

		/**
		 * Process the HTML from one of the rich text editors and output the converted string.
		 *
		 * @since	2.0.0
		 * @param	string				$html								The HTML with replace tags such as [AMOUNT].
		 * @param	string				$output_filter
		 * @param	WC_Product|null		$product							The product for which to print instalment info.
		 * @uses	self::get_product_from_the_post()
		 * @uses	self::is_product_supported()
		 * @uses	self::apply_modal_window()
		 * @uses	wc_get_price_including_tax()							Available as part of the WooCommerce core plugin since 3.0.0.
		 * @uses	WC_Abstract_Legacy_Product::get_price_including_tax()	Possibly available as part of the WooCommerce core plugin since 2.6.0. Deprecated in 3.0.0.
		 * @uses	WC_Product::get_price()									Possibly available as part of the WooCommerce core plugin since 2.6.0.
		 * @uses	self::display_price_html()
		 * @uses	apply_filters()											Available in WordPress core since 0.17.
		 * @used-by	self::print_info_for_product_detail_page()
		 * @used-by	self::print_info_for_listed_products()
		 */
		private function process_and_print_splitoff_paragraph($html, $output_filter, $product = null) {
			if (is_null($product)) {
				$product = $this->get_product_from_the_post();
			}

			/*Check if the currency is supported*/
			if(get_option('woocommerce_currency') != get_woocommerce_currency()){
				return;
			}
			if (!$this->is_product_supported($product, true)) {
				# Don't display anything on the product page if the product is not supported when purchased on its own.
				return;
			}

			if (!$this->is_currency_supported()) {
				# Don't display anything on the product page if the website currency is not within supported currencies.
				return;
			}

			if (!$product->get_price()){
				return;
			}

			/*if( ($this->settings['pay-over-time-limit-min'] == 'N/A' && $this->settings['pay-over-time-limit-max'] == 'N/A')
				|| empty($this->settings['pay-over-time-limit-min']) && empty($this->settings['pay-over-time-limit-max'])) {
				return;
			}
			*/

			$of_or_from = 'of';
			$from_price = NAN;
			$price = NAN;

			/**
			 * Note: See also: WC_Product_Variable::get_variation_price( $min_or_max = 'min', $include_taxes = false )
			 */
			 $child_product_ids=[];
			if ($product->has_child()){
				$parent_product=$product;
				$child_product_ids = $parent_product->get_children();
			}
            else if($product->get_type()=="variation" && !$this->is_product_within_limits($product, true)){
				$parent_product = wc_get_product($product->get_parent_id());
				$child_product_ids = $parent_product->get_children();
			}

			if (count($child_product_ids) > 1) {
				if ($parent_product->is_type('variable')) {
					$min_variable_price = $parent_product->get_variation_price('min', true);
					$max_variable_price = $parent_product->get_variation_price('max', true);

					if ($this->is_price_within_limits($min_variable_price)) {
						$from_price = $min_variable_price;
					}
					elseif (!is_nan($this->get_child_product_price_within_limits($child_product_ids))) {
						$from_price = $this->get_child_product_price_within_limits($child_product_ids);
					}

					if (!is_nan($from_price) && $from_price < $max_variable_price) {
						$of_or_from = 'from';
					}
				}
				elseif (!is_nan($this->get_child_product_price_within_limits($child_product_ids))) {
					$of_or_from = 'from';
					$from_price = $this->get_child_product_price_within_limits($child_product_ids);
				}
			}

			include('WC_Gateway_SplitOff/assets.php');
			$currency =	get_option('woocommerce_currency');

			if (!empty($assets[strtolower($currency)])) {
				$region_assets = $assets[strtolower($currency)];
			} else {
				$region_assets = $assets['aud'];
			}

			$show_outside_limit = (!isset($this->settings['show-outside-limit-on-product-page']) || $this->settings['show-outside-limit-on-product-page'] == 'yes');

			if (
				$output_filter == 'splitoff_html_on_individual_product_pages' &&
				!$this->is_product_within_limits($product, true)
			) {
				if ( is_nan($from_price) && $show_outside_limit ) {
					//Individual Product Pages fallback
					/*if ($this->settings['pay-over-time-limit-min'] != 0) {
						$fallback_asset	= $region_assets['fallback_asset'];
					} else {*/
						$fallback_asset = $region_assets['fallback_asset_2'];
					//}
					$html = $fallback_asset;
					$html = str_replace(array(
						'[MIN_LIMIT]',
						'[MAX_LIMIT]'
					), array(
						$this->display_price_html( 0.01/*floatval( $this->settings['pay-over-time-limit-min'] )*/ ),
						$this->display_price_html( 100000/*floatval( $this->settings['pay-over-time-limit-max'] )*/ )
					), $html);
				}
				elseif (!is_nan($from_price))
				{
					$amount = $this->display_price_html(round($from_price/4, 2));
					$html = str_replace(array(
						'[OF_OR_FROM]',
						'[AMOUNT]'
					), array(
						$of_or_from,
						$amount
					), $html);
				}
				else {
					return;
				}
			}
			elseif (
				$output_filter == 'splitoff_html_on_product_variants' &&
				!$this->is_product_within_limits($product, true) &&
				$show_outside_limit
			) {
				if (is_nan($price)) {
					$price= $this->get_product_final_price($product);
				}

				if ($this->settings['pay-over-time-limit-min'] != 0) {
					$fallback_asset = $region_assets['product_variant_fallback_asset'];
				} else {
					$fallback_asset	= $region_assets['product_variant_fallback_asset_2'];
				}

				$html = $fallback_asset;
				$html = str_replace(array(
					'[MIN_LIMIT]',
					'[MAX_LIMIT]'
				), array(
					$this->display_price_html( floatval( $this->settings['pay-over-time-limit-min'] ) ),
					$this->display_price_html( floatval( $this->settings['pay-over-time-limit-max'] ) )
				), $html);
			}
			elseif (
				$output_filter == 'splitoff_html_on_product_thumbnails' &&
				!is_nan($from_price)
			) {
				$amount = $this->display_price_html(round($from_price/4, 2));
				$html = str_replace(array(
					'[OF_OR_FROM]',
					'[AMOUNT]'
				), array(
					$of_or_from,
					$amount
				), $html);
			}
			else{
				if (is_nan($price)) {
					$price= $this->get_product_final_price($product);
				}
				if ($this->is_price_within_limits($price)) {
					$amount = $this->display_price_html( round($price / 4, 2) );
					$html = str_replace(array(
					'[OF_OR_FROM]',
					'[AMOUNT]'
					), array(
						$of_or_from,
						$amount
					), $html);
				}
				else{
					return;
				}

			}

			# Execute shortcodes on the string after running internal replacements,
			# but before applying filters and rendering.
			$html = do_shortcode( "<p class=\"splitoff-payment-info\">{$html}</p>" );

			# Add the Modal Window to the page
			# Website Admin have no access to the Modal Window codes for data integrity reasons
			$html = $this->apply_modal_window($html);


			# Allow other plugins to maniplulate or replace the HTML echoed by this funtion.
			echo apply_filters( $output_filter, $html, $product, $price );
		}

		/**
		 * Print a paragraph of SplitOff info onto the individual product pages if enabled and the product is valid.
		 *
		 * Note:	Hooked onto the "woocommerce_single_product_summary" Action.
		 *
		 * @since	2.0.0
		 * @see		SplitOff_Plugin::__construct()							For hook attachment.
		 * @param	WC_Product|null		$product							The product for which to print instalment info.
		 * @uses	self::process_and_print_splitoff_paragraph()
		 */
		public function print_info_for_product_detail_page($product = null) {
			if (!isset($this->settings['show-info-on-product-pages'])
				|| $this->settings['show-info-on-product-pages'] != 'yes'
				|| empty($this->settings['product-pages-info-text'])) {
				# Don't display anything on product pages unless the "Payment info on individual product pages"
				# box is ticked and there is a message to display.
				return;
			}

			$this->process_and_print_splitoff_paragraph($this->settings['product-pages-info-text'], 'splitoff_html_on_individual_product_pages', $product);
		}

		/**
		 * Print a paragraph of SplitOff info onto each product item in the shop loop if enabled and the product is valid.
		 *
		 * Note:	Hooked onto the "woocommerce_after_shop_loop_item_title" Action.
		 *
		 * @since	2.0.0
		 * @see		SplitOff_Plugin::__construct()							For hook attachment.
		 * @param	WC_Product|null		$product							The product for which to print instalment info.
		 * @uses	self::process_and_print_splitoff_paragraph()
		 * @uses 	is_single()
		 * @uses 	WC_Product::is_in_stock()
		 * @used-by	self::filter_woocommerce_get_price_html()
		 * @used-by	self::filter_woocommerce_variation_price_html()
		 */
		public function print_info_for_listed_products($product = null) {
			# Product Pages

			# get the global wp_query to fetch the current product
			global $wp_query;

			# handle the Variant Product of this Product Single Page
			if (
				is_single()
				&& !empty($product)
				&& (
					(method_exists($product, 'get_parent_id') && $product->get_parent_id() == $wp_query->post->ID)
					|| (property_exists($product, 'parent_id') && $product->parent_id == $wp_query->post->ID)
				)
			) {
				if (
					isset($this->settings['show-info-on-product-variant'])
					&& $this->settings['show-info-on-product-variant'] == 'yes'
					&& $product->is_in_stock()
				) {
					$this->process_and_print_splitoff_paragraph($this->settings['product-variant-info-text'], 'splitoff_html_on_product_variants', $product);
				}
			}
			else {
				# Category Pages & Related Products
				if (isset($this->settings['show-info-on-category-pages'])
					&& $this->settings['show-info-on-category-pages'] == 'yes'
					&& !empty($this->settings['category-pages-info-text'])) {
					# Don't display anything on product items within the shop loop unless
					# the "Payment info on product listing pages" box is ticked
					# and there is a message to display.
					$this->process_and_print_splitoff_paragraph($this->settings['category-pages-info-text'], 'splitoff_html_on_product_thumbnails', $product);
				}

			}
		}

		/**
		 * Format float as currency.
		 *
		 * @since	2.0.0
		 * @param	float $price
		 * @return	string The formatted price HTML.
		 * @used-by	self::process_and_print_splitoff_paragraph()
		 * @used-by	self::render_schedule_on_cart_page()
		 */
		private function display_price_html($price) {
			if (function_exists('wc_price')) {
				return wc_price($price);
			} elseif (function_exists('woocommerce_price')) {
				return woocommerce_price($price);
			}
			return '$' . number_format($price, 2, '.', ',');
		}

		/**
		 * Instalment calculation.
		 *
		 * @since	2.0.0
		 * @see		PaymentScheduleManager::generateSchedule()	From java core infrastructure.
		 * @param	float	$order_amount						The order amount in dollars.
		 * @param	int		$number_of_payments					The number of payments. Defaults to 4.
		 * @return	array										The instalment amounts in dollars.
		 * @used-by	self::render_schedule_on_cart_page()
		 * @used-by	self::payment_fields()
		 */
		private function generate_payment_schedule($order_amount, $number_of_payments = 4) {
			$order_amount_in_cents = $order_amount * 100;
			$instalment_amount_in_cents = round($order_amount_in_cents / $number_of_payments, 0, PHP_ROUND_HALF_UP);
			$cents_left_over = $order_amount_in_cents - ($instalment_amount_in_cents * $number_of_payments);

			$schedule = array();

			for ($i = 0; $i < $number_of_payments; $i++) {
				$schedule[$i] = $instalment_amount_in_cents / 100;
			}

			$schedule[$i - 1] += $cents_left_over / 100;

			return $schedule;
		}

		/**
		 * Render SplitOff elements (logo and payment schedule) on Cart page.
		 *
		 * This is dependant on all of the following criteria being met:
		 *		- The SplitOff Payment Gateway is enabled.
		 *		- The cart total is valid and within the merchant payment limits.
		 *		- The "Payment Info on Cart Page" box is ticked and there is a message to display.
		 *		- All of the items in the cart are considered eligible to be purchased with SplitOff.
		 *
		 * Note:	Hooked onto the "woocommerce_cart_totals_after_order_total" Action.
		 *
		 * @since	2.0.0
		 * @see		SplitOff_Plugin::__construct()								For hook attachment.
		 * @uses	self::generate_payment_schedule()
		 * @uses	self::display_price_html()
		 * @uses	self::apply_modal_window()
		 * @uses	apply_filters()												Available in WordPress core since 0.17.
		 */
		public function render_schedule_on_cart_page() {
			/*Check if the currency is supported*/
			if(get_option('woocommerce_currency') != get_woocommerce_currency()){
				return;
			}
			if (!array_key_exists('enabled', $this->settings) || $this->settings['enabled'] != 'yes') {
				return;
			} else {
				$total = WC()->cart->total;
				if ($total <= 0 ) {
					return;
				}
			}

			if (!isset($this->settings['show-info-on-cart-page']) || $this->settings['show-info-on-cart-page'] != 'yes' || empty($this->settings['cart-page-info-text'])) {
				return;
			}

			foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
				$product = $cart_item['data'];
				if (!$this->is_product_supported($product)) {
					return;
				}
			}

			/* Check for API Failure */
			/*if( $this->settings['pay-over-time-limit-min'] == 'N/A' && $this->settings['pay-over-time-limit-max'] == 'N/A'
				|| empty($this->settings['pay-over-time-limit-min']) && empty($this->settings['pay-over-time-limit-max'])) {
				return;
			}
			else*/ if( $total < floatval( 0.01/*$this->settings['pay-over-time-limit-min']*/ ) || $total > floatval( 10000/*$this->settings['pay-over-time-limit-max']*/ ) ) {

				//Cart Fallback Flow
				include('WC_Gateway_SplitOff/assets.php');
				$currency =	get_option('woocommerce_currency');

				if (!empty($assets[strtolower($currency)])) {
					$region_assets		=	$assets[strtolower($currency)];
					$fallback_asset 	= 	$region_assets['fallback_asset'];
				}
				else {
					$fallback_asset 	= 	$assets['aud']['fallback_asset'];
				}

				$html = '<tr><td colspan="100%">' . $fallback_asset . '</td></tr>';

				$html = str_replace(array(
					'[MIN_LIMIT]',
					'[MAX_LIMIT]'
				), array(
					$this->display_price_html( floatval( $this->settings['pay-over-time-limit-min'] ) ),
					$this->display_price_html( floatval( $this->settings['pay-over-time-limit-max'] ) )
				), $html);
			}
			else {
				//Normal Cart Flow
				$schedule = $this->generate_payment_schedule(WC()->cart->total);
				$amount = $this->display_price_html($schedule[0]);

				$html = str_replace(array(
					'[AMOUNT]'
				), array(
					$amount
				), $this->settings['cart-page-info-text']);
			}

			# Execute shortcodes on the string before applying filters and rendering it.
			$html = do_shortcode( $html );

			# Add the Modal Window to the page
			# Website Admin have no access to the Modal Window codes for data integrity reasons
			$html = $this->apply_modal_window($html);

			# Allow other plugins to maniplulate or replace the HTML echoed by this funtion.
			echo apply_filters( 'splitoff_html_on_cart_page', $html );
		}

		/**
		 * Display as a payment option on the checkout page.
		 *
		 * Note:	This overrides the method defined in the parent class.
		 *
		 * @since	2.0.0
		 * @see		WC_Payment_Gateway::payment_fields()						For the method that this overrides.
		 * @uses	WC()														Available in WooCommerce core since 2.1.0.
		 * @uses	SplitOff_Plugin_Merchant::get_payment_types_for_amount()	If configured to use API v0.
		 * @uses	get_option('woocommerce_currency')								Available in WooCommerce core since 2.6.0.
		 * @uses	self::generate_payment_schedule()
		 * @uses	self::apply_modal_window()
		 * @uses	apply_filters()												Available in WordPress core since 0.17.
		 */
		public function payment_fields() {
			$order_total = WC()->cart->total;

			/*if ($this->settings['api-version'] == 'v0') {
				$merchant = new SplitOff_Plugin_Merchant;
				$payment_types = $merchant->get_payment_types_for_amount($order_total);

				if (count($payment_types) == 0) {
					echo "Error 004 - Unfortunately, orders of this value cannot be processed through SplitOff.";
					return false;
				}
			} else*/
			if ($this->settings['api-version'] == 'v1') {
				$limit_min = 1; //floatval( $this->settings['pay-over-time-limit-min'] );
				$limit_max = 10000; //floatval( $this->settings['pay-over-time-limit-max'] );
				$store_currency = strtoupper(get_option('woocommerce_currency'));

				if ($order_total < $limit_min) {
                    # Order total is less than the minimum payment allowed for this merchant.
                    self::log("SplitOff hidden from checkout because the order total is outside merchant payment limits. ('{$order_total}' < '{$limit_min}')");
                    echo "Error 001 - Unfortunately, orders of this value cannot be processed through SplitOff.";
                    return false;
                } elseif ($order_total > $limit_max) {
                    # Order total is more than the maximum payment allowed for this merchant.
                    self::log("SplitOff hidden from checkout because the order total is outside merchant payment limits. ('{$order_total}' > '{$limit_max}')");
                    echo "Error 002 - Unfortunately, orders of this value cannot be processed through SplitOff.";
                    return false;
                } elseif (!$this->is_currency_supported()) {
                    # WooCommerce is not using AUD / NZD.
                    self::log("SplitOff hidden from checkout because the store currency is not supported. ('{$store_currency}')");
                    echo "Error 003 - Unfortunately, orders of this value cannot be processed through SplitOff.";
                    return false;
                }
			}

			$instalments = $this->generate_payment_schedule($order_total);



			# Give other plugins a chance to manipulate or replace the HTML echoed by this funtion.
			ob_start();
			include "{$this->include_path}/instalments.html.php";

			$html = ob_get_clean();

			# Add the Modal Window to the page
			# Website Admin have no access to the Modal Window codes for data integrity reasons
			$html = $this->apply_modal_window($html);

			echo apply_filters( 'splitoff_html_at_checkout', $html, $order_total, $instalments );
		}

		/**
		 * Build a return URL based on the current site URL.
		 *
		 * Note:	The SplitOff API appends a string in the following format:
		 *			"?&status=<STATUS>&token=<TOKEN>"
		 *			This can corrupt existing querystring parameters.
		 *			This is fixed by injecting the following into $extra_args: 'q' => '', which
		 *			suffixes our Return URLs with "&q=". This means we'll end up with $_GET['q'] => '?'
		 *			instead of having a question mark injected into one of our important parameters.
		 *
		 * @since	2.0.0
		 * @param	int		$p			The Post ID of the SplitOff_Quote item.
		 * @param	string	$action		The name of the action that should be taken on the return page.
		 * @param	string	$nonce		The WordPress Nonce that was generated for this URL.
		 * @param	array	$extra_args	Any additional querystring parameters to be incorporated into the Return URL.
		 * @return	string
		 * @used-by	self::process_payment()
		 * @used-by	self::override_order_creation()
		 */
		public function build_splitoff_quote_url($p, $action, $nonce, $extra_args = array()) {
			$site_url = get_home_url();
			$site_url_components = parse_url($site_url);
			$return_url = '';

			# Scheme:

			if (isset($site_url_components['scheme'])) {
				$return_url .= $site_url_components['scheme'] . '://';
			}

			# Host:

			if (isset($site_url_components['host'])) {
				$return_url .= $site_url_components['host'];
			}

			# Port:

			if (isset($site_url_components['port'])) {
				$return_url .= ':' . $site_url_components['port'];
			}

			# Path:

			if (isset($site_url_components['path'])) {
				$return_url .= rtrim($site_url_components['path'], '/') . '/';
			} else {
				$return_url .= '/';
			}

			# Query:

			$existing_args = array();

			if (isset($site_url_components['query'])) {
				parse_str($site_url_components['query'], $existing_args);
			}

			$args = array(
				'post_type' => 'splitoff_quote',
				'p' => $p,
				'action' => $action,
				'nonce' => $nonce,
			);

			$args = array_merge($existing_args, $args, $extra_args);

			$return_url .= '?' . http_build_query($args);

			# Fragment:

			if (isset($site_url_components['fragment'])) {
				$return_url .= '#' . $site_url_components['fragment'];
			}

			# Return the constructed URL.

			return $return_url;
		}

		/**
		 * Function for encoding special data for storage as WP Post Meta.
		 *
		 * @since	2.0.5
		 * @see		special_decode
		 * @param	mixed		$data
		 * @uses	serialize
		 * @uses	base64_encode
		 * @return	string
		 */
		private function special_encode($data)
		{
			return base64_encode(serialize($data));
		}

		/**
		 * Function for decoding special data from storage as WP Post Meta.
		 *
		 * @since	2.0.5
		 * @see		special_encode
		 * @param	string		$string
		 * @uses	base64_decode
		 * @uses	unserialize
		 * @return	mixed
		 */
		private function special_decode($string)
		{
			return unserialize(base64_decode($string));
		}

		/**
		 * Order Creation - Part 1 of 2: SplitOff Quote.
		 *
		 * ** For WooCommerce versions BELOW 3.6.0 **
		 *
		 * Override WooCommerce's create_order function and make our own order-quote object. We will manually
		 * convert this into a proper WC_Order object later, if the checkout completes successfully. Part of the data
		 * collected here is submitted to the SplitOff API to generate a token, the rest is persisted to the
		 * database to build the WC_Order object.
		 * Originally based on WooCommerce 2.6.8.
		 *
		 * Note:	This is only applicable for API v1, and is not used when Compatibility Mode is Enabled.
		 *
		 * Note:	This needs to follow the WC_Checkout::create_order() method very closely. In order to properly
		 * 			create the WC_Order object later, we need to make sure we're storing all of the data that will be
		 * 			needed later. If it fails, it needs to return an integer that evaluates to true in order to bypass the
		 * 			standard WC_Order creation process.
		 *
		 * Note:	Hooked onto the "woocommerce_create_order" Filter (if `WC_VERSION` < 3.6.0).
		 *
		 * @since	2.0.0
		 * @see		SplitOff_Plugin::__construct()				For hook attachment.
		 * @see		WC_Checkout::create_order()					For the method we're overriding.
		 * @see		self::create_wc_order_from_splitoff_quote()	For where the data persisted by this method is used to construct
		 *														a WC_Order object.
		 * @param	null		$null
		 * @param	WC_Checkout	$checkout						The current checkout instance.
		 * @uses	wp_insert_post()							Available in WordPress core.
		 * @uses	is_wp_error()								Available in WordPress core.
		 * @uses	WC()										Available in WooCommerce core since 2.1.0.
		 * @uses	get_option('woocommerce_currency')			Available in WooCommerce core since 2.6.0.
		 * @uses	WC_Checkout::get_posted_address_data()		Available in WooCommerce core since 2.1.0.
		 * @uses	WC_Cart::get_cart()
		 * @uses	version_compare()							Available in PHP since 4.1
		 * @uses	WC_Product::get_sku()
		 * @uses	WC_Cart::get_fees()
		 * @uses	WC_Cart::has_discount()
		 * @uses	WC_Cart::get_coupons()
		 * @uses	WC_Cart::get_coupon_discount_amount()
		 * @uses	WC_Cart::get_coupon_discount_tax_amount()
		 * @uses	wp_create_nonce()
		 * @uses	self::build_splitoff_quote_url()
		 * @uses	WC_Cart::get_tax_amount()
		 * @uses	WC_Cart::get_shipping_tax_amount()
		 * @uses	WC_Shipping::get_packages()
		 * @uses	WC_Shipping_Rate::get_meta_data()
		 * @uses	SplitOff_Plugin_Merchant::get_order_token_for_splitoff_quote()
		 * @uses	get_current_user_id()						Available in WordPress core since 2.0.3.
		 * @uses	wc_clean()									Available in WooCommerce core since 2.6.0.
		 * @uses	WC_Cart::get_cart_for_session()
		 * @uses	WC_Cart::get_cart_discount_total()
		 * @uses	WC_Cart::get_cart_discount_tax_total()
		 * @uses	WC_Cart::needs_shipping()
		 * @uses	WC_Checkout::get_posted_data()				Since 2.0.3. Available in WooCommerce core since 3.1.0.
		 * @uses	self::special_encode()						Since 2.0.5. Used to avoid storing invalid JSON, because `json_encode` escapes with slashes, but `add_metadata` calls `wp_unslash` on $meta_value.
		 * @return	int|void
		 */
		public function override_order_creation($null, $checkout) {

			# Ensure that the get_posted_data() is being used here, because the function applies 2 filters:
			# 	- apply_filters( 'woocommerce_process_checkout_<type>_field')
			# 	- apply_filters( 'woocommerce_checkout_posted_data')
			# Note: If the WooCommerce version is >= 3.0.0 and < 3.1.0,
			#       $_POST should be parsed directly. Neither method below should be used.
			$posted = method_exists($checkout, 'get_posted_data') ? $checkout->get_posted_data() : $checkout->posted;

			# Set session value of "splitoff_create_account" to check for customer signed up at checkout or not
			# For Cancel payment redirection
			WC()->session->set("splitoff_create_account", isset($posted["createaccount"])?$posted["createaccount"]:0);

			/**
			 * @since 2.1.0 Abort if Compatibility Mode is Enabled.
			 */
			if ($this->compatibility_mode) {
				self::log("Compatibility Mode is Enabled. Will not override creation of WooCommerce Order.");

				return;
			}

			# Only override the order creation if the customer is paying with SplitOff.

			if ($posted['payment_method'] != 'splitoff') {
				return;
			}

			# Only override the order creation if the gateway is configured to use API v1.

			if ($this->settings['api-version'] != 'v1') {
				return;
			}

			self::log('WC_Gateway_SplitOff::override_order_creation');

			# Create an SplitOff Quote object. We need to do this before sending the order data to the API
			# so that we can include the quote ID in the callback URLs.

			$post_id = wp_insert_post( array(
				'post_content' => 'Thank you for your order. Now redirecting you to SplitOff to complete your payment...',
				'post_title' => 'SplitOff Order',
				'post_status' => 'publish',
				'post_type' => 'splitoff_quote'
			), true );

			if (!is_wp_error( $post_id )) {
				# Log the ID and Permalink of the newly created post.

				self::log("New SplitOff Quote generated with ID:{$post_id} and permalink:\"" . get_permalink( $post_id ) . "\"");

				# Store references to the WooCommerce WC_Cart, WC_Shipping and WC_Session objects.

				$cart = WC()->cart;
				$shipping = WC()->shipping;
				$session = WC()->session;

				# Define the array for the data we will send to the SplitOff API.

				$data = array();

				# Total amount.

				$data['amount'] = array(
				    'amount' => number_format((!empty($cart->total)?$cart->total:0), 2, '.', ''),
					'currency' => $this->check_null(get_option('woocommerce_currency'))
				);

				# Billing address.

				$billing_address_raw = array();
				$billing_address_encoded = array();
				if ( $checkout->checkout_fields['billing'] ) {
					foreach ( array_keys( $checkout->checkout_fields['billing'] ) as $field ) {
						$field_name = str_replace( 'billing_', '', $field );
						$billing_address_raw[ $field_name ] = $checkout->get_posted_address_data( $field_name );
						$billing_address_encoded[ $field_name ] = $this->special_encode($billing_address_raw[ $field_name ]);
					}
				}

				$data['billing'] = array(
				    'name' => $this->check_null($billing_address_raw['first_name']) . ' ' . $this->check_null($billing_address_raw['last_name']),
				    'line1' => $this->check_null($billing_address_raw['address_1']),
				    'line2' => $this->check_null($billing_address_raw['address_2']),
				    'suburb' => $this->check_null($billing_address_raw['city']),
				    'state' => $this->check_null($billing_address_raw['state']),
				    'postcode' => $this->check_null($billing_address_raw['postcode']),
				    'countryCode' => $this->check_null($billing_address_raw['country']),
				    'phone' => $this->check_null($billing_address_raw['phone'])
				);

				# Shipping address.

				$shipping_address_raw = array();
				$shipping_address_encoded = array();
				if ( $checkout->checkout_fields['shipping'] ) {
					foreach ( array_keys( $checkout->checkout_fields['shipping'] ) as $field ) {
						$field_name = str_replace( 'shipping_', '', $field );
						$shipping_address_raw[ $field_name ] = $checkout->get_posted_address_data( $field_name, 'shipping' );
						$shipping_address_encoded[ $field_name ] = $this->special_encode($shipping_address_raw[ $field_name ]);
					}
				}

				$data['shipping'] = array(
				    'name' => $this->check_null($shipping_address_raw['first_name']) . ' ' . $this->check_null($shipping_address_raw['last_name']),
				    'line1' => $this->check_null($shipping_address_raw['address_1']),
				    'line2' => $this->check_null($shipping_address_raw['address_2']),
				    'suburb' => $this->check_null($shipping_address_raw['city']),
				    'state' => $this->check_null($shipping_address_raw['state']),
				    'postcode' => $this->check_null($shipping_address_raw['postcode']),
				    'countryCode' => $this->check_null($shipping_address_raw['country']),
				);

				if (!empty($shipping_address_raw['phone'])){
				    $data['shipping']['phone'] = $this->check_null($shipping_address_raw['phone']);
				}

				# Consumer.

				$data['consumer'] = array(
    				'phoneNumber' => $this->check_null($billing_address_raw['phone']),
    				'givenNames' => $this->check_null($billing_address_raw['first_name']),
    				'surname' => $this->check_null($billing_address_raw['last_name']),
    				'email' => $this->check_null($billing_address_raw['email'])
				);

				# Cart items.

				$data['items'] = array(); # Store data for the SplitOff API.
				$cart_items = array(); # Store data to build a WC_Order object later.

				foreach ($cart->get_cart() as $cart_item_key => $values) {
					$product = $values['data'];

					if (version_compare( WC_VERSION, '3.0.0', '>=' )) {
						$cart_items[$cart_item_key] = array(
							'props' => array(
								'quantity'     => $values['quantity'],
								'variation'    => $this->special_encode($values['variation']),
								'subtotal'     => $values['line_subtotal'],
								'total'        => $values['line_total'],
								'subtotal_tax' => $values['line_subtotal_tax'],
								'total_tax'    => $values['line_tax'],
								'taxes'        => $values['line_tax_data']
							)
						);
						if ($product) {
							$cart_items[$cart_item_key]['id'] = $product->get_id();

							$cart_items[$cart_item_key]['props'] = array_merge($cart_items[$cart_item_key]['props'], array(
								'name'         => $this->special_encode($product->get_name()),
								'tax_class'    => $this->special_encode($product->get_tax_class()),
								'product_id'   => $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id(),
								'variation_id' => $product->is_type( 'variation' ) ? $product->get_id() : 0
							));
						}

						# As well as storing the expected data fields above,
						# also grab any additional custom data fields that may
						# have been attached to the cart items by third-party plugins.

						foreach ( $values as $values_array_key => $values_array_items ) {
							if ( !in_array( $values_array_key, array('key', 'variation_id', 'variation', 'quantity', 'data_hash', 'line_tax_data', 'line_subtotal', 'line_subtotal_tax', 'line_total', 'line_tax', 'data') ) ) {
								$cart_items[$cart_item_key][$values_array_key] = $this->special_encode($values_array_items);
							}
						}
					} else {
						$cart_items[$cart_item_key] = array(
							'class' => $this->special_encode(get_class($product)),
							'id' => $product->id,
							'quantity' => $values['quantity'],
							'variation' => $this->special_encode($values['variation']),
							'totals' => array(
								'subtotal' => $values['line_subtotal'],
								'subtotal_tax' => $values['line_subtotal_tax'],
								'total' => $values['line_total'],
								'tax' => $values['line_tax'],
								'tax_data' => $values['line_tax_data'] # Since WooCommerce 2.2
							)
						);
					}

					$price=(($values['line_subtotal'] + $values['line_subtotal_tax']) / $values['quantity']);
					$data['items'][] = array(
					    'name' => $this->check_null($product->post->post_title),
					    'sku' => $this->check_null($product->get_sku()),
					    'quantity' => $this->check_null($values['quantity'],0),
						'price' => array(
						    'amount' => number_format((!empty($price)?$price:0), 2, '.', ''),
						    'currency' => $this->check_null(get_option('woocommerce_currency'))
						)
					);

				}

				# Fees.

				$cart_fees = array();

				foreach ( $cart->get_fees() as $fee_key => $fee ) {
					$cart_fees[$fee_key] = $this->special_encode($fee);
				}

				# Discounts.

				if ($cart->has_discount()) {
					# The total is stored in $cart->get_total_discount(), but we should also be able to get a list.
					$data['discounts'] = array();
					foreach ($cart->coupon_discount_amounts as $code => $amount) {
						$data['discounts'][] = array(
							'displayName' => $code,
							'amount' => array(
							    'amount' => number_format((!empty($amount)?$amount:0), 2, '.', ''),
							    'currency' => $this->check_null(get_option('woocommerce_currency'))
							)
						);
					}
				}

				# Coupons.

				$cart_coupons = array();
				foreach ($cart->get_coupons() as $code => $coupon) {
					$cart_coupons[$code] = array(
						'discount_amount' => $cart->get_coupon_discount_amount($code),
						'discount_tax_amount' => $cart->get_coupon_discount_tax_amount($code),
						'coupon' => $this->special_encode($coupon)
					);
				}

				# Merchant callback URLs.

				$splitoff_fe_confirm_nonce = wp_create_nonce( "splitoff_fe_confirm_nonce-{$post_id}" );
				$splitoff_fe_cancel_nonce = wp_create_nonce( "splitoff_fe_cancel_nonce-{$post_id}" );

				$fe_confirm_url = $this->build_splitoff_quote_url($post_id, 'fe-confirm', $splitoff_fe_confirm_nonce, array('q' => ''));
				$fe_cancel_url = $this->build_splitoff_quote_url($post_id, 'fe-cancel', $splitoff_fe_cancel_nonce, array('q' => ''));

				$data['merchant'] = array(
					'redirectConfirmUrl' => $fe_confirm_url,
					'redirectCancelUrl' => $fe_cancel_url
				);

				# Taxes.

				$tax_total=$cart->tax_total + $cart->shipping_tax_total;
				$data['taxAmount'] = array(
				    'amount' => number_format((!empty($tax_total)?$tax_total:0), 2, '.', ''),
				    'currency' => $this->check_null(get_option('woocommerce_currency'))
				);

				$cart_taxes = array();
				foreach (array_keys($cart->taxes + $cart->shipping_taxes) as $tax_rate_id) {
					if ($tax_rate_id && $tax_rate_id !== apply_filters( 'woocommerce_cart_remove_taxes_zero_rate_id', 'zero-rated' )) {
						$cart_taxes[$tax_rate_id] = array(
							'tax_amount' => $cart->get_tax_amount($tax_rate_id),
							'shipping_tax_amount' => $cart->get_shipping_tax_amount($tax_rate_id)
						);
					}
				}

				# Shipping costs.
				$shipping_total=$cart->shipping_total + $cart->shipping_tax_total;
				if (!is_null($cart->shipping_total) && $cart->shipping_total > 0) {
					$data['shippingAmount'] = array(
					    'amount' => number_format((!empty($shipping_total)?$shipping_total:0), 2, '.', ''),
					    'currency' =>  $this->check_null(get_option('woocommerce_currency'))
					);
				}

				# Shipping methods.

				if (version_compare( WC_VERSION, '3.0.0', '>=' )) {
					$chosen_shipping_methods = $session->get( 'chosen_shipping_methods' );

					/**
					 * Don't send an empty shipping address object to SplitOff if shipping is not needed.
					 *
					 * @see		WC_Order::needs_shipping_address()	https://docs.woocommerce.com/wc-apidocs/source-class-WC_Order.html#1243-1266
					 */
					$methods_without_shipping = apply_filters( 'woocommerce_order_hide_shipping_address', array('local_pickup') );
					$needs_address = false;

					if (!empty($chosen_shipping_methods)) {
						foreach ($chosen_shipping_methods as $shiping_method_id) {
							$shipping_method_name = current(explode(':', $shiping_method_id));
							if (!in_array($shipping_method_name, $methods_without_shipping)) {
								$needs_address = true;
								break;
							}
						}
					}

					if (!$needs_address) {
						unset($data['shipping']);
					}
				} else {
					/**
					 * Don't send an empty shipping address object to SplitOff if shipping is not needed.
					 * Note that prior to WooCommerce 3.0, this only prevents the empty object from being sent,
					 * it doesn't care if it was needed or not.
					 */
					$needs_address = false;

					if (array_key_exists('shipping', $data) && is_array($data['shipping']) && !empty($data['shipping'])) {
						foreach ($data['shipping'] as $field_name => $field_value) {
							if (!empty($field_value)) {
								$needs_address = true;
								break;
							}
						}
					}

					if (!$needs_address) {
						unset($data['shipping']);
					}
				}

				# Shipping packages.

				$shipping_packages = array();

				foreach ($shipping->get_packages() as $package_key => $package) {
					if (isset($package['rates'][$checkout->shipping_methods[$package_key]])) {
						$shipping_rate = $package['rates'][$checkout->shipping_methods[$package_key]];
						$package_metadata = $shipping_rate->get_meta_data();

						$shipping_packages[$package_key] = array();
						if (version_compare( WC_VERSION, '3.0.0', '>=' )) {
							$shipping_packages[$package_key]['package'] = $this->special_encode($package);
						} else {
							$shipping_packages[$package_key]['id'] = $shipping_rate->id;
							$shipping_packages[$package_key]['label'] = $this->special_encode($shipping_rate->label);
							$shipping_packages[$package_key]['cost'] = $shipping_rate->cost;
							$shipping_packages[$package_key]['taxes'] = $shipping_rate->taxes;
							$shipping_packages[$package_key]['method_id'] = $shipping_rate->method_id;
						}
						$shipping_packages[$package_key]['package_metadata'] = $this->special_encode($package_metadata);
					}
				}

				# Send the order data to SplitOff to get a token.

				$merchant = new SplitOff_Plugin_Merchant;
				$response_obj = $merchant->get_order_token_for_splitoff_quote($data);

				if ($response_obj !== false) {
					self::log("WP_Post #{$post_id} given SplitOff Order token: {$response_obj['token']}");

					# Generate a nonce for the preauth URL.
					$splitoff_preauth_nonce = wp_create_nonce( "splitoff_preauth_nonce-{$post_id}" );

					# Add the meta data to the SplitOff_Quote post record.
					add_post_meta( $post_id, 'status', 'pending' );
					add_post_meta( $post_id, 'token', $response_obj->token );
					add_post_meta( $post_id, 'token_expiry', $response_obj->expires ); # E.g.: "2016-05-10T13:14:01Z"
					add_post_meta( $post_id, 'customer_id', apply_filters( 'woocommerce_checkout_customer_id', get_current_user_id() ) ); # WC_Checkout::$customer_id is private. See WC_Checkout::process_checkout() for how it populates this property.
					add_post_meta( $post_id, 'cart_hash', md5( json_encode( wc_clean( $cart->get_cart_for_session() ) ) . $cart->total ) );
					add_post_meta( $post_id, 'cart_shipping_total', $cart->shipping_total );
					add_post_meta( $post_id, 'cart_shipping_tax_total', $cart->shipping_tax_total );
					add_post_meta( $post_id, 'cart_discount_total', $cart->get_cart_discount_total() );
					add_post_meta( $post_id, 'cart_discount_tax_total', $cart->get_cart_discount_tax_total() );
					add_post_meta( $post_id, 'cart_tax_total', $cart->tax_total );
					add_post_meta( $post_id, 'cart_total', $cart->total );
					add_post_meta( $post_id, 'cart_items', json_encode($cart_items) );
					add_post_meta( $post_id, 'cart_fees', json_encode($cart_fees) );
					add_post_meta( $post_id, 'cart_coupons', json_encode($cart_coupons) );
					add_post_meta( $post_id, 'cart_taxes', json_encode($cart_taxes) );
					add_post_meta( $post_id, 'cart_needs_shipping', (bool)$cart->needs_shipping() );
					if (version_compare( WC_VERSION, '3.0.0', '>=' )) {
						add_post_meta( $post_id, 'chosen_shipping_methods', json_encode($chosen_shipping_methods) );
					}
					add_post_meta( $post_id, 'shipping_packages', json_encode($shipping_packages) );
					add_post_meta( $post_id, 'billing_address', json_encode($billing_address_encoded) );
					add_post_meta( $post_id, 'shipping_address', json_encode($shipping_address_encoded) );
					add_post_meta( $post_id, 'api_data', json_encode($data) );

					# Store the Checkout Posted Data within a Post Meta to run the woocommerce_checkout_order_processed hooks
					add_post_meta( $post_id, 'posted', json_encode($posted) );

					add_post_meta( $post_id, 'splitoff_preauth_nonce', $splitoff_preauth_nonce );
					add_post_meta( $post_id, 'splitoff_fe_confirm_nonce', $splitoff_fe_confirm_nonce );
					add_post_meta( $post_id, 'splitoff_fe_cancel_nonce', $splitoff_fe_cancel_nonce );

					# Return the ID of the SplitOff_Quote if you want
					# to let WooCommerce trigger the payment process.

					//return $post_id;

					# Or, execute this ourselves to skip
					# the action/filter hooks, including:
					# - "woocommerce_checkout_order_processed"
					# - "woocommerce_payment_successful_result"

					$this->process_payment($post_id);
				} else {
					# SplitOff didn't give us a token for the order.
					# Mark the quote as failed.
					add_post_meta( $post_id, 'status', 'failed' );

					# Log the error and return a truthy integer (otherwise WooCommerce will not bypass the standard order creation process).
					self::log("WC_Gateway_SplitOff::override_order_creation() returned -2 (SplitOff did not provide a token for this order.)");
					self::log("Error API Payload: " . json_encode($data));
					return -2;
				}
			} else {
				# The SplitOff_Quote post could not be created.
				# Log the error and return a truthy integer (otherwise WooCommerce will not bypass the standard order creation process).
				$errors_str = implode($post_id->get_error_messages(), ' ');
				self::log("WC_Gateway_SplitOff::override_order_creation() returned -1 (Could not create \"splitoff_quote\" post. WordPress threw error(s): {$errors_str})");
				return -1;
			}
		}

		/**
		 * Order Creation - Part 1 of 2: SplitOff Quote.
		 *
		 * ** For WooCommerce versions 3.6.0 and above **
		 *
		 * Override WooCommerce's create_order function and make our own order-quote object. We will manually
		 * convert this into a proper WC_Order object later, if the checkout completes successfully. Part of the data
		 * collected here is submitted to the SplitOff API to generate a token, the rest is persisted to the
		 * database to build the WC_Order object.
		 *
		 * Originally based on WooCommerce 3.6.5.
		 *
		 * Note:	This is only applicable for API v1, and is not used when Compatibility Mode is Enabled.
		 *
		 * Note:	This needs to follow the WC_Checkout::create_order() method very closely. In order to properly
		 * 			create the WC_Order object later, we need to make sure we're storing all of the data that will be
		 * 			needed later. If it fails, it needs to return an integer that evaluates to true in order to bypass the
		 * 			standard WC_Order creation process.
		 *
		 * Note:	Hooked onto the "woocommerce_create_order" Filter (if `WC_VERSION` >= 3.6.0).
		 *
		 * @since	2.1.0
		 * @see		SplitOff_Plugin::__construct()					For hook attachment.
		 * @see		WC_Checkout::create_order()						For the method we're overriding.
		 * @see		self::create_wc_order_from_splitoff_quote_3_6()	For where the data persisted by this method is used to construct
		 *															a WC_Order object.
		 * @param	null		$null
		 * @param	WC_Checkout	$checkout							The current checkout instance.
		 * @uses	wp_insert_post
		 * @uses	self::special_encode()							Used to persist serialised objects as meta data safely.
		 * @return	int|WP_Error
		 */
		public function override_order_creation_3_6($null, $checkout) {

			# Get posted data.

			$data = $checkout->get_posted_data();

			# Set session value of "splitoff_create_account" to check for customer signed up at checkout or not
			WC()->session->set("splitoff_create_account",(isset($data["createaccount"])?$data["createaccount"]:0));

			/**
			 * @since 2.1.0 Abort if Compatibility Mode is Enabled.
			 */
			if ($this->compatibility_mode) {
				self::log("Compatibility Mode is Enabled. Will not override creation of WooCommerce Order.");

				return;
			}

			# Only override the order creation if the customer is paying with SplitOff.

			if ($data['payment_method'] != 'splitoff') {
				return;
			}

			# Only override the order creation if the gateway is configured to use API v1.

			if ($this->settings['api-version'] != 'v1') {
				return;
			}

			self::log('WC_Gateway_SplitOff::override_order_creation_3_6');

			# Create an SplitOff Quote object. We need to do this before sending the order data to the API
			# so that we can include the quote ID in the callback URLs.

			$post_id = wp_insert_post( array(
				'post_content' => 'Redirecting to SplitOff to complete payment...',
				'post_title' => 'SplitOff Order',
				'post_status' => 'publish',
				'post_type' => 'splitoff_quote'
			), true );

			if (!is_wp_error( $post_id )) {
				# Log the ID and Permalink of the newly created post.

				self::log("New SplitOff Quote generated with ID:{$post_id} and permalink:\"" . get_permalink( $post_id ) . "\"");

				# Collect data from the cart, session and checkout.
				# This data will be used by SplitOff to generate an order token,
				# also persisted to the database so that a WC_Order can be created
				# later if payment can be captured successfully.

				$cart = WC()->cart;

				$cart_hash = $cart->get_cart_hash();
				$available_gateways = WC()->payment_gateways->get_available_payment_gateways();

				$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods' );
				$shipping_packages = WC()->shipping()->get_packages();

				$customer_id = apply_filters( 'woocommerce_checkout_customer_id', get_current_user_id() );
				$order_vat_exempt = ( $cart->get_customer()->get_is_vat_exempt() ? 'yes' : 'no' );
				$currency = $this->check_null(get_option('woocommerce_currency'));
				$prices_include_tax = ( get_option( 'woocommerce_prices_include_tax' ) === 'yes' );
				$customer_ip_address = WC_Geolocation::get_ip_address();
				$customer_user_agent = wc_get_user_agent();
				$customer_note = ( isset( $data['order_comments'] ) ? $data['order_comments'] : '' );
				$payment_method = ( isset( $available_gateways[ $data['payment_method'] ] ) ? $available_gateways[ $data['payment_method'] ] : $data['payment_method'] );
				$shipping_total = $cart->get_shipping_total();
				$discount_total = $cart->get_discount_total();
				$discount_tax = $cart->get_discount_tax();
				$cart_tax = $cart->get_cart_contents_tax() + $cart->get_fee_tax();
				$shipping_tax = $cart->get_shipping_tax();
				$total = $cart->get_total( 'edit' );

				# Define the array for the data we will send to the SplitOff API.

				$splitoff_api_request_body_obj = array();

				# Total Amount

				$splitoff_api_request_body_obj['amount'] = array(
				'amount' => number_format((!empty($total)?$total:0), 2, '.', ''),
					'currency' => $currency
				);

				# Consumer

				$splitoff_api_request_body_obj['consumer'] = array(
				'phoneNumber' => $this->check_null($data['billing_phone']),
				'givenNames' => $this->check_null($data['billing_first_name']),
				'surname' => $this->check_null($data['billing_last_name']),
				'email' => $this->check_null($data['billing_email'])
				);

				# Billing Contact

				if (!empty($data['billing_first_name']) && !empty($data['billing_last_name'])) {
					$name = $data['billing_first_name'] . ' ' . $data['billing_last_name'];
				} elseif (!empty($data['billing_first_name'])) {
					$name = $data['billing_first_name'];
				} elseif (!empty($data['billing_last_name'])) {
					$name = $data['billing_last_name'];
				} else {
					$name = '';
				}
				$splitoff_api_request_body_obj['billing'] = array(
					'name' => $name,
				    'line1' => $this->check_null($data['billing_address_1']),
				    'line2' => $this->check_null($data['billing_address_2']),
				    'suburb' => $this->check_null($data['billing_city']),
				    'state' => $this->check_null($data['billing_state']),
				    'postcode' => $this->check_null($data['billing_postcode']),
				    'countryCode' => $this->check_null($data['billing_country']),
				    'phoneNumber' => $this->check_null($data['billing_phone'])
				);

				# Shipping Contact - Only if required (See WC_Order::needs_shipping_address)

				$methods_without_shipping_arr = apply_filters( 'woocommerce_order_hide_shipping_address', array('local_pickup') );
				$shipping_address_is_required = false;

				if (!empty($chosen_shipping_methods)) {
					foreach ($chosen_shipping_methods as $shipping_method_id) {
						$shipping_method_name = current(explode(':', $shipping_method_id));
						if (!in_array($shipping_method_name, $methods_without_shipping_arr)) {
							$shipping_address_is_required = true;
							break;
						}
					}
				}

				if ($shipping_address_is_required) {
					if (!empty($data['shipping_first_name']) && !empty($data['shipping_last_name'])) {
						$name = $data['shipping_first_name'] . ' ' . $data['shipping_last_name'];
					} elseif (!empty($data['shipping_first_name'])) {
						$name = $data['shipping_first_name'];
					} elseif (!empty($data['shipping_last_name'])) {
						$name = $data['shipping_last_name'];
					} else {
						$name = '';
					}

					/*
					* Adding a check for shipping address details as for virtual products session returns
					* previously selected "chosen_shipping_methods" and this block throws error
					* as shipping address is not actually required.
					*/
					if($name !="" && $data['shipping_address_1'] != ""){
						$splitoff_api_request_body_obj['shipping'] = array(
						'name' => $name,
						    'line1' => $this->check_null($data['shipping_address_1']),
						    'line2' => $this->check_null($data['shipping_address_2']),
						    'suburb' => $this->check_null($data['shipping_city']),
						    'state' => $this->check_null($data['shipping_state']),
						    'postcode' => $this->check_null($data['shipping_postcode']),
						    'countryCode' => $this->check_null($data['shipping_country'])
						);
					}
				}

				# Courier

				/**
				 * We can get the name of the shipping method, but it probably isn't actually the courier.
				 * Nothing is shipped at the time of placing the order.
				 *
				 * @todo Hook into the Order Status Update process and send an Update Shipping Courier call
				 *       with a current timestamp as the `shippedAt` attribute when an SplitOff order is
				 *       progressed from Processing to Completed.
				 */

				# Items

				$cart_items_arr = $cart->get_cart();

                foreach ($cart_items_arr as $cart_item_key => $values) {
					$product = $values['data'];


                    if (!array_key_exists('items', $splitoff_api_request_body_obj)) {
						$splitoff_api_request_body_obj['items'] = array();
					}
					$price=$product->get_price();
                    $product_id = $values['product_id'];

                    $product   = wc_get_product( $product_id );
                    $image_id  = $product->get_image_id();
                    $image_url = wp_get_attachment_image_url( $image_id, 'full' );

                    $splitoff_api_request_body_obj['items'][] = array(
					    'name' => $this->check_null($product->get_name()),
					        'sku' => $this->check_null($product->get_sku()),
					    'quantity' => $this->check_null($values['quantity'],0),
						'price' => array(
						    'amount' => number_format((!empty($price)?$price:0), 2, '.', ''),
						    'currency' => $this->check_null(get_option('woocommerce_currency'))
						),
                        'imageUrl' => $image_url,
                        'pageUrl' => $product->get_permalink()
					);
                }

				# Discounts

				if ($cart->has_discount()) {
					$splitoff_api_request_body_obj['discounts'] = array();

					foreach ($cart->get_coupons() as $code => $coupon) {
						$discount_amount = $cart->get_coupon_discount_amount($code, false);

						$splitoff_api_request_body_obj['discounts'][] = array(
						    'displayName' => $this->check_null($code),
							'amount' => array(
							    'amount' => number_format((!empty($discount_amount)?$discount_amount:0), 2, '.', ''),
							    'currency' => $this->check_null(get_option('woocommerce_currency'))
							)
						);
					}
				}

				# Merchant Redirect URLs

				$splitoff_fe_confirm_nonce = wp_create_nonce( "splitoff_fe_confirm_nonce-{$post_id}" );
				$splitoff_fe_cancel_nonce = wp_create_nonce( "splitoff_fe_cancel_nonce-{$post_id}" );

				$fe_confirm_url = $this->build_splitoff_quote_url($post_id, 'fe-confirm', $splitoff_fe_confirm_nonce);
				$fe_cancel_url = $this->build_splitoff_quote_url($post_id, 'fe-cancel', $splitoff_fe_cancel_nonce);

				$splitoff_api_request_body_obj['merchant'] = array(
					'redirectConfirmUrl' => $fe_confirm_url,
					'redirectCancelUrl' => $fe_cancel_url
				);

				# Merchant Order Reference

				$splitoff_api_request_body_obj['merchantReference'] = (string)$post_id;

				# Tax Amount

				$total_tax=$cart->get_total_tax();
				$splitoff_api_request_body_obj['taxAmount'] = array(
				    'amount' => number_format((!empty($total_tax)?$total_tax:0), 2, '.', ''),
				'currency' => $this->check_null(get_option('woocommerce_currency'))
				);

				# Shipping Amount
				$total_shipping=$cart->get_shipping_total();
				$splitoff_api_request_body_obj['shippingAmount'] = array(
				    'amount' => number_format((!empty($total_shipping)?$total_shipping:0), 2, '.', ''),
					'currency' => $this->check_null(get_option('woocommerce_currency'))
				);


				# Send the order data to SplitOff to get a token.

				$merchant = new SplitOff_Plugin_Merchant;
				$response_obj = $merchant->get_order_token_for_splitoff_quote($splitoff_api_request_body_obj);

                self::log('OBJ: ' . print_r($response_obj));

				if ($response_obj !== false) {
					self::log("WP_Post #{$post_id} given SplitOff Order token: {$response_obj->token}");

					# Generate a nonce for the preauth URL.
					$splitoff_preauth_nonce = wp_create_nonce( "splitoff_preauth_nonce-{$post_id}" );

					# Add the meta data to the SplitOff_Quote post record.

					add_post_meta( $post_id, 'status', 'pending' );

					add_post_meta( $post_id, 'created_via', 'override_order_creation_3_6' );

					add_post_meta( $post_id, 'token', $response_obj->token );
					add_post_meta( $post_id, 'token_expiry', $response_obj->expires );

					add_post_meta( $post_id, 'posted', $this->special_encode($data) );
					add_post_meta( $post_id, 'cart', $this->special_encode($cart) );

					add_post_meta( $post_id, 'cart_hash', $this->special_encode($cart_hash) );

					add_post_meta( $post_id, 'chosen_shipping_methods', $this->special_encode($chosen_shipping_methods) );
					add_post_meta( $post_id, 'shipping_packages', $this->special_encode($shipping_packages) );

					add_post_meta( $post_id, 'customer_id', $this->special_encode($customer_id) );
					add_post_meta( $post_id, 'order_vat_exempt', $this->special_encode($order_vat_exempt) );
					add_post_meta( $post_id, 'currency', $this->special_encode($currency) );
					add_post_meta( $post_id, 'prices_include_tax', $this->special_encode($prices_include_tax) );
					add_post_meta( $post_id, 'customer_ip_address', $this->special_encode($customer_ip_address) );
					add_post_meta( $post_id, 'customer_user_agent', $this->special_encode($customer_user_agent) );
					add_post_meta( $post_id, 'customer_note', $this->special_encode($customer_note) );
					add_post_meta( $post_id, 'payment_method', $this->special_encode($payment_method) );
					add_post_meta( $post_id, 'shipping_total', $this->special_encode($shipping_total) );
					add_post_meta( $post_id, 'discount_total', $this->special_encode($discount_total) );
					add_post_meta( $post_id, 'discount_tax', $this->special_encode($discount_tax) );
					add_post_meta( $post_id, 'cart_tax', $this->special_encode($cart_tax) );
					add_post_meta( $post_id, 'shipping_tax', $this->special_encode($shipping_tax) );
					add_post_meta( $post_id, 'total', $this->special_encode($total) );

					add_post_meta( $post_id, 'splitoff_preauth_nonce', $splitoff_preauth_nonce );
					add_post_meta( $post_id, 'splitoff_fe_confirm_nonce', $splitoff_fe_confirm_nonce );
					add_post_meta( $post_id, 'splitoff_fe_cancel_nonce', $splitoff_fe_cancel_nonce );

					$this->process_payment($post_id);
				} else {
					# SplitOff didn't give us a token for the order.
					# Mark the quote as failed.
					add_post_meta( $post_id, 'status', 'failed' );

					# Log the error and return a truthy integer (otherwise WooCommerce will not bypass the standard order creation process).
					self::log("WC_Gateway_SplitOff::override_order_creation_3_6() returned -2 (SplitOff did not provide a token for this order.) !!!!!");
					//self::log("Error API Payload: " . json_encode($splitoff_api_request_body_obj));
					return -2;
				}
			} else {
				# The SplitOff_Quote post could not be created.
				# Log the error and return a truthy integer (otherwise WooCommerce will not bypass the standard order creation process).
				$errors_str = implode($post_id->get_error_messages(), ' ');
				self::log("WC_Gateway_SplitOff::override_order_creation_3_6() returned -1 (Could not create \"splitoff_quote\" post. WordPress threw error(s): {$errors_str})");
				return -1;
			}
		}

		/**
		 * Order Creation - Part 2 of 2: WooCommerce Order.
		 *
		 * ** For WooCommerce versions BELOW 3.6.0 **
		 *
		 * Creates an order, originally based on WooCommerce 2.6.8.
		 * This method must only be called if the payment is approved
		 * and the capture is successful.
		 *
		 * Note:	This is only applicable for API v1.
		 *
		 * @since	2.0.0
		 * @see		self::override_order_creation()			For where the data used by this method was persisted to the database.
		 * @param	int					$post_id			The ID of the SplitOff_Quote, which will become the Merchant Order Number.
		 * @global	wpdb				$wpdb				The WordPress Database Access Abstraction Object.
		 * @uses	wc_transaction_query()					Available in WooCommerce core since 2.5.0.
		 * @uses	wp_delete_post()
		 * @uses	wc_create_order()						Available in WooCommerce core since 2.6.0.
		 * @uses	WC_Abstract_Order::add_product()		Available in WooCommerce core since 2.2.
		 * @uses	WC_Abstract_Order::add_fee()			Available in WooCommerce core.
		 * @uses	WC_Abstract_Order::add_shipping()		Available in WooCommerce core.
		 * @uses	WC_Abstract_Order::add_tax()			Available in WooCommerce core since 2.2.
		 * @uses	WC_Abstract_Order::add_coupon()			Available in WooCommerce core.
		 * @uses	WC_Abstract_Order::set_address()		Available in WooCommerce core.
		 * @uses	WC_Abstract_Order::set_prices_include_tax()		Available in WooCommerce core since 3.0.0.
		 * @uses	WC_Geolocation::get_ip_address()				Available in WooCommerce core since 2.4.0.
		 * @uses	WC_Abstract_Order::set_customer_ip_address()	Available in WooCommerce core since 3.0.0.
		 * @uses	wc_get_user_agent()								Available in WooCommerce core since 3.0.0.
		 * @uses	WC_Abstract_Order::set_customer_user_agent()	Available in WooCommerce core since 3.0.0.
		 * @uses	WC_Abstract_Order::set_payment_method	Available in WooCommerce core.
		 * @uses	WC_Abstract_Order::set_shipping_total()	Available in WooCommerce core since 3.0.0.
		 * @uses	WC_Abstract_Order::set_total			Available in WooCommerce core.
		 * @uses	WC_Abstract_Order::set_shipping_tax()	Available in WooCommerce core since 3.0.0.
		 * @uses	WC_Abstract_Order::add_order_note()		Available in WooCommerce core.
		 * @uses	WC_Abstract_Order::payment_complete()	Available in WooCommerce core.
		 * @uses	self::special_decode()					Since 2.0.5. Used to restore field values to the state they were in before we encoded them with `self::special_encode`.
		 * @return	WC_Order|WP_Error
		 * @used-by	self::confirm_splitoff_quote()
		 */
		public function create_wc_order_from_splitoff_quote($post_id) {
			global $wpdb;

			try {
				// Start transaction if available
				wc_transaction_query( 'start' );

				# Retrieve the order data from the SplitOff_Quote item.

				$token = get_post_meta( $post_id, 'token', true );
				$customer_id = get_post_meta( $post_id, 'customer_id', true );
				$cart_hash = get_post_meta( $post_id, 'cart_hash', true );
				$cart_shipping_total = (float)get_post_meta( $post_id, 'cart_shipping_total', true );
				$cart_shipping_tax_total = (float)get_post_meta( $post_id, 'cart_shipping_tax_total', true );
				$cart_discount_total = (float)get_post_meta( $post_id, 'cart_discount_total', true );
				$cart_discount_tax_total = (float)get_post_meta( $post_id, 'cart_discount_tax_total', true );
				$cart_tax_total = (float)get_post_meta( $post_id, 'cart_tax_total', true );
				$cart_total = (float)get_post_meta( $post_id, 'cart_total', true );
				$cart_items = json_decode(get_post_meta( $post_id, 'cart_items', true ), true);
				$cart_fees = json_decode(get_post_meta( $post_id, 'cart_fees', true ), false);
				$cart_coupons = json_decode(get_post_meta( $post_id, 'cart_coupons', true ), true);
				$cart_taxes = json_decode(get_post_meta( $post_id, 'cart_taxes', true ), true);
				$cart_needs_shipping = (bool)get_post_meta( $post_id, 'cart_needs_shipping', true );
				if (version_compare( WC_VERSION, '3.0.0', '>=' )) {
					$chosen_shipping_methods = json_decode(get_post_meta( $post_id, 'chosen_shipping_methods', true ), true);
				}
				$shipping_packages = json_decode(get_post_meta( $post_id, 'shipping_packages', true ), true);
				$billing_address = json_decode(get_post_meta( $post_id, 'billing_address', true ), true);
				$shipping_address = json_decode(get_post_meta( $post_id, 'shipping_address', true ), true);
				$api_data = json_decode(get_post_meta( $post_id, 'api_data', true ), true);
				$posted = json_decode(get_post_meta( $post_id, 'posted', true ), true);
				$splitoff_preauth_nonce = get_post_meta( $post_id, 'splitoff_preauth_nonce', true );
				$splitoff_fe_confirm_nonce = get_post_meta( $post_id, 'splitoff_fe_confirm_nonce', true );
				$splitoff_fe_cancel_nonce = get_post_meta( $post_id, 'splitoff_fe_cancel_nonce', true );
				$splitoff_order_id = get_post_meta( $post_id, 'splitoff_order_id', true );

				# Force-delete the SplitOff_Quote item. This will make its ID available to be used as the WC_Order ID.

				wp_delete_post( $post_id, true );

				# Create the WC_Order item.

				$order_data = array(
					'status'        => apply_filters( 'woocommerce_default_order_status', 'pending' ),
					'customer_id'   => $customer_id,
					'customer_note' => isset( $posted['order_comments'] ) ? $posted['order_comments'] : '',
					'cart_hash'     => $cart_hash,
					'created_via'   => 'checkout',
				);

				$GLOBALS['splitoff_quote_id'] = $post_id;
				$order = wc_create_order( $order_data );
				if (isset($GLOBALS['splitoff_quote_id'])) {
					unset($GLOBALS['splitoff_quote_id']);
				}

				if ( is_wp_error( $order ) ) {
					throw new Exception( sprintf( __( 'Error %d: Unable to create order1. Please try again.', 'woocommerce' ), 520 ) );
				} elseif ( false === $order ) {
					throw new Exception( sprintf( __( 'Error %d: Unable to create order2. Please try again.', 'woocommerce' ), 521 ) );
				} else {

					$order_id = $this->get_order_id($order);

					do_action( 'woocommerce_new_order', $order_id );
				}

				// Store the line items to the new/resumed order
				foreach ( $cart_items as $cart_items_key => $cart_item ) {
					if (version_compare( WC_VERSION, '3.0.0', '>=' )) {
						$values = array(
							'data' => wc_get_product($cart_item['id']),
							'quantity' => $cart_item['props']['quantity'],
							'variation' => $this->special_decode($cart_item['props']['variation']),
							'line_subtotal' => $cart_item['props']['subtotal'],
							'line_total' => $cart_item['props']['total'],
							'line_subtotal_tax' => $cart_item['props']['subtotal_tax'],
							'line_tax' => $cart_item['props']['total_tax'],
							'line_tax_data' => $cart_item['props']['taxes']
						);

						# Also reinsert any custom line item fields
						# that may have been attached by third-party plugins.

						foreach( $cart_item as $cart_item_key => $cart_item_value ) {
							if( !in_array($cart_item_key, array('id', 'props')) ) {
								$values[$cart_item_key] = $this->special_decode($cart_item_value);
							}
						}


						$item                       = apply_filters( 'woocommerce_checkout_create_order_line_item_object', new WC_Order_Item_Product(), $cart_items_key, $values, $order );
						$item->legacy_values        = $values; // @deprecated For legacy actions.
						$item->legacy_cart_item_key = $cart_items_key; // @deprecated For legacy actions.
						$item->set_props( array(
							'quantity'     => $cart_item['props']['quantity'],
							'variation'    => $this->special_decode($cart_item['props']['variation']),
							'subtotal'     => $cart_item['props']['subtotal'],
							'total'        => $cart_item['props']['total'],
							'subtotal_tax' => $cart_item['props']['subtotal_tax'],
							'total_tax'    => $cart_item['props']['total_tax'],
							'taxes'        => $cart_item['props']['taxes'],
							'name'         => $this->special_decode($cart_item['props']['name']),
							'tax_class'    => $this->special_decode($cart_item['props']['tax_class']),
							'product_id'   => $cart_item['props']['product_id'],
							'variation_id' => $cart_item['props']['variation_id']
						) );
						$item->set_backorder_meta();

						do_action( 'woocommerce_checkout_create_order_line_item', $item, $cart_items_key, $values, $order );

						// Add item to order and save.
						$order->add_item( $item );
					} else {
						$product = new $cart_item['class']( $cart_item['id'] );
						unset( $cart_item['class'] );
						unset( $cart_item['id'] );
						$cart_item['data'] = $product;

						$item_id = $order->add_product(
							$product,
							$cart_item['quantity'],
							array(
								'variation' => $this->special_decode($cart_item['variation']),
								'totals'    => $cart_item['totals']
							)
						);

						if ( ! $item_id ) {
							throw new Exception( sprintf( __( 'Error %d: Unable to create order. Please try again.', 'woocommerce' ), 525 ) );
						}

						// Allow plugins to add order item meta
						do_action( 'woocommerce_add_order_item_meta', $item_id, $cart_item, $cart_items_key );
					}
				}

				// Store fees
				foreach ( $cart_fees as $fee_key => $fee ) {
					# $fee needs to be an object, so we parsed the JSON to an object,
					# but $fee->tax_data needs to be an associative array, with numeric keys.
					# Just convert it now.
					//$fee->tax_data = (array)$fee->tax_data; # This keeps the array keys as strings. We want integers.
					$tax_data = array();
					foreach ($fee->tax_data as $key_str => $amount) {
						$tax_data[(int)$key_str] = $this->special_decode($amount);
					}
					$fee->tax_data = $tax_data;

					$item_id = $order->add_fee( $fee );

					if ( ! $item_id ) {
						throw new Exception( sprintf( __( 'Error %d: Unable to create order. Please try again.', 'woocommerce' ), 526 ) );
					}

					// Allow plugins to add order item meta to fees
					do_action( 'woocommerce_add_order_fee_meta', $order_id, $item_id, $fee, $fee_key );
				}

				// Store shipping for all packages
				foreach ( $shipping_packages as $package_key => $package_data ) {
					$package_metadata = $this->special_decode( $package_data['package_metadata'] );

					if (version_compare( WC_VERSION, '3.0.0', '>=' )) {
						$package = $this->special_decode( $package_data['package'] );

						if ( isset( $chosen_shipping_methods[ $package_key ], $package['rates'][ $chosen_shipping_methods[ $package_key ] ] ) ) {
							$shipping_rate = $package['rates'][ $chosen_shipping_methods[ $package_key ] ];
							$item = new WC_Order_Item_Shipping;
							$item->legacy_package_key = $package_key; // @deprecated For legacy actions.
							$item->set_props( array(
								'method_title' => $shipping_rate->label,
								'method_id'    => $shipping_rate->method_id,
								'instance_id'  => $shipping_rate->instance_id,
								'total'        => wc_format_decimal( $shipping_rate->cost ),
								'taxes'        => array(
									'total' => $shipping_rate->taxes,
								),
							) );

							foreach ( $package_metadata as $key => $value ) {
								$item->add_meta_data( $key, $value, true );
							}

							/**
							 * Action hook to adjust item before save.
							 * @since WooCommerce 3.0.0
							 */
							do_action( 'woocommerce_checkout_create_order_shipping_item', $item, $package_key, $package, $order );

							// Add item to order and save.
							$order->add_item( $item );
						}
					} else {
						$package = new WC_Shipping_Rate( $package_data['id'], $this->special_decode($package_data['label']), $package_data['cost'], $package_data['taxes'], $package_data['method_id'] );

						foreach ($package_metadata as $key => $value) {
							$package->add_meta_data($key, $value);
						}

						$item_id = $order->add_shipping( $package );

						if ( ! $item_id ) {
							throw new Exception( sprintf( __( 'Error %d: Unable to create order. Please try again.', 'woocommerce' ), 527 ) );
						}

						// Allows plugins to add order item meta to shipping
						do_action( 'woocommerce_add_shipping_order_item', $order_id, $item_id, $package_key );
					}
				}

				// Store tax rows
				foreach ( $cart_taxes as $tax_rate_id => $cart_tax ) {
					if ( ! $order->add_tax( $tax_rate_id, $cart_tax['tax_amount'], $cart_tax['shipping_tax_amount'] ) ) {
						throw new Exception( sprintf( __( 'Error %d: Unable to create order. Please try again.', 'woocommerce' ), 528 ) );
					}
				}

				// Store coupons
				foreach ( $cart_coupons as $code => $coupon_data ) {
					//$coupon_data['coupon'] = $this->special_decode( $coupon_data['coupon'] );
					if ( ! $order->add_coupon( $code, $coupon_data['discount_amount'], $coupon_data['discount_tax_amount'] ) ) {
						throw new Exception( sprintf( __( 'Error %d: Unable to create order. Please try again.', 'woocommerce' ), 529 ) );
					}
				}

				/**
				 * @since 2.0.3
				 * Decode the shipping & billing address fields.
				 */
				foreach($billing_address as $key => $billing_data) {
					$billing_address[$key] = $this->special_decode($billing_data);
				}
				foreach($shipping_address as $key => $shipping_data) {
					$shipping_address[$key] = $this->special_decode($shipping_data);
				}

				$order->set_address( $billing_address, 'billing' );
				$order->set_address( $shipping_address, 'shipping' );
				if (version_compare( WC_VERSION, '3.0.0', '>=' )) {
					$order->set_prices_include_tax( 'yes' === get_option( 'woocommerce_prices_include_tax' ) );
					$order->set_customer_ip_address( WC_Geolocation::get_ip_address() );
					$order->set_customer_user_agent( wc_get_user_agent() );
				}
				$order->set_payment_method( $this );
				if (version_compare( WC_VERSION, '3.0.0', '>=' )) {
					$order->set_shipping_total( $cart_shipping_total );
				} else {
					$order->set_total( $cart_shipping_total, 'shipping' );
				}
				$order->set_total( $cart_discount_total, 'cart_discount' );
				$order->set_total( $cart_discount_tax_total, 'cart_discount_tax' );
				$order->set_total( $cart_tax_total, 'tax' );
				if (version_compare( WC_VERSION, '3.0.0', '>=' )) {
					$order->set_shipping_tax( $cart_shipping_tax_total );
				} else {
					$order->set_total( $cart_shipping_tax_total, 'shipping_tax' );
				}
				$order->set_total( $cart_total );
				$order->add_order_note( __( "Payment approved. SplitOff Order ID: {$splitoff_order_id}", 'woo_splitoff') );
				$order->payment_complete( $splitoff_order_id );

				// Update user meta
				if ( $customer_id ) {
					# Can't do the following unless we can get an instance of the WC_Checkout...
					/*if ( apply_filters( 'woocommerce_checkout_update_customer_data', true, $checkout ) ) {
						foreach ( $billing_address as $key => $value ) {
							update_user_meta( $customer_id, 'billing_' . $key, $value );
						}
						if ( WC()->cart->needs_shipping() ) {
							foreach ( $shipping_address as $key => $value ) {
								update_user_meta( $customer_id, 'shipping_' . $key, $value );
							}
						}
					}
					do_action( 'woocommerce_checkout_update_user_meta', $customer_id, $posted );*/
				}

				// Let plugins add meta
				do_action( 'woocommerce_checkout_update_order_meta', $order_id, $posted );

				// If we got here, the order was created without problems!
				wc_transaction_query( 'commit' );
			} catch ( Exception $e ) {
				// There was an error adding order data!
				wc_transaction_query( 'rollback' );
				return new WP_Error( 'checkout-error', $e->getMessage() );
			}

			return $order;
		}

		/**
		 * Order Creation - Part 2 of 2: WooCommerce Order.
		 *
		 * ** For WooCommerce versions 3.6.0 and above **
		 *
		 * Creates an order, originally based on WooCommerce 3.6.5.
		 * This method must only be called if the payment is approved
		 * and the capture is successful.
		 *
		 * Note:	This is only applicable for API v1.
		 *
		 * @since	2.1.0
		 * @see		self::override_order_creation_3_6()		For where the data used by this method was persisted to the database.
		 * @param	int					$post_id			The ID of the SplitOff_Quote, which will become the Merchant Order Number.
		 * @global	wpdb				$wpdb				The WordPress Database Access Abstraction Object.
		 * @uses	self::special_decode()					Used to restore persisted meta fields to their original state.
		 * @return	WC_Order|WP_Error
		 * @used-by	self::confirm_splitoff_quote()
		 */
		public function create_wc_order_from_splitoff_quote_3_6($post_id) {

			# Get persisted data

			$checkout = WC()->checkout;
			$data = $this->special_decode(get_post_meta( $post_id, 'posted', true ));
			$cart = $this->special_decode(get_post_meta( $post_id, 'cart', true ));

			$cart_hash = $this->special_decode(get_post_meta( $post_id, 'cart_hash', true ));

			$chosen_shipping_methods = $this->special_decode(get_post_meta( $post_id, 'chosen_shipping_methods', true ));
			$shipping_packages = $this->special_decode(get_post_meta( $post_id, 'shipping_packages', true ));

			$customer_id = $this->special_decode(get_post_meta( $post_id, 'customer_id', true ));
			$order_vat_exempt = $this->special_decode(get_post_meta( $post_id, 'order_vat_exempt', true ));
			$currency = $this->special_decode(get_post_meta( $post_id, 'currency', true ));
			$prices_include_tax = $this->special_decode(get_post_meta( $post_id, 'prices_include_tax', true ));
			$customer_ip_address = $this->special_decode(get_post_meta( $post_id, 'customer_ip_address', true ));
			$customer_user_agent = $this->special_decode(get_post_meta( $post_id, 'customer_user_agent', true ));
			$customer_note = $this->special_decode(get_post_meta( $post_id, 'customer_note', true ));
			$payment_method = $this->special_decode(get_post_meta( $post_id, 'payment_method', true ));
			$shipping_total = $this->special_decode(get_post_meta( $post_id, 'shipping_total', true ));
			$discount_total = $this->special_decode(get_post_meta( $post_id, 'discount_total', true ));
			$discount_tax = $this->special_decode(get_post_meta( $post_id, 'discount_tax', true ));
			$cart_tax = $this->special_decode(get_post_meta( $post_id, 'cart_tax', true ));
			$shipping_tax = $this->special_decode(get_post_meta( $post_id, 'shipping_tax', true ));
			$total = $this->special_decode(get_post_meta( $post_id, 'total', true ));

			try {

				# Force-delete the SplitOff_Quote item. This will make its ID available to be used as the WC_Order ID.

				wp_delete_post( $post_id, true );

	            /**
	             * @see WC_Checkout::create_order
	             */

	            $order = new WC_Order();

	            $fields_prefix = array(
	                'shipping' => true,
	                'billing'  => true,
	            );

	            $shipping_fields = array(
	                'shipping_method' => true,
	                'shipping_total'  => true,
	                'shipping_tax'    => true,
	            );
	            foreach ( $data as $key => $value ) {
	                if ( is_callable( array( $order, "set_{$key}" ) ) ) {
	                    $order->{"set_{$key}"}( $value );
	                } elseif ( isset( $fields_prefix[ current( explode( '_', $key ) ) ] ) ) {
	                    if ( ! isset( $shipping_fields[ $key ] ) ) {
	                        $order->update_meta_data( '_' . $key, $value );
	                    }
	                }
	            }

	            $order->set_created_via( 'checkout' );
	            $order->set_cart_hash( $cart_hash );
	            $order->set_customer_id( $customer_id );
	            $order->add_meta_data( 'is_vat_exempt', $order_vat_exempt );
	            $order->set_currency( $currency );
	            $order->set_prices_include_tax( $prices_include_tax );
	            $order->set_customer_ip_address( $customer_ip_address );
	            $order->set_customer_user_agent( $customer_user_agent );
	            $order->set_customer_note( $customer_note );
	            $order->set_payment_method( $payment_method );
	            $order->set_shipping_total( $shipping_total );
	            $order->set_discount_total( $discount_total );
	            $order->set_discount_tax( $discount_tax );
	            $order->set_cart_tax( $cart_tax );
	            $order->set_shipping_tax( $shipping_tax );
	            $order->set_total( $total );

	            $checkout->create_order_line_items( $order, $cart );
	            $checkout->create_order_fee_lines( $order, $cart );
	            $checkout->create_order_shipping_lines( $order, $chosen_shipping_methods, $shipping_packages );
	            $checkout->create_order_tax_lines( $order, $cart );
	            $checkout->create_order_coupon_lines( $order, $cart );

	            # Store the Post ID in the superglobal array so we can use it in
	            # self::filter_woocommerce_new_order_data, which is attached to the
	            # "woocommerce_new_order_data" hook and allows us
	            # to inject data into the `wp_insert_post` call.

	            $GLOBALS['splitoff_quote_id'] = $post_id;

	            do_action( 'woocommerce_checkout_create_order', $order, $data );

	            $order_id = $order->save();

	            do_action( 'woocommerce_checkout_update_order_meta', $order_id, $data );

	            # Clear globals after use, if not already cleared.

	            if (isset($GLOBALS['splitoff_quote_id'])) {
					unset($GLOBALS['splitoff_quote_id']);
				}

	            return $order;
	        } catch ( Exception $e ) {
	            return new WP_Error( 'checkout-error', $e->getMessage() );
	        }
		}

		/**
		 * Is this a post of type "splitoff_quote"?
		 *
		 * @since	2.0.0
		 * @param	WP_Post|int	$post	The WP_Post object or ID.
		 * @return	bool				Whether or not the given post is of type "splitoff_quote".
		 */
		private function is_post_splitoff_quote($post) {
			if (is_numeric($post) && $post > 0) {
				$post = get_post( (int)$post );
			}

			if ($post instanceof WP_Post) {
				if ($post->post_type == 'splitoff_quote') {
					return true;
				}
			}

			return false;
		}

		/**
		 * Render the HTML that runs the front-end JS for launching the SplitOff lightbox.
		 *
		 * @since	2.0.0
		 * @param	string		$token					The order token to use when launching the lightbox.
		 * @param	string		$lightbox_launch_method	Optional. The method to use when launching the SplitOff lightbox.
		 *												"redirect" or "display". Defaults to "redirect".
		 * @param	array|null	$init_object			Optional. A jsonifiable object to be passed to the SplitOff.init()
		 *												JS method. Defaults to null.
		 * @uses	get_option('woocommerce_currency')
		 * @used-by	self::override_single_post_template_for_splitoff_quotes()
		 * @used-by	self::inject_preauth_html()
		 * @used-by	self::receipt_page()
		 */
		private function render_js($token, $lightbox_launch_method = 'redirect', $init_object = null) {

			# Get the Store Currency to determine the country code
			switch (get_option('woocommerce_currency')) {
				case 'USD':
					$country = "US";
					break;
				case 'CAD':
					$country = "CA";
					break;
				case 'NZD':
					$country = "NZ";
					break;
				default:
					$country = "AU";
			}

			if (empty($init_object)) {
				$init_object = 	array(
									"countryCode" => $country
								);
			}
			else {
				$init_object["countryCode"] = $country;
			}

			include "{$this->include_path}/splitoff_js_init.html.php";
		}

		/**
		 * This is called by the WooCommerce checkout via AJAX, if SplitOff was the selected payment method.
		 *
		 * Note:	This overrides the method defined in the parent class.
		 *
		 * @since	2.0.0
		 * @see		WC_Payment_Gateway::process_payment()	For the method we are overriding.
		 * @param	int|null	$order_id					The ID of the order. This would normally be the ID of a WC_Order post,
		 *													but in our case it may be the ID of an "splitoff_quote" post,
		 *													if we have overridden the order creation method.
		 * @uses	self::build_splitoff_quote_url()
		 * @uses	SplitOff_Plugin_Merchant::get_order_token_for_wc_order_in_v1
		 * @uses	wp_send_json()							Available as part of WordPress core since 3.5.0
		 * @return	array									May also render JSON and exit.
		 */
		public function process_payment($order_id = null) {
			self::log("process_payment({$order_id})");

			/*if ($this->settings['api-version'] == 'v0') {
				$order_total = WC()->cart->total;

				if( function_exists("wc_get_order") ) {
					$order = wc_get_order( $order_id );
				} else {
					$order = new WC_Order( $order_id );
				}

				$merchant = new SplitOff_Plugin_Merchant;
				$token = $merchant->get_order_token_for_wc_order($order);
				$payment_types = $merchant->get_payment_types_for_amount($order_total);

				if (count($payment_types) == 0) {
				    $order->add_order_note( __( 'Order amount: $' . number_format($order_total, 2) . ' is not supported.', 'woo_splitoff' ) );
					wc_add_notice( __( 'Unfortunately, an order of $' . number_format($order_total, 2) . ' cannot be processed through SplitOff.', 'woo_splitoff' ), 'error' );

					return array(
						'result' => 'failure',
						'redirect' => $order->get_checkout_payment_url( true )
					);
				} elseif ($token == false) {
					$order->add_order_note( __( 'Unable to generate the order token. Payment couldn\'t proceed.', 'woo_splitoff' ) );
					wc_add_notice( __( 'Sorry, there was a problem preparing your payment.', 'woo_splitoff' ), 'error' );

					return array(
						'result' => 'failure',
						'redirect' => $order->get_checkout_payment_url( true )
					);
				} else {
					update_post_meta( $order_id, '_splitoff_token', $token );
				}

				return array(
					'result' => 'success',
					'redirect' => $order->get_checkout_payment_url( true )
				);
			} else*/

			    if ($this->settings['api-version'] == 'v1') {

                    if ($this->compatibility_mode) {
                        if ( function_exists('wc_get_order') ) {
                            $order = wc_get_order( $order_id );
                        } else {
                            $order = new WC_Order( $order_id );
                        }

                        $merchant = new SplitOff_Plugin_Merchant;
                        $token = $merchant->get_order_token_for_wc_order_in_v1($order);

                        if ($token) {
                            update_post_meta( $order_id, '_splitoff_token', $token );

                            return array(
                                'result' => 'success',
                                'redirect' => $order->get_checkout_payment_url( true )
                            );
                        }

                        return array(
                            'result' => 'failure',
                            'redirect' => $order->get_checkout_payment_url( true )
                        );
                    }
                    else {
                        if ($order_id == -2) {
                            # SplitOff didn't give us a token for the order.
                            wp_send_json(array(
                                'result'	=> 'success',
                                'messages'	=> '<div class="woocommerce-error">There was a problem preparing your payment. Please try again.</div>'
                            ));
                        } elseif ($order_id == -1) {
                            # The SplitOff_Quote post could not be created.
                        } elseif ($order_id > 0) {

                            $splitoff_quote = get_post($order_id);

                            if ($this->is_post_splitoff_quote($splitoff_quote)) {

                                if(!array_key_exists('splitoff-checkout-experience',$this->settings) || (array_key_exists('splitoff-checkout-experience',$this->settings) &&  $this->settings['splitoff-checkout-experience'] == 'redirect')){
                                    $token = get_post_meta( $splitoff_quote->ID, 'token', true );
                                    switch (get_option('woocommerce_currency')) {
                                        case 'USD':
                                            $country = "us";
                                            break;
                                        case 'CAD':
                                            $country = "ca";
                                            break;
                                        case 'NZD':
                                            $country = "nz";
                                            break;
                                        default:
                                            $country = "au";
                                    }
                                    $result = array(
                                        'result'	=> 'success',
                                        'redirect'	=> $this->get_web_url().'/cart.html?orderToken='.$token . '&id=' . $this->get_merchant_id()
                                    );
                                }
                                else{
                                    $splitoff_preauth_nonce = get_post_meta( $splitoff_quote->ID, 'splitoff_preauth_nonce', true );
                                    $result = array(
                                        'result'	=> 'success',
                                        'redirect'	=> $this->build_splitoff_quote_url($splitoff_quote->ID, 'preauth', $splitoff_preauth_nonce)
                                    );
                                }
                                # Don't return $result because we're not sending
                                # this back to WooCommerce. Instead, send the
                                # response directly to the browser so that we
                                # avoid triggering the action/filter hooks:
                                # - "woocommerce_checkout_order_processed"
                                # - "woocommerce_payment_successful_result"

                                if ( is_ajax() ) {
                                    wp_send_json( $result );
                                } else {
                                    wp_redirect( $result['redirect'] );
                                    exit;
                                }
                            }
                        }
                    }
			    }

			# If all else fails, send a generic failure message.
			wp_send_json(array(
				'result'	=> 'success',
				'messages'	=> '<div class="woocommerce-error">An unexpected error has occurred. Please try again.</div>'
			));
		}

		/**
		 * If calling wc_create_order() for an SplitOff Quote, tell wp_insert_post() to reuse the ID of the quote.
		 *
		 * Note:	Hooked onto the "woocommerce_new_order_data" Filter.
		 * Note:	The "woocommerce_new_order_data" Filter has been part of WooCommerce core since 2.6.0.
		 * @see		http://hookr.io/plugins/woocommerce/2.6.0/filters/woocommerce_new_order_data/
		 *
		 * @since	2.0.0
		 * @see		SplitOff_Plugin::__construct() for hook attachement.
		 * @param	array $order_data An array of parameters to pass to wp_insert_post().
		 * @return	array The filtered array to be passed as the first argument of wp_insert_post().
		 */
		public function filter_woocommerce_new_order_data( $order_data ) {
			if (array_key_exists('splitoff_quote_id', $GLOBALS) && is_numeric($GLOBALS['splitoff_quote_id']) && $GLOBALS['splitoff_quote_id'] > 0) {
				$order_data['import_id'] = (int)$GLOBALS['splitoff_quote_id'];
				unset($GLOBALS['splitoff_quote_id']);
			}
			return $order_data;
		}

		/**
		 * Cancel the SplitOff_Quote from the lightbox and return to the checkout.
		 *
		 * Note:	This is only applicable for API v1.
		 *
		 * @since	2.0.0
		 * @param	int	$splitoff_quote_id	The ID of the quote that was cancelled.
		 * @uses	wp_trash_post()			Available in WooCommerce core since 2.9.0.
		 * @uses	wc_add_notice()			Available in WooCommerce core since 2.1.
		 * @uses	wc_get_checkout_url()	Available in WooCommerce core since 2.5.0.
		 * @uses	wp_redirect()			Available in WordPress core since 1.5.1.
		 * @return	false					Only returns false if the redirect fails.
		 * @used-by	self::override_single_post_template_for_splitoff_quotes()
		 */
		private function cancel_splitoff_quote($splitoff_quote_id) {
			global $wpdb;

			# Mark the quote as cancelled.
			update_post_meta( $splitoff_quote_id, 'status', 'cancelled' );

			# Don't use `wp_trash_post` or `wp_delete_post`
			# because we don't want any hooks to fire.

 			self::log($wpdb->query( $wpdb->prepare( "DELETE FROM `{$wpdb->postmeta}` WHERE `post_id` = %d", $splitoff_quote_id ) ) . " row(s) deleted from `{$wpdb->postmeta}` table.");
 			self::log($wpdb->query( $wpdb->prepare( "DELETE FROM `{$wpdb->posts}` WHERE `ID` = %d LIMIT 1", $splitoff_quote_id ) ) . " row(s) deleted from `{$wpdb->posts}` table.");

			# Store a checkout notice in the session.
			wc_add_notice( __( 'Your order has been cancelled.', 'woo_splitoff' ), 'notice' );

			# Get session value of "splitoff_create_account" to check for customer signed up at checkout or not
			$is_new_user=WC()->session->get("splitoff_create_account");

			#Release "splitoff_create_account" variable from the session
			WC()->session->__unset( 'splitoff_create_account' );

			# Redirect back to the cart if user signup at checkout (new customer)
			# Else redirect to the checkout
			if (wp_redirect(($is_new_user)?wc_get_cart_url():wc_get_checkout_url() )) {
				exit;
			}

			return false;
		}

		/**
		 * Confirm the SplitOff_Quote from the lightbox.
		 * This is called if the quote is pre-approved. It will process the capture, and create the
		 * WC_Order if the payment is captured successfully.
		 *
		 * Note:	This is only applicable for API v1.
		 *
		 * @since	2.0.0
		 * @param	int		$splitoff_quote_id	The Post ID of the Aterpay_Quote.
		 * @param	string	$token				The token to be captured.
		 * @uses	wc_add_notice()				Available since WooCommerce 2.1.
		 * @see		https://docs.woocommerce.com/wc-apidocs/function-wc_add_notice.html
		 * @uses	wc_get_checkout_url()		Available since WooCommerce 2.5.0.
		 * @see		https://docs.woocommerce.com/wc-apidocs/source-function-wc_get_checkout_url.html#1131-1148
		 * @return	WP_Error|false				Only returns if it doesn't write redirect headers and die.
		 * @used-by	self::override_single_post_template_for_splitoff_quotes()
		 */
		private function confirm_splitoff_quote($splitoff_quote_id, $token) {
			self::log("WC_Gateway_SplitOff::confirm_splitoff_quote({$splitoff_quote_id}, '{$token}')...");

			# Process the capture.

			$merchant = new SplitOff_Plugin_Merchant;
			$capture_response = $merchant->direct_payment_capture($token, $splitoff_quote_id);

			//$capture_response = json_decode($capture_response, true);

			self::log("capture_response: " . $capture_response);
			//self::log("splitoff_quote_id: " . $splitoff_quote_id);

			if ($capture_response == 'APPROVED') {
                # Convert the SplitOff_Quote into a WC_Order.
				self::log('status - APPROVED');
				$created_via = get_post_meta( $splitoff_quote_id, 'created_via', true );
				if ($created_via == 'override_order_creation_3_6') {
					$splitoff_order_id = get_post_meta( $splitoff_quote_id, 'splitoff_order_id', true );
					$order = $this->create_wc_order_from_splitoff_quote_3_6($splitoff_quote_id);
					$posted = $this->special_decode(get_post_meta( $splitoff_quote_id, 'posted', true ));
				} else {
					$order = $this->create_wc_order_from_splitoff_quote($splitoff_quote_id);
					$posted = json_decode(get_post_meta( $splitoff_quote_id, 'posted', true ), true);
				}

				# Redirect to the receipt page (if front-end)

				if (!is_wp_error($order)) {
					do_action( 'woocommerce_checkout_order_processed', $splitoff_quote_id, $posted, $order );

					if ($created_via == 'override_order_creation_3_6') {
						$order->add_order_note( __( "Payment approved. SplitOff Order ID: {$splitoff_order_id}", 'woo_splitoff') );
						$order->payment_complete( $splitoff_order_id );
					}

					if (wp_redirect( $order->get_checkout_order_received_url() )) {
						exit;
					}
				}

				# Return the WP_Error if the WC_Order could not be created.

				return $order;
			} elseif ($capture_response == 'DECLINED') {
				# Log the event.

                if (wp_redirect($this->get_web_url().'/cart.html?orderToken='.$token . '&id=' . $this->get_merchant_id())){
                    exit;
                }

				self::log("SplitOff Quote #{$splitoff_quote_id} declined by SplitOff.");
				self::log('status - DECLINED');
				# Redirect back to the checkout page with an error (if front-end).
				$currency = get_option('woocommerce_currency');
				$cs_number=(isset($this->customer_service_number[$currency]) && !empty($this->customer_service_number[$currency]))?$this->customer_service_number[$currency]:"04 3004 8889";
				wc_add_notice( __( 'Your payment was declined. For more information, please contact the SplitOff Customer Service Team on '.$cs_number.'.', 'woo_splitoff' ), 'error' );

				# Get session value of "splitoff_create_account" to check for customer signed up at checkout or not
				$is_new_user=WC()->session->get("splitoff_create_account");

				#Release "splitoff_create_account" variable from the session
				WC()->session->__unset( 'splitoff_create_account' );

				# Redirect back to the cart if user signup at checkout (new customer)
				# Else redirect to the checkout
				if (wp_redirect(($is_new_user)?wc_get_cart_url():wc_get_checkout_url() )) {
				    exit;
				}

			} else {
				self::log('status - none');

                # We don't know what happened. Hopefully it was an API error which we logged. In any case,
                # display a generic error at the checkout.

                wc_add_notice( __( 'Your payment could not be processed. Please try again.', 'woo_splitoff' ), 'error' );

                if (wp_redirect( wc_get_checkout_url() )) {
                    exit;
                }

            }

			# Can only reach this point if wp_redirect() failed.
			return false;
		}

		/**
		 * When viewing the public URL for an "splitoff_quote" post, intercept the rendering of the page and just write the javascript
		 * for redirecting to the SplitOff payment gateway. This is because the process_payment() method must return JSON with
		 * either a redirect URL or a message to display (the message can be HTML), and since we skipped the WC_Order creation
		 * there's no WooCommerce page to render.
		 *
		 * Note:	This is only applicable for API v1, as v0 does not create "splitoff_quote" posts.
		 *
		 * Note:	Hooked onto the "template_redirect" Action.
		 *
		 * @since	2.0.0
		 * @global	WP_Query	$wp_query
		 * @see		SplitOff_Plugin::__construct()	For hook attachement.
		 * @see		self::process_payment()			For how the user is redirected to the URL that implements this function.
		 * @uses	current_time()
		 * @uses	self::render_js()
		 * @uses	metadata_exists()				Available in WordPress core since 3.3.0.
		 * @uses	self::cancel_splitoff_quote()
		 * @uses	self::confirm_splitoff_quote()
		 */
		public function override_single_post_template_for_splitoff_quotes() {
			if (!is_admin()) {
				if (!empty($_GET)) {
					$splitoff_quote = null;
					if (array_key_exists('post_type', $_GET) && array_key_exists('p', $_GET)) {
						$page = (int) sanitize_text_field($_GET['p']);
					    if ($_GET['post_type'] == 'splitoff_quote' && is_numeric($page)) {
							$splitoff_quote = get_post($page);
						}
					}
					if (is_null($splitoff_quote)) {
						$splitoff_quote = get_post();
					}
					if ($this->is_post_splitoff_quote($splitoff_quote)) {

						# should it be 404
						$is_404 = true;

						if (array_key_exists('action', $_GET) && array_key_exists('nonce', $_GET)) {
							switch ($_GET['action']) {
								case 'preauth':
									$splitoff_preauth_nonce = $_GET['nonce'];

									# Avoiding verify nonce since there are chance that the codes is run several times due to website modifications
									// if (wp_verify_nonce( $splitoff_preauth_nonce, "splitoff_preauth_nonce-{$splitoff_quote->ID}" ) && $splitoff_preauth_nonce == get_post_meta( $splitoff_quote->ID, 'splitoff_preauth_nonce', true )) {

									if ($splitoff_preauth_nonce == get_post_meta( $splitoff_quote->ID, 'splitoff_preauth_nonce', true )) {

										//delete_post_meta($splitoff_quote->ID, 'splitoff_preauth_nonce'); # Force the nonce to actually be a proper single-use nonce.

										$token = get_post_meta( $splitoff_quote->ID, 'token', true );
										$token_expiry = get_post_meta( $splitoff_quote->ID, 'token_expiry', true );

										if (!empty($token) && is_string($token) && strlen($token) > 0) {
											if (current_time( 'timestamp', true ) < strtotime($token_expiry)) { # Note: This is comparing the current GMT time to the stored UTC time.
												if (false) {
													# Redirect mode.
													# Render the JS in redirect mode and exit.
													if (!headers_sent()) {
														header('Content-type: text/html');
													}
													$this->render_js($token);
													exit;
												} else {
													# Display mode.
													# Queue the token for rendering on the page.
													# Return, allowing the public post to render normally.
													$this->token = $token;
													return;
												}
											} else {
												# The token has expired. No point trying to launch the lightbox with a token
												# that we know has expired because it won't work. It may actually start the
												# spinner and fail to handle the 404, resulting in a never-ending progress
												# indicator. Avoid the potentiall terrible UX and just tell the customer their
												# token has expired.

												# Log the event.
												self::log("The token for SplitOff Quote #{$splitoff_quote->ID} has expired. Customer will be returned to checkout and notified.");

												# Update and trash the post.
												update_post_meta( $splitoff_quote->ID, 'status', 'failed' );
												if (function_exists('wp_trash_post')) {
													wp_trash_post( $splitoff_quote->ID  );
												}

												# Store an error notice and redirect the customer back to the checkout.
												wc_add_notice( __( 'Your payment token has expired. Please try again.', 'woo_splitoff' ), 'error' );
												if (wp_redirect( wc_get_checkout_url() )) {
													exit;
												}
											}
										} else {
											# The customer should not have reached this point if SplitOff did not create a token.
											# An error would have been returned by the AJAX request to place the order.
											# @see self::process_payment()
										}
									} elseif (function_exists('metadata_exists') && !metadata_exists( 'post', $splitoff_quote->ID, 'splitoff_preauth_nonce' )) {
										# Trying to re-use a nonce. This is probably a refresh when the JS was rendered in
										# display mode. Give the customer the same "token expired" message and take them back
										# to the checkout.

										# Log the event.
										self::log("Customer tried to re-use the preauth nonce, probably by refreshing the page. Customer will be returned to checkout and notified that the token expired.");

										# Update and trash the post.
										update_post_meta( $splitoff_quote->ID, 'status', 'failed' );
										if (function_exists('wp_trash_post')) {
											wp_trash_post( $splitoff_quote->ID  );
										}

										# Store an error notice and redirect the customer back to the checkout.
										wc_add_notice( __( 'Your payment token has expired. Please try again.', 'woo_splitoff' ), 'error' );
										if (wp_redirect( wc_get_checkout_url() )) {
											exit;
										}
									}
								break;

								case 'fe-cancel':
									$splitoff_fe_cancel_nonce = $_GET['nonce'];

									# Avoiding verify nonce since there are chance that the codes is run several times due to website modifications
									// if (wp_verify_nonce( $splitoff_fe_cancel_nonce, "splitoff_fe_cancel_nonce-{$splitoff_quote->ID}" ) && $splitoff_fe_cancel_nonce == get_post_meta( $splitoff_quote->ID, 'splitoff_fe_cancel_nonce', true )) {

									if ($splitoff_fe_cancel_nonce == get_post_meta( $splitoff_quote->ID, 'splitoff_fe_cancel_nonce', true )) {
										//delete_post_meta($splitoff_quote->ID, 'splitoff_fe_cancel_nonce'); # Force the nonce to actually be a proper single-use nonce.

										if ($_GET['status'] == 'CANCELLED') {
											# Log the event.
											self::log("SplitOff Quote #{$splitoff_quote->ID} cancelled by Consumer.");

											# Cancel the SplitOff Quote:
											# - Mark the quote as cancelled.
											# - Redirect back to the checkout with a notice.
											$this->cancel_splitoff_quote($splitoff_quote->ID);
										} else {
											# What?
										}
									}
								break;

								case 'fe-confirm':
									$splitoff_fe_confirm_nonce = $_GET['nonce'];

									# Avoiding verify nonce since there are chance that the codes is run several times due to website modifications
									// if (wp_verify_nonce( $splitoff_fe_confirm_nonce, "splitoff_fe_confirm_nonce-{$splitoff_quote->ID}" ) && $splitoff_fe_confirm_nonce == get_post_meta( $splitoff_quote->ID, 'splitoff_fe_confirm_nonce', true )) {

									if ($splitoff_fe_confirm_nonce == get_post_meta( $splitoff_quote->ID, 'splitoff_fe_confirm_nonce', true )) {
										//delete_post_meta($splitoff_quote->ID, 'splitoff_fe_confirm_nonce'); # Force the nonce to actually be a proper single-use nonce.

										if ($_GET['status'] == 'SUCCESS') {
											# Log the event.
											self::log("SplitOff Quote #{$splitoff_quote->ID} confirmed by Consumer.");

											# The order reached pre-approval status.
											# Confirm the SplitOff Quote:
											# - Submit the direct payment capture request to the API.
											# - Convert the SplitOff into a WC_Order.
											# - Redirect to the receipt page.
											$this->confirm_splitoff_quote($splitoff_quote->ID, sanitize_text_field(rtrim($_GET['orderToken'], '/')));
										} elseif ($_GET['status'] == 'FAILURE') {
											# Log the event.
											self::log("SplitOff Quote #{$splitoff_quote->ID} declined by SplitOff.");

											# This should never happen in v1, because the capture hasn't been initiated yet.
											# This is the same as a decline.
											# @see self::confirm_splitoff_quote() where $capture_response == 'DECLINED'
											$currency = get_option('woocommerce_currency');
											$cs_number=(isset($this->customer_service_number[$currency]) && !empty($this->customer_service_number[$currency]))?$this->customer_service_number[$currency]:"04 3004 8889";
		                                    wc_add_notice( __( 'Your payment was declined. For more information, please contact the SplitOff Customer Service Team on '.$cs_number.'.', 'woo_splitoff' ), 'error' );
											if (wp_redirect( wc_get_checkout_url() )) {
												exit;
											}
										} else {
											# What?
										}
									}
								break;
							}
						}

						if ($is_404) {
							global $wp_query;
							$wp_query->set_404();
							status_header(404);
							nocache_headers();
							include get_query_template( '404' );
							exit;
						}
					}
				}
			}
		}

		/**
		 * Inject the preauth HTML onto the page, only if a token has been queued to render. $this->token will only hold
		 * a value if self::override_single_post_template_for_splitoff_quotes() validated the preauth URL on "wp_loaded".
		 *
		 * Note:	Hooked onto the "wp_head" Action.
		 * Note:	Hooked onto the "wp_footer" Action.
		 * Note:	Hooked onto the "shutdown" Action.
		 *
		 * @since	2.0.0
		 * @see		SplitOff_Plugin::__construct()	For hook attachment.
		 * @uses	self::render_js()
		 */
		public function inject_preauth_html() {
			if (!empty($this->token)) {
				$this->render_js($this->token, 'redirect');
				$this->token = null;
			}
		}

		/**
		 * Trigger SplitOff JavaScript / Payment Capture on Receipt page.
		 * Before payment, this is actually the "Pay for order" page.
		 *
		 * Note:	This is only applicable for API v0, and v1 in Compatibility Mode.
		 * Note:	Hooked onto the "woocommerce_receipt_splitoff" Action.
		 *
		 * @since	1.0.0
		 * @param	string 		$order_id
		 * @uses	wc_get_order()
		 * @uses	get_bloginfo()							Available in WordPress core since 0.71.
		 * @uses	WC_Payment_Gateway::get_return_url()
		 * @uses	WC_Payment_Gateway::has_status()
		 * @uses	WC_Payment_Gateway::update_status()
		 * @uses	self::render_js()
		 */
		public function receipt_page($order_id) {
			if ($this->settings['api-version'] != 'v0' && !$this->compatibility_mode) {
				return;
			}

			if (function_exists('wc_get_order') ) {
				$order = wc_get_order( $order_id );
			} else {
				$order = new WC_Order( $order_id );
			}

			# Get the order token from the database.
			$token = get_post_meta( $order_id, '_splitoff_token', true );

			if (empty($token)) {
				self::log("Failed to render checkout receipt page - token cannot be empty.");
				/**
				 * @todo Cancel the order.
				 * @todo Store an error message.
				 * @todo Redirect back to the checkout.
				 */
				return;
			}

			if ($this->settings['api-version'] == 'v1' && $this->compatibility_mode) {

				if (!empty($_GET) && array_key_exists('status', $_GET) && array_key_exists('orderToken', $_GET)) {
					# The status and orderToken parameters are present in the URL.
					# The Consumer has probably has probably just returned from SplitOff after confirming their
					# payment schedule.

					# Get the status and order token from the URL.
					$splitoff_order_token = sanitize_text_field($_GET['orderToken']);
					$splitoff_order_status = sanitize_text_field($_GET['status']);

					# Process the payment capture.

					if ($splitoff_order_token == $token && $splitoff_order_status == 'SUCCESS') {
						$merchant = new SplitOff_Plugin_Merchant;

						$response = $merchant->direct_payment_capture_compatibility_mode($splitoff_order_token);

						if (is_object($response)) {
							if ($response->status == 'APPROVED') {
								self::log("Payment APPROVED for WooCommerce Order #{$order_id} (SplitOff Order #{$response->id}).");

								$order->add_order_note( sprintf(__( 'Payment approved. SplitOff Order ID: %s.', 'woo_splitoff' ), $response->id) );
								$order->payment_complete($response->id);

								if (wp_redirect( $this->get_return_url($order) )) {
									exit;
								}
							} elseif ($response->status == 'DECLINED') {
								self::log("Payment DECLINED for WooCommerce Order #{$order_id} (SplitOff Order #{$response->id}).");

								$order->update_status( 'failed', sprintf(__( 'Payment declined. SplitOff Order ID: %s.', 'woo_splitoff' ), $response->id) );
								$currency = get_option('woocommerce_currency');
								$cs_number=(isset($this->customer_service_number[$currency]) && !empty($this->customer_service_number[$currency]))?$this->customer_service_number[$currency]:"04 3004 8889";
    							wc_add_notice( sprintf(__( 'Your payment was declined for SplitOff Order #%s. Please try again. For more information, please contact the SplitOff Customer Service team on '.$cs_number.'.', 'woo_splitoff' ), $response->id), 'error' );

								# Get session value of "splitoff_create_account" to check for customer signed up at checkout or not
								$is_new_user = WC()->session->get("splitoff_create_account");

								# Release "splitoff_create_account" variable from the session
								WC()->session->__unset( 'splitoff_create_account' );

								# Redirect back to the cart if user signup at checkout (new customer)
								# Else redirect to the checkout
								if (wp_redirect(($is_new_user)?wc_get_cart_url():wc_get_checkout_url() )) {
									exit;
								}
							}
						} else {
							self::log("Updating status of WooCommerce Order #{$order_id} to \"Failed\", because response is not an object.");

							$order->update_status( 'failed', __( 'SplitOff payment failed.', 'woo_splitoff' ) );

							wc_add_notice( __( 'Payment failed. Please try again.', 'woo_splitoff' ), 'error' );
							if (wp_redirect( wc_get_checkout_url() )) {
								exit;
							}
						}
					} else {
						if ($splitoff_order_token == $token) {
							self::log("Updating status of WooCommerce Order #{$order_id} to \"Failed\", because `status` and `orderToken` are set, but `status` is \"{$splitoff_order_status}\".");

							$order->update_status( 'failed', __( 'SplitOff payment failed.', 'woo_splitoff' ) );

							wc_add_notice( __( 'Payment failed. Please try again.', 'woo_splitoff' ), 'error' );
							if (wp_redirect( wc_get_checkout_url() )) {
								exit;
							}
						} else {
							self::log("Updating status of WooCommerce Order #{$order_id} to \"Failed\", because \$token and `orderToken` do not match (\"{$token}\" vs. \"{$splitoff_order_token}\").");

							$order->update_status( 'failed', __( 'SplitOff payment failed.', 'woo_splitoff' ) );

							wc_add_notice( __( 'Payment failed due to token mismatch. Please try again.', 'woo_splitoff' ), 'error' );
							if (wp_redirect( wc_get_checkout_url() )) {
								exit;
							}
						}
					}
				} else {
					# The status and orderToken parameters are not present in the URL.
					# The Consumer probably has not been through the SplitOff screenflow yet.
					$this->render_js($token, 'redirect');
				}
			} elseif ($this->settings['api-version'] == 'v0') {
				# Return URL.
				$blogurl = str_replace(array('https:', 'http:'), '', get_bloginfo('url'));
				$returnurl = str_replace(array('https:', 'http:', $blogurl), '', $this->get_return_url($order));

				# Update order status if not already pending.
				$is_pending = false;
				if (property_exists($order, 'has_status')) {
					$is_pending = $order->has_status('pending');
				} else {
					$is_pending = ($order->status == 'pending');
				}
				if (!$is_pending) {
					$order->update_status('pending');
				}

				# Render the JS.
				$this->render_js($token, 'redirect', array(
					'relativeCallbackURL' => $returnurl
				));
			}
		}

		/**
		 * Validate the order status on the Thank You page. Will never actually alter the Order ID.
		 *
		 * Note:	This is only applicable for API v0.
		 * Note:	Hooked onto the "woocommerce_thankyou_order_id" Filter.
		 *
		 * @since	1.0.0
		 * @param	int			$order_id
		 * @uses	SplitOff_Plugin_Merchant::get_order()
		 * @return	int
		 */
		public function payment_callback($order_id) {
			if ($this->settings['api-version'] != 'v0') {
				return $order_id;
			}

			if (array_key_exists('orderId', $_GET)) {
				$splitoff_order_id = sanitize_text_field($_GET['orderId']);

				self::log("Checking status of WooCommerce Order #{$order_id} (SplitOff Order #{$splitoff_order_id})");

				$merchant = new SplitOff_Plugin_Merchant;
				$response = $merchant->get_order(null, $splitoff_order_id);

				if ($response === false) {
					self::log("SplitOff_Plugin_Merchant::get_order() returned false.");
				} elseif (is_object($response)) {
					self::log("SplitOff_Plugin_Merchant::get_order() returned an order with a status of \"{$response->status}\".");

					if (function_exists('wc_get_order')) {
						$order = wc_get_order( $order_id );
					} else {
						$order = new WC_Order( $order_id );
					}

					$is_completed = $is_processing = $is_pending = $is_on_hold = $is_failed = false;

					if (method_exists($order, 'has_status')) {
						$is_completed = $order->has_status( 'completed' );
						$is_processing = $order->has_status( 'processing' );
						$is_pending = $order->has_status( 'pending' );
						$is_on_hold = $order->has_status( 'on-hold' );
						$is_failed = $order->has_status( 'failed' );
					} else {
						if ($order->status == 'completed') {
							$is_completed = true;
						} elseif ($order->status == 'processing') {
							$is_processing = true;
						} elseif ($order->status == 'pending') {
							$is_pending = true;
						} elseif ($order->status == 'on-hold') {
							$is_on_hold = true;
						} elseif ($order->status == 'failed') {
							$is_failed = true;
						}
					}

					if ($response->status == 'APPROVED') {
						if (!$is_completed && !$is_processing) {
							self::log("Updating status of WooCommerce Order #{$order_id} to \"Processing\".");

							$order->add_order_note( sprintf(__( 'Payment approved. SplitOff Order ID: %s', 'woo_splitoff' ), $response->id) );
							$order->payment_complete($response->id);

							if (function_exists("wc_empty_cart")) {
								wc_empty_cart();
							}
							else {
								woocommerce_empty_cart();
							}
						}
					} elseif ($response->status == 'PENDING') {
						if (!$is_on_hold) {
							self::log("Updating status of WooCommerce Order #{$order_id} to \"On Hold\".");

							$order->add_order_note( sprintf(__( 'SplitOff payment is pending approval. SplitOff Order ID: %s', 'woo_splitoff' ), $response->id) );
							$order->update_status( 'on-hold' );
							update_post_meta($order_id,'_transaction_id',$response->id);
						}
					} elseif ($response->status == 'FAILURE' || $response->status == 'FAILED') {
						if (!$is_failed) {
							self::log("Updating status of WooCommerce Order #{$order_id} to \"Failed\".");

							$order->add_order_note( sprintf(__( 'SplitOff payment declined. Order ID from SplitOff: %s', 'woo_splitoff' ), $response->id) );
							$order->update_status( 'failed' );
						}
					} else {
						if (!$is_pending) {
							self::log("Updating status of WooCommerce Order #{$order_id} to \"Pending Payment\".");

							$order->add_order_note( sprintf(__( 'Payment %s. SplitOff Order ID: %s', 'woo_splitoff' ), strtolower($response->status), $response->id) );
							$order->update_status( 'pending' );
						}
					}
				}
			}

			return $order_id;
		}

		/**
		 * Can the order be refunded?
		 *
		 * @since	1.0.0
		 * @param	WC_Order	$order
		 * @return	bool
		 */
		public function can_refund_order($order) {
			if ($order instanceof WC_Order && method_exists($order, 'get_transaction_id')) {
				return $order && $order->get_transaction_id();
			}

			return false;
		}

		/**
		 * Process a refund if supported.
		 *
		 * Note:	This overrides the method defined in the parent class.
		 *
		 * @since	1.0.0
		 * @see		WC_Payment_Gateway::process_refund()		For the method that this overrides.
		 * @param	int			$order_id
		 * @param	float		$amount							Optional. The amount to refund. This cannot exceed the total.
		 * @param	string		$reason							Optional. The reason for the refund. Defaults to an empty string.
		 * @uses	SplitOff_Plugin_Merchant::create_refund()
		 * @return	bool
		 */
		public function process_refund($order_id, $amount = null, $reason = '') {
			$order_id = (int)$order_id;

			self::log("Refunding WooCommerce Order #{$order_id} for \${$amount}...");

			if (function_exists('wc_get_order')) {
				$order = wc_get_order( $order_id );
			} else {
				$order = new WC_Order( $order_id );
			}

			if (!$this->can_refund_order($order)) {
				self::log('Refund Failed - No Transaction ID.');
				return false;
			}

			$merchant = new SplitOff_Plugin_Merchant;
			$success = $merchant->create_refund($order, $amount);

			if ($success) {
				$order->add_order_note( __( "Refund of \${$amount} sent to SplitOff. Reason: {$reason}", 'woo_splitoff' ) );
				return true;
			}

			$order->add_order_note( __( "Failed to send refund of \${$amount} to SplitOff.", 'woo_splitoff' ) );
			return false;
		}

		/**
		 * Check if the customer cancelled the payment from the lightbox.
		 *
		 * Note:	This is only applicable for API v0.
		 * Note:	Hooked onto the "template_redirect" Action.
		 *
		 * @since	1.0.0
		 * @see		SplitOff_Plugin::__construct()		For hook attachment.
		 * @uses	wc_get_order_id_by_order_key()
		 * @uses	wc_get_order()
		 * @uses	wp_redirect()
		 * @uses	WC_Order::get_cancel_order_url_raw()
		 * @uses	WC_Order::get_cart_url()
		 */
		public function splitoff_check_for_cancelled_payment() {
			if ($this->settings['api-version'] != 'v0') {
				return;
			}

			if (array_key_exists('key', $_GET) && array_key_exists('status', $_GET) && $_GET['status'] == 'CANCELLED' && array_key_exists('orderToken', $_GET)) {
				$order_id = wc_get_order_id_by_order_key(sanitize_text_field($_GET['key']));

				if ($order_id > 0) {
					if (function_exists('wc_get_order')) {
						$order = wc_get_order( $order_id );
					} else {
						$order = new WC_Order( $order_id );
					}
				} else {
					$order = null;
				}

				if ($order instanceof WC_Order) {
					self::log("Order #{$order_id} payment cancelled by the customer from the SplitOff lightbox.");

					if (method_exists($order, 'get_cancel_order_url_raw')) {
						if (wp_redirect( $order->get_cancel_order_url_raw() )) {
							exit;
						}
					} else {
						$order->update_status( 'cancelled' );
						if (wp_redirect( WC()->cart->get_cart_url() )) {
							exit;
						}
					}
				}
			}
		}

		/**
		 * Return the current settings for SplitOff Plugin
		 *
		 * @since	2.1.0
		 * @used-by	generate_category_hooks(), generate_product_hooks()
		 * @return 	array 	settings array values
		 */
		public function getSettings() {
			return $this->settings;
		}
		/**
		 * Returns Default Customisation Settings of SplitOff Plugin
		 *
		 * Note:	Hooked onto the "wp_ajax_splitoff_action" Action.
		 *
		 * @since	2.1.2
		 * @uses	get_form_fields()   returns $this->form_fields() array
		 * @return 	array               default splitoff customization settings
		 */
		public function reset_settings_api_form_fields() {
				$splitoff_default_settings = $this->get_form_fields();

				$settings_to_replace = array(
					'show-info-on-category-pages'           => $splitoff_default_settings['show-info-on-category-pages']['default'],
					'category-pages-info-text'              => $splitoff_default_settings['category-pages-info-text']['default'],
					'category-pages-hook'                   => $splitoff_default_settings['category-pages-hook']['default'],
					'category-pages-priority'               => $splitoff_default_settings['category-pages-priority']['default'],
					'show-info-on-product-pages'            => $splitoff_default_settings['show-info-on-product-pages']['default'],
					'product-pages-info-text'               => $splitoff_default_settings['product-pages-info-text']['default'],
					'product-pages-hook'                    => $splitoff_default_settings['product-pages-hook']['default'],
					'product-pages-priority'                => $splitoff_default_settings['product-pages-priority']['default'],
					'show-info-on-product-variant'          => $splitoff_default_settings['show-info-on-product-variant']['default'],
					'product-variant-info-text'             => $splitoff_default_settings['product-variant-info-text']['default'],
					//'show-outside-limit-on-product-page'    => $splitoff_default_settings['show-outside-limit-on-product-page']['default'],
					'show-info-on-cart-page'                => $splitoff_default_settings['show-info-on-cart-page']['default'],
					'cart-page-info-text'                   => $splitoff_default_settings['cart-page-info-text']['default'],
				);

				wp_send_json($settings_to_replace);
		}
		/**
		 * Adds/Updates 'splitoff-plugin-version' in SplitOff settings
		 *
		 * Note:	Hooked onto the "woocommerce_update_options_payment_gateways_" Action.
		 *
		 * @since	2.1.2
		 * @uses	get_option()      returns option value
		 * @uses	update_option()   updates option value
		 */
		public function process_admin_options() {
			parent::process_admin_options();

			if(get_option('woocommerce_splitoff_settings')){
				$updated_splitoff_settings=array_replace(get_option('woocommerce_splitoff_settings'),array('splitoff-plugin-version'=>SplitOff_Plugin::$version));
				update_option('woocommerce_splitoff_settings',$updated_splitoff_settings);
			}
		}
		/**
		 * Returns final price of the given product
		 *
		 * @since	2.1.2
		 * @param	WC_Product	$product									The product in question, in the form of a WC_Product object.
		 *																	This affects whether the minimum setting is considered.
		 * @uses	wc_get_price_to_display()								Available as part of the WooCommerce core plugin since 3.0.0.
		 * @uses	WC_Abstract_Legacy_Product::get_display_price()			Possibly available as part of the WooCommerce core plugin since 2.6.0. Deprecated in 3.0.0.
		 * @uses	WC_Product::get_price()									Possibly available as part of the WooCommerce core plugin since 2.6.0.
		 * @return	float | string											Final price of the product.
		 * @used-by self::process_and_print_splitoff_paragraph()
		 */
		public function get_product_final_price($product){
			if (function_exists('wc_get_price_to_display')) {
				$price = wc_get_price_to_display( $product );
			} elseif (method_exists($product, 'get_display_price')) {
				$price = $product->get_display_price();
			} elseif (method_exists($product, 'get_price')) {
				$price = $product->get_price();
			} else {
				$price = 0.00;
			}
			return $price;
		}
		/**
		 * Get ID for the given order
		 *
		 * @since	2.1.4
		 * @param	WC_Order| null	$order					The WooCommerce order that we want to find out about.
		 * @return	string|false
		 * @used-by	self::create_wc_order_from_splitoff_quote()
		 */
		public function get_order_id($order=null){
			if (is_null($order)) {
				return false;
			}

			if (method_exists($order, "get_id")) {
				return $order->get_id();
			}
			else {
				return $order->ID;
			}
		}
		/**
		 * Hides the SplitOff notice
		 *
		 * Note:	Hooked onto the "wp_ajax_splitoff_dismiss_action" Action.
		 *
		 * @since	2.1.4
		 * @uses	get_option('splitoff_rate_notice_dismiss')
		 * @uses	update_option('splitoff_rate_notice_dismiss')
		 * @uses	add_option('splitoff_rate_notice_dismiss')
		 * @return 	bool
		 */
		public function splitoff_notice_dismiss(){
			if(get_option('splitoff_rate_notice_dismiss')){
				update_option('splitoff_rate_notice_dismiss','yes');
			}
			else{
				add_option('splitoff_rate_notice_dismiss','yes');
			}

			wp_send_json(true);
		}
		/**
		 * Provide a shortcode for rendering the standard SplitOff paragraph for theme builders.
		 *
		 * E.g.:
		 * 	- [splitoff_paragraph] OR [splitoff_paragraph type="product"] OR [splitoff_paragraph id="99"]
		 *
		 * @since	2.1.5
		 * @see		SplitOff_Plugin::__construct()		For shortcode definition.
		 * @param	array	$atts			            Array of shortcode attributes.
		 * @uses	shortcode_atts()
		 * @return	string
		 */
		public function shortcode_splitoff_paragraph($atts) {
			$atts = shortcode_atts( array(
				'type' => 'product',
				'id'   => 0
			), $atts );

			if(array_key_exists('id',$atts) &&  $atts['id']!=0){
				if (function_exists('wc_get_product')) {
					$product = wc_get_product( $atts['id'] );
				} else {
					$product = new WC_Product( $atts['id'] );
				}
			}
			else{
				$product = $this->get_product_from_the_post();
			}

			ob_start();
			if($atts['type'] == "product" && !is_null($product)){
				$this->print_info_for_product_detail_page($product);
			}
			return ob_get_clean();
		}
		/**
		 * Function for null check of data.
		 *
		 * @since	2.1.5
		 * @param	mixed		$value
		 * @param	mixed		$default_value
		 * @uses	is_null
		 * @return	mixed
		 */
		private function check_null($value,$default_value="")
		{

		    return is_null($value)?$default_value:$value;
		}
	}
}
