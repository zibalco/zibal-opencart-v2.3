<?php

class ControllerExtensionPaymentZibal extends Controller {
	// Sent in the User-Agent so gateway logs can count installs per plugin version.
	const PLUGIN_VERSION = '1.0.0';

	public function index() {
		$this->load->language('extension/payment/zibal');
		
		$data['text_connect'] = $this->language->get('text_connect');
		$data['text_loading'] = $this->language->get('text_loading');
		$data['text_wait'] = $this->language->get('text_wait');
		
    	$data['button_confirm'] = $this->language->get('button_confirm');

		return $this->load->view('extension/payment/zibal', $data);
	}

	private function get_user_agent() {
		$opencart_version = defined('VERSION') ? VERSION : 'unknown';

		return 'ZibalOpenCart/' . self::PLUGIN_VERSION . ' OpenCart/' . $opencart_version . ' PHP/' . PHP_VERSION;
	}

	private function post_to_zibal($url, $data = false) {
		$response = array(
			'raw'   => '',
			'http'  => 0,
			'error' => '',
			'json'  => null
		);

		if (!function_exists('curl_init')) {
			$response['error'] = 'PHP cURL extension is not installed';

			return $response;
		}

		$ch = curl_init();

		if (!$ch) {
			$response['error'] = 'curl_init failed';

			return $response;
		}

		curl_setopt($ch, CURLOPT_URL, "https://gateway.zibal.ir/v1/".$url);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json; charset=utf-8'));
		curl_setopt($ch, CURLOPT_USERAGENT, $this->get_user_agent());
		curl_setopt($ch, CURLOPT_POST, 1);
		if ($data) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

		}
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($ch, CURLOPT_TIMEOUT, 20);
		$result = curl_exec($ch);

		$response['raw'] = is_string($result) ? $result : '';
		$response['http'] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$response['error'] = curl_error($ch);

		// The handle is freed automatically from PHP 8.0 on, and curl_close() is deprecated in 8.5.
		if (PHP_VERSION_ID < 80000) {
			curl_close($ch);
		}

		if ($response['raw'] !== '') {
			$json = json_decode($response['raw'], false, 512, JSON_BIGINT_AS_STRING);

			if (is_object($json)) {
				$response['json'] = $json;
			}
		}

