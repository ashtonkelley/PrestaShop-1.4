<?php
/*
* 2007-2013 PrestaShop
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
*  @copyright  2007-2013 PrestaShop SA
*  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

if (!defined('_PS_VERSION_'))
	exit;

class LoyaltyModule extends ObjectModel
{
	public $id_loyalty_state;
	public $id_customer;
	public $id_order;
	public $id_discount;
	public $points;
	public $date_add;
	public $date_upd;

	protected $fieldsRequired = array('id_customer', 'points');
	protected $fieldsValidate = array('id_loyalty_state' => 'isInt', 'id_customer' => 'isInt', 'id_discount' => 'isInt', 'id_order' => 'isInt', 'points' => 'isInt');

	protected $table = 'loyalty';
	protected $identifier = 'id_loyalty';
	
	public function getFields()
	{
		parent::validateFields();
		$fields['id_loyalty_state'] = (int)$this->id_loyalty_state;
		$fields['id_customer'] = (int)$this->id_customer;
		$fields['id_order'] = (int)$this->id_order;
		$fields['id_discount'] = (int)$this->id_discount;
		$fields['points'] = (int)$this->points;
		$fields['date_add'] = pSQL($this->date_add);
		$fields['date_upd'] = pSQL($this->date_upd);
		return $fields;
	}

	public function save($nullValues = false, $autodate = true)
	{
		parent::save($nullValues, $autodate);
		$this->historize();
	}

	public static function getByOrderId($id_order)
	{
		if (!Validate::isUnsignedId($id_order))
			return false;

		$result = Db::getInstance()->getRow('
		SELECT f.id_loyalty
		FROM `'._DB_PREFIX_.'loyalty` f
		WHERE f.id_order = '.(int)($id_order));

		return isset($result['id_loyalty']) ? $result['id_loyalty'] : false;
	}
	
	public static function getAllByOrderId($id_order)
	{
		if (!Validate::isUnsignedId($id_order))
			return false;

		$result = Db::getInstance()->executeS('
		SELECT f.id_loyalty
		FROM `'._DB_PREFIX_.'loyalty` f
		WHERE f.id_order = '.(int)($id_order));

		return $result;
	}	

	public static function getOrderNbPoints($order)
	{
		if (!Validate::isLoadedObject($order))
			return false;
		return self::getCartNbPoints(new Cart((int)$order->id_cart));
	}

	public static function getCartNbPoints($cart, $newProduct = null)
	{
		$total = 0;
		if (Validate::isLoadedObject($cart))
		{
			$cartProducts = $cart->getProducts();
			$taxesEnabled = Product::getTaxCalculationMethod();
			if (isset($newProduct) && !empty($newProduct))
			{
				$cartProductsNew['id_product'] = (int)$newProduct->id;
				if ($taxesEnabled == PS_TAX_EXC)
					$cartProductsNew['price'] = number_format($newProduct->getPrice(false, (int)($newProduct->getIdProductAttributeMostExpensive())), 2, '.', '');
				else
					$cartProductsNew['price_wt'] = number_format($newProduct->getPrice(true, (int)($newProduct->getIdProductAttributeMostExpensive())), 2, '.', '');
				$cartProductsNew['cart_quantity'] = 1;
				$cartProducts[] = $cartProductsNew;
			}
			foreach ($cartProducts as $product)
			{
				if (!(int)Configuration::get('PS_LOYALTY_NONE_AWARD') && Product::isDiscounted((int)$product['id_product']))
				{
					global $smarty;
					if (isset($smarty) && is_object($newProduct) && $product['id_product'] == $newProduct->id)
						$smarty->assign('no_pts_discounted', 1);
					continue;
				}
				$price = $taxesEnabled == PS_TAX_EXC ? $product['price'] : $product['price_wt'];
				$total += self::getNbPointsByPrice($price, isset($cart->id_currency) ? (int)$cart->id_currency : 0) * (int)$product['cart_quantity'];
			}
			foreach ($cart->getDiscounts(false) as $discount)
				$total -= self::getNbPointsByPrice($discount['value_real'], isset($cart->id_currency) ? (int)$cart->id_currency : 0);;
		}

		return $total;
	}

	public static function getVoucherValue($nbPoints, $id_currency = NULL)
	{
		global $cookie;
		
		if (empty($id_currency))
			$id_currency = (int)$cookie->id_currency;
		
		return (int)$nbPoints * (float)Tools::convertPrice(Configuration::get('PS_LOYALTY_POINT_VALUE'), new Currency((int)$id_currency));
	}

	public static function getNbPointsByPrice($price, $id_currency = 0)
	{
		if (!$id_currency)
			return 0;

		if (Configuration::get('PS_CURRENCY_DEFAULT') != $id_currency)
		{
			$currency = new Currency((int)$id_currency);
			if (Validate::isLoadedObject($currency) && $currency->conversion_rate)
				$price = $price / $currency->conversion_rate;
		}

		/* Prevent division by zero */
		$points = 0;
		if ($pointRate = (float)Configuration::get('PS_LOYALTY_POINT_RATE'))
			$points = floor(number_format($price, 2, '.', '') / $pointRate);

		return (int)$points;
	}

	public static function getPointsByCustomer($id_customer)
	{
		 
		$a = Db::getInstance()->getValue('
		SELECT SUM(f.points) points
		FROM `'._DB_PREFIX_.'loyalty` f
		WHERE f.id_customer = '.(int)($id_customer).'
		AND f.id_loyalty_state IN ('.(int)(LoyaltyStateModule::getValidationId()).', '.(int)(LoyaltyStateModule::getNoneAwardId()).')');
	
		$b = Db::getInstance()->getValue('
		SELECT SUM(f.points) points
		FROM `'._DB_PREFIX_.'loyalty` f
		WHERE f.id_customer = '.(int)($id_customer).'
		AND f.id_loyalty_state = '.(int)LoyaltyStateModule::getCancelId().' AND points < 0');
								
		return (int)$a + $b;
	}

	public static function getAllByIdCustomer($id_customer, $id_lang, $onlyValidate = false, $pagination = false, $nb = 10, $page = 1)
	{
		$query = '
		SELECT f.id_order AS id, f.date_add AS date, (o.total_paid - o.total_shipping) total_without_shipping, f.points, f.id_loyalty, f.id_loyalty_state, fsl.name state
		FROM `'._DB_PREFIX_.'loyalty` f
		LEFT JOIN `'._DB_PREFIX_.'orders` o ON (f.id_order = o.id_order)
		LEFT JOIN `'._DB_PREFIX_.'loyalty_state_lang` fsl ON (f.id_loyalty_state = fsl.id_loyalty_state AND fsl.id_lang = '.(int)($id_lang).')
		WHERE f.id_customer = '.(int)($id_customer);
		if ($onlyValidate === true)
			$query .= ' AND f.id_loyalty_state = '.(int)LoyaltyStateModule::getValidationId();
		$query .= ' GROUP BY f.id_loyalty '.
		($pagination ? 'LIMIT '.(((int)($page) - 1) * (int)($nb)).', '.(int)($nb) : '');

		return Db::getInstance()->ExecuteS($query);
	}

	public static function getDiscountByIdCustomer($id_customer, $last=false)
	{
		$query = '
		SELECT f.id_discount AS id_discount, f.date_upd AS date_add
		FROM `'._DB_PREFIX_.'loyalty` f
		LEFT JOIN `'._DB_PREFIX_.'orders` o ON (f.`id_order` = o.`id_order`)
		WHERE f.`id_customer` = '.(int)($id_customer).' 
		AND f.`id_discount` > 0
		AND o.`valid` = 1';
		if ($last === true)
			$query.= ' ORDER BY f.id_loyalty DESC LIMIT 0,1';
		$query.= ' GROUP BY f.id_discount';

		return Db::getInstance()->ExecuteS($query);
	}

	public static function registerDiscount($discount)
	{
		if (!Validate::isLoadedObject($discount))
			die(Tools::displayError('Incorrect object Discount.'));
		$items = self::getAllByIdCustomer((int)$discount->id_customer, NULL, true);
		$associated = false;
		foreach ($items AS $item)
		{
			$f = new LoyaltyModule((int)$item['id_loyalty']);
			
			/* Check for negative points for this order */
			$negativePoints = (int)Db::getInstance()->getValue('SELECT SUM(points) points FROM '._DB_PREFIX_.'loyalty WHERE id_order = '.(int)$f->id_order.' AND id_loyalty_state = '.(int)LoyaltyStateModule::getCancelId().' AND points < 0');
			
			if ($f->points + $negativePoints <= 0)
				continue;
			
			$f->id_discount = (int)$discount->id;
			$f->id_loyalty_state = (int)LoyaltyStateModule::getConvertId();
			$f->save();
			$associated = true;
		}
		return $associated;
	}

	public static function getOrdersByIdDiscount($id_discount)
	{
		$items = Db::getInstance()->ExecuteS('
		SELECT f.id_order AS id_order, f.points AS points, f.date_upd AS date
		FROM `'._DB_PREFIX_.'loyalty` f
		WHERE f.id_discount = '.(int)($id_discount).' AND f.id_loyalty_state = '.(int)(LoyaltyStateModule::getConvertId()));

		if (!empty($items) AND is_array($items))
		{
			foreach ($items AS $key => $item)
			{
				$order = new Order((int)$item['id_order']);
				$items[$key]['id_currency'] = (int)$order->id_currency;
				$items[$key]['id_lang'] = (int)$order->id_lang;
				$items[$key]['total_paid'] = $order->total_paid;
				$items[$key]['total_shipping'] = $order->total_shipping;
			}
			return $items;
		}

		return false;
	}

	/* Register all transaction in a specific history table */
	private function historize()
	{
		Db::getInstance()->Execute('
		INSERT INTO `'._DB_PREFIX_.'loyalty_history` (`id_loyalty`, `id_loyalty_state`, `points`, `date_add`)
		VALUES ('.(int)($this->id).', '.(int)($this->id_loyalty_state).', '.(int)($this->points).', NOW())');
	}

}
