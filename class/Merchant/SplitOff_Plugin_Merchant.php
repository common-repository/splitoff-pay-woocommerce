<?php
/**
* SplitOff Plugin Merchant Handler Class
*/

class SplitOff_Plugin_Merchant {
	/**
	 * Protected variables.
	 *
	 * @var		WC_Gateway_SplitOff	$gateway					A reference to the WooCommerce Payment Gateway for SplitOff.
	 * 															This is used for retreiving user settings, such as the API URL.
	 * @var		string				$api_version				The SplitOff API version to use ("v0" or "v1").
	 * @var		string				$payment_types_url			The full URL to the "payment-types" API endpoint (v0).
	 * @var		string				$configuration_url			The full URL to the "configuration" API endpoint (v1).
	 * @var		string				$orders_url					The full URL to the "orders" API endpoint (v0 and v1).
	 * @var		string				$direct_payment_capture_url	The full URL to the "orders" API endpoint (v1).
	 */
	protected $gateway, $api_version, $payment_types_url, $configuration_url, $orders_url, $refund_url, $direct_payment_capture_url;

	/**
	 * Class constructor. Called when an object of this class is instantiated.
	 *
	 * @since	1.0.3
	 * @uses	WC_Gateway_SplitOff::get_api_url()
	 * @uses	WC_Gateway_SplitOff::get_api_version()
	 */
	public function __construct() {
		include('endpoints.php');
		$this->gateway = WC_Gateway_SplitOff::getInstance();
		$api_url = $this->gateway->get_api_url();
		$this->api_version = $this->gateway->get_api_version();
		/*if ($this->api_version == 'v0') {
			$this->payment_types_url = $api_url . $endpoints['v0']['payment-types'];
			$this->orders_url = $api_url . $endpoints['v0']['orders'];
		}
		else */
		if ($this->api_version == 'v1') {
			//$this->configuration_url = $api_url . $endpoints['v1']['configuration'];
			$this->orders_url = $api_url . $endpoints['v1']['orders'];
			//$this->payments_url = $api_url . $endpoints['v1']['payments'];
            $this->refund_url = $api_url . $endpoints['v1']['refund'];
			$this->direct_payment_capture_url = $api_url . $endpoints['v1']['capture-payment'];
            $this->settings['pay-over-time-limit-min'] = 1;
            $this->settings['pay-over-time-limit-min'] = 10000;
		}
	}

	/**
	 * Filters the string used for Merchant IDs & Secret Keys.
	 *
	 * @since	1.0.0
	 * @param	string	$str
	 * @return	string
	 * @used-by	self::build_authorization_header()
	 */
	private function cleanup_string($str) {
		return preg_replace('/[^a-z0-9]+/i', '', $str);
	}

	/**
	 * Build the SplitOff Authorization header for use with the APIs.
	 *
	 * @since	1.0.0
	 * @uses	self::cleanup_string()
	 * @uses	WC_Gateway_SplitOff::get_merchant_id()
	 * @uses	WC_Gateway_SplitOff::get_secret_key()
	 * @return	string
	 * @used-by	self::get_from_api()
	 * @used-by	self::post_to_api()
	 */
	private function build_authorization_header() {
		$cleaned_merchant_id = $this->cleanup_string($this->gateway->get_merchant_id());
		$cleaned_secret_key = $this->cleanup_string($this->gateway->get_secret_key());

		return 'Basic ' . base64_encode($cleaned_merchant_id . ':' . $cleaned_secret_key);
	}

	/**
	 * Build the SplitOff User-Agent header for use with the APIs.
	 *
	 * @since	1.0.0
	 * @global	string	$wp_version
	 * @uses	WC()
	 * @return	string
	 * @used-by	self::get_from_api()
	 * @used-by	self::post_to_api()
	 */
	private function build_user_agent_header() {
		global $wp_version;

		$plugin_version = SplitOff_Plugin::$version;
		$php_version = PHP_VERSION;
		$woocommerce_version = WC()->version;
		$merchant_id = $this->gateway->get_merchant_id();

		$extra_detail_0 = '';
		$extra_detail_1 = '';
		$extra_detail_2 = '';

		if ($this->gateway->get_compatibility_mode()) {
			$extra_detail_0 .= 'CompatibilityMode; ';
		}

		$matches = array();
		if (array_key_exists('SERVER_SOFTWARE', $_SERVER) && preg_match('/^[a-zA-Z0-9]+\/\d+(\.\d+)*/', $_SERVER['SERVER_SOFTWARE'], $matches)) {
			$s = $matches[0];
			$extra_detail_1 .= "; {$s}";
		}

		if (array_key_exists('REQUEST_SCHEME', $_SERVER) && array_key_exists('HTTP_HOST', $_SERVER)) {
			$s = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'];
			$extra_detail_2 .= " {$s}";
		}

		return "SplitOff Pay for WooCommerce/{$plugin_version} ({$extra_detail_0}PHP/{$php_version}; WordPress/{$wp_version}; WooCommerce/{$woocommerce_version}; Merchant/{$merchant_id}{$extra_detail_1}){$extra_detail_2}";
	}

	/**
	 * Build an SplitOff-compatible Money object for use in the API posts.
	 *
	 * @since	1.0.0
	 * @param	float	$amount
	 * @param	string	$currency
	 * @uses	get_option('woocommerce_currency')
	 * @return	array
	 * @used-by	self::get_payment_types_for_amount()
	 * @used-by	self::get_order_token_for_wc_order()
	 * @used-by	self::create_refund()
	 */
	private function build_money_object($amount, $currency = '') {
		if (empty($currency)) {
			$currency = get_option('woocommerce_currency');
		}

		return array(
			'amount' => number_format((float)$amount, 2, '.', ''),
			'currency' => $currency
		);
	}

