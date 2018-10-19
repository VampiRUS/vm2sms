<?php

defined('_JEXEC') or die('Restricted access');

if (!class_exists('vmPSPlugin')) {
	require JPATH_VM_PLUGINS . DS . 'vmpsplugin.php';
}

class plgVmPaymentVm2sms extends vmPSPlugin
{
	private $innerdata;

	public function __construct(&$subject, $config)
	{

		parent::__construct($subject, $config);
		// 		vmdebug('Plugin stuff',$subject, $config);
		$this->_loggable = false;
		$varsToPush = array();

		$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
	}

	/**
	 * Create the table for this plugin if it does not yet exist.
	 * @author Valérie Isaksen
	 */
	public function getVmPluginCreateTableSQL()
	{
		return null;
	}

	/**
	 * Fields to create the payment table
	 * @return string SQL Fileds
	 */
	public function getTableSQLFields()
	{
		$SQLfields = array(
		);

		return $SQLfields;
	}

	public function plgVmConfirmedOrder($cart, $order)
	{
		return null;
	}
	public function plgVmOnUpdateOrderPayment($data, $old_status)
	{
		$this->send($data, $old_status);
		return null;
	}

	/**
	 * Display stored payment data for an order
	 *
	 */
	public function plgVmOnShowOrderBEPayment($virtuemart_order_id, $virtuemart_payment_id)
	{
		return null;
	}

	public function getCosts(VirtueMartCart $cart, $method, $cart_prices)
	{
		if (preg_match('/%$/', $method->cost_percent_total)) {
			$cost_percent_total = substr($method->cost_percent_total, 0, -1);
		} else {
			$cost_percent_total = $method->cost_percent_total;
		}
		return ($method->cost_per_transaction + ($cart_prices['salesPrice'] * $cost_percent_total * 0.01));
	}

	protected function checkConditions($cart, $method, $cart_prices)
	{
		$this->convert($method);
		// 		$params = new JParameter($payment->payment_params);
		$address = (($cart->ST == 0) ? $cart->BT : $cart->ST);

		$amount      = $cart_prices['salesPrice'];
		$amount_cond = ($amount >= $method->min_amount and $amount <= $method->max_amount
			or
			($method->min_amount <= $amount and ($method->max_amount == 0)));
		if (!$amount_cond) {
			return false;
		}
		$countries = array();
		if (!empty($method->countries)) {
			if (!is_array($method->countries)) {
				$countries[0] = $method->countries;
			} else {
				$countries = $method->countries;
			}
		}

		// probably did not gave his BT:ST address
		if (!is_array($address)) {
			$address                          = array();
			$address['virtuemart_country_id'] = 0;
		}

		if (!isset($address['virtuemart_country_id'])) {
			$address['virtuemart_country_id'] = 0;
		}
		if (count($countries) == 0 || in_array($address['virtuemart_country_id'], $countries) || count($countries) == 0) {
			return true;
		}

		return false;
	}

	public function convert($method)
	{

		$method->min_amount = (float) $method->min_amount;
		$method->max_amount = (float) $method->max_amount;
	}

	public function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
	{
		return $this->onStoreInstallPluginTable($jplugin_id);
	}

	public function plgVmOnSelectCheckPayment(VirtueMartCart $cart, &$msg)
	{
		return $this->OnSelectCheck($cart);
	}

