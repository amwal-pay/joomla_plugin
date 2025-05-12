<?php
/**
 *
 * AmwalPay payment plugin
 *
 * @author $URI: https://www.amwal-pay.com/
 * @author AmwalPay Development Team
 * @version $Id: amwalpay.php
 * @package VirtueMart
 * @subpackage payment
 * Copyright (C) 2004 - 2020 Virtuemart Team. All rights reserved.
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL, see LICENSE.php
 * VirtueMart is free software. This version may have been modified pursuant
 * to the GNU General Public License, and as distributed it includes or
 * is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 * See /administrator/components/com_virtuemart/COPYRIGHT.php for copyright notices and details.
 *
 * http://virtuemart.net
 */

defined('_JEXEC') or die('Restricted access');

if (!class_exists('AmwalPay')) {
	require(VMPATH_ROOT . '/plugins/vmpayment/amwalpay/amwalpay/helpers/amwalpay.php');
}
class plgVmPaymentAmwalPay extends vmPSPlugin
{
	private $_currentMethod;
	private $_file;
	function __construct(&$subject, $config)
	{
		parent::__construct($subject, $config);

		$path = JFactory::getConfig();
		$log_path = $path->get('log_path', VMPATH_ROOT . "/log");
		$this->file = $log_path . '/amwalpay.log';
		$this->tableFields = array_keys($this->getTableSQLFields());
		$this->_loggable = TRUE;
		$this->_tablepkey = 'id';
		$this->_tableId = 'id';
		$varsToPush = array(
			'live' => array('', 'char'),
			'merchant_id' => array('', 'char'),
			'terminal_id' => array('', 'char'),
			'secret_key' => array('', 'char'),
			'debug' => array(0, 'int')
		);
		$this->addVarsToPushCore($varsToPush, 1);
		$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
	}
	function getTableSQLFields()
	{
		//virtuemart_order_id, order_number
		$SQLfields = array(
			'id' => 'int(11) UNSIGNED NOT NULL AUTO_INCREMENT',
			'virtuemart_order_id' => 'int(1) UNSIGNED',
			'order_number' => 'char(64)',
		);
		return $SQLfields;
	}
	/**
	 *
	 * @param $cart
	 * @param $order
	 * @return bool|null|void
	 */
	public function plgVmConfirmedOrder($cart, $order)
	{
		if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
			return NULL; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return FALSE;
		}
		$app = JFactory::getApplication();
		$order_number = $order['details']['BT']->order_number;
		$_VMOrderID = $order['details']['BT']->virtuemart_order_id;
		$selectedMethod = $this->getVmPluginMethod($cart->virtuemart_paymentmethod_id);
		$totalInPaymentCurrency = vmPSPlugin::getAmountInCurrency($order['details']['BT']->order_total, $method->currency_id);
		$amount = $totalInPaymentCurrency['value'];

		$locale = $order['details']['BT']->order_language; // Get the current language locale
		$currentDate = new DateTime();
		$datetime = $currentDate->format('YmdHis');
		$refNumber = $_VMOrderID . "_" . $currentDate->format('ymds');

		// if $locale content en make $locale = "en"
		if (strpos($locale, 'en') !== false) {
			$locale = "en";
		} else {
			$locale = "ar";
		}

		// Generate secure hash
		$secret_key = AmwalPay::generateString(
			$amount,
			512,
			$selectedMethod->merchant_id,
			$refNumber
			,
			$selectedMethod->terminal_id,
			$selectedMethod->secret_key,
			$datetime
		);

		$data = (object) [
			'AmountTrxn' => "$amount",
			'MerchantReference' => "$refNumber",
			'MID' => $selectedMethod->merchant_id,
			'TID' => $selectedMethod->terminal_id,
			'CurrencyId' => 512,
			'LanguageId' => $locale,
			'SecureHash' => $secret_key,
			'TrxDateTime' => $datetime,
			'PaymentViewType' => 1,
			'RequestSource' => 'Checkout_Joomla',
			'SessionToken' => '',
		];
		AmwalPay::addLogs($selectedMethod->debug, $this->file, 'Payment Request: ', print_r($data, 1));
		$doc = JFactory::getDocument();
		$doc->addScript($this->amwal_scripts($selectedMethod->live));

