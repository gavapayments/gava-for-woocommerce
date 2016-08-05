<?php

/**
 * Some reading
 *
 * https://docs.woocommerce.com/document/payment-gateway-api/
 * http://woocommerce.wp-a2z.org/oik_api/wc_abstract_orderpayment_complete/
 * https://www.skyverge.com/blog/get-woocommerce-page-urls/
 * http://www.rcorreia.com/woocommerce/woocommerce-automatically-set-order-status-payment-received/
 * https://www.skyverge.com/blog/how-to-set-woocommerce-virtual-order-status-to-complete-after-payment/
 */

class WC_Gava extends WC_Payment_Gateway {

	public function __construct()
	{
		$this->id = 'gava';
		$this->icon = WP_PLUGIN_URL . "/" . plugin_basename( dirname(__FILE__)) . '/assets/icon.png';
		$this->icon = null;
		$this->method_title = 'Gava';
		$this->has_fields = false;

		$this->init_form_fields();
		$this->init_settings();

		$this->title = $this->settings['title'];
		$this->description =  $this->settings['description'];
		$this->checkoutUrl = $this->settings['checkout_url'];
		$this->secretKey = $this->settings['secret'];
		$this->instructionLabel = $this->settings['instruction_label'];
		$this->buttonLabel = $this->settings['button_label'];
		
		$this->msg['message'] = "";
		$this->msg['class'] = "";

		if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>='))
		{
			add_action( 'woocommerce_update_options_payment_gateways_'.$this->id, array( &$this, 'process_admin_options' ) );
		} else {
			add_action('woocommerce_update_options_payment_gateways', array( &$this, 'process_admin_options' ) );
		}

		add_action('woocommerce_receipt_gava', array(&$this, 'receipt_page'));
		add_action('woocommerce_api_' . strtolower(get_class($this)), array(&$this, 'webhook'));
	}

	function init_form_fields()
	{
		$this->form_fields = array(

			'enabled' => array(
				'title' => __('Enable/Disable', 'gava'),
				'type' => 'checkbox',
				'label' => __('Enable Gava Payments.', 'gava'),
				'default' => 'no'
			),

			'title' => array(
				'title' => __('Title:', 'gava'),
				'type' => 'text',
				'description' => __('Title shown to the customer when picking a payment method.', 'gava'),
				'default' => __('EcoCash and Telecash', 'gava')
			),

			'description' => array(
				'title' => __('Description:', 'gava'),
				'type' => 'textarea',
				'description' => __('Description shown to the customer when picking a payment method.', 'gava'),
				'default' => __('Pay securely with EcoCash and Telecash.', 'gava')
			),

			'instruction_label' => array(
				'title' => __('Description:', 'gava'),
				'type' => 'textarea',
				'description' => __('Guiding text shown above the link or button which takes the customer to gava.', 'gava'),
				'default' => __(
					'Thank you for your order, please click the button below to pay with Ecocash or Telecash.',
					'gava')
			),

			'button_label' => array(
				'title' => __('Description:', 'gava'),
				'type' => 'textarea',
				'description' => __('Text in the button customer clicks to go to Gava.', 'gava'),
				'default' => __('Pay with Ecocash or Telecash.','gava')
			),

			'checkout_url' => array(
				'title' => __('Checkout URL', 'gava'),
				'type' => 'text',
				'description' => __('Your Gava checkout URL.', 'gava')),

			'secret' => array(
				'title' => __('Gava Secret Key', 'gava'),
				'type' => 'text',
				'description' => __('Secret key', 'gava')),			
		);
			
	}// end init form fields

	public function admin_options()
	{
		echo "<h3>".__('Gava Payment Gateway', 'gava')."</h3>";
		echo "<p>".__('Gava allows you to automate payments with just the line in your phone', 'gava')."</p>";
		echo '<table class="form-table">';
		//Generate the HTML for the settings form
		$this->generate_settings_html();
		echo '</table>';
	}

	/*
	 *  There are no payment fields for Gava, but we want to show the description if set
	 */
	function payment_fields(){
		if ( $this->description ) echo wpautop(wptexturize($this->description));
	}