	public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn)
	{
		return $this->displayListFE($cart, $selected, $htmlIn);
	}

	public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
	{
		return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
	}

	public function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId)
	{

		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
			return null; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}
		$this->getPaymentCurrency($method);

		$paymentCurrencyId = $method->payment_currency;
		return;
	}

	public function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter)
	{
		return $this->onCheckAutomaticSelected($cart, $cart_prices, $paymentCounter);
	}

	public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
	{
		$this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
	}

	public function plgVmonShowOrderPrintPayment($order_number, $method_id)
	{
		return $this->onShowOrderPrint($order_number, $method_id);
	}

	public function plgVmDeclarePluginParamsPayment($name, $id, &$data)
	{
		return $this->declarePluginParams('payment', $name, $id, $data);
	}

	public function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
	{
		return $this->setOnTablePluginParams($name, $id, $table);
	}

	private function send($data, $old_status)
	{
		$db = JFactory::getDBO();
		$db->setQuery('SELECT * FROM #__virtuemart_order_userinfos WHERE virtuemart_order_id=' . $data->virtuemart_order_id);
		$userdata           = $db->loadObjectList('address_type');
		$cparams            = JComponentHelper::getParams('com_vm2sms');
		$phone              = '';
		$phone_field        = $cparams->get('phone_field');
		$custom_phone_field = $cparams->get('custom_phone_field');
		if ($custom_phone_field) {
			$phone_field = $custom_phone_field;
		}
		if (isset($userdata['BT']) && isset($userdata['BT']->$phone_field)) {
			$phone = $userdata['BT']->$phone_field;
		}
		if (isset($userdata['ST']) && isset($userdata['ST']->$phone_field)) {
			$phone = $userdata['ST']->$phone_field;
		}
		$this->innerdata = array($data, $userdata);
		$repl            = array(' ', '-', '(', ')');
		$phone           = str_replace($repl, "", $phone);
		$db->setQuery('SELECT
			send_sms,
			text_sms,
			worktime,
			manager_text_sms,
			manager_send_sms,
			manager_worktime,
			include_comment
		from #__vm2sms where `status`=' . $db->quote($data->order_status));
		$param = $db->loadObject();
		JPluginHelper::importPlugin('vm2sms');
		$dispatcher = JDispatcher::getInstance();
		$sender     = $cparams->get('sender_name');
		if ($phone && $param->send_sms) {
			$text = preg_replace_callback('/%((comment)\.{0,1}(\d*)|ordernumber|orderpass|(price)\.{0,1}(\d*)|first_name)%/', array('vm2smsapi', "text_replace"), $param->text_sms);
			if ($param->include_comment) {
				$backtrace = debug_backtrace();
				foreach ($backtrace as $line) {
					if ($line['function'] == 'updateStatusForOneOrder'
						&& $line['class'] == 'VirtueMartModelOrders'
						&& isset($line['args'][1])
						&& is_array($line['args'][1])) {
						$text .= "\n" . $line['args'][1]['comments'];
					}
				}

			}
			$results = $dispatcher->trigger('onSendSMS', array($phone, $text, $param->worktime, $sender));
		}
		$phones = explode(',', $cparams->get('manager_phones'));
		foreach ($phones as $phone) {
			$phone = trim($phone);
			if ($phone && $param->manager_send_sms) {
				$text    = preg_replace_callback('/%((comment)\.{0,1}(\d*)|ordernumber|orderpass|(price)\.{0,1}(\d*)|first_name)%/', array($this, "text_replace"), $param->manager_text_sms);
				$results = $dispatcher->trigger('onSendSMS', array($phone, $text, $param->manager_worktime, $sender));
			}
		}
	}

	public function text_replace($m)
	{
		$result = '';
		switch ($m[1]) {
			case 'ordernumber':$result = $this->innerdata[0]->order_number;
				break;
			case 'orderpass':$result = $this->innerdata[0]->order_pass;
				break;
			case 'first_name':$result = $this->innerdata[1]['BT']->first_name;
				break;
			default:
				$result = $m[0];
				if (isset($m[4]) && $m[4] == 'price') {
					$dec = 2;
					if (!($m[5] == '')) {
						$dec = $m[5];
					}
					$result = number_format($this->innerdata[0]->order_total, $dec, '.', '');
				}
				if (isset($m[2]) && $m[2] == 'comment') {
					$limit = $m[3] - 1;
					$db    = JFactory::getDBO();
					$db->setQuery('SELECT comments FROM #__virtuemart_order_histories WHERE virtuemart_order_id=' . $this->innerdata[0]->virtuemart_order_id . ' ORDER BY virtuemart_order_history_id DESC');
					$comments = $db->loadObjectList();
					$result   = str_replace("<br />", "\n", $comments[$limit]->comments);
				}
				break;
		}
		return $result;
	}

}

// No closing tag