	/**
	 * Store an API error. If the last request to an API resulted in an error, store it in the options table
	 * so we can show it throughout the admin.
	 *
	 * @since	1.0.0
	 * @param	mixed	$error
	 * @return	bool
	 */
	private function store_api_error($error) {
		return update_option( 'woocommerce_splitoff_api_error', $error );
	}

	/**
	 * Clear the last API error. This should be called each time an API call executes successfully.
	 *
	 * @since	1.0.0
	 * @return	bool
	 */
	private function clear_api_error() {
		return delete_option( 'woocommerce_splitoff_api_error' );
	}

	/**
	 * Retrieve the Content-Type, HTTP_CORRELATION_ID and CF-Ray headers from the HTTP response.
	 *
	 * @since	2.1.0
	 * @param	array	$response	The array returned from wp_remote_get or wp_remote_post.
	 * @uses	wp_remote_retrieve_headers
	 * @return	array				An array of header values with lowercased keys.
	 * @used-by	self::parse_response
	 */
	private function get_response_headers($response) {
		global $wp_version;

		if (version_compare( $wp_version, '4.6.0', '>=' )) {
			# Since WordPress 4.6, wp_remote_retrieve_headers returns an object of class Requests_Utility_CaseInsensitiveDictionary.

			$headers_obj = wp_remote_retrieve_headers( $response );

			return $headers_obj->getAll();
		} else {
			# Prior to WordPress 4.6, wp_remote_retrieve_headers returned an array.

			$headers_arr = wp_remote_retrieve_headers( $response );

			return array_change_key_case($headers_arr, CASE_LOWER);
		}
	}

	/**
	 * Parse the response from an SplitOff API.
	 * Returns an object if the response is JSON, and if it can be parsed.
	 *
	 * @since	2.1.0
	 * @param	array	$response	The array returned from wp_remote_get or wp_remote_post.
	 * @uses	wp_remote_retrieve_response_code
	 * @uses	self::get_response_headers
	 * @uses	wp_remote_retrieve_body
	 * @uses	WC_Gateway_SplitOff::log
	 * @return	StdClass|false		An object on success, or false on failure.
	 * @used-by	self::get_from_api
	 * @used-by	self::post_to_api
	 */
	private function parse_response($response) {
		$response_code = wp_remote_retrieve_response_code( $response );
		$headers_arr = $this->get_response_headers( $response );
		$body_str = wp_remote_retrieve_body( $response );

		if (array_key_exists('content-type', $headers_arr) && strpos(strtolower($headers_arr['content-type']), 'application/json') === 0) {
			$body_obj = json_decode($body_str);

			if (is_null($body_obj)) {
				WC_Gateway_SplitOff::log("API {$response_code} RESPONSE - PARSING ERROR #" . json_last_error() . ': ' . json_last_error_msg());
			}
		} else {
			$body_obj = null;

			WC_Gateway_SplitOff::log("API {$response_code} RESPONSE - UNEXPECTED FORMAT! content-type: " . (array_key_exists('content-type', $headers_arr) ? $headers_arr['content-type'] : 'null') . '; http_correlation_id: ' . (array_key_exists('http_correlation_id', $headers_arr) ? $headers_arr['http_correlation_id'] : 'null') . '; cf-ray: ' . (array_key_exists('cf-ray', $headers_arr) ? $headers_arr['cf-ray'] : 'null'));
		}

		if ($body_obj) {
			$return_obj = new \StdClass;
			$return_obj->body = $body_obj;

			return $return_obj;
		}

		return false;
	}

	/**
	 * GET from an API endpoint.
	 *
	 * @since	1.0.0
	 * @param	string	$url
	 * @uses	wp_remote_get()
	 * @uses	self::build_authorization_header()
	 * @uses	self::build_user_agent_header()
	 * @uses	self::parse_response
	 * @uses	WC_Gateway_SplitOff::log()
	 * @return	StdClass|WP_Error|false
	 * @used-by	self::get_payment_types()
	 * @used-by	self::get_configuration()
	 * @used-by	self::get_order()
	 */
	private function get_from_api($url) {
		WC_Gateway_SplitOff::log("GET {$url}");

		$response = wp_remote_get( $url, array(
			'timeout' => 80,
			'headers' => array(
				'Authorization' => $this->build_authorization_header(),
				'User-Agent' => $this->build_user_agent_header(),
				'Accept' => 'application/json'
			)
		));

		if (!is_wp_error( $response )) {
			return $this->parse_response( $response );
		} else {
			# Unable to establish a secure connection with the SplitOff API endpoint.
			# Likely a TLS or network error.
			# Log the error details.
			foreach ($response->errors as $code => $messages_arr) {
				$messages_str = implode("\n", $messages_arr);
				WC_Gateway_SplitOff::log("API NETWORK ERROR! Code: \"{$code}\"; Message(s):\n" . $messages_str);
			}

			# Return the WP_Error object.
			return $response;
		}

		return false;
	}