		$jsData = json_encode($data); // Already an object; this just ensures proper format
		$base_url = JURI::root();
		$callback = $base_url . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived';
		$inlineScript = "
			 window.SmartBoxData = $jsData;
			 window.BaseUrl = '$base_url';
			 window.CallBack = '$callback';
		 ";

		$doc->addScriptDeclaration($inlineScript);

		$doc->addScript(JURI::root() . 'plugins/vmpayment/amwalpay/amwalpay/assets/js/smart_box.js');

		$db = JFactory::getDbo();
		$query = $db->getQuery(true)
			->insert($db->quoteName('#__virtuemart_payment_plg_amwalpay'))
			->columns($db->quoteName(['virtuemart_order_id', 'order_number']))
			->values($db->quote($_VMOrderID) . ',' . $db->quote($order_number));
		$db->setQuery($query);
		$db->execute();
	}
	public function amwal_scripts($live)
	{

		if ($live == "prod") {
			$liveurl = "https://checkout.amwalpg.com/js/SmartBox.js?v=1.1";
		} else if ($live == "uat") {
			$liveurl =
				"https://test.amwalpg.com:7443/js/SmartBox.js?v=1.1";
		} else if ($live == "sit") {
			$liveurl =
				"https://test.amwalpg.com:19443/js/SmartBox.js?v=1.1";

		}
		return $liveurl;
	}
	function plgVmOnPaymentResponseReceived(&$html)
	{
		$orderId = substr(AmwalPay::sanitizeVar('merchantReference'), 0, -9);

		if (empty($orderId) || is_null($orderId) || $orderId === false || $orderId === "") {
			throw new Exception('Ops, you are accessing wrong data');
		}
		$orderModel = VmModel::getModel('orders');
		$order = $orderModel->getOrder($orderId);


		if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
			return NULL;
		}

		if ($method->payment_element != 'amwalpay') {
			throw new Exception('Ops, you are accessing wrong data');
		}

		$payment_name = $this->renderPluginName($method);
		$html = $this->_getPaymentResponseHtml($order['details']['BT']->order_number, $payment_name);
		$isPaymentApproved = false;

		$integrityParameters = [
			"amount" => AmwalPay::sanitizeVar('amount'),
			"currencyId" => AmwalPay::sanitizeVar('currencyId'),
			"customerId" => AmwalPay::sanitizeVar('customerId'),
			"customerTokenId" => AmwalPay::sanitizeVar('customerTokenId'),
			"merchantId" => $method->merchant_id,
			"merchantReference" => AmwalPay::sanitizeVar('merchantReference'),
			"responseCode" => AmwalPay::sanitizeVar('responseCode'),
			"terminalId" => $method->terminal_id,
			"transactionId" => AmwalPay::sanitizeVar('transactionId'),
			"transactionTime" => AmwalPay::sanitizeVar('transactionTime')
		];
		AmwalPay::addLogs($method->debug, $this->file, 'Callback Response: ', print_r($integrityParameters, 1));
		$secureHashValue = AmwalPay::generateStringForFilter($integrityParameters, $method->secret_key);
		$integrityParameters['secureHashValue'] = $secureHashValue;
		$integrityParameters['secureHashValueOld'] = AmwalPay::sanitizeVar('secureHashValue');

		if (AmwalPay::sanitizeVar('responseCode') === '00' || $secureHashValue == AmwalPay::sanitizeVar('secureHashValue')) {
			$isPaymentApproved = true;
		}

		$info = 'Old Hash -- ' . AmwalPay::sanitizeVar('secureHashValue') . '  New Hash -- ' . $secureHashValue . "</br>";
		AmwalPay::addLogs($method->debug, $this->file, $info . ' Payment', $isPaymentApproved ? 'Approved' : 'Canceled');

		if ($isPaymentApproved) {
			$note = 'AmwalPay : Payment Approved';
			$msg = 'In callback action, for order #' . $orderId . ' ' . $note;
			$order_history['order_status'] = 'C';
			$order_history['comments'] = $note;
			$orderModel->updateStatusForOneOrder($orderId, $order_history, true);
			AmwalPay::addLogs($method->debug, $this->file, $msg);
			$link = JRoute::_("index.php?option=com_virtuemart&view=orders&layout=details&order_number=" . $order['details']['BT']->order_number . "&order_pass=" . $order['details']['BT']->order_pass, false);
			$cart = VirtueMartCart::getCart();
			$cart->emptyCart();
			$html .= "<br /><b style='color: green'>$note.</b>";
			$html .= '<br /><a class="vm-button-correct" href="' . $link . '">' . vmText::_('COM_VIRTUEMART_ORDER_VIEW_ORDER') . '</a>';
			return true;
		} else {
			$note = 'AmwalPay : Payment is not Completed';
			$msg = 'In callback action, for order #' . $orderId . ' ' . $note;
			$order_history['order_status'] = 'X';
			$order_history['comments'] = $note;
			$orderModel->updateStatusForOneOrder($orderId, $order_history, true);
			AmwalPay::addLogs($method->debug, $this->file, $msg);
			$link = JRoute::_("index.php?option=com_virtuemart&view=orders&layout=details&order_number=" . $order['details']['BT']->order_number . "&order_pass=" . $order['details']['BT']->order_pass, false);
			$html .= "<br /><b style='color: red'>$note.</b>";
			$html .= '<br /><br /><a class="vm-button-correct" href="' . $link . '">' . vmText::_('COM_VIRTUEMART_ORDER_VIEW_ORDER') . '</a>';
			return false;
		}
	}
	function _getPaymentResponseHtml($order_number, $payment_name)
	{
		VmConfig::loadJLang('com_virtuemart');
		$html = '<table>' . "\n";
		$html .= $this->getHtmlRow('COM_VIRTUEMART_PAYMENT_NAME', $payment_name);
		if (!empty($order_number)) {
			$html .= $this->getHtmlRow('Order Number', $order_number);
		}
		$html .= '</table>' . "\n";
		return $html;
	}

	/**
	 *     * This event is fired after the payment method has been selected.
	 * It can be used to store additional payment info in the cart.
	 * @param VirtueMartCart $cart
	 * @param $msg
	 * @return bool|null
	 */
	public function plgVmOnSelectCheckPayment(VirtueMartCart $cart, &$msg)
	{
		return $this->OnSelectCheck($cart);
	}

	/**
	 * * List payment methods selection
	 * @param VirtueMartCart $cart
	 * @param int $selected
	 * @param $htmlIn
	 * @return bool
	 */

	public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected, &$htmlIn)
	{

		if ($this->getPluginMethods($cart->vendorId) === 0) {
			if (empty($this->_name)) {
				$app = JFactory::getApplication();
				$app->enqueueMessage(vmText::_('COM_VIRTUEMART_CART_NO_' . strtoupper($this->_psType)));
				return false;
			} else {
				return false;
			}
		}
		$method_name = $this->_psType . '_name';
		$idN = 'virtuemart_' . $this->_psType . 'method_id';

		foreach ($this->methods as $this->_currentMethod) {
			if ($this->checkConditions($cart, $this->_currentMethod, $cart->cartPrices)) {

				$html = '';
				$cartPrices = $cart->cartPrices;
				if (isset($this->_currentMethod->cost_method)) {
					$cost_method = $this->_currentMethod->cost_method;
				} else {
					$cost_method = true;
				}
				$methodSalesPrice = $this->setCartPrices($cart, $cartPrices, $this->_currentMethod, $cost_method);

				$this->_currentMethod->payment_currency = $this->getPaymentCurrency($this->_currentMethod);
				$this->_currentMethod->$method_name = $this->renderPluginName($this->_currentMethod);

				$html .= $this->getPluginHtml($this->_currentMethod, $selected, $methodSalesPrice);

				$htmlIn[$this->_psType][$this->_currentMethod->$idN] = $html;
			}
		}

		return true;

	}
	protected function getPluginHtml($plugin, $selectedPlugin, $pluginSalesPrice)
	{
		$pluginmethod_id = $this->_idName;
		$pluginName = $this->_psType . '_name';
		if ($selectedPlugin == $plugin->{$pluginmethod_id}) {
			$checked = 'checked="checked"';
		} else {
			$checked = '';
		}

		$currency = CurrencyDisplay::getInstance();
		$costDisplay = "";
		if ($pluginSalesPrice) {
			$costDisplay = $currency->priceDisplay($pluginSalesPrice);
			$t = vmText::_('COM_VIRTUEMART_PLUGIN_COST_DISPLAY');
			if (strpos($t, '/') !== FALSE) {
				list($discount, $fee) = explode('/', vmText::_('COM_VIRTUEMART_PLUGIN_COST_DISPLAY'));
				if ($pluginSalesPrice >= 0) {
					$costDisplay = '<span class="' . $this->_type . '_cost fee"> (' . $fee . ' +' . $costDisplay . ")</span>";
				} else if ($pluginSalesPrice < 0) {
					$costDisplay = '<span class="' . $this->_type . '_cost discount"> (' . $discount . ' -' . $costDisplay . ")</span>";
				}
			} else {
				$costDisplay = '<span class="' . $this->_type . '_cost fee"> (' . $t . ' +' . $costDisplay . ")</span>";
			}
		}

		$dynUpdate = '';
		if (VmConfig::get('oncheckout_ajax', false)) {
			$dynUpdate = ' data-dynamic-update="1" ';
		}

		$payment_logo = '<img src="' . JURI::root() . 'plugins/vmpayment/amwalpay/amwalpay/assets/imgs/amwalpay.svg" alt="Amwalpay" />';
		$html = '<input type="radio" ' . $dynUpdate . ' name="' . $pluginmethod_id . '" id="' . $this->_psType . '_id_' . $plugin->$pluginmethod_id . '"   value="' . $plugin->$pluginmethod_id . '" ' . $checked . ">\n"
			. '<label for="' . $this->_psType . '_id_' . $plugin->$pluginmethod_id . '">' . '<span class="' . $this->_type . '">' . $plugin->$pluginName . $payment_logo . $costDisplay . "</span></label>\n";
		return $html;
	}

	/**
	 * Validate payment on checkout
	 * @param VirtueMartCart $cart
	 * @return bool|null
	 */
	//Calculate the price (value, tax_id) of the selected method, It is called by the calculator
	//This function does NOT to be reimplemented. If not reimplemented, then the default values from this function are taken.
	public function plgVmOnSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
	{
		if (!($selectedMethod = $this->getVmPluginMethod($cart->virtuemart_paymentmethod_id))) {
			return FALSE;
		}
		//$this->isExpToken($selectedMethod, $cart) ;
		return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
	}
	// Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
	// The plugin must check first if it is the correct type
	function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices, &$paymentCounter)
	{
		return $this->onCheckAutomaticSelected($cart, $cart_prices, $paymentCounter);
	}

	// This method is fired when showing the order details in the frontend.
	// It displays the method-specific data.
	public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
	{
		$this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
	}

	// This method is fired when showing when priting an Order
	// It displays the the payment method-specific data.
	function plgVmonShowOrderPrintPayment($order_number, $method_id)
	{
		return $this->onShowOrderPrint($order_number, $method_id);
	}

	function plgVmDeclarePluginParamsPaymentVM3(&$data)
	{
		return $this->declarePluginParams('payment', $data);
	}

	function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
	{
		return $this->setOnTablePluginParams($name, $id, $table);
	}

}