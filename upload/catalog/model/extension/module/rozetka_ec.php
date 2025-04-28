<?php
class ModelExtensionModuleRozetkaEc extends Model {
	
	/**
     * Оновлює дані замовлення за переданими даними.
     *
     * @param array $data Дані замовлення від Rozetka.
     * 
     * @return int|false ID замовлення, якщо оновлення успішне, або false у разі помилки.
     */
	public function setOrderData($data) {
		$order_id = $this->getOrderIdByUuid($data['external_id']);
		
		$query_order_data = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order` WHERE `order_id` = '" . (int)$order_id . "' AND `order_status_id` = 0");
		
		if($query_order_data->num_rows) {
			
			$order_data = $this->getCleanOrder($order_id);
			
			if($order_data) {
				$email = '';
		
				if(!empty($data['customer']['email'])) {
					$email = $data['customer']['email'];
				}
				
				if(!$email) {					
					$email = time() . '@' . $this->request->server['HTTP_HOST'];
				}
			
				$address = $this->getAddress($data['delivery_details']);
				$shipping = $this->getShippingMethod($data['delivery_details']);
				$payment = $this->getPaymentMethod($data);
				$shipping_customer = $this->getShippingCustomer($data);
				
				$order_data['email'] = $email;
				$order_data['firstname'] = $data['customer']['first_name'];
				$order_data['lastname'] = $data['customer']['last_name'];
				$order_data['telephone'] = $data['customer']['phone'];
				$order_data['comment'] = !empty($data['comment']) ? $data['comment'] : $order_data['comment'];
				
				$order_data['payment_firstname'] = $shipping_customer['first_name'];
				$order_data['payment_lastname'] = $shipping_customer['last_name'];
				$order_data['payment_address_1'] = $address['address_1'];
				$order_data['payment_city'] = $address['city'];
				$order_data['payment_method'] = $payment['name'];
                $order_data['payment_code'] = $payment['code'];
				
				$order_data['shipping_firstname'] = $shipping_customer['first_name'];
				$order_data['shipping_lastname'] = $shipping_customer['last_name'];
				$order_data['shipping_address_1'] = $address['address_1'];
				$order_data['shipping_city'] = $address['city'];
				$order_data['shipping_method'] = $shipping['name'];
				$order_data['shipping_code'] = $shipping['code'];
				
				$order_data['products'] = $this->getOrderproducts($order_id);
				$order_data['totals'] = $this->getOrderTotals($order_id);

				$this->model_checkout_order->editOrder($order_id, $order_data);	
				
				return $order_id;
			}
			
			return false;
		}
		
		return false;
	}
	
	/**
     * Отримує ID замовлення за його UUID.
     *
     * @param string $uuid Унікальний ідентифікатор Rozetka.
     * 
     * @return int|false ID замовлення або false, якщо запис не знайдено.
     */
	public function getOrderIdByUuid($uuid) {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "rozetka_ec_uuid` WHERE `uuid` = '" . $this->db->escape($uuid) . "'");
		
		if($query->num_rows) {
			return $query->row['order_id'];
		} else {
			return false;
		}
	}
	