	/**
	 * POST JSON to an API endpoint and load the response.
	 *
	 * @since	1.0.0
	 * @param	string	$url	The full URL to the API Endpoint.
	 * @param	mixed	$data	The jsonifiable data to be posted to the API.
	 * @uses	wp_remote_post()
	 * @uses	self::build_authorization_header()
	 * @uses	self::build_user_agent_header()
	 * @uses	self::parse_response
	 * @uses	WC_Gateway_SplitOff::log()
	 * @return	StdClass|false
	 * @used-by	self::get_payment_types_for_amount()
	 * @used-by	self::get_order_token_for_splitoff_quote()
	 * @used-by	self::direct_payment_capture()
	 * @used-by	self::create_refund()
	 */
	private function post_to_api($url, $data) {
		WC_Gateway_SplitOff::log("POST {$url}");

			$data_str = json_encode($data);

			$response = wp_remote_post( $url, array(
				'timeout' => 80,
				'headers' => array(
					'Authorization' => $this->build_authorization_header(),
					'User-Agent' => $this->build_user_agent_header(),
					'Content-Type' => 'application/json',
					'Content-Length' => strlen($data_str),
					'Accept' => 'application/json',
					'Origin' => '*'
				),
				'body' => $data_str
			) );

			if (!is_wp_error( $response )) {
				return $this->parse_response( $response );
			} else {
				# Unable to establish a secure connection with the SplitOff API endpoint.
				# Likely a TLS or network error.
				# Log the error details.
				foreach ($response->errors as $code => $messages_arr) {
					$messages_str = implode("\n", $messages_arr);
					WC_Gateway_SplitOff::log("API NETWORK ERROR! Code: \"{$code}\"; Message(s):\n" . $messages_str);
				}

				# Return the WP_Error object.
				return $response;
			}

			return false;
	}

	/**
	 * Get the valid payment types for this merchant.
	 *
	 * Note:	This is only for API v0.
	 *
	 * @since	1.0.0
	 * @uses	self::get_from_api()
	 * @uses	self::store_api_error()
	 * @uses	WC_Admin_Settings::add_error()
	 * @uses	self::clear_api_error()
	 * @uses	WC_Gateway_SplitOff::log()
	 * @return	array|false					The list of available types, or false on error.
	 */
	public function get_payment_types() {
		$response = $this->get_from_api($this->payment_types_url);

		if (is_wp_error( $response )) {
			# Unable to establish a secure connection with the SplitOff API endpoint.
			# Likely a TLS or network error.
			# Show the error throughout the admin until corrected.
			$error_codes_arr = $response->get_error_codes();
			$error = new \StdClass;
			$error->timestamp = date('Y-m-d H:i:s');
			$error->message = 'The SplitOff Pay for WooCommerce plugin cannot communicate with the SplitOff API.';
			if (count($error_codes_arr) > 0) {
				$error->message .= ' Error code(s) returned: "' . implode('", "', $error_codes_arr) . '".';
			}
			$this->store_api_error($error);
			if (is_admin()) {
				# Admin has just saved the settings. The "admin_notices" action has already triggered, so we need
				# to implement WooCommerce's secondary notice reporting function instead.
				WC_Admin_Settings::add_error(__( $error->message, 'woo_splitoff' ));
			}
		} elseif (is_object($response)) {
			$body = $response->body;

			if (is_array($body)) {
				$this->clear_api_error();
				return $body;
			} elseif (is_object($body) && property_exists($body, 'errorCode')) {
				# Log the error details.
				WC_Gateway_SplitOff::log("API ERROR #{$body->httpStatusCode} \"{$body->errorCode}\": {$body->message} (Error ID: {$body->errorId})");

				# Also display a simplified version of the error to the admin.
				$error = new \StdClass;
				$error->timestamp = date('Y-m-d H:i:s');
				$error->code = $body->httpStatusCode;
				if ($error->code == 401) {
					$error->message = 'Your SplitOff API credentials are incorrect.';
				} else {
					$error->message = 'The SplitOff Pay for WooCommerce plugin cannot communicate with the SplitOff API.';
				}
				if (property_exists($body, 'errorId') && $body->errorId) {
					$error->id = $body->errorId;
				}
				$this->store_api_error($error);
				if (is_admin()) {
					# Admin has just saved the settings. The "admin_notices" action has already triggered, so we need
					# to implement WooCommerce's secondary notice reporting function instead.
					$text = __( "SplitOff API Error #{$error->code}: {$error->message}", 'woo_splitoff' );
					if (property_exists($error, 'id') && $error->id) {
						$text .= __( " (Error ID: {$error->id})", 'woo_splitoff' );
					}
					WC_Admin_Settings::add_error($text);
				}
			}
		} elseif ($response === false) {
			# Response is false
		}

		return false;
	}

