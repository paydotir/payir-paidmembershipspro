<?php

add_action('plugins_loaded', 'load_payir_pmpro_class', 11);
add_action('plugins_loaded', ['PMProGateway_Payir', 'init'], 12);

function load_payir_pmpro_class()
{
	if (class_exists('PMProGateway')) {

		class PMProGateway_Payir extends PMProGateway
		{
			public function PMProGateway_Payir($gateway = NULL)
			{
				$this->gateway = $gateway;
				$this->gateway_environment = pmpro_getOption('gateway_environment');

				return $this->gateway;
			}

			public static function init()
			{
				add_filter('pmpro_gateways', ['PMProGateway_Payir', 'pmpro_gateways']);
				add_filter('pmpro_payment_options', ['PMProGateway_Payir', 'pmpro_payment_options']);
				add_filter('pmpro_payment_option_fields', ['PMProGateway_Payir', 'pmpro_payment_option_fields'], 10, 2);

				$gateway = pmpro_getOption('gateway');

				if ($gateway == 'payir') {

					add_filter('pmpro_checkout_before_change_membership_level', ['PMProGateway_Payir', 'pmpro_checkout_before_change_membership_level'], 10, 2);
					add_filter('pmpro_include_billing_address_fields', '__return_false');
					add_filter('pmpro_include_payment_information_fields', '__return_false');
					add_filter('pmpro_required_billing_fields', ['PMProGateway_Payir', 'pmpro_required_billing_fields']);
				}

				add_action('wp_ajax_nopriv_payir-ins', ['PMProGateway_Payir', 'pmpro_wp_ajax_payir_ins']);
				add_action('wp_ajax_payir-ins', ['PMProGateway_Payir', 'pmpro_wp_ajax_payir_ins']);
			}

			public static function pmpro_gateways($gateways)
			{
				if (empty($gateways['payir'])) {

					$gateways['payir'] = 'درگاه پرداخت و کیف پول الکترونیک Pay.ir';
				}

				return $gateways;
			}

			public static function getGatewayOptions()
			{
				$options = array(

					'payir_api',
					'currency'
				);

				return $options;
			}

			public static function pmpro_payment_options($options)
			{
				$payir_options = self::getGatewayOptions();
				$options       = array_merge($payir_options, $options);

				return $options;
			}

			public static function pmpro_required_billing_fields($fields)
			{
				unset($fields['bfirstname']);
				unset($fields['blastname']);
				unset($fields['baddress1']);
				unset($fields['bcity']);
				unset($fields['bstate']);
				unset($fields['bzipcode']);
				unset($fields['bphone']);
				unset($fields['bemail']);
				unset($fields['bcountry']);
				unset($fields['CardType']);
				unset($fields['AccountNumber']);
				unset($fields['ExpirationMonth']);
				unset($fields['ExpirationYear']);
				unset($fields['CVV']);

				return $fields;
			}

			public static function pmpro_payment_option_fields($values, $gateway)
			{
			?>
				<tr class="pmpro_settings_divider gateway gateway_payir" <?php if ($gateway != 'payir') { ?>style="display:none;"<?php } ?>>
					<td colspan="2"><?php echo 'تنظیمات درگاه پرداخت Pay.ir'; ?></td>
				</tr>
				<tr class="gateway gateway_payir" <?php if ($gateway != 'payir') { ?>style="display:none;"<?php }?>>
					<th scope="row" valign="top">
						<label for="payir_api">کلید API</label>
					</th>
					<td>
						<input type="text" id="payir_api" name="payir_api" size="60" value="<?php echo esc_attr($values['payir_api']); ?>" />
					</td>
				</tr>
			<?php
			}

			public static function pmpro_checkout_before_change_membership_level($user_id, $morder)
			{
				global $wpdb, $discount_code_id;
				global $pmpro_currency;

				if (empty($morder)) {

					return;
				}

				$morder->user_id = $user_id;
				$morder->saveOrder();

				if (!empty($discount_code_id)) {

					$wpdb->query("INSERT INTO $wpdb->pmpro_discount_codes_uses (code_id, user_id, order_id, timestamp) VALUES('" . $discount_code_id . "', '" . $user_id . "', '" . $morder->id . "', now())");
				}

				$order_id = $morder->code;
				$gtw_env  = pmpro_getOption('gateway_environment');

				if (extension_loaded('curl')) {

					if ($gtw_env == NULL || $gtw_env == 'sandbox') {

						$api_key     = 'test';
						$send_url    = 'https://pay.ir/payment/test/send';
						$gateway_url = 'https://pay.ir/payment/test/gateway/';

					} else {

						$api_key     = pmpro_getOption('payir_api');
						$send_url    = 'https://pay.ir/payment/send';
						$gateway_url = 'https://pay.ir/payment/gateway/';
					}

					$callback = admin_url('admin-ajax.php') . '?action=payir-ins&oid=' . $order_id;
					$amount   = abs($morder->subtotal);

					if ($pmpro_currency != 'IRR') {

						$amount = $amount * 10;
					}
					
					$params = array(

						'api'          => $api_key,
						'amount'       => $amount,
						'redirect'     => urlencode($callback),
						'factorNumber' => $order_id
					);

					$result = self::common($send_url, $params);

					if ($result && isset($result->status) && $result->status == 1) {

						$message = 'شماره تراکنش ' . $result->transId;

						$morder->payment_transaction_id = $result->transId;

						$morder->status = 'pending';
						$morder->notes  = $message;
						$morder->saveOrder();

						$gateway_url = $gateway_url . $result->transId;

						wp_redirect($gateway_url);
						exit;

					} else {

						$message = 'در ارتباط با وب سرویس Pay.ir خطایی رخ داده است';
						$message = isset($result->errorMessage) ? $result->errorMessage : $message;

						$morder->status = 'error';
						$morder->notes  = $message;
						$morder->saveOrder();

						wp_die($message);
						exit;
					}

				} else {

					$message = 'تابع cURL در سرور فعال نمی باشد';

					$morder->status = 'error';
					$morder->notes  = $message;
					$morder->saveOrder();

					wp_die($message);
					exit;
				}
			}

			public static function pmpro_wp_ajax_payir_ins()
			{
				global $gateway_environment;
				global $pmpro_currency;


				if (!isset($_GET['oid']) || is_null($_GET['oid'])) {

					$message = 'در حین تراکنش خطایی رخ داده است، شماره سفارش ارسال شده ناقص است';

					wp_die($message);
					exit;
				}

				$oid = sanitize_text_field($_GET['oid']);

				$morder = NULL;

				try {

					$morder = new MemberOrder($oid);

					$morder->getMembershipLevel();
					$morder->getUser();

				} catch (Exception $exception) {

					$message = 'در حین تراکنش خطایی رخ داده است، شماره سفارش ارسال شده غیر معتبر است';

					wp_die($message);
					exit;
				}

				$current_user_id = get_current_user_id();

				if ($current_user_id !== intval($morder->user_id)) {

					$message = 'در حین تراکنش خطایی رخ داده است، این شماره سفارش به شما تعلق ندارد';

					wp_die($message);
					exit;
				}

				if (isset($_POST['status']) && isset($_POST['transId']) && isset($_POST['factorNumber'])) {

					$status        = sanitize_text_field($_POST['status']);
					$trans_id      = sanitize_text_field($_POST['transId']);
					$factor_number = sanitize_text_field($_POST['factorNumber']);
					$message       = sanitize_text_field($_POST['message']);

					if (isset($status) && $status == 1) {

						$gtw_env = pmpro_getOption('gateway_environment');

						if ($gtw_env == NULL || $gtw_env == 'sandbox') {

							$api_key    = 'test';
							$verify_url = 'https://pay.ir/payment/test/verify';

						} else {

							$api_key    = pmpro_getOption('payir_api');
							$verify_url = 'https://pay.ir/payment/verify';
						}

						$params = array (

							'api'     => $api_key,
							'transId' => $trans_id
						);

						$result = self::common($verify_url, $params);

						if ($result && isset($result->status) && $result->status == 1) {

							$card_number = isset($_POST['cardNumber']) ? sanitize_text_field($_POST['cardNumber']) : 'Null';

							$amount = abs($morder->subtotal);

							if ($pmpro_currency != 'IRR') {

								$amount = $amount * 10;
							}

							if ($amount == $result->amount) {

								if (self::do_level_up($morder, $trans_id, $factor_number)) {

									$message = 'تراکنش شماره ' . $trans_id . ' با موفقیت انجام شد. شماره کارت پرداخت کننده ' . $card_number;

									$morder->notes = $message;
									$morder->saveOrder();

									$redirect = pmpro_url('confirmation', '?level=' . $morder->membership_level->id);

									wp_redirect($redirect);
									exit;

								} else {

									$message = 'در حین ارتقاء سطح عضویت خطای نامشخصی رخ داده است';

									$morder->status = 'error';
									$morder->notes  = $message;
									$morder->saveOrder();

									wp_die($message);
									exit;
								}

							} else {

								$message = 'رقم تراكنش با رقم پرداخت شده مطابقت ندارد';

								$morder->status = 'error';
								$morder->notes  = $message;
								$morder->saveOrder();

								wp_die($message);
								exit;
							}

						} else {

							$message = 'در ارتباط با وب سرویس Pay.ir و بررسی تراکنش خطایی رخ داده است';
							$message = isset($result->errorMessage) ? $result->errorMessage : $message;

							$morder->status = 'error';
							$morder->notes  = $message;
							$morder->saveOrder();

							wp_die($message);
							exit;
						}

					} else {

						$message = $message ? $message : 'تراكنش با خطا مواجه شد و یا توسط پرداخت کننده کنسل شده است';

						$morder->status = 'cancelled';
						$morder->notes  = $message;
						$morder->saveOrder();

						wp_die($message);
						exit;
					}

				} else {

					$message = 'اطلاعات ارسال شده مربوط به تایید تراکنش ناقص و یا غیر معتبر است';

					$morder->status = 'error';
					$morder->notes  = $message;
					$morder->saveOrder();

					wp_die($message);
					exit;
				}
			}

			public static function do_level_up(&$morder, $txn_id, $sub_id)
			{
				global $wpdb;
				global $pmpro_error;

				$morder->membership_level = apply_filters('pmpro_inshandler_level', $morder->membership_level, $morder->user_id);

				if (!empty($morder->membership_level->expiration_number)) {

					$expiration_number = $morder->membership_level->expiration_number;
					$expiration_period = $morder->membership_level->expiration_period;

					$enddate = "'" . date('Y-m-d', strtotime('+ ' . $expiration_number . ' ' . $expiration_period, current_time('timestamp'))) . "'";

				} else {

					$enddate = 'NULL';
				}

				$morder->getDiscountCode();

				if (!empty($morder->discount_code)) {

					$morder->getMembershipLevel(TRUE);

					$discount_code_id = $morder->discount_code->id;

				} else {

					$discount_code_id = NULL;
				}

				$startdate = apply_filters('pmpro_checkout_start_date', "'" . current_time('mysql') . "'", $morder->user_id, $morder->membership_level);

				$custom_level = array(

					'user_id'         => $morder->user_id,
					'membership_id'   => $morder->membership_level->id,
					'code_id'         => $discount_code_id,
					'initial_payment' => $morder->membership_level->initial_payment,
					'billing_amount'  => $morder->membership_level->billing_amount,
					'cycle_number'    => $morder->membership_level->cycle_number,
					'cycle_period'    => $morder->membership_level->cycle_period,
					'billing_limit'   => $morder->membership_level->billing_limit,
					'trial_amount'    => $morder->membership_level->trial_amount,
					'trial_limit'     => $morder->membership_level->trial_limit,
					'startdate'       => $startdate,
					'enddate'         => $enddate
				);

				if (!empty($pmpro_error)) {

					inslog($pmpro_error);

					echo $pmpro_error;
				}

				if (pmpro_changeMembershipLevel($custom_level, $morder->user_id) !== FALSE) {

					$morder->status = 'success';

					$morder->payment_transaction_id      = $txn_id;
					$morder->subscription_transaction_id = $sub_id;
					$morder->saveOrder();

					if (!empty($discount_code) && !empty($use_discount_code)) {

						$wpdb->query("INSERT INTO $wpdb->pmpro_discount_codes_uses (code_id, user_id, order_id, timestamp) VALUES('" . $discount_code_id . "', '" . $morder->user_id . "', '" . $morder->id . "', '".current_time('mysql') . "')");
					}

					if (!empty($_POST['first_name'])) {

						$old_firstname = get_user_meta($morder->user_id, 'first_name', TRUE);

						if (!empty($old_firstname)) {

							update_user_meta($morder->user_id, 'first_name', $_POST['first_name']);
						}
					}

					if (!empty($_POST['last_name'])) {

						$old_lastname = get_user_meta($morder->user_id, 'last_name', TRUE);

						if (!empty($old_lastname)) {

							update_user_meta($morder->user_id, 'last_name', $_POST['last_name']);
						}
					}

					do_action('pmpro_after_checkout', $morder->user_id);

					if (!empty($morder)) {

						$invoice = new MemberOrder($morder->id);

					} else {

						$invoice = NULL;
					}

					$user = get_userdata(intval($morder->user_id));

					if (empty($user)) {

						return FALSE;
					}

					$user->membership_level = $morder->membership_level;

					$pmproemail = new PMProEmail();

					$pmproemail->sendCheckoutEmail($user, $invoice);
					$pmproemail->sendCheckoutAdminEmail($user, $invoice);

					return TRUE;

				} else {

					return FALSE;
				}
			}

			private static function common($url, $params)
			{
				$ch = curl_init();

				curl_setopt($ch, CURLOPT_URL, $url);
				curl_setopt($ch, CURLOPT_POST, TRUE);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
				curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
				curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));

				$response = curl_exec($ch);
				$error    = curl_errno($ch);

				curl_close($ch);

				$output = $error ? FALSE : json_decode($response);

				return $output;
			}
		}
	}
}
