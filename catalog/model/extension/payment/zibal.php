<?php

class ModelExtensionPaymentZibal extends Model
{
	  public function getMethod($address)
	  {
		$this->load->language('extension/payment/zibal');

		// The gateway is only offered while the customer is shopping in Rial or Toman.
		if ($this->config->get('zibal_status') && isset($this->session->data['currency']) && $this->getCurrencyUnit($this->session->data['currency'])) {

			  $status = true;

      	} else {

			$status = false;

		}

		$method_data = array();

		if ($status) {
      		$method_data = array(
        		'code'       => 'zibal',
        		'title'      => $this->language->get('text_title'),
				'terms'      => '',
				'sort_order' => $this->config->get('zibal_sort_order')
      		);
    	}

    	return $method_data;
  	}

	// How many Rials one unit of the given currency is: 1 for Rial, 10 for Toman, 0 for anything else.
	public function getCurrencyUnit($currency_code) {
		$code = strtoupper((string)$currency_code);

		if (in_array($code, array('RLS', 'IRR'))) {
			return 1;
		}

		if (in_array($code, array('TOM', 'IRT'))) {
			return 10;
		}

		return 0;
	}

	// Created on demand so stores that installed an older copy of the extension get the table too.
	private function createTable() {
		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "zibal_transaction` (
			`zibal_transaction_id` INT(11) NOT NULL AUTO_INCREMENT,
			`order_id` INT(11) NOT NULL,
			`track_id` VARCHAR(32) NOT NULL,
			`amount` BIGINT(20) NOT NULL,
			`status` VARCHAR(16) NOT NULL DEFAULT 'pending',
			`ref_number` VARCHAR(64) NOT NULL DEFAULT '',
			`card_number` VARCHAR(32) NOT NULL DEFAULT '',
			`paid_at` VARCHAR(40) NOT NULL DEFAULT '',
			`last_response` TEXT NOT NULL,
			`date_added` DATETIME NOT NULL,
			`date_modified` DATETIME NOT NULL,
			PRIMARY KEY (`zibal_transaction_id`),
			UNIQUE KEY `track_id` (`track_id`),
			KEY `order_id` (`order_id`)
		) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci");
	}

	public function addTransaction($order_id, $track_id, $amount) {
		$this->createTable();

		$query = $this->db->query("SELECT zibal_transaction_id FROM `" . DB_PREFIX . "zibal_transaction` WHERE track_id = '" . $this->db->escape($track_id) . "'");

		// A trackId may only ever belong to one local order.
		if ($query->num_rows) {
			return false;
		}

		$this->db->query("INSERT INTO `" . DB_PREFIX . "zibal_transaction` SET order_id = '" . (int)$order_id . "', track_id = '" . $this->db->escape($track_id) . "', amount = '" . $this->db->escape((string)(int)$amount) . "', status = 'pending', last_response = '', date_added = NOW(), date_modified = NOW()");

		return true;
	}

	public function getTransactionByTrackId($track_id) {
		$this->createTable();

		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "zibal_transaction` WHERE track_id = '" . $this->db->escape($track_id) . "'");

		return $query->row;
	}

	// Serialises every callback of one order, so two requests can never both see it as unpaid.
	// The lock is released when the request (and with it the database connection) ends.
	public function lockOrder($order_id) {
		$query = $this->db->query("SELECT GET_LOCK('" . $this->db->escape(DB_PREFIX . 'zibal_order_' . (int)$order_id) . "', 15) AS locked");

		return !empty($query->row['locked']);
	}

	// pending -> paid switch. Returns true only for the single request that performs it.
	public function markPaid($zibal_transaction_id, $details) {
		$this->db->query("UPDATE `" . DB_PREFIX . "zibal_transaction` SET status = 'paid', ref_number = '" . $this->db->escape($details['ref_number']) . "', card_number = '" . $this->db->escape($details['card_number']) . "', paid_at = '" . $this->db->escape($details['paid_at']) . "', date_modified = NOW() WHERE zibal_transaction_id = '" . (int)$zibal_transaction_id . "' AND status = 'pending'");

		return $this->db->countAffected() > 0;
	}

	// The order confirmation for this payment has been written; it must never be written again.
	public function markCompleted($zibal_transaction_id) {
		$this->db->query("UPDATE `" . DB_PREFIX . "zibal_transaction` SET status = 'completed', date_modified = NOW() WHERE zibal_transaction_id = '" . (int)$zibal_transaction_id . "' AND status = 'paid'");
	}

	public function setLastResponse($zibal_transaction_id, $response) {
		$this->db->query("UPDATE `" . DB_PREFIX . "zibal_transaction` SET last_response = '" . $this->db->escape($response) . "', date_modified = NOW() WHERE zibal_transaction_id = '" . (int)$zibal_transaction_id . "'");
	}

	// Writes a note into the order history without touching the order status, stock or e-mails.
	public function addOrderNote($order_id, $comment) {
		$query = $this->db->query("SELECT order_status_id FROM `" . DB_PREFIX . "order` WHERE order_id = '" . (int)$order_id . "'");

		if (!$query->num_rows) {
			return;
		}

		$this->db->query("INSERT INTO `" . DB_PREFIX . "order_history` SET order_id = '" . (int)$order_id . "', order_status_id = '" . (int)$query->row['order_status_id'] . "', notify = '0', comment = '" . $this->db->escape($comment) . "', date_added = NOW()");
	}
}
?>