	/**
	 * Get the configuration (valid payment types and limits) for this merchant.
	 *
	 * Note:	This is only for API v1.
	 *
	 * @since	1.0.0
	 * @uses	self::get_from_api()
	 * @uses	self::store_api_error()
	 * @uses	WC_Admin_Settings::add_error()
	 * @uses	self::clear_api_error()
	 * @uses	WC_Gateway_SplitOff::log()
	 * @return	array|false					The list of available payment types, or false on error.
	 */
	public function get_configuration() {
		$response = $this->get_from_api($this->configuration_url);

		if (is_wp_error( $response )) {
			# Unable to establish a secure connection with the SplitOff API endpoint.
			# Likely a TLS or network error.
			# Show the error throughout the admin until corrected.
			$error_codes_arr = $response->get_error_codes();
			$error = new \StdClass;
			$error->timestamp = date('Y-m-d H:i:s');
			$error->message = 'The SplitOff Pay for WooCommerce plugin cannot communicate with the SplitOff API.';
			if (count($error_codes_arr) > 0) {
				$error->message .= ' Error code(s) returned: "' . implode('", "', $error_codes_arr) . '".';
			}
			$this->store_api_error($error);
			if (is_admin()) {
				# Admin has just saved the settings. The "admin_notices" action has already triggered, so we need
				# to implement WooCommerce's secondary notice reporting function instead.
				WC_Admin_Settings::add_error(__( $error->message, 'woo_splitoff' ));
			}
		} elseif (is_object($response)) {
			$body = $response->body;

			if (is_array($body)) {
				$this->clear_api_error();
				return $body;
			} elseif (is_object($body) && property_exists($body, 'errorCode')) {
				# Log the error details.
				WC_Gateway_SplitOff::log("API ERROR #{$body->httpStatusCode} \"{$body->errorCode}\": {$body->message} (Error ID: {$body->errorId})");

				# Also display a simplified version of the error to the admin.
				$error = new \StdClass;
				$error->timestamp = date('Y-m-d H:i:s');
				$error->code = $body->httpStatusCode;
				if ($error->code == 401) {
					$error->message = 'Your SplitOff API credentials are incorrect.';
				} else {
					$error->message = 'The SplitOff Pay for WooCommerce plugin cannot communicate with the SplitOff API.';
				}
				if (property_exists($body, 'errorId') && $body->errorId) {
					$error->id = $body->errorId;
				}
				$this->store_api_error($error);
				if (is_admin()) {
					# Admin has just saved the settings. The "admin_notices" action has already triggered, so we need
					# to implement WooCommerce's secondary notice reporting function instead.
					$text = __( "SplitOff API Error #{$error->code}: {$error->message}", 'woo_splitoff' );
					if (property_exists($error, 'id') && $error->id) {
						$text .= __( " (Error ID: {$error->id})", 'woo_splitoff' );
					}
					WC_Admin_Settings::add_error($text);
				}
				# Return Error Code only if 401 - to reset the Payment Limits
				if ($error->code = 401) {
					return $error->code;
				}
			}
		} elseif ($response === false) {
			# Response is false
		}

		return false;
	}

	/**
	 * Get the valid payment types available from SplitOff for this amount.
	 *
	 * Note:	This is only for API v0.
	 *
	 * @since	1.0.0
	 * @param	float	$order_total	Order Total Amount
	 * @uses	self::build_money_object()
	 * @uses	self::post_to_api()
	 * @return	array|false					The list of available types, or false on error.
	 */
	public function get_payment_types_for_amount($order_total) {
		$response = $this->post_to_api($this->payment_types_url, array(
			'orderAmount' => $this->build_money_object($order_total)
		));

		if (is_wp_error( $response )) {
			# WP Error Detected
		} elseif (is_object($response)) {
			$body = $response->body;

			if (is_array($body)) {
				return $body;
			} elseif (is_object($body) && property_exists($body, 'errorCode')) {
				# Log the error details.
				WC_Gateway_SplitOff::log("API ERROR #{$body->httpStatusCode} \"{$body->errorCode}\": {$body->message} (Error ID: {$body->errorId})");
			}
		} elseif ($response === false) {
			# Response is false
		}

		return false;
	}

	/**
	 * Request an order token from SplitOff.
	 *
	 * Note:	This is only for API v0.
	 *
	 * @since	1.0.0
	 * @param	WC_Order|null	$order	An instance of WC_Order or null.
	 * @param	string			$type	Payment type. Defaults to "PBI".
	 * @uses	self::build_money_object()
	 * @uses	wc_get_product()		Available in WooCommerce core since 2.2.0.
	 *									Also see:	WC()->product_factory->get_product()
	 *									Also see:	WC_Product_Factory::get_product()
	 * @return	string|bool				Returns the token string or false if no order token could be generated.
	 * @used-by	WC_Gateway_SplitOff::process_payment()
	 */
	public function get_order_token_for_wc_order($order = null, $type = 'PBI') {
		if (!($order instanceof WC_Order)) {
			return false;
		}

		$order_id = $order->get_id();

		$data = array(
			'consumer' => array(
				'mobile' => $order->billing_phone,
				'givenNames' => $order->billing_first_name,
				'surname' => $order->billing_last_name,
				'email' => $order->billing_email
			),
			'paymentType' => $type,
			'orderDetail' => array(
				'merchantOrderDate' => time(),
				'merchantOrderId' => $order_id,
				'items' => array(), # Populated below.
				'includedTaxes' => $this->build_money_object($order->get_cart_tax()),
				'shippingAddress' => array(
					'name' => $order->shipping_first_name . ' ' . $order->shipping_last_name,
					'address1' => $order->shipping_address_1,
					'address2' => $order->shipping_address_2,
					'suburb' => $order->shipping_city,
					'postcode' => $order->shipping_postcode
				),
				'billingAddress' => array(
					'name' => $order->billing_first_name . ' ' . $order->billing_last_name,
					'address1' => $order->billing_address_1,
					'address2' => $order->billing_address_2,
					'suburb' => $order->billing_city,
					'postcode' => $order->billing_postcode
				),
				'orderAmount' => $this->build_money_object($order->get_total())
			)
		);

		$order_items = $order->get_items();

		if (count($order_items)) {
			foreach ($order_items as $item) {
				if ($item['variation_id']) {
					if (function_exists('wc_get_product')) {
						$product = wc_get_product( $item['variation_id'] );
					} else {
						$product = new WC_Product( $item['variation_id'] );
					}
				} else {
					if (function_exists('wc_get_product')) {
						$product = wc_get_product( $item['product_id'] );
					} else {
						$product = new WC_Product( $item['product_id'] );
					}
				}

				$data['orderDetail']['items'][] = array(
					'name' => $item['name'],
					'sku' => $product->get_sku(),
					'quantity' => $item['qty'],
					'price' => $this->build_money_object($item['line_subtotal'] / $item['qty'])
				);
			}
		}

		if ($order->get_shipping_method()) {
			$data['orderDetail']['shippingCourier'] = substr($order->get_shipping_method(), 0, 127);
			$data['orderDetail']['shippingCost'] = $this->build_money_object($order->get_total_shipping());
		}

		if ($order->get_total_discount()) {
			$data['orderDetail']['discountType'] = 'Discount';
			$data['orderDetail']['discount'] = $this->build_money_object(0 - $order->get_total_discount());
		}

		$response = $this->post_to_api($this->orders_url, $data);
		//WC_Gateway_SplitOff::log('SOME: ' . print_r($response));
		if (is_wp_error( $response )) {
			# A WP Error is encountered
		} elseif (is_object($response)) {
			$body = $response->body;

			if (is_object($body)) {
				if (property_exists($body, 'orderToken') && is_string($body->orderToken) && strlen($body->orderToken) > 0) {
					return $body->orderToken;
				} elseif (property_exists($body, 'errorCode')) {
					# Log the error details.
					WC_Gateway_SplitOff::log("API ERROR #{$body->httpStatusCode} \"{$body->errorCode}\": {$body->message} (Error ID: {$body->errorId})");
				}
			} else {
				# Body is not an Object
			}
		} elseif ($response === false) {
			# Response is false
		}

		return false;
	}

