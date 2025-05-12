<?php
/**
 *
 * Paymob payment plugin
 *
 * @author $URI: https://paymob.com
 * @author Paymob Development Team
 * @version $Id: getlogpath.php
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
defined('JPATH_BASE') or die();

jimport('joomla.form.formfield');
class JFormFieldGetlogpath extends JFormField
{

	/**
	 * Element name
	 *
	 * @access    protected
	 * @var        string
	 */
	var $type = 'getlogpath';
	protected function getInput()
	{
		JHtml::_('behavior.colorpicker');
		$html = '
        <p>Log file will be saved in joomla_directory/administrator/logs/</p>';
		;
		return $html;
	}
}