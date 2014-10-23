<?php
/**
 * 2014 Easymarketing AG
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to info@easymarketing.de so we can send you a copy immediately.
 *
 * @author    silbersaiten www.silbersaiten.de <info@silbersaiten.de>
 * @copyright 2014 Easymarketing AG
 * @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

class EasymarketingProductsModuleFrontController extends ModuleFrontController {
	public $display_header = false;
	public $display_footer = false;

	public function initContent()
	{
		parent::initContent();

		$log_type = 'products';
		$response = null;
		$message = '===== '.date('Y.m.d h:i:s').' ====='."\r\n";
		$message .= 'Request: '.print_r($_GET, true);

		if (!Tools::getIsset('lang') ||	(Tools::getIsset('lang') && ($id_lang = Language::getIdByIso(Tools::getValue('lang'))) == false ))
			$id_lang = Configuration::get('PS_LANG_DEFAULT');

		if (Tools::getValue('shop_token') == Configuration::get('EASYMARKETING_SHOP_TOKEN'))
		{

			if (Tools::getIsset('offset') && Tools::getIsset('limit') &&
				Validate::isInt(Tools::getValue('offset')) && Validate::isInt(Tools::getValue('limit')))
			{
				$offset = Tools::getValue('offset');
				$limit = Tools::getValue('limit');

				$products = $this->module->getProducts($id_lang, $offset, $limit, 'id_product', 'ASC', false, true, null);
				$currency = $this->module->getCurrency();
				$shipping_carriers = $this->module->getShippingCarriers($id_lang);

				if (count($products) > 0)
				{
					$response = array(
						'offset' => $offset,
						'products' => array(),
					);

					foreach ($products as $product)
						$response['products'][] = $this->module->getProductInfo($product, $shipping_carriers, $id_lang, $currency);
				}
			}
		}

		$message .= 'Response: '.print_r($response, true);
		Easymarketing::logToFile($message, $log_type);
		if ($response != null)
			die(Tools::jsonEncode($response));
		else
			die();
	}
}