	/**
	 * Request an order token from SplitOff.
	 *
	 * Note:	This is only for API v1 in Compatibility Mode.
	 *
	 * @since	2.1.0
	 * @param	WC_Order|null				$order		An instance of WC_Order or null.
	 * @uses	self::build_money_object()
	 * @uses	WC_Order::get_address_prop()			Available in WooCommerce core since 3.0.0.
	 * @return	string|bool								Returns the token string, or false if no order token
	 *													could be generated.
	 * @used-by	WC_Gateway_SplitOff::process_payment()
	 */
	public function get_order_token_for_wc_order_in_v1($order = null) {

	    /*if (!($order instanceof WC_Order)) {
			return false;
		}*/

		$order_id = $order->get_id();

		$data = array(
			'amount' => $this->build_money_object($order->get_total())
		);

		/*if (version_compare( t, '3.0.0', '>=' )) {
			$data['consumer'] = array(
				'phoneNumber' => $order->get_billing_phone(),
				'givenNames' => $order->get_billing_first_name(),
				'surname' => $order->get_billing_last_name(),
				'email' => $order->get_billing_email()
			);
			$data['billing'] = array(
				'name' => $order->get_billing_first_name() . ' ' . $order->get_billing_first_name(),
				'line1' => $order->get_billing_address_1(),
				'line2' => $order->get_billing_address_2(),
				'suburb' => $order->get_billing_city(),
				'state' => $order->get_billing_state(),
				'postcode' => $order->get_billing_postcode(),
				'countryCode' => $order->get_billing_country(),
				'phoneNumber' => $order->get_billing_phone()
			);
			if ($order->needs_shipping_address()) {
				$data['shipping'] = array(
					'name' => $order->get_shipping_first_name() . ' ' . $order->get_shipping_first_name(),
					'line1' => $order->get_shipping_address_1(),
					'line2' => $order->get_shipping_address_2(),
					'suburb' => $order->get_shipping_city(),
					'state' => $order->get_shipping_state(),
					'postcode' => $order->get_shipping_postcode(),
					'countryCode' => $order->get_shipping_country()
				);
			}

			//$data['courier'] = array();
			$data['merchant'] = array(
				'redirectConfirmUrl' => $order->get_checkout_payment_url( true ),
				'redirectCancelUrl' => $order->get_cancel_order_url_raw()
			);
			$data['merchantReference'] = $order->get_order_number();
			$data['taxAmount'] = $this->build_money_object($order->get_total_tax());
			$data['shippingAmount'] = $this->build_money_object($order->get_shipping_total());
		} else {*/
			$data['consumer'] = array(
				'phoneNumber' => $order->billing_phone,
				'givenNames' => $order->billing_first_name,
				'surname' => $order->billing_last_name,
				'email' => $order->billing_email
			);
			$data['billing'] = array(
				'name' => $order->billing_first_name . ' ' . $order->billing_last_name,
				'line1' => $order->billing_address_1,
				'line2' => $order->billing_address_2,
				'suburb' => $order->billing_city,
				'postcode' => $order->billing_postcode
			);
			if ($order->needs_shipping_address()) {
				$data['shipping'] = array(
					'name' => $order->shipping_first_name . ' ' . $order->shipping_last_name,
					'line1' => $order->shipping_address_1,
					'line2' => $order->shipping_address_2,
					'suburb' => $order->shipping_city,
					'postcode' => $order->shipping_postcode
				);
			}
			/**
			 * @todo Retrieve courier information from WC version < 3.0.0 and send to SplitOff
			 */
			//$data['courier'] = array();
			/*if ($order->get_shipping_method()) {
				$data['courier']['name'] = substr($order->get_shipping_method(), 0, 127);
			}*/

			//WC_Gateway_SplitOff::log(print_r($order->get_checkout_payment_url( true )));

			$data['merchant'] = array(
				'redirectConfirmUrl' => $order->get_checkout_payment_url( true ),
				'redirectCancelUrl' => $order->get_cancel_order_url_raw()
			);
			$data['merchantReference'] = $order_id;
			$data['taxAmount'] = $this->build_money_object($order->get_cart_tax());
			$data['shippingAmount'] = $this->build_money_object($order->get_total_shipping());
		//}

		$order_items = $order->get_items();

		if (count($order_items)) {
			$data['items'] = array();

			foreach ($order_items as $item) {
				if ($item['variation_id']) {
					if (function_exists('wc_get_product')) {
						$product = wc_get_product( $item['variation_id'] );
					} else {
						$product = new WC_Product( $item['variation_id'] );
					}
				} else {
					if (function_exists('wc_get_product')) {
						$product = wc_get_product( $item['product_id'] );
					} else {
						$product = new WC_Product( $item['product_id'] );
					}
				}

				$data['items'][] = array(
					'name' => $item['name'],
					'sku' => $product->get_sku(),
					'quantity' => $item['qty'],
					'price' => $this->build_money_object($item['line_subtotal'] / $item['qty'])
				);
			}
		}

		/**
		 * @todo Send discounts to SplitOff
		 */
		/*if ($order->get_total_discount()) {
			$data['discounts'] = array();
			$data['discounts'][] = array(
				'displayName' => 'Discount',
				'amount' => $this->build_money_object(0 - $order->get_total_discount())
			);
		}*/

		$response = $this->post_to_api($this->orders_url, $data);

        /*
		WC_Gateway_SplitOff::log(is_object( $response ));
		return ($response);
        */


		if (is_wp_error( $response )) {
			# A WP Error is encountered
		} elseif (is_object($response)) {
			$body = $response->body;

			if (is_object($body)) {
				if (property_exists($body, 'token') && is_string($body->token) && strlen($body->token) > 0) {
					$api_response_str = "SplitOff order token: {$body->token}";

					WC_Gateway_SplitOff::log($api_response_str);
					$order->add_order_note( __( $api_response_str, 'woo_splitoff' ) );

					return $body->token;
				} elseif (property_exists($body, 'errorCode')) {
					$api_response_str = "API Error #{$body->httpStatusCode} \"{$body->errorCode}\": {$body->message} (Error ID: {$body->errorId})";

					WC_Gateway_SplitOff::log($api_response_str);
					$order->add_order_note( __( $api_response_str, 'woo_splitoff' ) );

					wc_add_notice( __( "Sorry, there was a problem preparing your payment. (Error #{$body->httpStatusCode}: {$body->message})", 'woo_splitoff' ), 'error' );
				}
			} else {
				# Body is not an Object
			}
		} elseif ($response === false) {
			# Response is false
		} else {
			# ???
		}

		return false;

	}