	/**
	 * Receipt Page
	 *
	 * @todo Redirect user soon as they hit this page
	 */
	function receipt_page($order)
	{
		global $woocommerce;

		$woocommerce->cart->empty_cart();
		echo '<p>'.__($this->instructionLabel, 'gava').'</p>';
		$redirect = $this->createGavaCheckout($order);
		echo '<div><a class="button" href="'.$redirect.'">'.__($this->buttonLabel, 'gava').'</a></div>';
	}

	/**
	 * Exit with error during checkout
	 *
	 * @param mixed $error Error
	 * @return void
	 */
	function exitWithError($error)
	{
		global $woocommerce;

		$cartURL = $woocommerce->cart->get_cart_url();
		//$woocommerce->cart->get_checkout_url();

		$cartURL = "javascript: alert('ToDo');";
		echo "<p>Failed to create checkout. Please contact support, or <a href='".$cartURL."'>click here</a> ".
			"to return to the website and try again</p>";

		
		//Just gonna leave this here
		echo '<p>Error details: </p><pre>';
		print_r($error);
		echo '</pre>';
		

		exit();
	}

	/**
	 * Exit with error
	 *
	 * @todo 	HTTP Status
	 * @param 	string $error Error
	 * @return 	void
	 */
	function callbackError($error)
	{
		echo $error;
		die();
	}

	/**
	 * Fetches checkout with given hash.
	 * A return of false generally means the checkout is not valid to us
	 * Will exit with error for the ither scenarios
	 *
	 * @param string $hash Checkout hash
	 * return object|false
	 */
	function fetchCheckout($hash)
	{
		//Get checkout, confirm sig
		$endpoint = rtrim($this->checkoutUrl, '/') . '/checkout/details/' . $hash;

		$response = wp_remote_get($endpoint);

		if (is_wp_error($response)) {
			return false;
		}

		$responseCode = wp_remote_retrieve_response_code($response);

		if ($responseCode !== 200) {
			$this->callbackError('Non-200 status during checkout fetch');
		}

		$checkout = json_decode(wp_remote_retrieve_body($response));

		if (!$checkout) return false;

		$expectedProperties = array(
			'checkoutId',
			'checkoutHash',
			'reference',
			'paid',
			'amount',
			'phone',
			'transactionCode',
			'paymentMethod',
			'note',
			'signature'
		);

		foreach ($expectedProperties as $property) {

			if (!property_exists($checkout, $property)) return false;

		}

		if (!$this->validateSignature($checkout)) return false;

		return $checkout;
	}

	/**
	 * Given an iterable $payload, it authenticates its signature property
	 *
	 * @param 	mixed $payload Object or array
	 * @return 	mixed
	 */
	function validateSignature($request)
	{
		$string = '';

		foreach ($request as $key => $value) {
			if ($key === 'signature') continue;

			$string .= $value;
		}

		$signature = hash('sha512', $string . $this->secretKey);
		return ($signature === $request->signature);
	}

	/**
	 * Creates Gava checkout and returns the checkout URL
	 *
	 * @param 	mixed $orderId Order ID
	 * @return 	string
	 */
	public function createGavaCheckout($orderId)
	{		
		global $woocommerce;

		$order = new WC_Order($orderId);
		
		$redirect_url = add_query_arg( 'utm_nooverride', '1', $this->get_return_url($order));
		$return_url = add_query_arg('utm_nooverride', '1', $this->get_return_url($order));

		$payload = array(
			'reference' => $orderId,
			'amount' => number_format($order->order_total, 2, '.', null),
			'return_url' => $return_url,
			'cancel_url' => $order->get_cancel_order_url(),
		);

		$payload['signature'] = $this->sign($payload);

		//create checkout, redirect to it
		$endpoint = rtrim($this->checkoutUrl, '/') . '/create?return_checkout_url';
		$response = wp_remote_post($endpoint, array('body' => $payload));

		if (is_wp_error($response)) {
			$this->exitWithError($response);
		}

		$checkoutUrl = wp_remote_retrieve_body($response);

		$responseCode = wp_remote_retrieve_response_code($response);

		if ($responseCode !== 200) {
			$this->exitWithError($response);
		}

		return $checkoutUrl;
	}

	/*
	 * Process the payment and return the result
	 */	

