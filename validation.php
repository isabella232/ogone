<?php
/*
* 2007-2011 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2011 PrestaShop SA
*  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

include(dirname(__FILE__).'/../../config/config.inc.php');

/**
 * @var ogone
 */
$ogone = Module::getInstanceByName('ogone');

if (!$ogone->active)
	die($ogone->l('Module is desactivated'));

if (!Configuration::get('OGONE_SHA_OUT'))
	die($ogone->l('Invalid value of variable OGONE_SHA_OUT'));

/* First we need to check var presence */
$neededVars = array('orderID', 'amount', 'currency', 'PM', 'ACCEPTANCE', 'STATUS', 'CARDNO', 'PAYID', 'NCERROR', 'BRAND', 'SHASIGN');
$params = '<br /><br />'.$ogone->l('Received parameters:').'<br /><br />';

$secure_key = Tools::getIsset('secure_key') ? Tools::getValue('secure_key') : '';
$sha_sign_received = Tools::getIsset('SHASIGN') ? Tools::getValue('SHASIGN') : '';

foreach ($neededVars as $k)
	if (!Tools::getIsset($k))
		die($ogone->l('Missing parameter:').' '.$k);
	else
		$params .= Tools::safeOutput($k).' : '.Tools::safeOutput(Tools::getValue($k)).'<br />';

/* Fist, check for a valid SHA-1 signature */
$ogoneParams = array();
$ignoreKeyList = $ogone->getIgnoreKeyList();

foreach ($_GET as $key => $value)
	if (Tools::strtoupper($key) != 'SHASIGN' && $value != '' && !in_array($key, $ignoreKeyList))
	$ogoneParams[Tools::strtoupper($key)] = $value;
ksort($ogoneParams);

$id_cart = (int)$ogoneParams['ORDERID'];

/* Then, load the customer cart and perform some checks */
$cart = new Cart($id_cart);
if (Validate::isLoadedObject($cart))
{
	$shasign = '';
	foreach ($ogoneParams as $key => $value)
		$shasign .= Tools::strtoupper($key).'='.$value.Configuration::get('OGONE_SHA_OUT');
	$sha1 = Tools::strtoupper(sha1($shasign));

	if ($sha_sign_received && $sha1 == $sha_sign_received)
	{

		$ogone_return_code = (int)$ogoneParams['STATUS'];
		$existing_id_order = (int)Order::getOrderByCartId($id_cart);

		$ogone_state = $ogone->getCodePaymentStatus($ogone_return_code);
		$ogone_state_description = $ogone->getCodeDescription($ogone_return_code);
		$payment_state_id = $ogone->getPaymentStatusId($ogone_state);

		$amount_paid = ($ogone_state !== ogone::PAYMENT_ACCEPTED ? 0 : (float)$ogoneParams['AMOUNT']);
		
		if ($existing_id_order)
		{
			$order = new Order($existing_id_order);
			
			/* Update the amount really paid */
			if ($order->total_paid_real !== $amount_paid)
			{
				$order->total_paid_real = $amount_paid;
				$order->update();
			}
						
			/* Send a new message and change the state */
			$history = new OrderHistory();
			$history->id_order = (int)$existing_id_order;
			$history->changeIdOrderState($payment_state_id, (int)$existing_id_order);
			$history->addWithemail(true, array());
			
			/* Add message */
			$ogone->addMessage($existing_id_order, sprintf('%s: %d %s %s %f', $ogone->l('Ogone update'), $ogone_return_code, $ogone_state, $ogone_state_description, $amount_paid));
			
		} else
			$ogone->validate((int)$ogoneParams['ORDERID'], $payment_state_id, $amount_paid, sprintf('%s %s %s', $ogone_state_description, Tools::safeOutput($ogone_state), $params), Tools::safeOutput($secure_key));
	}
	else
	{
		$message = $ogone->l('Invalid SHA-1 signature').'<br />'.$ogone->l('SHA-1 given:').' '.Tools::safeOutput($sha_sign_received).'<br />'.
		$ogone->l('SHA-1 calculated:').' '.Tools::safeOutput($sha1).'<br />'.$ogone->l('Plain key:').' '.Tools::safeOutput($shasign);
		$ogone->validate((int)$ogoneParams['ORDERID'], Configuration::get('PS_OS_ERROR'), 0, $message.'<br />'.$params, Tools::safeOutput($secure_key));
	}
}