	/**
	 * Request an order token from SplitOff.
	 *
	 * Note:	This is only for API v1.
	 *
	 * @since	1.0.0
	 * @param	array|null		$data	The jsonifiable order data to be posted to the API.
	 * @return	string|false
	 * @used-by	WC_Gateway_SplitOff::override_order_creation()
	 */
	public function get_order_token_for_splitoff_quote($data = null) {
		$response = $this->post_to_api($this->orders_url, $data);


        //$response = json_decode($response, true);
        //WC_Gateway_SplitOff::log('Merchant: ' . print_r($response['token']));


		if (is_wp_error( $response )) {
			# A WP Error is encountered
		} elseif (is_object($response)) {
			$body = $response->body;

			if (is_object($body)) {
				if (property_exists($body, 'token') && is_string($body->token) && strlen($body->token) > 0) {
					return $body;
				} elseif (property_exists($body, 'errorCode')) {
					# Log the error details.
					WC_Gateway_SplitOff::log("API ERROR #{$body->httpStatusCode} \"{$body->errorCode}\": {$body->message} (Error ID: {$body->errorId})");
				}
			} else {
				# Body is not an Object
			}
		} elseif ($response === false) {
			# Response is false
		}

		//return false;
	}

	/**
	 * Post a direct payment capture request for a given token.
	 *
	 * Note:	This is only for API v1.
	 *
	 * @since	1.0.0
	 * @param	string			$token				The token that SplitOff gave us for the order.
	 * @param	string			$splitoff_quote_id	The Merchant Order Number.
	 * @return	string|false						Either "APPROVED", "DECLINED" or false.
	 * @used-by	WC_Gateway_SplitOff::confirm_splitoff_quote()
	 */
	public function direct_payment_capture($token, $splitoff_quote_id = '') {
		WC_Gateway_SplitOff::log("SplitOff_Plugin_Merchant::direct_payment_capture('{$token}', '{$splitoff_quote_id}')...");

		$quote = get_post($splitoff_quote_id);

		$data = array(
			'token' => $token
		);

		if (!empty($splitoff_quote_id)) {
			$data['merchantReference'] = $splitoff_quote_id;
		}

		$response = $this->post_to_api($this->direct_payment_capture_url, $data);

		if (is_wp_error( $response )) {

			# Log the WP Error object.
			$error_code = json_encode($response->get_error_codes());
			$error_messages = json_encode($response->get_error_messages());

			$log_str = "WP ERROR: {$error_code}; WP ERROR MESSAGES: {$error_messages}";
			WC_Gateway_SplitOff::log($log_str);

		} elseif (is_object($response)) {
			$body = $response->body;

			if (is_object($body)) {
				if (property_exists($body, 'status') && is_string($body->status) && strlen($body->status) > 0) {
					# Note: $body->status will be either "APPROVED" or "DECLINED".

					# If successful, attach the SplitOff Order ID to the quote.
					if (property_exists($body, 'id') && $body->id && $splitoff_quote_id > 0) {
						add_post_meta( $splitoff_quote_id, 'splitoff_order_id', (int)$body->id );
					}

					# Log the response.
					$log_str = "PAYMENT {$body->status}";
					if (property_exists($body, 'id') && $body->id) {
						$log_str .= " (SplitOff Order ID: {$body->id})";
					}
					if (property_exists($body, 'errorId') && $body->errorId) {
						$log_str .= " (Error ID: {$body->errorId})";
					}
					WC_Gateway_SplitOff::log($log_str);

					# Return the status response.
					return $body->status;
				} elseif (property_exists($body, 'httpStatusCode') && $body->httpStatusCode == 402) {
					# Note: If the payment is declined, the API will probably throw a 402 error instead of $body->status == "DECLINED".

					# Log the decline.
					WC_Gateway_SplitOff::log('PAYMENT DECLINED' . ((property_exists($body, 'errorId') && $body->errorId) ? " (Error ID: {$body->errorId})" : ''));

					# Return the standardised status response.
					return 'DECLINED';
				} elseif (property_exists($body, 'errorCode')) {
					# Log the error details.
					WC_Gateway_SplitOff::log("API ERROR #{$body->httpStatusCode} \"{$body->errorCode}\": {$body->message} (Error ID: {$body->errorId})");
				} else {
					# Empty Body
				}
			} else {
				# Body is not an object
			}
		} elseif ($response === false) {
			# Response is not an object
		} else {
			# Response is not false, not an object and no WP Error
		}

		return false;
	}

