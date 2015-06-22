<?php
/**
* 2007-2015 PrestaShop
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
*  @author    PrestaShop SA <contact@prestashop.com>
*  @copyright 2007-2015 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
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

$params = '<br /><br />'.$ogone->l('Received parameters:').'<br /><br />'.PHP_EOL;

$secure_key = Tools::getIsset('secure_key') ? Tools::getValue('secure_key') : '';
$sha_sign_received = Tools::getIsset('SHASIGN') ? Tools::getValue('SHASIGN') : '';

foreach ($ogone->getNeededKeyList() as $k)
	if (!Tools::getIsset($k))
		die($ogone->l('Missing parameter:').' '.$k);
	else
		$params .= Tools::safeOutput($k).' : '.Tools::safeOutput(Tools::getValue($k)).'<br />'.PHP_EOL;

/* Fist, check for a valid SHA-1 signature */
$ogone_params = array();
$ignore_key_list = $ogone->getIgnoreKeyList();

foreach ($_GET as $key => $value)
	if (Tools::strtoupper($key) != 'SHASIGN' && $value != '' && !in_array($key, $ignore_key_list))
	$ogone_params[Tools::strtoupper($key)] = $value;

$id_cart = (int)$ogone_params['ORDERID'];

/* Then, load the customer cart and perform some checks */
$cart = new Cart($id_cart);
if (Validate::isLoadedObject($cart))
{

	$sha1 = $ogone->calculateShaSign($ogone_params, Configuration::get('OGONE_SHA_OUT'));

	if ($sha_sign_received && $sha1 == $sha_sign_received)
	{

		$ogone_return_code = (int)$ogone_params['STATUS'];
		$existing_id_order = (int)Order::getOrderByCartId($id_cart);

		$ogone_state = $ogone->getCodePaymentStatus($ogone_return_code);
		$ogone_state_description = $ogone->getCodeDescription($ogone_return_code);
		$payment_state_id = $ogone->getPaymentStatusId($ogone_state);

		$amount_paid = ($ogone_state === Ogone::PAYMENT_ACCEPTED || $ogone_state === Ogone::PAYMENT_AUTHORIZED ? (float)$ogone_params['AMOUNT'] : 0);

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
			$message = sprintf('%s: %d %s %s %f', $ogone->l('Ogone update'), $ogone_return_code, $ogone_state, $ogone_state_description, $amount_paid);
			$ogone->addMessage($existing_id_order, $message);

		}
		else
		{
			$message = sprintf('%s %s %s', $ogone_state_description, Tools::safeOutput($ogone_state), $params);
			$ogone->validate((int)$ogone_params['ORDERID'], $payment_state_id, $amount_paid, $message, Tools::safeOutput($secure_key));
		}
	}
	else
	{
		$message = $ogone->l('Invalid SHA-1 signature').'<br />'.$ogone->l('SHA-1 given:').' '.Tools::safeOutput($sha_sign_received).'<br />'.
		$ogone->l('SHA-1 calculated:').' '.Tools::safeOutput($sha1).'<br />'.$ogone->l('Params: ').' '.Tools::safeOutput($params);
		$ogone->validate((int)$ogone_params['ORDERID'], Configuration::get('PS_OS_ERROR'), 0, $message.'<br />'.$params, Tools::safeOutput($secure_key));
	}
}