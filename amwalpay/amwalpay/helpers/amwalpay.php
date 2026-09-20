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
class AmwalPay
{
	public static function HttpRequest($apiPath, $data = array())
	{
		if (!in_array('curl', get_loaded_extensions())) {
			throw new Exception('Curl extension is not loaded on your server, please check with server admin. Then try again!');
		}

		$agent = self::sanitizeVar('HTTP_USER_AGENT', 'SERVER');
		ini_set('precision', 14);
		ini_set('serialize_precision', -1);
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, $apiPath);
		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
		curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_USERAGENT, $agent);

		$response = curl_exec($curl);

		if (false === $response) {
			throw new Exception('Curl error: ' . curl_error($curl));
		}
		curl_close($curl);

		return json_decode($response, false);
	}
	public static function createCardsTokenTable()
	{
		$db = JFactory::getDbo();

		$table = $db->quoteName('#__amwalpay_cards_token');

		$query = 'CREATE TABLE IF NOT EXISTS ' . $table . ' (
        `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
        `user_id` BIGINT(20) NOT NULL,
        `token` VARCHAR(56) NOT NULL,
        `merchant_id` VARCHAR(56) NOT NULL,
        `environment` VARCHAR(56) NOT NULL,
        PRIMARY KEY (`id`),
        KEY `user_id` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;';

		$db->setQuery($query);

		try {
			$db->execute();
		} catch (Exception $e) {
			return false;
		}

		return true;
	}
	public static function getUserTokens($customer, $loggerFile, $settings)
	{
		$token = '';

		// Customer must be logged in
		if (!$customer || !empty($customer->guest)) {
			return $token;
		}

		$userId = (int) $customer->id;

		if (!$userId) {
			return $token;
		}

		$db = JFactory::getDbo();

		$table = '#__amwalpay_cards_token';

		// Get saved card token
		$query = $db->getQuery(true)
			->select('*')
			->from($db->quoteName($table))
			->where($db->quoteName('user_id') . ' = ' . $userId)
			->where(
				$db->quoteName('merchant_id') . ' = ' .
				$db->quote($settings->merchant_id)
			)
			->where(
				$db->quoteName('environment') . ' = ' .
				$db->quote($settings->live)
			);

		$db->setQuery($query);

		try {
			$result = $db->loadAssoc();
		} catch (Exception $e) {
			return $token;
		}

		if (!$result || empty($result['token'])) {
			return $token;
		}

		$apiUrl = self::getApiUrl($settings->live);

		if (empty($apiUrl) || empty($apiUrl['webhook'])) {
			return $token;
		}

		$data = array(
			'customerId' => $result['token'],
			'merchantId' => $settings->merchant_id
		);

		$data['secureHashValue'] = self::generateStringForFilter(
			$data,
			$settings->secret_key
		);

		$webhookUrl = $apiUrl['webhook'];

		$sessionTokenRes = self::HttpRequest(
			$webhookUrl . 'Customer/GetSmartboxDirectCallSessionToken',
			$data
		);

		// Log API response
		if (class_exists('AmwalPay')) {
			AmwalPay::addLogs(
				$settings->debug,
				$loggerFile,
				'In api Customer/GetSmartboxDirectCallSessionToken: ',
				print_r($sessionTokenRes, true)
			);
		}

		if (
			isset($sessionTokenRes) &&
			isset($sessionTokenRes->data) &&
			isset($sessionTokenRes->data->sessionToken)
		) {
			$token = $sessionTokenRes->data->sessionToken;
		}

		return $token;
	}
	public static function getApiUrl($env)
	{
		if ($env == "prod") {
			return ['smartbox' => 'https://checkout.amwalpg.com/js/SmartBox.js?v=1.1', 'webhook' => 'https://webhook.amwalpg.com/'];
		} else if ($env == "uat") {
			return ['smartbox' => 'https://test.amwalpg.com:7443/js/SmartBox.js?v=1.1', 'webhook' => 'https://test.amwalpg.com:14443/'];
		} else if ($env == "sit") {
			return ['smartbox' => 'https://test.amwalpg.com:19443/js/SmartBox.js?v=1.1', 'webhook' => 'https://test.amwalpg.com:24443/'];
		}
	}
	public static function generateString(
		$amount,
		$currencyId,
		$merchantId,
		$merchantReference,
		$terminalId,
		$hmacKey,
		$trxDateTime,
		$sessionToken
	) {

		$string = "Amount={$amount}&CurrencyId={$currencyId}&MerchantId={$merchantId}&MerchantReference={$merchantReference}&RequestDateTime={$trxDateTime}&SessionToken={$sessionToken}&TerminalId={$terminalId}";

		$sign = self::encryptWithSHA256($string, $hmacKey);
		return strtoupper($sign);
	}

	public static function encryptWithSHA256($input, $hexKey)
	{
		// Convert the hex key to binary
		$binaryKey = hex2bin($hexKey);
		// Calculate the SHA-256 hash using hash_hmac
		$hash = hash_hmac('sha256', $input, $binaryKey);
		return $hash;
	}
	public static function generateStringForFilter(
		$data,
		$hmacKey

	) {
		// Convert data array to string key value with and sign
		$string = '';
		foreach ($data as $key => $value) {
			$string .= $key . '=' . ($value === "null" || $value === "undefined" ? '' : $value) . '&';
		}
		$string = rtrim($string, '&');
		// Generate SIGN
		$sign = self::encryptWithSHA256($string, $hmacKey);
		return strtoupper($sign);
	}
	public static function sanitizeVar($name, $global = 'GET')
	{
		if (isset($GLOBALS['_' . $global][$name])) {
			if (is_array($GLOBALS['_' . $global][$name])) {
				return $GLOBALS['_' . $global][$name];
			}
			return htmlspecialchars($GLOBALS['_' . $global][$name], ENT_QUOTES);
		}
		return null;
	}

	public static function addLogs($debug, $file, $note, $data = false)
	{
		if (is_bool($data)) {
			('1' === $debug) ? error_log(PHP_EOL . gmdate('d.m.Y h:i:s') . ' - ' . $note, 3, $file) : false;
		} else {
			('1' === $debug) ? error_log(PHP_EOL . gmdate('d.m.Y h:i:s') . ' - ' . $note . ' -- ' . json_encode($data), 3, $file) : false;
		}
	}
}