	/**
	 * Post a direct payment capture request for a given token in compatibility mode.
	 *
	 * Note:	This is only for API v1 in Compatibility Mode.
	 *
	 * @since	2.1.0
	 * @param	string			$token							The token that SplitOff gave us for the order.
	 * @return	StdClass|false									Either an object with id and status of "APPROVED"
	 *															or "DECLINED", or false.
	 * @used-by	WC_Gateway_SplitOff::on_redirect_confirm_url
	 */
	public function direct_payment_capture_compatibility_mode($token) {
		$data = array(
			'token' => $token
		);

		$response = $this->post_to_api($this->direct_payment_capture_url, $data);

		if (is_wp_error( $response )) {
			# A WP Error is encountered
		} elseif (is_object($response)) {
			$body = $response->body;

			if (is_object($body)) {
				if (property_exists($body, 'id') && property_exists($body, 'status')) {
					# Note: `$body->status` can only be "APPROVED".

					# Log the response.
					WC_Gateway_SplitOff::log("PAYMENT {$body->status} (SplitOff Order ID: {$body->id})");

					# Return the id and status in response.

					$response_obj = new \StdClass;
					$response_obj->id = $body->id;
					$response_obj->status = $body->status;

					return $response_obj;
				} elseif (property_exists($body, 'httpStatusCode') && $body->httpStatusCode == 402) {
					# Note: If the payment is declined, the API will throw a 402 error instead of a Payment object with a status of "DECLINED".

					# Log the decline.
					WC_Gateway_SplitOff::log("API Error #{$body->httpStatusCode} \"{$body->errorCode}\": {$body->message} (Error ID: {$body->errorId})");

					# Use the token to find out the SplitOff Order ID.

					$get_payment_response = $this->get_from_api("{$this->payments_url}/token:{$token}");

					if (!is_wp_error( $get_payment_response ) && is_object($get_payment_response)) {
						$get_payment_response_body = $get_payment_response->body;
					} else {
						$get_payment_response_body = false;
					}

					# Return the id and status in response.

					$response_obj = new \StdClass;
					$response_obj->id = 0;
					$response_obj->status = 'DECLINED';
					if (is_object($get_payment_response_body)) {
						if (property_exists($get_payment_response_body, 'id')) {
							$response_obj->id = $get_payment_response_body->id;
						}
						if (property_exists($get_payment_response_body, 'status')) {
							$response_obj->status = $get_payment_response_body->status;
						}
					}

					return $response_obj;
				} elseif (property_exists($body, 'errorCode')) {
					# Log the error details.
					WC_Gateway_SplitOff::log("API Error #{$body->httpStatusCode} \"{$body->errorCode}\": {$body->message} (Error ID: {$body->errorId})");
				} else {
					# Empty Body
				}
			} else {
				# Body is not an object
			}
		} elseif ($response === false) {
			# Response is not an object
		} else {
			# Response is not false, not an object and no WP Error
		}

		return false;
	}

