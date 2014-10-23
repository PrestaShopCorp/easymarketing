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

class EasymarketingCategoriesModuleFrontController extends ModuleFrontController {
	public $display_header = false;
	public $display_footer = false;

	public function initContent()
	{
		parent::initContent();
		$log_type = 'categories';
		$response = null;
		$message = '===== '.date('Y.m.d h:i:s').' ====='."\r\n";
		$message .= 'Request: '.print_r($_GET, true);


		if (!Tools::getIsset('lang') ||
			(Tools::getIsset('lang') && ($id_lang = Language::getIdByIso(Tools::getValue('lang'))) == false ))
		{
			$id_lang = Configuration::get('PS_LANG_DEFAULT');
		}


		if (Tools::getValue('shop_token') == Configuration::get('EASYMARKETING_SHOP_TOKEN'))
		{
			if (Tools::getIsset('id') && Validate::isInt(Tools::getValue('id')))
			{
				$id = Tools::getValue('id');
				$valid_category = true;
				$selected_cats = unserialize(Configuration::get('EASYMARKETING_EXPORT_CATEGORIES'));
				if (is_array($selected_cats))
				{
					foreach ($selected_cats as $selected_cat)
						$selected_cat_ids[] = $selected_cat['id_category'];

					if (!in_array($id, $selected_cat_ids))
						$valid_category = false;
				}


				if ($valid_category == true)
				{
					$cat = Category::getCategoryInformations(array($id), $id_lang);

					if (count($cat) == 1)
					{
						$response = array(
							'id'    => $cat[$id]['id_category'],
							'name'  => $cat[$id]['name'],
							'url'   => $this->context->link->getCategoryLink($id, $id_lang),
							'children' => array()
						);

						$children = Category::getChildren($id, $id_lang);
						if (count($children) > 0)
						{
							foreach ($children as $child)
							{
								if (is_array($selected_cat_ids) && (count($selected_cat_ids) > 0) &&
									in_array($child['id_category'], $selected_cat_ids))
										$response['children'][] = $child['id_category'];
								else if (count($selected_cat_ids) == 0)
									$response['children'][] = $child['id_category'];
							}
						}
					}
				}
			}
		}

		$message.= 'Response: '.print_r($response, true);
		Easymarketing::logToFile($message, $log_type);
		if ($response != null)
			die(Tools::jsonEncode($response));
		else
			die();
	}
}