	function process_payment($orderId)
	{
		$order = new WC_Order($orderId);

		if ( version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>') )
		{
			return array('result' => 'success', 'redirect' => $order->get_checkout_payment_url(true));
		} else {
			return array(
				'result' => 'success',
				'redirect' => add_query_arg(
					'order',
					$order->id,
					add_query_arg(
						'key',
						$order->order_key,
						get_permalink(get_option('woocommerce_pay_page_id')
					)
				)));
		}
	}    

	/**
	 * Given an iterable $payload, it signs it with the secret key
	 *
	 * @param 	mixed $payload Object or array
	 * @return 	mixed
	 */
	function sign($payload)
	{
		$string = '';

		foreach ($payload as $key => $value) {
			if ($key === 'signature') continue;

			$string .= $value;
		}

		return hash('sha512', $string . $this->secretKey);
	}

	/**
	 * Exit the script with (optional) $message
	 *
	 * @param string|null $message Message
	 * @return void
	 */
	function exitScript($message = null)
	{
		if ($message) echo $message;
		exit();
	}

	/**
	 * Webhooks handler
	 *
	 * @return void
	 */
	function webhook()
	{
		@ob_clean();
		global $woocommerce;

		//Listen for callback, validate with server, close checkout
		$callback = json_decode(file_get_contents('php://input'));

		if (!$callback) $this->callbackError('Missing parameters');

		$expectedProperties = array(
			'checkoutId',
			'checkoutHash',
			'reference',
			'paid',
			'amount',
			'phone',
			'transactionCode',
			'paymentMethod',
			'note',
			'signature'
		);

		foreach ($expectedProperties as $property) {

			if (!property_exists($callback, $property)) $this->callbackError('Missing parameters');

		}

		if (!$this->validateSignature($callback))
			$this->callbackError('Callback signature validation failed');

		if (!$checkout = $this->fetchCheckout($callback->checkoutHash))
			$this->callbackError('Checkout fetch failed');

		//Defense: Gava doesn't yet have automated status changes from paid to not paid
		if (!$checkout->paid) $this->exitScript('Checkout not paid on Gava');

		$orderId = $checkout->reference;
		if (!$orderId) $this->exitScript('Reference empty');
			
		$order = new WC_Order($orderId);
		if (!$order) $this->exitScript('Order not found');

		if ($order->status === 'completed' || $order->status === 'processing')
			$this->exitScript('Callback already processed');

		// Validate Amount
	    if (!$this->floatsEqual($order->get_total(), $checkout->amount)) {
	    	// Put this order on-hold for manual checking
	    	$order->update_status(
	    		'on-hold',
	    		sprintf( __(
	    			'Validation error: Amount %s paid does not match the required.', 'woocommerce' ), 
	    			$checkout->amount
	    	));

	    	return;

	    	//Email admin
	    	$mailer = $woocommerce->mailer();

        	$message = $mailer->wrap_message(
        		__( 'Amount paid does not match that required for the order', 'woocommerce' ),
        		sprintf( __(
        			'Order %s has had an amount mismatch. Please review in Gava (checkout ID %s) .', 'woocommerce'),
        			$order->get_order_number(),
        			$checkout->checkoutId
        		)
			);

			$mailer->send(
				get_option('admin_email'),
				sprintf(
					__('Payment amount mismatch for order %s', 'woocommerce'),
					$order->get_order_number()
				),
				$message
			);

	    	return;
	    }

	    //We get here, we can complete the order successfully
	    update_post_meta($order->id, 'Paid from number', $checkout->phone);
        update_post_meta($order->id, 'Transaction code', $checkout->transactionCode);

		$order->payment_complete();
		$order->add_order_note("Gava payment successful<br/>Unique Checkout ID: ".$checkout->checkoutId);
	}

	function floatsEqual($a, $b)
	{
		$a = (float)$a;
		$b = (float)$b;

		if (abs(($a-$b)/$b) < 0.00001) {
			return TRUE;
		}

		return FALSE;
	}

}
	

function woocommerce_add_gava_gateway($methods)
{
	$methods[] = 'WC_Gava';
	return $methods;
}

add_filter('woocommerce_payment_gateways', 'woocommerce_add_gava_gateway');