	/**
	 * Post a refund request for a given payment.
	 *
	 * @since	1.0.0
	 * @param	WC_Order	$order	The WooCommerce order that this refund relates to.
	 * @param	float		$amount	The amount to be refunded.
	 * @uses	self::build_money_object()
	 * @uses	self::post_to_api()
	 * @uses	property_exists()
	 * @uses	is_object()
	 * @uses	is_wp_error()
	 * @used-by	WC_Gateway_SplitOff::process_refund()
	 */
	public function create_refund($order, $amount) {
		$splitoff_order_id = $order->get_transaction_id();

        WC_Gateway_SplitOff::log("TEST API  ");

		if (method_exists($order, 'get_currency')) {
		    $currency = $order->get_currency();
		} else {
		    $currency = $order->get_order_currency();
		}

		if ($this->api_version == 'v0') {
			//$response = $this->post_to_api("{$this->orders_url}/{$splitoff_order_id}/refunds", array(
			$response = $this->post_to_api("{$this->refund_url}?id={$splitoff_order_id}", array(
				'amount' => $this->build_money_object(0 - $amount, $currency),
				'merchantRefundId' => ''
			));
			$refund_id_property = 'id';
		} elseif ($this->api_version == 'v1') {
			//$response = $this->post_to_api("{$this->payments_url}/{$splitoff_order_id}/refund", array(
			$response = $this->post_to_api("{$this->refund_url}?id={$splitoff_order_id}", array(
				'amount' => $this->build_money_object($amount, $currency)
			));
			$refund_id_property = 'refundId';
		} else {
			# unknown API - do nothing
			$response = false;
		}

		if (is_wp_error($response) || $response === false) {

			# WP Error
			$response = print_r($response, true);
			WC_Gateway_SplitOff::log("API ERROR. WP Error: {$response}");

			return false;

		} elseif (is_object($response)) {
			$body = $response->body;

			if (is_object($body)) {
				if (property_exists($body, $refund_id_property) && $body->{$refund_id_property}) {
					# Log the ID.
					WC_Gateway_SplitOff::log("Refund succesful. Refund ID: {$body->{$refund_id_property}}.");

					# Return true.
					return true;
				} elseif (property_exists($body, 'errorCode')) {
					# Log the error details.
					WC_Gateway_SplitOff::log("API ERROR #{$body->httpStatusCode} \"{$body->errorCode}\": {$body->message} (Error ID: {$body->errorId})");
				} else {
					# Missing required Body Response
					$response = print_r($response, true);
					WC_Gateway_SplitOff::log("API ERROR. Missing required Body Response: {$response}");
				}
			} else {
				# Response body is not an object
				# Log the unexpected response
				$response = print_r($response, true);
				WC_Gateway_SplitOff::log("API ERROR. Response is not an Object: {$response}");
			}
		}

		return false;
	}

	/**
	 * Get the SplitOff Order Details
	 * Note:	This is only for API v0.
	 *
	 * @since	1.0.0
	 * @param	WC_Order|null	$order					The WooCommerce order that we want to find out about.
	 * @param	string			$splitoff_order_id		Optional. The ID of the SplitOff order that we want to
	 *													find out about. Defaults to "".
	 * @uses	self::get_from_api()
	 * @return	StdClass|false
	 * @used-by	WC_Gateway_SplitOff::payment_callback()
	 * @used-by	SplitOff_Plugin_Cron::check_pending_abandoned_orders()
	 */
	public function get_order($order, $splitoff_order_id = '') {
		if ($this->api_version != 'v0') {
			return false;
		}

		if (!empty($splitoff_order_id)) {
			$endpoint_url = "{$this->orders_url}/{$splitoff_order_id}";
		} elseif ($order instanceof WC_Order) {

			$order_id = $order->get_id();

			$custom_keys = get_post_custom_keys($order_id);
			if (in_array('_transaction_id', $custom_keys)) {
				# Use the SplitOff Order ID if available.
				$splitoff_order_id = get_post_meta( $order_id, '_transaction_id', true );
				$endpoint_url = "{$this->orders_url}/{$splitoff_order_id}";
			} elseif (in_array('_splitoff_token', $custom_keys)) {
				# Otherwise use the SplitOff Order token.
				$splitoff_order_token = get_post_meta( $order_id, '_splitoff_token', true );
				$endpoint_url = "{$this->orders_url}?token={$splitoff_order_token}";
			} else {
				# Missing vital arguments, failing the operations
				return false;
			}
		} else {
			# Invalid arguments.
			return false;
		}

		$response = $this->get_from_api($endpoint_url);

		if (is_wp_error( $response )) {
			# WP Error Detected
		} elseif (is_object($response)) {
			$body = $response->body;

			if (is_object($body)) {
				if (property_exists($body, 'id') && property_exists($body, 'status')) {
					return $body;
				} elseif (property_exists($body, 'errorCode')) {
					# Log the error details.
					WC_Gateway_SplitOff::log("API ERROR #{$body->httpStatusCode} \"{$body->errorCode}\": {$body->message} (Error ID: {$body->errorId})");
				}
			} else {
				# Body is not an Object
			}
		} elseif ($response === false) {
			# Response is not an Object
		}

		return false;
	}

	/**
	 * Get the SplitOff Order Details
	 * Note:	This is only for API v1.
	 *
	 * @since	2.1.0
	 * @param	string			$token					The SplitOff order token.
	 * @uses	self::get_from_api()
	 * @return	StdClass|false
	 * @used-by	WC_Gateway_SplitOff::payment_callback()
	 */
	public function get_order_by_v1_token($token) {
		if ($this->api_version != 'v1') {
			return false;
		}

		$endpoint_url = "{$this->orders_url}/{$token}";

		$response = $this->get_from_api($endpoint_url);

		if (is_wp_error( $response )) {
			# WP Error Detected
		} elseif (is_object($response)) {
			$body = $response->body;

			if (is_object($body)) {
				if (property_exists($body, 'errorCode')) {
					# Log the error details.
					WC_Gateway_SplitOff::log("API ERROR #{$body->httpStatusCode} \"{$body->errorCode}\": {$body->message} (Error ID: {$body->errorId})");
				}

				return $body;
			} else {
				# Body is not an Object
			}
		} elseif ($response === false) {
			# Response is not an Object
		}

		return false;
	}
}
