<?php
	/**
	 * Plugin Name: Paybyrd
	 * Description: Take payments from WooCommerce on your store using Paybyrd.
	 * Author: Paybyrd
	 * Author URI: https://www.paybyrd.com
	 * Version: 2.19.0
	 * Domain Path: /languages
	 */

	if (!defined('ABSPATH')) {
		exit; // Exit if accessed directly
	}

	/**
	 * Check if WooCommerce is active
	 **/
	if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
		add_action('plugins_loaded', 'init_paybyrd_payment_class');

		function init_paybyrd_payment_class() {
			// Load Translations
			load_plugin_textdomain('paybyrd-woocommerce', false, plugin_basename(dirname(__FILE__)) . '/languages');
	
			class WC_Gateway_Paybyrd extends WC_Payment_Gateway {
				public $domain;

				/**
				 * Constructor for the gateway.
				 */
				public function __construct() {
					$this->id					= 'paybyrd';
					$this->has_fields			= false;
					$this->method_title			= __('Paybyrd Gateway', 'paybyrd-woocommerce');
					$this->method_description	= __('Allows payments with paybyrd gateway', 'paybyrd-woocommerce');
					$this->API_URL				= 'https://gateway.paybyrd.com/';
					$this->WEBHOOK_API_URL		= 'https://webhook.paybyrd.com/';

					// Load the settings.
					$this->init_form_fields();
					$this->init_settings();

					// Define user set variables
					$this->title						= $this->get_option('title');
					$this->description					= $this->get_option('description');
					$this->paidOrderStatus				= $this->get_option('paidOrderStatus');
					$this->enabled						= $this->get_option('enabled');
					$this->instructions					= $this->get_option('instructions', $this->description);
					$this->testmode						= 'yes' === $this->get_option('testmode');
					$this->test_api_key					= $this->get_option('test_private_key');
					$this->api_key						= $this->get_option('private_key');
					$this->private_key					= $this->testmode ? $this->get_option('test_private_key') : $this->get_option('private_key');
					$this->recreate_hook				= $this->get_option('recreate_hook');
					$this->hook_id						= $this->get_option('hook_id');
					$this->hook_test_id					= $this->get_option('hook_test_id');
					$this->hf_background_color			= $this->get_option('hf_background_color');
					$this->hf_form_background_color		= $this->get_option('hf_form_background_color');
					$this->hf_primary_color				= $this->get_option('hf_primary_color');
					$this->hf_text_color				= $this->get_option('hf_text_color');
					$this->hf_effects_background_color	= $this->get_option('hf_effects_background_color');
					$this->hf_size						= $this->get_option('hf_size');

					// Hook for save settings
					add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
					add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'generate_hook']);

					// Receipt page
					add_action('woocommerce_receipt_' . $this->id, [$this, 'receipt_page']);

					// Payment listner/API hook
					add_action('woocommerce_api_' . $this->id, [$this, 'check_response']);

					// Thank you page
					add_action('woocommerce_thankyou_' . $this->id, [$this, 'thankyou']);
				}

				public function generate_hook() {
					$prod_success = false;
					$test_success = false;
					$prod_response = [];
					$test_response = [];

					if ($this->recreate_hook === 'yes') {
						$getWebhooksArgs = [
							'timeout' => 15,
							'sslverify' => true,
							'method' => 'GET',
							'headers' => [
								'Content-Type' => 'application/json',
								'Accept' => 'application/json',
								'X-Api-Key' => $this->api_key
							],
						];

						$getWebhooksResponse = wp_remote_request($this->WEBHOOK_API_URL . 'api/v1/settings', $getWebhooksArgs);
						$getWebhooksResponse = wp_remote_retrieve_body($getWebhooksResponse);
						$getWebhooksResponse = json_decode($getWebhooksResponse, true);

						if ($getWebhooksResponse['data'] && !empty($getWebhooksResponse['data'])) {
							foreach ($getWebhooksResponse['data'] as $webhook) {
								$deleteWebhookArgs = [
									'timeout' => 15,
									'sslverify' => true,
									'method' => 'DELETE',
									'headers' => [
										'Content-Type' => 'application/json',
										'Accept' => 'application/json',
										'X-Api-Key' => $this->api_key
									],
								];
		
								wp_remote_request($this->WEBHOOK_API_URL . 'api/v1/settings/' . $webhook['id'], $deleteWebhookArgs);
							}
						}

						$getWebhooksArgs = [
							'timeout' => 15,
							'sslverify' => true,
							'method' => 'GET',
							'headers' => [
								'Content-Type' => 'application/json',
								'Accept' => 'application/json',
								'X-Api-Key' => $this->test_api_key
							],
						];

						$getWebhooksResponse = wp_remote_request($this->WEBHOOK_API_URL . 'api/v1/settings', $getWebhooksArgs);
						$getWebhooksResponse = wp_remote_retrieve_body($getWebhooksResponse);
						$getWebhooksResponse = json_decode($getWebhooksResponse, true);

						if ($getWebhooksResponse['data'] && !empty($getWebhooksResponse['data'])) {
							foreach ($getWebhooksResponse['data'] as $webhook) {
								$deleteWebhookArgs = [
									'timeout' => 15,
									'sslverify' => true,
									'method' => 'DELETE',
									'headers' => [
										'Content-Type' => 'application/json',
										'Accept' => 'application/json',
										'X-Api-Key' => $this->test_api_key
									],
								];
		
								wp_remote_request($this->WEBHOOK_API_URL . 'api/v1/settings/' . $webhook['id'], $deleteWebhookArgs);
							}
						}
					}

					if ($this->api_key && $this->recreate_hook === 'yes') {
						$baseURL = get_rest_url();
						$body = wp_json_encode([
							'url' => $baseURL.'paybyrd/v1/webhook',
							'credentialType' => 'api-key',
							'apiKey' => $this->api_key,
							'events' => [
								'order.created',
								'order.pending',
								'order.paid',
								'order.refunded',
								'order.canceled',
								'order.expired',
								'order.temporaryfailed',
							],
							'paymentMethods' => []
						]);

						$args = [
							'timeout' => 15,
							'sslverify' => true,
							'method' => 'POST',
							'headers' => [
								'Content-Type' => 'application/json',
								'Accept' => 'application/json',
								'X-Api-Key' => $this->api_key
							],
							'body' => $body
						];

						$response = wp_remote_request($this->WEBHOOK_API_URL . 'api/v1/settings', $args);
						$response = wp_remote_retrieve_body($response);
						$response = json_decode($response, true);
						$data = $response['data'];

						if ($data && $data['id']) {
							$this->update_option('hook_id', $data['id']);

							$prod_success = true;
						}

						$prod_response = $response;
					}
					
					if ($this->test_api_key && $this->recreate_hook === 'yes') {
						$baseURL = get_rest_url();
						$body = wp_json_encode([
							'url' => $baseURL.'paybyrd/v1/webhook',
							'credentialType' => 'api-key',
							'apiKey' => $this->test_api_key,
							'events' => [
								'order.created',
								'order.pending',
								'order.paid',
								'order.refunded',
								'order.canceled',
								'order.expired',
								'order.temporaryfailed',
							],
							'paymentMethods' => []
						]);

						$args = [
							'timeout' => 15,
							'sslverify' => true,
							'method' => 'POST',
							'headers' => [
								'Content-Type' => 'application/json',
								'Accept' => 'application/json',
								'X-Api-Key' => $this->test_api_key
							],
							'body' => $body
						];

						$response = wp_remote_request($this->WEBHOOK_API_URL . 'api/v1/settings', $args);
						$response = wp_remote_retrieve_body($response);
						$response = json_decode($response, true);
						$data = $response['data'];

						if ($data && $data['id']) {
							$this->update_option('hook_test_id', $data['id']);

							$test_success = true;
						}

						$test_response = $response;
					}

					$this->update_option('recreate_hook', 'no');
					return ['prodSuccess' => $prod_success, 'testSuccess' => $test_success, 'prodResponse' => $prod_response, 'testResponse' => $test_response];
				}

				public function process_admin_options() {
					$saved = parent::process_admin_options();
					$this->init_form_fields();
					$this->recreate_hook = $this->get_option('recreate_hook');
					return $saved;
				}

				/**
				 * Initialise Gateway Settings Form Fields.
				 */
				public function init_form_fields() {
					wp_register_script('woocommerce_form_paybyrd', plugins_url('form_paybyrd.js', __FILE__), ['jquery']);

    				$this->form_fields = apply_filters('wc_paybyrd_fields', [
						'enabled' => [
							'title'			=> __('Enable/Disable', 'paybyrd-woocommerce'),
							'label'			=> __('Enable Paybyrd Gateway', 'paybyrd-woocommerce'),
							'type'			=> 'checkbox',
							'description'	=> '',
							'default'		=> 'yes'
						],
						'title' => [
							'title'			=> __('Title', 'paybyrd-woocommerce'),
							'type'			=> 'text',
							'description'	=> __('This controls the title which the user sees during checkout', 'paybyrd-woocommerce'),
							'default'		=> __('Paybyrd Payment', 'paybyrd-woocommerce'),
							'desc_tip'		=> true
						],
						'description' => [
							'title'			=> __('Description', 'paybyrd-woocommerce'),
							'type'			=> 'textarea',
							'description'	=> __('Payment method description that the customer will see on your checkout', 'paybyrd-woocommerce'),
							'default'		=> __('Payment Information', 'paybyrd-woocommerce'),
							'desc_tip'		=> true
						],
						'paidOrderStatus' => [
							'title'			=> __('Paid order status', 'paybyrd-woocommerce'),
							'type'			=> 'select',
							'description'	=> __('Select the status that the order will be changed after payment success', 'paybyrd-woocommerce'),
							'default'		=> 'processing',
							'desc_tip'		=> true,
							'options' => array(
								'processing' => __('Processing', 'paybyrd-woocommerce'),
								'completed' => __('Completed', 'paybyrd-woocommerce'),
						   	)
						],
						'testmode' => [
							'title'			=> __('Test Mode', 'paybyrd-woocommerce'),
							'label'			=> __('Enable Test Mode', 'paybyrd-woocommerce'),
							'type'			=> 'checkbox',
							'description'	=> __('Place the payment gateway in test mode using test API keys', 'paybyrd-woocommerce'),
							'default'		=> 'yes',
							'desc_tip'		=> true
						],
						'test_private_key' => [
							'title'			=> __('Test Private Key', 'paybyrd-woocommerce'),
							'type'			=> 'password'
						],
						'private_key' => [
							'title'			=> __('Live Private Key', 'paybyrd-woocommerce'),
							'type'			=> 'password'
						],
						'hook_id' => [
							'title'			=> __('Webhook ID', 'paybyrd-woocommerce'),
							'type'			=> 'text',
							'custom_attributes' => ['readonly' => 'readonly'],
						],
						'hook_test_id' => [
							'title'			=> __('Webhook Test ID', 'paybyrd-woocommerce'),
							'type'			=> 'text',
							'custom_attributes' => ['readonly' => 'readonly'],
						],
						'hf_size' => [
							'title'			=> __('Hosted Form Size', 'paybyrd-woocommerce'),
							'type'			=> 'select',
							'description'	=> __('Select the size that the iframe will have when the payment starts', 'paybyrd-woocommerce'),
							'default'		=> 'full',
							'desc_tip'		=> true,
							'options' => array(
								'full' => __('Full', 'paybyrd-woocommerce'),
								'half' => __('Half', 'paybyrd-woocommerce'),
						   	)
						],
						'hf_background_color' => [
							'title' 		=> __('Hosted Form background color', 'paybyrd-woocommerce'),
							'type' 			=> 'text',
							'description'	=> __('This controls the color of the iframe background. Default is purple gradient.', 'paybyrd-woocommerce'),
							'class' 		=> 'colorpick',
							'desc_tip'		=> true
						],
						'hf_form_background_color' => [
							'title' 		=> __('Hosted Form payment form background color', 'paybyrd-woocommerce'),
							'type' 			=> 'text',
							'description'	=> __('This controls the color of the payment form background. Default is darkgrey.', 'paybyrd-woocommerce'),
							'class' 		=> 'colorpick',
							'desc_tip'		=> true
						],
						'hf_primary_color' => [
							'title' 		=> __('Hosted Form primary color', 'paybyrd-woocommerce'),
							'type' 			=> 'text',
							'description'	=> __('This controls the color of buttons background. Default is purple.', 'paybyrd-woocommerce'),
							'class' 		=> 'colorpick',
							'desc_tip'		=> true
						],
						'hf_text_color' => [
							'title' 		=> __('Hosted Form text color', 'paybyrd-woocommerce'),
							'type' 			=> 'text',
							'description'	=> __('This controls the color of the texts in the payment form. Default is white.', 'paybyrd-woocommerce'),
							'class' 		=> 'colorpick',
							'desc_tip'		=> true
						],
						'hf_effects_background_color' => [
							'title' 		=> __('Hosted Form input field effects background color', 'paybyrd-woocommerce'),
							'type' 			=> 'text',
							'description'	=> __('This controls the background color of all inputs of the Hosted Form and the hover of the payment methods. Default is the same of Primary Color.', 'paybyrd-woocommerce'),
							'class' 		=> 'colorpick',
							'desc_tip'		=> true
						],
						'recreate_hook' => [
							'title' 		=> __('Generate Webhook', 'paybyrd-woocommerce'),
							'label'			=> __('Generate Webhook', 'paybyrd-woocommerce'),
							'type'			=> 'checkbox',
							'description'	=> __('Check if you want to generate/regenerate the webhook credentials', 'paybyrd-woocommerce'),
							'default'		=> 'no',
							'desc_tip'		=> true
						]
					]);

					wp_enqueue_script('woocommerce_form_paybyrd');
				}

				function formScripts() { 
					wp_register_script('woocommerce_paybyrd', plugins_url('paybyrd.js', __FILE__), ['jquery']); 
					wp_enqueue_script('woocommerce_paybyrd'); 
				}

				public function payment_scripts($order) {
					wp_register_script('woocommerce_paybyrd', plugins_url('paybyrd.js', __FILE__), ['jquery']);

					$firstname	   = $order->get_billing_first_name();
					$lastname     = $order->get_billing_last_name();
					$email 		   = $order->get_billing_email();
					$operationId = sha1($order->get_id() . $this->private_key);
					$amount = $order->get_total();

					$body = wp_json_encode([
						'isoAmount' => round((float)$amount*100),
						'currency' => get_woocommerce_currency(),
						'orderRef' => 'wp_' . $order->get_id(),
						'expiresIn' => 5,
						'shopper' => array(
							'email' => $email,
							'firstName' => $firstname,
							'lastName' => $lastname
						),
						'orderOptions' => array(
							'redirectUrl' => add_query_arg('wc-api', "{$this->id}",
								add_query_arg('order', $order->get_id(),
								add_query_arg('operationId', $operationId,
								$this->get_return_url($order)
							)))
						),
						'paymentOptions' => array(
							'useSimulated' => !!$this->testmode,
							'tokenOptions' => array(
								'customReference' => $email
							)
						)
					]);

					$args = [
						'timeout' => 15,
						'sslverify' => true,
						'method'  => 'POST',
						'headers' => [
							'Content-Type' => 'application/json',
							'Accept' => 'application/json',
							'X-Api-Key' => $this->private_key
						],
						'body' => $body
					];
					
					$response = wp_remote_post($this->API_URL . 'api/v2/orders', $args);
					$response = wp_remote_retrieve_body($response);
					$response = json_decode($response, true);

					if ($response['orderId']) {
						wp_localize_script('woocommerce_paybyrd', 'paybyrd_params', [
							'hfBackgroundColor' => $this->hf_background_color,
							'hfFormBackgroundColor' => $this->hf_form_background_color,
							'hfPrimaryColor' => $this->hf_primary_color,
							'hfTextColor' => $this->hf_text_color,
							'hfEffectsBackgroundColor' => $this->hf_effects_background_color,
							'checkoutKey' => $response['checkoutKey'],
							'orderId' => $response['orderId'],
							'locale' => get_locale(),
							'size' => $this->hf_size,
							'failureRedirectUrl' => $order->get_cancel_order_url(),
							'redirectUrl' => add_query_arg('wc-api', "{$this->id}",
								add_query_arg('order', $order->get_id(),
								add_query_arg('operationId', $operationId,
								$this->get_return_url($order)
							)))
						]);

						$this->formScripts();
					}
				}

				/**
				 * Process the payment and return the result.
				 *
				 * @param int $order_id
				 * @return array
				 */
				public function process_payment($order_id) {
					global $woocommerce;
					global $wp;

					$order = wc_get_order($order_id);
					$order_key = $order->get_order_key();
					
					$checkout_page_url = function_exists('wc_get_cart_url') ? wc_get_checkout_url() : $woocommerce->cart->get_checkout_url();

					// Return payment redirect
					return [
						'result' => 'success',
						'redirect' => add_query_arg('order', $order->get_id(),
						add_query_arg('key', $order_key, add_query_arg(array(), $checkout_page_url)))
					];
				}

				public function receipt_page($order) {
					global $woocommerce;

					$order = wc_get_order($order);

					echo __('The form for payment will be loaded soon...', 'paybyrd-woocommerce');

					$this->payment_scripts($order);
				}

				/**
				* Check Payment Response
				**/
				public function check_response() {
					global $woocommerce;

					$order = wc_get_order(sanitize_text_field($_GET['order']));
					$orderId = isset($_GET['orderId']) ? sanitize_text_field($_GET['orderId']) : null;
					$operationId = isset($_GET['operationId']) ? sanitize_text_field($_GET['operationId']) : null;
					$serviceSupplierId = isset($_GET['serviceSupplierId']) ? sanitize_text_field($_GET['serviceSupplierId']) : null;
					$entityId = isset($_GET['entityId']) ? sanitize_text_field($_GET['entityId']) : null;
					$paymentReference = isset($_GET['paymentReference']) ? sanitize_text_field($_GET['paymentReference']) : null;

					$args = [
						'timeout' => 15,
						'sslverify' => true,
						'headers' => [
							'Content-Type' => 'application/json',
							'X-Api-Key' => $this->private_key
						]
					];
					
					$response = wp_remote_get($this->API_URL . "api/v2/orders/{$orderId}", $args);
					$response = wp_remote_retrieve_body($response);
					$response = json_decode($response, true);

					if (!$response) {
						http_response_code(404);
						echo "Order not found";
						exit();
					}

					$isOrderValid = sha1($order->get_id() . $this->private_key) === $operationId;

					if (
						$response['status'] === 'Success' ||
						$response['status'] === 'AcquirerSuccess' ||
						$response['status'] === 'Success' ||
						$response['status'] === 'paid' ||
						$response['status'] === 'acquirersuccess' ||
						$response['status'] === 'success' &&
						$isOrderValid
					) {
						if ($this->testmode) {
							$order->update_status('payment-test', __('Test Approved', 'paybyrd-woocommerce'));
						} else {
							$order->update_status($this->paidOrderStatus, __('Payment successfully paid', 'paybyrd-woocommerce'));
						}

						WC()->cart->empty_cart();

						return wp_redirect($this->get_return_url($order));
					} else if (
						$response['status'] === 'Pending' ||
						$response['status'] === 'pending' &&
						$isOrderValid
					) {
						// Multicaixa
						if ($entityId && $paymentReference) {
							$message = '
								Para realizar o pagamento por referência utilize os dados abaixo em qualquer terminal Multicaixa

								<br />
								Entidade: ' . $entityId . '
								Referência: ' . $paymentReference . '
								Montante: €' . $response['amount'] . '
							';

							$order->add_order_note($message, 1);
						}

						// Multibanco
						if ($serviceSupplierId && $paymentReference) {
							$message = '
								Para efetuar o pagamento a partir do seu homebanking ou de um ATM, selecione "Pagamentos e outros serviços" e, em seguida, selecione "Pagamentos de serviços/compras"

								<br />
								Entidade: ' . $serviceSupplierId . '
								Referência: ' . $paymentReference . '
								Montante: €' . $response['amount'] . '
							';

							$order->add_order_note($message, 1);
						}

						$order->update_status('on-hold', __('Payment pending', 'paybyrd-woocommerce'));
						return wp_redirect(
							add_query_arg('order', $order->get_id(),
							add_query_arg('serviceSupplierId', $serviceSupplierId,
							add_query_arg('paymentReference', $paymentReference,
							add_query_arg('entityId', $entityId,
							$this->get_return_url($order)
						)))));
					} else if (
						$response['status'] === 'Canceled' ||
						$response['status'] === 'canceled' &&
						$isOrderValid
					) {
						$order->update_status('cancelled', __('Payment refunded', 'paybyrd-woocommerce'));
						return wp_redirect($order->get_cancel_order_url());
					} else {
						$order->update_status('failed', __('There was a problem with your payment', 'paybyrd-woocommerce'));
						return wp_redirect($order->get_cancel_order_url());
					}
				}

				/**
				* Thank you page
				**/
				public function thankyou() {
					global $woocommerce;

					$order = isset($_GET['order']) ? wc_get_order(sanitize_text_field($_GET['order'])) : null;

					$serviceSupplierId = isset($_GET['serviceSupplierId']) ? sanitize_text_field($_GET['serviceSupplierId']) : null;
					$entityId = isset($_GET['entityId']) ? sanitize_text_field($_GET['entityId']) : null;
					$paymentReference = isset($_GET['paymentReference']) ? sanitize_text_field($_GET['paymentReference']) : null;
					
					// Multicaixa
					if ($entityId && $paymentReference) {
						$message = '
							<h2 class="woocommerce-order-details__title">Dados Multicaixa</h2>

							<div>
								Para realizar o pagamento por referência utilize
								os dados abaixo em qualquer terminal Multicaixa
							</div>

							<br />
							<div><b>Entidade:</b> ' . $entityId . '</div>
							<div><b>Referência:</b> ' . $paymentReference . '</div>
							<div><b>Montante:</b> ' . get_woocommerce_currency_symbol() . ' ' . $order->get_total() . '</div>
							<br />
						';

						echo $message;
					}

					// Multibanco
					if ($serviceSupplierId && $paymentReference) {
						$message = '
							<h2 class="woocommerce-order-details__title">Dados Multibanco</h2>

							<div>
								Para efetuar o pagamento a partir do seu homebanking ou de um ATM,
								selecione "Pagamentos e outros serviços" e, em seguida, selecione
								"Pagamentos de serviços/compras"
							</div>

							<br />
							<div><b>Entidade:</b> ' . $serviceSupplierId . '</div>
							<div><b>Referência:</b> ' . $paymentReference . '</div>
							<div><b>Montante:</b> ' . get_woocommerce_currency_symbol() . ' ' . $order->get_total() . '</div>
							<br />
						';

						echo $message;
					}
				}
			}
		}

		// Register New Order Statuses
		function wpex_wc_register_post_statuses() {
			register_post_status('wc-payment-test', [
				'label'                     => __('Test Approved', 'paybyrd-woocommerce'),
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				'label_count'               => _n_noop('Test Approved (%s)', 'Test Approved (%s)')
			]);
		}
		add_filter('init', 'wpex_wc_register_post_statuses');

		// Add New Order Statuses to WooCommerce
		function wpex_wc_add_order_statuses($order_statuses) {
			$order_statuses['wc-payment-test'] = __('Test Approved', 'paybyrd-woocommerce');
			return $order_statuses;
		}
		add_filter('wc_order_statuses', 'wpex_wc_add_order_statuses');

		add_filter('woocommerce_payment_gateways', 'add_paybyrd_gateway_class');
		function add_paybyrd_gateway_class($methods) {
			$methods[] = 'WC_Gateway_Paybyrd';
			return $methods;
		}

		// Setup REST
		add_action('rest_api_init', 'generate_rest');
		function validateWebhook($content, $testMode, $paidOrderStatus) {
			global $wp;
			global $woocommerce;

			// GET Webhook Info
			$order = wc_get_order(substr($content['orderRef'], 3));

			// Set new Order State based on WebHook response
			switch ($content['status']) {
				case 'Paid':
				case 'AcquirerSuccess':
				case 'Success':
				case 'paid':
				case 'acquirersuccess':
				case 'success':
					if ($testMode) {
						$order->update_status('payment-test', __('Test Approved', 'paybyrd-woocommerce'));
						echo json_encode(["code" => 200, "message" => "Test order updated successfully", "status" => $content['status'], "id" => $order->id, "orderRef" => $content['orderRef']]);
					} else {
						$order->update_status($paidOrderStatus, __('Payment successfully paid', 'paybyrd-woocommerce'));
						echo json_encode(["code" => 200, "message" => "Order updated successfully", "status" => $content['status'], "id" => $order->id, "orderRef" => $content['orderRef']]);
					}

					if (WC()->cart) {
						WC()->cart->empty_cart();
					}
					break;
				case 'Refunded':
				case 'refunded':
					$order->update_status('refunded', __('Payment refunded', 'paybyrd-woocommerce'));
					echo json_encode(["code" => 200, "message" => "Order refunded", "status" => $content['status'], "id" => $order->id, "orderRef" => $content['orderRef']]);
					break;
				case 'Canceled':
				case 'canceled':
					$order->update_status('cancelled', __('Payment refunded', 'paybyrd-woocommerce'));
					echo json_encode(["code" => 200, "message" => "Order canceled", "status" => $content['status'], "id" => $order->id, "orderRef" => $content['orderRef']]);
					break;
				default:
					echo json_encode(["code" => 200, "message" => "Order still being processed", "status" => $content['status'], "id" => $order->id, "orderRef" => $content['orderRef']]);
					break;
			}
			die();
		}
		function generate_rest() {
			$namespace = 'paybyrd/v1';

			register_rest_route($namespace, 'webhook', array(
				'methods'   => 'POST',
				'callback'  => function($request) {
					$paybyrd = new WC_Gateway_Paybyrd();

					// GET orderId from POST Webhook
					$webhookData = json_decode(file_get_contents('php://input'), true);
					
					if (!$webhookData) {
						echo json_encode(["code" => 400, "message" => "No webhook data found"]);
						http_response_code(404);
						exit();
					}

					$webhookContent = $webhookData['content'];
					if (!$webhookContent['orderId']) {
						echo json_encode(["code" => 404, "message" => "No orderId found"]);
						http_response_code(404);
						exit();
					}

					// GET Webhook Info and Validate Auth
					$headers = getallheaders();
					$headerAuth = $headers['X-Api-Key'];

					if ($headerAuth !== $paybyrd->test_api_key && $headerAuth !== $paybyrd->api_key) {
						echo json_encode(["code" => 401, "message" => "Not authorized"]);
						http_response_code(401);
						exit();
					}

					return validateWebhook($webhookContent, $paybyrd->testmode, $paybyrd->paidOrderStatus);
				},
				'permission_callback' => '__return_true',
			));

			register_rest_route($namespace, 'generate/webhook', array(
				'methods'   => 'POST',
				'callback'  => function() {
					$paybyrd = new WC_Gateway_Paybyrd();

					$response = $paybyrd->generate_hook();

					if (!$response) {
						http_response_code(400);
					}

					return $response;
				},
				'permission_callback' => '__return_true',
			));
		}
	}
?>