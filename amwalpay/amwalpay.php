<?php
/**
 *
 * AmwalPay payment plugin
 *
 * @author $URI: https://amwalpay.om/
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
	private $file;
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
			'payment_view' => array('', 'char'),
			'contact_info_type' => array('', 'char'),
			'ignore_receipt' => array('', 'char'),
			'checkout_color' => array('', 'char'),
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
		AmwalPay::createCardsTokenTable();
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
		$user = JFactory::getUser();
		$order_number = $order['details']['BT']->order_number;
		$_VMOrderID = $order['details']['BT']->virtuemart_order_id;
		$selectedMethod = $this->getVmPluginMethod($cart->virtuemart_paymentmethod_id);
		$totalInPaymentCurrency = vmPSPlugin::getAmountInCurrency($order['details']['BT']->order_total, $method->currency_id);
		$amount = $totalInPaymentCurrency['value'];

		$locale = $order['details']['BT']->order_language; // Get the current language locale
		$currentDate = new DateTime();
		$datetime = $currentDate->format('YmdHis');
		$refNumber = $_VMOrderID . "_" . $currentDate->format('ymds');
		$payment_view = $selectedMethod->payment_view;
		$contact_info_type = $selectedMethod->contact_info_type;
		$ignoreReceipt = $selectedMethod->ignore_receipt == '1' ? true : false;
		$checkout_color = $selectedMethod->checkout_color ?? '#7f22ff';

		$sessionToken = AmwalPay::getUserTokens($user, $this->file, $selectedMethod);
		// if $locale content en make $locale = "en"
		if (strpos($locale, 'en') !== false) {
			$locale = "en";
		} else {
			$locale = "ar";
		}
		$base_url = JURI::root();
		$call_back = urldecode($base_url . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived');
		$cancel_url = urldecode($base_url . 'index.php/cart/checkout');
		$urls = in_array($payment_view, [1, 2])
			? ['', '']
			: [$call_back, $cancel_url];
		list($returnUrl, $cancelUrl) = $urls;
		// Generate secure hash
		$secret_key = AmwalPay::generateString(
			$amount,
			512,
			$selectedMethod->merchant_id,
			$refNumber
			,
			$selectedMethod->terminal_id,
			$selectedMethod->secret_key,
			$datetime,
			$sessionToken
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
			'RequestSource' => 'Checkout_Joomla',
			'SessionToken' => $sessionToken,
			'ReturnUrl' => $returnUrl,
			'CancelUrl' => $cancelUrl,
			'PaymentViewType' => in_array($payment_view, [1, 2]) ? $payment_view : 1,
			'CheckoutSiteMode' => ($payment_view === '3') ? 'offsite' : 'onsite',
			'ContactInfoType' => $contact_info_type,
			'IgnoreReceipt' => $ignoreReceipt,
			'PrimaryColor' => $checkout_color
		];
		AmwalPay::addLogs($selectedMethod->debug, $this->file, 'Payment Request: ', print_r($data, 1));
		$apiUrl = AmwalPay::getApiUrl($selectedMethod->live);
		$doc = JFactory::getDocument();
		$doc->addScript($apiUrl['smartbox']);

		$jsData = json_encode($data); // Already an object; this just ensures proper format
		$inlineScript = "
			 window.SmartBoxData = $jsData;
			 window.CancelUrl = '$cancel_url';
			 window.CallBack = '$call_back';
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
	public function plgVmOnPaymentResponseReceived(&$html)
	{
		if (AmwalPay::sanitizeVar('REQUEST_METHOD', 'SERVER') === 'POST') {
			$this->callCloudNotification();
		} else if (AmwalPay::sanitizeVar('REQUEST_METHOD', 'SERVER') === 'GET') {
			$this->callBack($html);
		}

	}
	public function callBack(&$html)
	{
		list($orderId, ) = explode('_', AmwalPay::sanitizeVar('merchantReference'));

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
		$link = JRoute::_("index.php?option=com_virtuemart&view=orders&layout=details&order_number=" . $order['details']['BT']->order_number . "&order_pass=" . $order['details']['BT']->order_pass, false);

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

		$info = 'Old Hash -- ' . AmwalPay::sanitizeVar('secureHashValue') . '  New Hash -- ' . $secureHashValue . "</br>";
		AmwalPay::addLogs($method->debug, $this->file, $info);
		if ($secureHashValue != AmwalPay::sanitizeVar('secureHashValue')) {
			AmwalPay::addLogs($method->debug, $this->file, 'Invalid Hash');
			$html .= "<br /><b style='color: red'>Ops, you are accessing wrong data</b>";
			$html .= '<br /><br /><a class="vm-button-correct" href="' . $link . '">' . vmText::_('COM_VIRTUEMART_ORDER_VIEW_ORDER') . '</a>';
			return false;
		}
		$this->saveCardToken(AmwalPay::sanitizeVar('customerId'), $method);
		if (AmwalPay::sanitizeVar('responseCode') === '00') {
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

			$html .= "<br /><b style='color: red'>$note.</b>";
			$html .= '<br /><br /><a class="vm-button-correct" href="' . $link . '">' . vmText::_('COM_VIRTUEMART_ORDER_VIEW_ORDER') . '</a>';
			return false;
		}
	}
	public function callCloudNotification()
	{
		$post_data = file_get_contents('php://input');
		$json_data = json_decode($post_data, true);

		list($orderId, ) = explode('_', $json_data['MerchantReference']);
		if (empty($orderId) || is_null($orderId) || $orderId === false || $orderId === "") {
			die(json_encode(['message' => 'Ops, you are accessing wrong data'], 400));
		}

		$orderModel = VmModel::getModel('orders');
		$order = $orderModel->getOrder($orderId);

		if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
			return NULL;
		}

		if ($method->payment_element != 'amwalpay') {
			die(json_encode(['message' => 'Ops, you are accessing wrong data'], 400));
		}

		AmwalPay::addLogs(
			$method->debug,
			$this->file,
			'In Cloud Notification Response: ',
			print_r($json_data, 1)
		);

		// Validate payload
		if (empty($json_data)) {
			AmwalPay::addLogs($method->debug, $this->file, 'Empty JSON data');
			die(json_encode(['message' => 'Invalid payload'], 400));
		}

		// Validate Merchant & Terminal IDs
		if ($json_data['MerchantId'] != $method->merchant_id || $json_data['TerminalId'] != $method->terminal_id) {
			AmwalPay::addLogs($method->debug, $this->file, 'Merchant/Terminal mismatch');
			die(json_encode(['message' => 'Configuration mismatch'], 403));
		}

		$integrityParameters = [
			"Amount" => $json_data['Amount'],
			"AuthorizationDateTime" => $json_data['AuthorizationDateTime'],
			"CurrencyId" => $json_data['CurrencyId'],
			"DateTimeLocalTrxn" => $json_data['DateTimeLocalTrxn'],
			"MerchantId" => $method->merchant_id,
			"MerchantReference" => $json_data['MerchantReference'],
			"Message" => $json_data['Message'],
			"PaidThrough" => $json_data['PaidThrough'],
			"ResponseCode" => $json_data['ResponseCode'],
			"SystemReference" => $json_data['SystemReference'],
			"TerminalId" => $method->terminal_id,
			"TxnType" => $json_data['TxnType'],
		];

		$secureHashValue = AmwalPay::generateStringForFilter($integrityParameters, $method->secret_key);
		$integrityParameters['secureHashValue'] = $secureHashValue;
		$integrityParameters['secureHashValueOld'] = $json_data['SecureHash'];

		AmwalPay::addLogs($method->debug, $this->file, 'Calculated Hash: ', print_r($integrityParameters, 1));
		if ($secureHashValue != $json_data['SecureHash']) {
			AmwalPay::addLogs($method->debug, $this->file, 'Invalid Hash');
			die(json_encode([
				'order_id' => $orderId,
				'message' => 'Invalid Hash',
			]));
		}
		$msg = 'Order #' . $orderId;
		if ($json_data['ResponseCode'] === '00') {
			// Success
			$note = 'AmwalPay Webhook: Payment Approved';
			$msg = $msg . ' ' . $note;
			$order_history['order_status'] = 'C';
			$order_history['comments'] = $note;
			$orderModel->updateStatusForOneOrder($orderId, $order_history, true);
			AmwalPay::addLogs($method->debug, $this->file, "Order #$orderId marked as paid");
		} else {
			// Failed
			$note = 'AmwalPay Webhook: Payment Failed';
			$msg = $msg . ' ' . $note;
			$order_history['order_status'] = 'X';
			$order_history['comments'] = $note;
			$orderModel->updateStatusForOneOrder($orderId, $order_history, true);
			AmwalPay::addLogs($method->debug, $this->file, "Order #$orderId marked as failed");
		}
		die(json_encode([
			'order_id' => $orderId,
			'message' => 'Order updated successfully',
			'data' => $json_data['SystemReference'],
		]));
	}
	public function saveCardToken($customerTokenId, $settings)
	{
		// Get the currently logged-in Joomla user
		$user = JFactory::getUser();

		// Customer must be logged in
		if ($user->guest || empty($customerTokenId) || $customerTokenId === 'null') {
			return false;
		}

		$userId = (int) $user->id;
		$userEmail = $user->email;

		// Get plugin configuration
		$merchantId = $settings->merchant_id;
		$environment = $settings->live;


		AmwalPay::addLogs(
			$settings->debug,
			$this->file,
			'Customer save Card Token for user -- ' . $userEmail,
			$customerTokenId
		);


		$db = JFactory::getDbo();
		$table = '#__amwalpay_cards_token';

		// Check whether the user already has a token
		$query = $db->getQuery(true)
			->select('*')
			->from($db->quoteName($table))
			->where($db->quoteName('user_id') . ' = ' . $userId)
			->where($db->quoteName('merchant_id') . ' = ' . $db->quote($merchantId))
			->where($db->quoteName('environment') . ' = ' . $db->quote($environment));

		$db->setQuery($query);
		$existing = $db->loadObject();

		if (!$existing) {
			// Insert new token
			$columns = array(
				'user_id',
				'token',
				'merchant_id',
				'environment'
			);

			$values = array(
				$userId,
				$db->quote($customerTokenId),
				$db->quote($merchantId),
				$db->quote($environment)
			);

			$query = $db->getQuery(true)
				->insert($db->quoteName($table))
				->columns($db->quoteName($columns))
				->values(implode(',', $values));

			$db->setQuery($query);

			if (!$db->execute()) {
				return false;
			}
		} else {
			// Update existing token
			$fields = array(
				$db->quoteName('token') . ' = ' . $db->quote($customerTokenId)
			);

			$query = $db->getQuery(true)
				->update($db->quoteName($table))
				->set($fields)
				->where($db->quoteName('user_id') . ' = ' . $userId)
				->where($db->quoteName('merchant_id') . ' = ' . $db->quote($merchantId))
				->where($db->quoteName('environment') . ' = ' . $db->quote($environment));

			$db->setQuery($query);

			if (!$db->execute()) {
				return false;
			}
		}

		return true;
	}
	public function _getPaymentResponseHtml($order_number, $payment_name)
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