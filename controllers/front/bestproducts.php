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

class EasymarketingBestproductsModuleFrontController extends ModuleFrontController
{
	public $display_header = false;
	public $display_footer = false;


	public function initContent()
	{
		parent::initContent();

		$log_type = 'best_products';
		$response = null;
		$message = '===== '.date('Y.m.d h:i:s').' ====='."\r\n";
		$message .= 'Request: '.print_r($_GET, true);

		if (Tools::getValue('shop_token') == Configuration::get('EASYMARKETING_SHOP_TOKEN'))
		{
			if (Tools::getIsset('limit') && Validate::isInt(Tools::getValue('limit')) &&
				Tools::getIsset('most_sold_since') && Validate::isInt(Tools::getValue('most_sold_since')))
			{
				$limit = (int)Tools::getValue('limit');
				$most_sold_since = (int)Tools::getValue('most_sold_since');

				$response = array(
					'limit' => $limit,
					'most_sold_since' => $most_sold_since,
					'products' => $this->module->getBestProducts($limit, $most_sold_since)
				);
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