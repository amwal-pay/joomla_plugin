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
    public static function generateString(
		$amount,
		$currencyId,
		$merchantId,
		$merchantReference,
		$terminalId,
		$hmacKey,
		$trxDateTime
	) {

		$string = "Amount={$amount}&CurrencyId={$currencyId}&MerchantId={$merchantId}&MerchantReference={$merchantReference}&RequestDateTime={$trxDateTime}&SessionToken=&TerminalId={$terminalId}";

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