		return $response;
	}

	public function confirm() {
		$this->load->language('extension/payment/zibal');
		
		$this->load->model('checkout/order');
		$this->load->model('extension/payment/zibal');

		$json = array();

		$order_id = isset($this->session->data['order_id']) ? (int)$this->session->data['order_id'] : 0;
		$order_info = $order_id ? $this->model_checkout_order->getOrder($order_id) : false;

		if (!$order_info || !isset($this->session->data['payment_method']['code']) || $this->session->data['payment_method']['code'] != 'zibal') {
			$json['error'] = $this->language->get('error_order_id');

			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode($json));
			return;
		}

		$amount = $this->correctAmount($order_info);

		// Only Rial and Toman orders are payable through Zibal.
		if ($amount <= 0) {
			$this->model_extension_payment_zibal->addOrderNote($order_id, $this->language->get('text_note_currency') . htmlspecialchars($order_info['currency_code'], ENT_QUOTES, 'UTF-8'));

			$json['error'] = $this->language->get('error_currency');

			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode($json));
			return;
		}

		$merchant = $this->config->get('zibal_merchant');
		$description = $this->language->get('text_order_no') . $order_info['order_id'];
		$mobile = isset($order_info['fax']) ? $order_info['fax'] : $order_info['telephone'];
		// Zibal appends trackId itself; the order is found through the stored trackId, never through the URL.
		$callbackUrl = str_replace('&amp;', '&', $this->url->link('extension/payment/zibal/callback', '', true));

		$parameters = array(
			'merchant' 		=> $merchant,
			'amount' 		=> $amount,
			'description' 	=> $description,
			'mobile' 		=> $mobile,
			'orderId' 		=> $order_info['order_id'],
			'callbackUrl' 	=> $callbackUrl
		);

		$requestResult = $this->post_to_zibal('request', $parameters);
		$result = $this->resultCode($requestResult);
		$trackId = ($requestResult['json'] && isset($requestResult['json']->trackId) && is_scalar($requestResult['json']->trackId)) ? (string)$requestResult['json']->trackId : '';

		if ($result === 100 && $this->isTrackId($trackId) && $this->model_extension_payment_zibal->addTransaction($order_id, $trackId, $amount)) {
			$json['success'] = "https://gateway.zibal.ir/start/" . $trackId;
		} else {
			$this->model_extension_payment_zibal->addOrderNote($order_id, $this->failureNote('text_note_request_failed', $trackId, $requestResult));

			if (!$requestResult['json']) {
				$json['error'] = $this->language->get('error_cant_connect');
			} else {
				$json = $this->checkState($result);
			}
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function callback() {
		$this->load->language('extension/payment/zibal');

		$this->load->model('checkout/order');
		$this->load->model('extension/payment/zibal');

		$this->document->setTitle($this->language->get('text_title'));
		
		$data['heading_title'] = $this->language->get('text_title');
		$data['text_results'] = $this->language->get('text_results');
		$data['results'] = "";
		
		$data['breadcrumbs'] = array();
		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_home'), 
			'href' => $this->url->link('common/home', '', true)
		);
		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_title'), 
			'href' => $this->url->link('extension/payment/zibal/callback', '', true)
		);

		try {
			// Nothing sent by the browser is trusted except as a lookup key for a transaction this shop created itself.
			$trackId = (isset($this->request->get['trackId']) && is_string($this->request->get['trackId'])) ? $this->request->get['trackId'] : '';

			if (!$this->isTrackId($trackId)) {
				throw new Exception($this->language->get('error_verify'));
			}

			$transaction = $this->model_extension_payment_zibal->getTransactionByTrackId($trackId);

			if (!$transaction) {
				throw new Exception($this->language->get('error_transaction'));
			}

			$order_id = (int)$transaction['order_id'];

			if (!$this->model_extension_payment_zibal->lockOrder($order_id)) {
				throw new Exception($this->language->get('error_connect_verify'));
			}

			// Read the state again now that no other request can change it.
			$transaction = $this->model_extension_payment_zibal->getTransactionByTrackId($trackId);
			$order_info = $this->model_checkout_order->getOrder($order_id);

			if (!$transaction || !$order_info)
				throw new Exception($this->language->get('error_order_id'));

			$success = $this->url->link('checkout/success', '', true);

			// Already settled: a repeated call must never change the order again.
			if ($transaction['status'] == 'completed') {
				$this->response->redirect($success);
			}

			// Paid but never confirmed means an earlier request died half-way; finish it once.
			if ($transaction['status'] == 'paid') {
				if (!$order_info['order_status_id']) {
					$this->completeOrder($order_id, $transaction, $transaction);
				} else {
					$this->model_extension_payment_zibal->markCompleted($transaction['zibal_transaction_id']);
				}

				$this->response->redirect($success);
			}

			// The order was handled some other way (another transaction, the admin, another gateway): leave it alone.
			if ($order_info['order_status_id'] || $order_info['payment_code'] != 'zibal') {
				throw new Exception($this->language->get('error_order_processed'));
			}

			$context = array(
				'merchant'	=> $this->config->get('zibal_merchant'),
				'trackId' 	=> $transaction['track_id']
			);

			$verifyResult = $this->post_to_zibal('verify', $context);
			$result = $this->resultCode($verifyResult);

			// 201 = verified earlier (e.g. the previous answer got lost); the inquiry below decides.
			if ($result !== 100 && $result !== 201) {
				$this->failTransaction($transaction, 'text_note_verify_failed', $verifyResult);

				throw new Exception($verifyResult['json'] ? $this->checkState($result)['error'] : $this->language->get('error_connect_verify'));
			}

			if ($result === 100 && !$this->matchesTransaction($verifyResult['json'], $transaction)) {
				$this->failTransaction($transaction, 'text_note_mismatch', $verifyResult);

				throw new Exception($this->language->get('error_amount'));
			}

			// Second, independent check with the stored trackId: Zibal itself must report it as paid and verified for this order and amount.
			$inquiryResult = $this->post_to_zibal('inquiry', $context);
			$inquiry = $inquiryResult['json'];

			if ($this->resultCode($inquiryResult) !== 100 || !isset($inquiry->status) || !is_numeric($inquiry->status) || (int)$inquiry->status !== 1) {
				$this->failTransaction($transaction, 'text_note_inquiry_failed', $inquiryResult);

				throw new Exception($this->language->get('error_connect_verify'));
			}

			if (!$this->matchesTransaction($inquiry, $transaction)) {
				$this->failTransaction($transaction, 'text_note_mismatch', $inquiryResult);

				throw new Exception($this->language->get('error_amount'));
			}

			$details = array(
				'ref_number'  => $this->pickField(array($inquiry, $verifyResult['json']), 'refNumber'),
				'card_number' => $this->pickField(array($inquiry, $verifyResult['json']), 'cardNumber'),
				'paid_at'     => $this->pickField(array($inquiry, $verifyResult['json']), 'paidAt')
			);

			// Only the one request that performs this switch may confirm the order.
			if ($this->model_extension_payment_zibal->markPaid($transaction['zibal_transaction_id'], $details)) {
				$this->completeOrder($order_id, $transaction, $details);
			}

			$this->response->redirect($success);

		} catch (Exception $e) {
			$data['error_warning'] = $e->getMessage();
			$data['button_continue'] = $this->language->get('button_view_cart');
			$data['continue'] = $this->url->link('checkout/cart');
		}

		$data['column_left'] = $this->load->controller('common/column_left');
		$data['column_right'] = $this->load->controller('common/column_right');
		$data['content_top'] = $this->load->controller('common/content_top');
		$data['content_bottom'] = $this->load->controller('common/content_bottom');
		$data['footer'] = $this->load->controller('common/footer');
		$data['header'] = $this->load->controller('common/header');

		$this->response->setOutput($this->load->view('extension/payment/zibal_confirm', $data));
	}

	private function isTrackId($trackId) {
		return is_string($trackId) && preg_match('/^[0-9]{1,32}$/', $trackId) === 1;
	}

	private function resultCode($response) {
		if ($response['json'] && isset($response['json']->result) && is_numeric($response['json']->result)) {
			return (int)$response['json']->result;
		}

		return null;
	}

	// The amount and orderId reported by Zibal must be the ones stored for this trackId.
	private function matchesTransaction($json, $transaction) {
		if (!is_object($json) || !isset($json->amount) || !is_numeric($json->amount) || !isset($json->orderId) || !is_scalar($json->orderId)) {
			return false;
		}

		return (string)$json->amount === (string)$transaction['amount'] && (string)$json->orderId === (string)$transaction['order_id'];
	}

	private function pickField($sources, $field) {
		foreach ($sources as $source) {
			if (is_object($source) && isset($source->$field) && is_scalar($source->$field) && trim((string)$source->$field) !== '' && trim((string)$source->$field) !== '-') {
				return utf8_substr(trim((string)$source->$field), 0, 32);
			}
		}

		return '';
	}

	// Exactly what cURL received, escaped only because the admin prints order comments as HTML.
	private function failureNote($title, $trackId, $response) {
		$raw = $response['raw'] !== '' ? $response['raw'] : $this->language->get('text_note_no_response') . $response['error'];

		$note  = $this->language->get($title) . "\n";
		$note .= $this->language->get('text_note_track_id') . ($this->isTrackId($trackId) ? $trackId : '-') . "\n";
		$note .= $this->language->get('text_note_http_code') . $response['http'] . "\n";
		$note .= $this->language->get('text_note_response') . "\n";
		$note .= htmlspecialchars(utf8_substr($raw, 0, 3000), ENT_QUOTES, 'UTF-8');

		return $note;
	}

	private function failTransaction($transaction, $title, $response) {
		$note = $this->failureNote($title, $transaction['track_id'], $response);

		// Reloading the callback must not pile up identical notes.
		if ($transaction['last_response'] === $note) {
			return;
		}

		$this->model_extension_payment_zibal->addOrderNote($transaction['order_id'], $note);
		$this->model_extension_payment_zibal->setLastResponse($transaction['zibal_transaction_id'], $note);
	}

	private function completeOrder($order_id, $transaction, $details) {
		$trackId = $transaction['track_id'];

		// A paid order must never be left without a status, even when the gateway setting was never saved.
		$order_status_id = (int)$this->config->get('zibal_order_status_id');

		if (!$order_status_id) {
			$order_status_id = (int)$this->config->get('config_order_status_id');
		}

		$paid_at = $details['paid_at'];

		// 2026-10-05T14:52:34.545000 -> 2026-10-05 14:52:34
		if (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})/', $paid_at, $match)) {
			$paid_at = $match[1] . ' ' . $match[2];
		}

		$comment  = $this->language->get('text_note_paid') . "\n";
		$comment .= $this->language->get('text_note_track_id') . $trackId . "\n";
		$comment .= $this->language->get('text_note_paid_at') . ($paid_at !== '' ? htmlspecialchars($paid_at, ENT_QUOTES, 'UTF-8') : '-') . "\n";
		$comment .= $this->language->get('text_note_card_number') . ($details['card_number'] !== '' ? htmlspecialchars($details['card_number'], ENT_QUOTES, 'UTF-8') : '-') . "\n";
		$comment .= $this->language->get('text_note_ref_number') . ($details['ref_number'] !== '' ? htmlspecialchars($details['ref_number'], ENT_QUOTES, 'UTF-8') : '-');

		$this->model_checkout_order->addOrderHistory($order_id, $order_status_id, $comment, true);

		$this->model_extension_payment_zibal->markCompleted($transaction['zibal_transaction_id']);
	}

	// Zibal works in Rial. Returns 0 when the order is not priced in Rial or Toman.
	private function correctAmount($order_info) {
		$unit = $this->model_extension_payment_zibal->getCurrencyUnit($order_info['currency_code']);

		if (!$unit) {
			return 0;
		}

		$amount = $this->currency->format($order_info['total'], $order_info['currency_code'], $order_info['currency_value'], false);

		return (int)round($amount * $unit);
	}

	private function checkState($status) {
		$json = array();
		$json['error'] = $this->language->get('error_status_undefined');

		if ($this->language->get('error_status_' . $status) != 'error_status_' . $status ) {
			$json['error'] = $this->language->get('error_status_' . $status);
		}

		return $json;
	}
}

?>