	/**
     * Отримує "чисті" (оригінальні) дані замовлення.
     *
     * @param int $order_id ID замовлення.
     * 
     * @return array Дані замовлення.
     */
	public function getCleanOrder($order_id) {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order` WHERE `order_id` = '" . (int)$order_id . "'");
	
		return $query->row;
	}
	
	/**
     * Отримує список товарів у замовленні.
     *
     * @param int $order_id ID замовлення.
     * 
     * @return array Масив товарів із їхніми опціями.
     */
	private function getOrderproducts($order_id) {
		$product_data = array();
		
		$products = $this->getDefaultOrderProducts($order_id);
		
		foreach($products as $product) {
			foreach($product as $key => $value) {
				$p_data[$key] = $value;
			}
			
			$p_data['option'] = $this->getOrderOptions($order_id, $product['order_product_id']);
			
			$product_data[] = $p_data;
		}
		
		return $product_data;
	}
	
	/**
     * Формує адресу доставки.
     *
     * @param array $data Дані про доставку.
     * 
     * @return array Адреса у форматі ['address_1' => ..., 'city' => ...].
     */
	private function getAddress($data) {
		$address_1 = '';
		
		if(!empty($data['provider']) && $data['provider'] == 'nova_poshta') {
			if($data['delivery_type'] == 'D') {
				$address_1 = $data['street'] . ', ' . $data['house'];
				
				if(!empty($data['apartment'])) {
					$address_1 .= ', ' . $data['apartment'];
				}
			} elseif($data['delivery_type'] == 'W') {
                $address_1 = !empty($data['warehouse_number']['name']) ? $data['warehouse_number']['name'] : $data['warehouse_number'];
			} elseif($data['delivery_type'] == 'P') {
                $address_1 = !empty($data['warehouse_number']['name']) ? $data['warehouse_number']['name'] : $data['warehouse_number'];
			}
		}
		
		return array(
			'address_1'	=> $address_1,
			'city'      => !empty($data['city']['cityName']) ? $data['city']['cityName'] : $data['city'],
		);
	}
	
	/**
     * Визначає метод оплати.
     *
     * @param array $data Дані про оплату.
     * 
     * @return array Метод оплати у форматі ['code' => ..., 'name' => ...].
     */
	private function getPaymentMethod($data) {		
		$name = $this->language->get('text_payment_rozetka_pre');
		$code = 'rozetka_checkout';

		if(!empty($data['purchase_details'][0]['status_code']) && $data['purchase_details'][0]['status_code'] == 'order_with_postpayment_confirmed') {
			$name = $this->language->get('text_payment_rozetka_post');
			$code = 'rozetka_checkout_postpayment';
		}
		
		return array(
			'name' => $name,
			'code' => $code,
		);
	}
	
	/**
     * Визначає отримувача посилки.
     *
     * @param array $data Дані про оплату.
     * 
     * @return array отримувач замовлення у форматі ['first_name' => ..., 'last_name' => ...].
     */
	private function getShippingCustomer($data) {		
		$first_name = $data['customer']['first_name'];
		$last_name = $data['customer']['last_name'];

		if(!empty($data['order_recipient'])) {
			$first_name = $data['order_recipient']['first_name'];
			$last_name = $data['order_recipient']['last_name'] . "\n" . $data['order_recipient']['phone'];
		}
		
		return array(
			'first_name' => $first_name,
			'last_name' => $last_name,
		);
	}
	
	/**
     * Визначає метод доставки.
     *
     * @param array $data Дані про доставку.
     * 
     * @return array Метод доставки у форматі ['code' => ..., 'name' => ...].
     */
	private function getShippingMethod($data) {
		$code = '';
		$name = '';
		
		if(!empty($data['provider']) && $data['provider'] == 'nova_poshta') {
			if($data['delivery_type'] == 'D') {
				$code = 'novaposhta.doors';
				$name = $this->language->get('text_nova_poshta_d');
			} elseif($data['delivery_type'] == 'W') {
				$code = 'novaposhta.department';
				$name = $this->language->get('text_nova_poshta_w');
			} elseif($data['delivery_type'] == 'P') {
				$code = 'novaposhta.poshtomat';
				$name = $this->language->get('text_nova_poshta_p');
			}
		}
		
		return array(
			'code'		=> $code,
			'name'		=> $name,
		);
	}
	
	/**
     * Прив'язує UUID до замовлення.
     *
     * @param int $order_id ID замовлення.
     * @param string $uuid Унікальний ідентифікатор Rozetka.
     */
	public function setUuidOrder($order_id, $uuid) {
		$this->db->query("DELETE FROM `" . DB_PREFIX . "rozetka_ec_uuid` WHERE `order_id` = '" . (int)$order_id . "'");
		$this->db->query("INSERT INTO `" . DB_PREFIX . "rozetka_ec_uuid` (`order_id`, `uuid`) VALUES ('" . (int)$order_id . "', '" . $this->db->escape($uuid) . "')");
	}
	
	/**
     * Вибірка товарів конкретного замовлення
     *
     * @param int $order_id ID замовлення.
	 *
     * @return array Повертає масив товарів конкретного замовлення.
     */
	public function getDefaultOrderProducts($order_id) {
		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_product WHERE order_id = '" . (int)$order_id . "'");

		return $query->rows;
	}
	
	/**
     * Вибірка тоталів замовлення 
     *
     * @param int $order_id ID замовлення.
	 *
     * @return array Повертає масив значень тоталів конкретного замовлення.
     */
	public function getOrderTotals($order_id) {
		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_total WHERE order_id = '" . (int)$order_id . "' ORDER BY sort_order");

		return $query->rows;
	}
	
	/**
     * Вибірка опцій замовлення 
     *
     * @param int $order_id ID замовлення.
	 *
     * @return array Повертає масив опцій конкретного замовлення.
     */
	public function getOrderOptions($order_id, $order_product_id) {
		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_option WHERE order_id = '" . (int)$order_id . "' AND order_product_id = '" . (int)$order_product_id . "'");

		return $query->rows;
	}
	
		
	/**
     * Перевірка замовлення на постоплату по UUID.
     *
     * @param string $uuid Унікальний ідентифікатор Rozetka.
     */
	public function checkOrderPostpayment($uuid) {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "rozetka_ec_uuid` reu LEFT JOIN `" . DB_PREFIX . "order` o ON(reu.order_id = o.order_id) WHERE reu.uuid = '" . $this->db->escape($uuid) . "' AND o.payment_code = 'rozetka_checkout_postpayment'");
		
		return $query->row;
	}
}