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

if (!defined('_PS_VERSION_'))
	exit;

class Easymarketing extends Module {
	private static $api_url = 'https://api.easymarketing.de';
	private static $easymarketing_api_version = '1';
	private static $fields_for_attributes_mapping = array(
		'gender', 'age_group', 'color', 'size', 'size_type', 'size_system', 'free_1', 'free_2', 'free_3',
	);
	private static $attr_mappings = array();
	private static $export_categories = array();
	private static $google_category_names = array();


	public function __construct()
	{
		$this->name = 'easymarketing';
		$this->tab = 'advertising_marketing';
		$this->version = '0.1.1';
		$this->author = 'easymarketing';
		$this->need_instance = 0;
		$this->ps_versions_compliancy = array(
			'min' => '1.6.0.0',
			'max' => '1.6.9.9'
		);
		$this->bootstrap = true;

		parent::__construct();

		$this->displayName      = $this->l('Easymarketing – Sales Turbo with Immediate Effect');
		$this->description      = $this->l('Easymarketing automatizes your advertising campaigns on Google, Google Shopping and Facebook and makes intelligent retargeting possible for you.');
		$this->confirmUninstall = $this->l('Are you sure you want to uninstall?');
	}

	public function install()
	{
		/*if (Shop::isFeatureActive())
			Shop::setContext(Shop::CONTEXT_ALL);*/
		if (!function_exists('curl_init'))
		{
			$this->_errors[] = $this->l('You need to enable cURL extension in PHP.');
			return false;
		}

		$return = true;
		$return &= parent::install();

		// automatic generation shop token
		if (!Configuration::hasKey('EASYMARKETING_SHOP_TOKEN'))
			$return &= $this->generateShopToken();

		$selected_cats = unserialize(Configuration::get('EASYMARKETING_EXPORT_CATEGORIES'));
		if (!is_array($selected_cats))
			Configuration::updateValue('EASYMARKETING_EXPORT_CATEGORIES', serialize(array()));

		Configuration::updateValue('EASYMARKETING_LOG_ENABLED', 0);

		$return &= $this->registerHook('footer');
		$return &= $this->registerHook('orderConfirmation');
		return (bool)$return;
	}


	public function uninstall()
	{
		$return = true;
		// uninstall parent
		$return &= parent::uninstall();
		return (bool)$return;
	}

	public function reset()
	{
		$return = true;
		return (bool)$return;
	}

	public function getExportRootCategory()
	{
		$selected_cats = unserialize(Configuration::get('EASYMARKETING_EXPORT_CATEGORIES'));
		if (!is_array($selected_cats) || (count($selected_cats) == 0))
			return 1;
		else
		{
			$root = 0;
			foreach ($selected_cats as $cat)
			{
				if ($cat['root'])
				{
					$root = $cat['id_category'];
					break;
				}
			}
			if ($root == 0) $root = 1;
			return $root;
		}
	}

	public function getTestProductId()
	{
		$products = $this->getProducts($this->context->language->id, 0, 1, 'id_product', 'ASC', false, true, null);
		if (isset($products[0]))
			return $products[0]['unique_id'];
		return 1;
	}

	public function getExportCategoriesIds()
	{
		$selected_cats = unserialize(Configuration::get('EASYMARKETING_EXPORT_CATEGORIES'));
		if (!is_array($selected_cats) || (count($selected_cats) == 0))
			return array();

		$ids = array();
		foreach ($selected_cats as $cat)
			$ids[] = $cat['id_category'];

		return $ids;
	}

	public function getGoogleCategoryNames()
	{
		$selected_cats = unserialize(Configuration::get('EASYMARKETING_EXPORT_CATEGORIES'));
		if (!is_array($selected_cats) || (count($selected_cats) == 0))
			return array();

		$names = array();
		foreach ($selected_cats as $cat)
		{
			if (trim($cat['name']) != '')
				$names[$cat['id_category']] = $cat['name'];
		}
		return $names;
	}

	public function doGoogleSiteVerification()
	{
		$status = array();
		$completed = false;
		$data = $this->downloadGoogleSiteVerificationData();
		if ($data == false)
		{
			$status[] = array(
				'res'=>'warning',
				'message'=>$this->l('Data of google site verification is not available.')
			);
		}
		else
		{
			$status[] = array(
				'res'=>'success',
				'message'=>$this->l('Data of google site verification has been downloaded.')
			);

			$res = $this->putGoogleSiteVerificationDataInFile($data);
			if ($res == false)
				$status[] = array(
					'res'=>'warning',
					'message'=>$this->l('Verification file cannot be written in root directory of shop.
					Please check this directory for write permission.')
				);
			else
			{
				$status[] = array(
					'res'=>'success',
					'message'=>$this->l('Verification file has been written successfully.'));
				$res = $this->uploadPerformSiteVerification();
				if ($res == false)
					$status[] = array(
						'res'=>'warning',
						'message'=>$this->l('Site Verification cannot be performed.')
					);
				else
				{
					$status[] = array(
						'res'=>'success',
						'message'=>$this->l('Site Verification has been performed.')
					);
					$completed = true;
				}
			}
		}
		Configuration::updateValue('EASYMARKETING_SITE_VERIFICATION_COMPLETED', $completed);
		Configuration::updateValue('EASYMARKETING_SITE_VERIFICATION_STATUS', serialize($status));

		return $completed;
	}

	public static function logToFile($msg, $key = '')
	{
		if (Configuration::get('EASYMARKETING_LOG_ENABLED'))
		{
			$filename = dirname(__FILE__).'/logs/log_'.$key.'.txt';
			$fd = fopen($filename, 'a');
			fwrite($fd, $msg."\n");
			fclose($fd);
		}
	}

	public function uploadEndpoints()
	{
		$params = array(
			'website_url'                   => $this->getWebsiteUrl(),
			'access_token'                  => Configuration::get('EASYMARKETING_ACCESS_TOKEN'),
			'shop_token'                    => Configuration::get('EASYMARKETING_SHOP_TOKEN'),
			'categories_api_endpoint'       => Context::getContext()->link->getModuleLink($this->name, 'categories',
					array()),
			'products_api_endpoint'         => Context::getContext()->link->getModuleLink($this->name, 'products',
					array()),
			'product_by_id_api_endpoint'    => Context::getContext()->link->getModuleLink($this->name, 'product',
					array()),
			'best_products_api_endpoint'    => Context::getContext()->link->getModuleLink($this->name, 'bestproducts',
					array()),
			'new_products_api_endpoint'     => Context::getContext()->link->getModuleLink($this->name, 'newproducts',
					array()),
			'shopsystem_info_api_endpoint'  => Context::getContext()->link->getModuleLink($this->name, 'shopsysteminfo',
					array()),
			'api_setup_test_single_product_id' => $this->getTestProductId(),
			'shop_category_root_id'            => $this->getExportRootCategory(),
		);

		$res = self::_curlPost('/configure_endpoints', $params, array(), true);

		if ($this->parseResponse($res, false, false) == true)
			return true;

		return false;
	}

	public function getWebsiteUrl()
	{
		return Configuration::get('PS_SHOP_DOMAIN');
	}

	public function generateShopToken()
	{
		$shop_token = Tools::passwdGen(16, 'ALPHANUMERIC');
		if (Configuration::updateValue('EASYMARKETING_SHOP_TOKEN', $shop_token))
			return true;

		return false;
	}

	/*
	 *  Conversion Tracker Code
	 *  1.integrated on the vendor's checkout success page
	 *  2.replace the conversion value with the value of the shopping basket 2 times: In the javascript as well
	 * as in the img-tag
	 *  3.two trackers provided, code is for Google and fb_code is for Facebook, as far as a Facebook
	 * tracker is available.
	 *  4.Remark: The tracker may also not be available at the time when the module is configured if it
	 * has not been set-up yet in the easymarketing backend. The module should be able to handle this.
	 */

	public function downloadConversionTracker()
	{
		$get = array(
			'website_url' => $this->getWebsiteUrl(),
		);

		$res = self::_curlGET('/conversion_tracker', $get);

		if (($tracker = $this->parseResponse($res, true, false)) != false)
		{
			if (Configuration::updateValue('EASYMARKETING_CONVERSION_TRACKER', urlencode($tracker)))
				return true;
		}
		return false;
	}

	/*
	 *  Lead tracker
	 * 1. integrated on two pages, the contact pages as well as the checkout page (after shopping-basket)
	 * 2. There are two trackers provided, code is for Google and fb_code is for Facebook, as far as a Facebook tracker
	 * is available.
	 * 3.Remark: The tracker may also not be available at the time when the module is configured if it has not been
	 * set-up yet in the easymarketing backend. The module should be able to handle this.
	 */

	public function downloadLeadTracker()
	{
		$get = array(
			'website_url' => $this->getWebsiteUrl(),
		);

		$res = self::_curlGET('/lead_tracker', $get);

		if (($tracker = $this->parseResponse($res, true, false)) != false)
		{
			if (Configuration::updateValue('EASYMARKETING_LEAD_TRACKER', urlencode($tracker)))
				return true;
		}
		return false;
	}

	/*
	 * Remarketing Code
	 * 1.integrated on each page of the site.
	 * 2.Remark: The tracker may also not be available at the time when the module is configured if it has not been
	 * set-up yet in the easymarketing backend. The module should be able to handle this.
	 * 3. we have to add also
	 * <script type="text/javascript">
		var google_tag_params = {
		ecomm_prodid: 'REPLACE_WITH_VALUE',
		ecomm_pagetype: 'REPLACE_WITH_VALUE',
		ecomm_totalvalue: 'REPLACE_WITH_VALUE'
		};
		</script>

	 * READ: https://support.google.com/adwords/answer/3103357
	 * ecomm_prodid 	1234
	 *
	 * Product Id - Must match the Product or Item Group ID from the Google Merchant Center
	 * feed. This allows the dynamic ad to show people the exact product they viewed.
	 *
	 * ecomm_pagetype 	home, searchresults, category, product, cart, purchase,other
	 *
	 * Page Type - Indicates which page people visited. You need to use one of the values listed in the middle column.
	 * Don't change these values and keep them in English, even if your site is in a different language. A value must
	 * be present on each page. These values might be used for the lists that AdWords created for you and for automated
	 * bid optimization. "Product" refers to viewing a product page, and "other" should be used for pages not covered
	 * by the other values. Important: every page needs to have a page type value.
	 *
	 * ecomm_totalvalue 	49.99 	Total Value - Specify the value of the product. On a cart or purchase page, you
	 * need to specify the total value (summing up the value of all products). This value might be used in automated
	 * bidding optimization and may be used to categorize your lists into groups according to the value of products.
	 *
	 * 4. For better performance add customized values
	 * READ: https://support.google.com/adwords/answer/3103357?hl=en-AU#customize_tag
	 */

	public function downloadGoogleRemarketingCode()
	{
		$get = array(
			'website_url' => $this->getWebsiteUrl(),
		);

		$res = self::_curlGET('/google_remarketing_code', $get);

		if (($tracker = $this->parseResponse($res, true, false)) != false)
		{
			if (Configuration::updateValue('EASYMARKETING_GOOGLE_REMARKETING_CODE', urlencode($tracker)))
				return true;
		}
		return false;
	}

	/*
	 * Facebook badge
	 *
	 * 1.a like button code that should be integrated on the vendor's checkout success page. Triggering it will like
	 * the vendor's facebook page. The vendor provides the details to his facebook fanpage in the easymarketing system.
	 */

	public function downloadFacebookBadge()
	{
		$get = array(
			'website_url' => $this->getWebsiteUrl(),
		);

		$res = self::_curlGET('/facebook_badge', $get);

		if (($tracker = $this->parseResponse($res, true, false)) != false)
		{
			if (Configuration::updateValue('EASYMARKETING_FACEBOOK_BADGE_CODE', urlencode($tracker)))
				return true;
		}
		return false;
	}

	public function downloadExtractionStatus()
	{
		$get = array(
			'website_url' => $this->getWebsiteUrl(),
		);

		$res = self::_curlGET('/extraction_status', $get);

		if (($status = $this->parseResponse($res, true, true)) != false)
			return $status;

		return false;
	}

	public function downloadGoogleSiteVerificationData()
	{
		$get = array(
			'website_url' => $this->getWebsiteUrl(),
		);

		$res = self::_curlGET('/site_verification_data', $get);

		if (($data = $this->parseResponse($res, true, true)) != false)
			return $data;

		return false;
	}

	public function downloadRetargetingId()
	{
		$get = array(
			'website_url' => $this->getWebsiteUrl(),
		);

		$res = self::_curlGET('/retargeting_id', $get);

		if (($data = $this->parseResponse($res, true, true)) != false)
			return $data;

		return false;
	}

	/**
	 * @param string $partner_id
	 * @param string $version (mini, medium, medium_two, large)
	 * @return bool
	 */
	public function downloadDemoChart($partner_id = '', $version = 'mini')
	{
		if ($version == 'medium')
			return '<iframe style="background-color: transparent; border: 0px none transparent;'.
			'padding: 0px; overflow: hidden;" seamless="seamless" scrolling="no" '.
			'frameborder="0" allowtransparency="true" width="300px" height="167px" '.
			'src="https://api.easymarketing.de/demo_chart?website_url='.$this->getWebsiteUrl().
			'&partner_id='.$partner_id.'&version='.$version.'"></iframe>';
		elseif ($version == 'medium_two')
			return '<iframe style="background-color: transparent; border: 0px none transparent;'.
			'padding: 0px; overflow: hidden;" seamless="seamless" scrolling="no" '.
			'frameborder="0" allowtransparency="true" width="325px" height="175px" '.
			'src="https://api.easymarketing.de/demo_chart?website_url='.$this->getWebsiteUrl().
			'&partner_id='.$partner_id.'&version='.$version.'"></iframe>';
		elseif ($version == 'large')
			return '<iframe style="background-color: transparent; border: 0px none transparent;'.
			'padding: 0px; overflow: hidden;" seamless="seamless" scrolling="no" '.
			'frameborder="0" allowtransparency="true" width="300px" height="250px" '.
			'src="https://api.easymarketing.de/demo_chart?website_url='.$this->getWebsiteUrl().
			'&partner_id='.$partner_id.'&version='.$version.'"></iframe>';
		else
			return '<iframe style="background-color: transparent; border: 0px none transparent;'.
			'padding: 0px; overflow: hidden;" seamless="seamless" scrolling="no" '.
			'frameborder="0" allowtransparency="true" width="357px" height="167px" '.
			'src="https://api.easymarketing.de/demo_chart?website_url='.$this->getWebsiteUrl().
			'&partner_id='.$partner_id.'&version='.$version.'"></iframe>';
	}

	/**
	 * @param string $partner_id
	 * @param string $height
	 * @param string $width
	 * @param string $small
	 * @return bool
	 */
	public function downloadAnalysis($partner_id = '', $height = '400', $width = '400', $small = '')
	{
		$get = array(
			'website_url' => $this->getWebsiteUrl(),
			'partner_id'   => $partner_id,
			'height'       => $height,
			'width'       => $width,
			'small'     => $small
		);

		$res = self::_curlGET('/analysis', $get);

		if (($data = $this->parseResponse($res, true, false)) != false)
			return $data;

		return false;
	}

	public function downloadPerformance($compact = '', $height = '1300', $width = '1040')
	{
		$get = array(
			'website_url' => $this->getWebsiteUrl(),
			'height'       => $height,
			'width'       => $width
		);
		if ($compact != '')
			$get['compact'] = 'true';

		$res = self::_curlGET('/users/performance', $get);

		if (($data = $this->parseResponse($res, true, false)) != false)
			return $data;

		return false;
	}

	public function putGoogleSiteVerificationDataInFile($data)
	{
		if (($data->html_file_name != '') && ($data->html_content != ''))
		{
			$path = _PS_ROOT_DIR_.'/'.$data->html_file_name;
			if (!$write_fd = fopen($path, 'w+'))
				return false;

			if (fwrite($write_fd, $data->html_content))
				return true;
		}
		return false;
	}

	/*
	 *  if it returns true, then
	 */

	public function uploadPerformSiteVerification()
	{
		$params = array(
			'website_url'                   => $this->getWebsiteUrl(),
			'verification_type'             => 'HTML',
		);

		$res = self::_curlPost('/perform_site_verification', $params);

		if ($this->parseResponse($res, false, false) == true)
			return true;

		return false;
	}

	public function uploadPushProductForSpecialPromotion($product_id)
	{
		$params = array(
			'website_url'           => $this->getWebsiteUrl(),
			'product_id'            => $product_id,
		);

		$res = self::_curlPost('/facebook_status', $params);

		if ($this->parseResponse($res, false, false) == true)
			return true;

		return false;
	}


	public function parseResponse($res, $return_res = false, $json_decode = false)
	{
		if ($res['status'] == '200')
		{
			if ($return_res == false)
				return true;
			else
			{
				if ($json_decode == true)
					return Tools::jsonDecode($res['result']);
				else
					return $res['result'];
			}
		} elseif ($res['status'] == '202')
			$this->_errors[] = $this->l('Product not found in EasyMarketing database');
		elseif ($res['status'] == '401')
			$this->_errors[] = $this->l('Easymarketing Access token is wrong');
		elseif ($res['status'] == '422')
		{
			$decode_res = Tools::jsonDecode($res['result']);
			$this->_errors[] = $decode_res->error;
		} elseif (in_array($res['status'], array('400', '100')))
		{
			$decode_res = Tools::jsonDecode($res['result']);
			if (isset($decode_res->errors))
			{
				foreach ($decode_res->errors as $error)
					$this->_errors[] = $error;
			}
		}
		return false;
	}


	private static function _curlPost($url, array $post = null, array $options = array(), $json = false)
	{
		$defaults = array(
			CURLOPT_HEADER => 0,
			CURLOPT_URL => self::$api_url.$url.'?access_token='.Configuration::get('EASYMARKETING_ACCESS_TOKEN'),
			CURLOPT_FRESH_CONNECT => 1,
			CURLOPT_FOLLOWLOCATION => 1,
			CURLOPT_SSL_VERIFYPEER => 0,
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_FORBID_REUSE => 1,
			CURLOPT_TIMEOUT => 10,
		);

		if ($json == true)
		{
			$encoded_data = Tools::jsonEncode($post);

			$defaults[CURLOPT_POST] = 1;
			$defaults[CURLOPT_CUSTOMREQUEST] = 'POST';
			$defaults[CURLOPT_POSTFIELDS] = $encoded_data;
			$defaults[CURLOPT_HTTPHEADER] = array(
				'Accept: application/vnd.easymarketing.com; version='.self::$easymarketing_api_version,
				'Content-Type: application/json',
				'Accept: application/json',
				'Content-Length: '.Tools::strlen($encoded_data)
			);
		}
		else
		{
			$defaults[CURLOPT_POST] = 1;
			$defaults[CURLOPT_HTTPHEADER] = array(
				'Accept: application/vnd.easymarketing.com; version='.self::$easymarketing_api_version,
				'Content-Type: application/json',
				'Accept: application/json'
			);
			$defaults[CURLOPT_POSTFIELDS] = http_build_query($post);
		}

		$http_status = '400';
		$result = '';
		if (function_exists('curl_init'))
		{
			$ch = curl_init();
			curl_setopt_array($ch, ($options + $defaults));
			if (!$result = curl_exec($ch))
				echo curl_error($ch);

			$http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);

			$message = '===== '.date('Y.m.d h:i:s').' ====='."\r\n";
			$message .= 'Request: '.print_r($defaults, true);
			$message .= 'Response '.print_r(array('status'=>$http_status, 'result'=>$result), true);
			self::logToFile($message, str_replace('/', '', $url));
		}
		return array('status'=>$http_status, 'result'=>$result);
	}



	private static function _curlGet($url, array $get = null, array $options = array())
	{
		$get['access_token'] = Configuration::get('EASYMARKETING_ACCESS_TOKEN');

		$defaults = array(
			CURLOPT_URL => self::$api_url.$url.(strpos($url, '?') === false ? '?' : '').http_build_query($get),
			CURLOPT_HEADER => 0,
			CURLOPT_USERAGENT => 'prestashop',
			CURLOPT_FOLLOWLOCATION => 0,
			CURLOPT_SSL_VERIFYPEER => 0,
			CURLOPT_HTTPHEADER => array(
				'Accept: application/vnd.easymarketing.com; version='.self::$easymarketing_api_version,
				'Content-Type: application/json', 'Accept: application/json'
			),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 10,
			CURLOPT_SSL_VERIFYHOST => 0,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_VERBOSE        => 0
		);

		$http_status = '400';
		$result = '';
		if (function_exists('curl_init'))
		{
			$ch = curl_init();
			curl_setopt_array($ch, ($options + $defaults));
			if (!$result = curl_exec($ch))
				echo curl_error($ch);

			$http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);

			$message = '===== '.date('Y.m.d h:i:s').' ====='."\r\n";
			$message .= 'Request: '.print_r($defaults, true);
			$message .= 'Response '.print_r(array('status'=>$http_status, 'result'=>$result), true);
			self::logToFile($message, str_replace('/', '', $url));
		}
		return array('status'=>$http_status, 'result'=>$result);
	}

	public function getContent()
	{
		$html  = '';
		$html .= $this->displayInfo();
		$html .= $this->postProcess();
		$html .= $this->displayForm();
		//$html .= $this->displayUserPerformance();

		return $html;
	}

	public function displayInfo()
	{
		$this->smarty->assign(array(
			'_path' =>		 $this->_path,
			'displayName' => $this->displayName,
			'author' =>      $this->author,
			'description' => $this->description,
			'demochart'   => $this->downloadDemoChart()
		));

		return $this->display(__FILE__, 'views/templates/module/info.tpl');
	}

	public function displayForm()
	{
		$this->context->controller->addJS($this->_path.'js/easymarketing.js');

		$html = '';
		$html .= $this->displayFormSettings();
		return $html;
	}

	protected function displayFormSettings()
	{
		$helper = new HelperForm();

		// Helper Options
		$helper->required = false;
		$helper->id = Tab::getCurrentTabId(); //always Tab::getCurrentTabId() at helper option

		// Helper
		$helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
		$helper->table = '';
		$helper->token = Tools::getAdminTokenLite('AdminModules');
		$helper->module = $this;
		$helper->identifier = null;
		$helper->toolbar_btn = null;
		$helper->ps_help_context = null;
		$helper->title = null;
		$helper->show_toolbar = true;
		$helper->toolbar_scroll = false;
		$helper->bootstrap = false;

		$helper->fields_value['EASYMARKETING_ACCESS_TOKEN'] =
			Configuration::get('EASYMARKETING_ACCESS_TOKEN');
		$helper->fields_value['EASYMARKETING_SHOP_TOKEN'] =
			Configuration::get('EASYMARKETING_SHOP_TOKEN');
		$helper->fields_value['EASYMARKETING_EXPORT_COMBINATIONS'] =
			Configuration::get('EASYMARKETING_EXPORT_COMBINATIONS');
		$helper->fields_value['EASYMARKETING_EXPORT_COMBINATIONS'] =
			Configuration::get('EASYMARKETING_EXPORT_COMBINATIONS');
		$helper->fields_value['EASYMARKETING_CONVERSION_TRACKER_ENABLED'] =
			Configuration::get('EASYMARKETING_CONVERSION_TRACKER_ENABLED');
		$helper->fields_value['EASYMARKETING_LEAD_TRACKER_ENABLED'] =
			Configuration::get('EASYMARKETING_LEAD_TRACKER_ENABLED');
		$helper->fields_value['EASYMARKETING_GOOGLE_REMARKETING_CODE_ENABLED'] =
			Configuration::get('EASYMARKETING_GOOGLE_REMARKETING_CODE_ENABLED');
		$helper->fields_value['EASYMARKETING_FACEBOOK_BADGE_CODE_ENABLED'] =
			Configuration::get('EASYMARKETING_FACEBOOK_BADGE_CODE_ENABLED');

		$helper->fields_value['EASYMARKETING_LOG_ENABLED'] =
			Configuration::get('EASYMARKETING_LOG_ENABLED');

		return $helper->generateForm(array($this->getFormFieldsSettings()));
	}

	protected function displayCategories()
	{
		$return = '
					<table cellspacing="0" cellpadding="0" class="table">
						<tr>
							<th>'.$this->l('Root').'</th>
							<th>
								<input type="checkbox" name="checkme" class="noborder"
								 onclick="processCheckBoxes(this.checked)" />
							</th>
							<th>'.$this->l('ID').'</th>
							<th>'.$this->l('Name').'</th>
							<th>'.$this->l('Google category name').'</th>
						</tr>';

		$categories = Category::getCategories((int)($this->context->cookie->id_lang), false, false);
		foreach ($categories as $key => $category)
		{
			$cat = new Category((int)$category['id_category']);

			$children = $cat->getAllChildren((int)($this->context->cookie->id_lang));
			$aallchildren = array();
			foreach ($children as $child)
				$aallchildren[] = $child->id;
			$categories[$key]['allchildren'] = $aallchildren;

			$children = Category::getChildren((int)$category['id_category'], $this->context->cookie->id_lang);
			$achildren = array();
			foreach ($children as $child)
				$achildren[] = $child['id_category'];
			$categories[$key]['children'] = $achildren;
		}
		// selected categories
		$indexedCategories = unserialize(Configuration::get('EASYMARKETING_EXPORT_CATEGORIES'));
		$content = '';
		$done = false;

		self::recurseCategoryForInclude($indexedCategories,
			$categories, 1, null, null, $content, $done);

		$return .= $content;

		$return .= '</table>';
		return $return;
	}

	protected function displaySiteVerification()
	{
		$site_verification_completed = Configuration::get('EASYMARKETING_SITE_VERIFICATION_COMPLETED');
		$site_verification_status = unserialize(Configuration::get('EASYMARKETING_SITE_VERIFICATION_STATUS'));

		$return = '';
		if ($site_verification_completed != 1)
			$return .= '<button class="btn btn-default" type="submit"
			name="submitDoSiteVerification"><i class="icon-download"></i> '.$this->l('Perform Google Site verification').'</button>';

		if (is_array($site_verification_status))
		{
			foreach ($site_verification_status as $status)
				$return .= '<div class="alert-'.$status['res'].'">'.$status['message'].'</div>';
		}
		return $return;
	}

	protected function displayTrackerCodes()
	{
		$return = '';
		$return .= '<button class="btn btn-default" type="submit"
			name="submitGetTrackerCodes"><i class="icon-cloud-download"></i> '.$this->l('Download trackers codes').'</button>';
		return $return;
	}

	protected function displayAttributesMapping()
	{
		$attrMapping = unserialize(Configuration::get('EASYMARKETING_EXPORT_ATTRIBUTES_MAPPING'));
		//get group of attributes
		$attr_result = array();
		$attrGroups = AttributeGroup::getAttributesGroups($this->context->language->id);
		foreach ($attrGroups as $ag)
			$attr_result[$ag['id_attribute_group']] = array(
				'name' => $ag['name']
			);

		$return = '<table cellspacing="0" cellpadding="0" class="table">
						<tr>
							<th>'.$this->l('Field name').'</th>
							<th>'.$this->l('Attribute group').'</th>
						</tr>';
		foreach (self::$fields_for_attributes_mapping as $field)
		{
			$return .= '<tr><td>'.$field.'</td><td><select name="attributesmapping['.$field.'][id_attribute_group]">';
			$return .= '<option value="0">--'.$this->l('Please select attribute group').'--</option>';
			foreach ($attr_result as $id_attribute_group => $attr)
				$return .= '<option value="'.$id_attribute_group.'"'.
					(((isset($attrMapping[$field]['id_attribute_group']) &&
						$attrMapping[$field]['id_attribute_group'] == $id_attribute_group))?' selected="selected"':'').
					'>'.$attr['name'].'</option>';
			$return .= '</select></td></tr>';
		}

		return $return.'</table>';
	}

	public static function recurseCategoryForInclude($indexedCategories, $categories, $current,
														$id_category_default = null,
														$has_suite = array(), &$content, &$done)
	{
		static $irow;

		$currentCategoryData = array();
		foreach ($categories as $category)
		{
			if ($category['id_category'] == $current)
			{
				$currentCategoryData = $category;
				break;
			}
		}
		$parentCategoryData = array();
		foreach ($categories as $category)
		{
			if ($category['id_category'] == $currentCategoryData['id_parent'])
			{
				$parentCategoryData = $category;
				break;
			}
		}

		if (!isset($done[$currentCategoryData['id_parent']]))
			$done[$currentCategoryData['id_parent']] = 0;
		$done[$currentCategoryData['id_parent']] += 1;

		$todo = isset($parentCategoryData['children'])?count($parentCategoryData['children']):0;
		$doneC = $done[$currentCategoryData['id_parent']];

		$level = $currentCategoryData['level_depth'] + 1;

		$selected = false;
		$name = false;

		$rootCategory = false;
		if (is_array($indexedCategories))
		{
			foreach ($indexedCategories as $categoryData)
			{
				if (array_key_exists('id_category', $categoryData) && array_key_exists('name', $categoryData))
				{
					if ($current == (int)$categoryData['id_category'])
					{
						$selected = true;
						$name = $categoryData['name'];
						$rootCategory = $categoryData['root'];
					}
				}
			}
		}

		$content .= '
		<tr class="'.($irow++ % 2 ? 'alt_row' : '').'">
			<td>
				<input type="radio" name="categoryRoot" class="categoryBox" id="categoryRoot_'.$current.'" value="'.$current.'"'.(($rootCategory) ? ' checked="checked"' : '').' />
			</td>
			<td>
				<input type="checkbox" name="categoryBox['.$current.'][id_category]" class="categoryBox'.($id_category_default == $current ? ' id_category_default' : '').'" id="categoryBox_'.$current.'" value="'.$current.'"'.(($selected) ? ' checked="checked"' : '').' />
			</td>
			<td>
				'.$current.'
			</td>
			<td>';

		for ($i = 2; $i < $level; $i++)
			$content .= '<img  src="../modules/easymarketing/img/lvl_'.$has_suite[$i - 2].'.gif" alt="" />';

		$content .= '<img src="../modules/easymarketing/img/'.($level == 1 ? 'lv1.gif' : 'lv2_'.($todo == $doneC ? 'f' : 'b').'.gif').'"
			alt="" /> &nbsp;
			<label for="categoryBox_'.$current.'" class="t">'.Tools::stripslashes($currentCategoryData['name']).'</label>
			</td>
			<td>
				<input type="text" name="categoryBox['.$current.'][name]" value="'.$name.'" />

				<input type="hidden" name="categoryLevel['.$current.']" value="'.$level.'" />
				<input type="hidden" name="categoryAllChildren['.$current.']" value="'.implode(';', $currentCategoryData['allchildren']).'" />
				<input type="hidden" name="categoryChildren['.$current.']" value="'.implode(';', $currentCategoryData['children']).'" />
			</td>
		</tr>';

		if ($level > 1)
			$has_suite[] = ($todo == $doneC ? 0 : 1);

		if (is_array($currentCategoryData['children']) &&
			count($currentCategoryData['children']) > 0)
		{

			foreach ($currentCategoryData['children'] as $child)
				self::recurseCategoryForInclude($indexedCategories, $categories,
					$child, $id_category_default, $has_suite, $content, $done);
		}
	}

	protected function getFormFieldsSettings()
	{
		$conversion_tracker = Tools::jsonDecode(urldecode(Configuration::get('EASYMARKETING_CONVERSION_TRACKER')));
		$lead_tracker = Tools::jsonDecode(urldecode(Configuration::get('EASYMARKETING_LEAD_TRACKER')));
		$remarketing_code = Tools::jsonDecode(urldecode(Configuration::get('EASYMARKETING_GOOGLE_REMARKETING_CODE')));
		$badge_code = urldecode(Configuration::get('EASYMARKETING_FACEBOOK_BADGE_CODE'));

		return array(
			'form' => array(
				'id_form' => 'export_categories',
				'legend' => array(
					'title' => $this->l('Global Settings'),
					'icon' => 'icon-export',
				),
				'input' =>array(
					array(
						'name'  => 'EASYMARKETING_ACCESS_TOKEN',
						'type'  => 'text',
						'label' => $this->l('Access token'),
						'desc'  => $this->l('The user needs to copy+paste this from his EASYMARKETING account.
						This is used to access EASYMARKETING webservices like returning daily user statistics, a
						conversion tracker to measure sales etc.'),
					),
					array(
						'name'  => 'EASYMARKETING_SHOP_TOKEN',
						'type'  => 'text',
						'label' => $this->l('Shop token'),
						'desc'  => $this->l('It will be used for authentication for the requests to the
						 webservice you will implement specified below.'),
					),
					array(
						'type' =>'html',
						'name' => 'site_verification',
						'label' => $this->l('Google Site Verification'),
						'html_content' => $this->displaySiteVerification(),
						'desc' => $this->l('Privacy is important to Google, we need to know you own a site
						before we\'ll show you certain information about it or enable you to use our tools.')
					),
					array(
						'type' =>'html',
						'name' => 'trackers code',
						'label' => $this->l('Trackers codes'),
						'html_content' => $this->displayTrackerCodes(),
						'desc'=>$this->l('You have to get tracker code (conversion tracker, lead tracker, facebook badge and
						 remarketing) for placing it on your shop.')
					),
					array(
						'name' => 'EASYMARKETING_EXPORT_COMBINATIONS',
						'type'  => 'switch',
						'label' => $this->l('Export Combinations'),
						'desc'  => $this->l(''),
						'is_bool' => true,
						'values' => array(
							array(
								'value' => 1,
							),
							array(
								'value' => 0,
							)
						),
					),
					array(
						'type' =>'html',
						'name' => 'categories',
						'label' => $this->l('Export Categories'),
						'html_content' => $this->displayCategories(),
					),
					array(
						'type' =>'html',
						'name' => 'attributes',
						'label' => $this->l('Attributes mapping'),
						'html_content' => $this->displayAttributesMapping(),
					),
					array(
						'name' => 'EASYMARKETING_CONVERSION_TRACKER_ENABLED',
						'type'  => 'switch',
						'label' => $this->l('Enable Google conversion tracker'),
						'desc'  => $this->l('Conversion Tracker Code will be integrated on the vendor\'s checkout success page.').
							' '.
							(isset($conversion_tracker->user_id)?'user_id:'.$conversion_tracker->user_id:'').
							(isset($conversion_tracker->code)?' , google code':'').
							(isset($conversion_tracker->fb_code)?' , fb code':''),

						'disabled' => !(isset($conversion_tracker->code) || isset($conversion_tracker->fb_code)),
						'is_bool' => true,
						'values' => array(
							array(
								'value' => 1,
							),
							array(
								'value' => 0,
							)
						),
					),
					array(
						'name' => 'EASYMARKETING_LEAD_TRACKER_ENABLED',
						'type'  => 'switch',
						'label' => $this->l('Enable Google lead tracker'),
						'desc'  => $this->l('Lead tracker will be integrated on two pages, the contact pages as well as the checkout page.').
							(($lead_tracker == null)?' LEAD TRACKER CODE DOES NOT EXIST.':''),
						'is_bool' => true,
						'disabled' => ($lead_tracker == null)?true:false,
						'values' => array(
							array(
								'value' => 1,
							),
							array(
								'value' => 0,
							)
						),
					),
					array(
						'name' => 'EASYMARKETING_GOOGLE_REMARKETING_CODE_ENABLED',
						'type'  => 'switch',
						'label' => $this->l('Enable Google Remarketing'),
						'desc'  => $this->l('Remarketing Code will be integrated on each page of the site').
							(($remarketing_code == null)?' REMARKETING CODE DOES NOT EXIST.':''),
						'is_bool' => true,
						'disabled' => ($remarketing_code == null)?true:false,
						'values' => array(
							array(
								'value' => 1,
							),
							array(
								'value' => 0,
							)
						),
					),
					array(
						'name' => 'EASYMARKETING_FACEBOOK_BADGE_CODE_ENABLED',
						'type'  => 'switch',
						'label' => $this->l('Enable Facebook Badge'),
						'desc'  => $this->l('Like button on the vendor\'s checkout success page').
							((trim($badge_code) == '')?' FACEBOOK BADGE CODE DOES NOT EXIST.':''),
						'is_bool' => true,
						'disabled' => (trim($badge_code) == '')?true:false,
						'values' => array(
							array(
								'value' => 1,
							),
							array(
								'value' => 0,
							)
						),
					),
					array(
						'type' => 'html',
						'name' => 'cron',
						'html_content' => $this->l('Cron is a job scheduler for Unix-based systems and it\'s a very
						handy tool, as you can schedule some routine tasks to run automatically, no matter if you or
						anyone else is present on your website: as long as the server hosting your site is running,
						cron will do it\'s job. To activate cron for this module, add the line below to your crontab file.').
							'<p>'.$this->l('This cron job will get updates codes of trackers every night at 1:00am.').
							' '.$this->l('It has same action as manually pressing button').
							' "'.$this->l('Download trackers codes').'".'.
							'<p><code>1 * * * * php -f '.dirname(__FILE__).DIRECTORY_SEPARATOR.'cron.php</code>'
					),
					array(
						'name' => 'EASYMARKETING_LOG_ENABLED',
						'type'  => 'switch',
						'label' => $this->l('Enable Log'),
						'desc'  => $this->l('Logs of actions in').' '.dirname(__FILE__).DIRECTORY_SEPARATOR.'logs '.$this->l('directory.'),
						'is_bool' => true,
						'disabled' => false,
						'values' => array(
							array(
								'value' => 1,
							),
							array(
								'value' => 0,
							)
						),
					),
				),
				'submit' => array(
					'title' => $this->l('Save options'),
					'name' => 'submitSaveOptions',
				),
			),
		);

	}

	public function postProcess()
	{
		$this->_errors = array();
		/*
		 *  ROUTE DOWNLOAD, SAVE, AND SHOW TRACKER

		$this->downloadConversionTracker();
		print_r(Tools::jsonDecode(urldecode(Configuration::get('EASYMARKETING_CONVERSION_TRACKER'))));
		*/

		/*
		 *   ROUTE GOOGLE REMARKETING
		$this->downloadGoogleRemarketingCode();
		print_r(Tools::jsonDecode(urldecode(Configuration::get('EASYMARKETING_GOOGLE_REMARKETING_CODE'))));
		*/
		//print_r($this->_errors);
		if (Tools::isSubmit('submitDoSiteVerification'))
		{
			if ($this->doGoogleSiteVerification())
				return $this->displayConfirmation($this->l('Site verification is completed'));
			else
				$this->_errors[] = $this->l('Site verification has not been completed');
		}

		if (Tools::isSubmit('submitGetTrackerCodes'))
		{
			$return = true;
			$return &= $this->downloadConversionTracker();
			$return &= $this->downloadLeadTracker();
			$return &= $this->downloadGoogleRemarketingCode();
			$return &= $this->downloadFacebookBadge();
			if ($return && (count($this->_errors) == 0))
				return $this->displayConfirmation($this->l('"Download trackers code" operation is completed'));
			else
				$this->_errors[] = $this->l('"Download trackers code" operation has not been completed');
		}


		// Generelle Einstellungen
		if (Tools::isSubmit('submitSaveOptions'))
		{
			// Global Settings
			if (!Configuration::updateValue('EASYMARKETING_ACCESS_TOKEN',
				Tools::getValue('EASYMARKETING_ACCESS_TOKEN')))
				$this->_errors[] = $this->l('Could not update').': EASYMARKETING_ACCESS_TOKEN';

			if (!Configuration::updateValue('EASYMARKETING_SHOP_TOKEN', Tools::getValue('EASYMARKETING_SHOP_TOKEN')))
				$this->_errors[] = $this->l('Could not update').': EASYMARKETING_SHOP_TOKEN';

			if (!Configuration::updateValue('EASYMARKETING_EXPORT_COMBINATIONS',
				(int)Tools::getValue('EASYMARKETING_EXPORT_COMBINATIONS')))
				$this->_errors[] = $this->l('Could not update').': EASYMARKETING_EXPORT_COMBINATIONS';

			$selected_cat = array();
			$categories = Tools::getValue('categoryBox');
			if (is_array($categories))
			{
				$i = 0;
				foreach ($categories as $category)
					if (array_key_exists('id_category', $category) && array_key_exists('name', $category))
					{
						$selected_cat[$i] = $category;
						if ($selected_cat[$i]['id_category'] == (int)Tools::getValue('categoryRoot'))
							$selected_cat[$i]['root'] = true;
						else
							$selected_cat[$i]['root'] = false;
						$i++;
					}
			}

			if (!Configuration::updateValue('EASYMARKETING_EXPORT_CATEGORIES', serialize($selected_cat)))
				$this->_errors[] = $this->l('Could not update').': EASYMARKETING_EXPORT_CATEGORIES';

			$attr_mapping = array();
			$attributesmapping = Tools::getValue('attributesmapping');
			if (is_array($attributesmapping))
			{
				foreach ($attributesmapping as $field_name => $field)
					if (array_key_exists('id_attribute_group', $field))
						$attr_mapping[$field_name] = $field;
			}

			if (!Configuration::updateValue('EASYMARKETING_EXPORT_ATTRIBUTES_MAPPING', serialize($attr_mapping)))
				$this->_errors[] = $this->l('Could not update').': EASYMARKETING_EXPORT_ATTRIBUTES_MAPPING';




			if (!Configuration::updateValue('EASYMARKETING_CONVERSION_TRACKER_ENABLED',
				(int)Tools::getValue('EASYMARKETING_CONVERSION_TRACKER_ENABLED')))
				$this->_errors[] = $this->l('Could not update').': EASYMARKETING_CONVERSION_TRACKER_ENABLED';

			if (!Configuration::updateValue('EASYMARKETING_LEAD_TRACKER_ENABLED',
				(int)Tools::getValue('EASYMARKETING_LEAD_TRACKER_ENABLED')))
				$this->_errors[] = $this->l('Could not update').': EASYMARKETING_LEAD_TRACKER_ENABLED';

			if (!Configuration::updateValue('EASYMARKETING_GOOGLE_REMARKETING_CODE_ENABLED',
				(int)Tools::getValue('EASYMARKETING_GOOGLE_REMARKETING_CODE_ENABLED')))
				$this->_errors[] = $this->l('Could not update').': EASYMARKETING_GOOGLE_REMARKETING_CODE_ENABLED';

			if (!Configuration::updateValue('EASYMARKETING_FACEBOOK_BADGE_CODE_ENABLED',
				(int)Tools::getValue('EASYMARKETING_FACEBOOK_BADGE_CODE_ENABLED')))
				$this->_errors[] = $this->l('Could not update').': EASYMARKETING_FACEBOOK_BADGE_CODE_ENABLED';


			if (!Configuration::updateValue('EASYMARKETING_LOG_ENABLED',
				(int)Tools::getValue('EASYMARKETING_LOG_ENABLED')))
				$this->_errors[] = $this->l('Could not update').': EASYMARKETING_LOG_ENABLED';

			$this->uploadEndpoints();

			if (count($this->_errors) <= 0)
				return $this->displayConfirmation($this->l('Settings updated'));

		}
	}

	public function getPackageShippingCost($cart, $id_carrier = null, $use_tax = true, Country $default_country = null, $product_list = null, $id_zone = null)
	{
		if ($cart->isVirtualCart())
			return 0;

		if (!$default_country)
			$default_country = Context::getContext()->country;

		$complete_product_list = $cart->getProducts();
		if (is_null($product_list))
			$products = $complete_product_list;
		else
			$products = $product_list;


		if (Configuration::get('PS_TAX_ADDRESS_TYPE') == 'id_address_invoice')
			$address_id = (int)$cart->id_address_invoice;
		elseif (count($product_list))
		{
			$prod = current($product_list);
			$address_id = (int)$prod['id_address_delivery'];
		}
		else
			$address_id = null;
		if (!Address::addressExists($address_id))
			$address_id = null;

		$cache_id = 'getPackageShippingCost_'.(int)$cart->id.'_'.(int)$address_id.'_'.(int)$id_carrier.'_'.(int)$use_tax.'_'.(int)$default_country->id;

		if ($products)
			foreach ($products as $product)
				$cache_id .= '_'.(int)$product['id_product'].'_'.(int)$product['id_product_attribute'];

		if (Cache::isStored($cache_id))
			return Cache::retrieve($cache_id);

		// Order total in default currency without fees
		$order_total = $this->getOrderTotal($cart, true, Cart::ONLY_PHYSICAL_PRODUCTS_WITHOUT_SHIPPING, $product_list);

		// Start with shipping cost at 0
		$shipping_cost = 0;
		// If no product added, return 0
		if (!count($products))
		{
			Cache::store($cache_id, $shipping_cost);
			return $shipping_cost;
		}

		if (!isset($id_zone))
		{
			// Get id zone
			if (!$cart->isMultiAddressDelivery()
				&& isset($cart->id_address_delivery) // Be carefull, id_address_delivery is not usefull one 1.5
				&& $cart->id_address_delivery
				&& Customer::customerHasAddress($cart->id_customer, $cart->id_address_delivery
				))
				$id_zone = Address::getZoneById((int)$cart->id_address_delivery);
			else
			{
				if (!Validate::isLoadedObject($default_country))
					$default_country = new Country(Configuration::get('PS_COUNTRY_DEFAULT'), Configuration::get('PS_LANG_DEFAULT'));

				$id_zone = (int)$default_country->id_zone;
			}
		}

		if ($id_carrier && !$cart->isCarrierInRange((int)$id_carrier, (int)$id_zone))
			$id_carrier = '';

		if (empty($id_carrier) && $cart->isCarrierInRange((int)Configuration::get('PS_CARRIER_DEFAULT'), (int)$id_zone))
			$id_carrier = (int)Configuration::get('PS_CARRIER_DEFAULT');

		$total_package_without_shipping_tax_inc = $cart->getOrderTotal(true, Cart::BOTH_WITHOUT_SHIPPING, $product_list);

		if (empty($id_carrier))
		{
			if ((int)$cart->id_customer)
			{
				$customer = new Customer((int)$cart->id_customer);
				$result = Carrier::getCarriers((int)Configuration::get('PS_LANG_DEFAULT'), true, false, (int)$id_zone, $customer->getGroups());
				unset($customer);
			}
			else
				$result = Carrier::getCarriers((int)Configuration::get('PS_LANG_DEFAULT'), true, false, (int)$id_zone);

			foreach ($result as $k => $row)
			{
				if ($row['id_carrier'] == Configuration::get('PS_CARRIER_DEFAULT'))
					continue;

				if (!isset(self::$_carriers[$row['id_carrier']]))
					self::$_carriers[$row['id_carrier']] = new Carrier((int)$row['id_carrier']);

				$carrier = self::$_carriers[$row['id_carrier']];

				// Get only carriers that are compliant with shipping method
				if (($carrier->getShippingMethod() == Carrier::SHIPPING_METHOD_WEIGHT && $carrier->getMaxDeliveryPriceByWeight((int)$id_zone) === false)
					|| ($carrier->getShippingMethod() == Carrier::SHIPPING_METHOD_PRICE && $carrier->getMaxDeliveryPriceByPrice((int)$id_zone) === false))
				{
					unset($result[$k]);
					continue;
				}

				// If out-of-range behavior carrier is set on "Desactivate carrier"
				if ($row['range_behavior'])
				{
					$check_delivery_price_by_weight = Carrier::checkDeliveryPriceByWeight($row['id_carrier'], $cart->getTotalWeight(), (int)$id_zone);

					$total_order = $total_package_without_shipping_tax_inc;
					$check_delivery_price_by_price = Carrier::checkDeliveryPriceByPrice($row['id_carrier'], $total_order, (int)$id_zone, (int)$cart->id_currency);

					// Get only carriers that have a range compatible with cart
					if (($carrier->getShippingMethod() == Carrier::SHIPPING_METHOD_WEIGHT && !$check_delivery_price_by_weight)
						|| ($carrier->getShippingMethod() == Carrier::SHIPPING_METHOD_PRICE && !$check_delivery_price_by_price))
					{
						unset($result[$k]);
						continue;
					}
				}

				if ($carrier->getShippingMethod() == Carrier::SHIPPING_METHOD_WEIGHT)
					$shipping = $carrier->getDeliveryPriceByWeight($cart->getTotalWeight($product_list), (int)$id_zone);
				else
					$shipping = $carrier->getDeliveryPriceByPrice($order_total, (int)$id_zone, (int)$cart->id_currency);

				//if (!isset($min_shipping_price))
				$min_shipping_price = $shipping;

				if ($shipping <= $min_shipping_price)
				{
					$id_carrier = (int)$row['id_carrier'];
					$min_shipping_price = $shipping;
				}
			}
		}

		if (empty($id_carrier))
			$id_carrier = Configuration::get('PS_CARRIER_DEFAULT');


		$carrier = new Carrier((int)$id_carrier, Configuration::get('PS_LANG_DEFAULT'));

		// No valid Carrier or $id_carrier <= 0 ?
		if (!Validate::isLoadedObject($carrier))
		{
			Cache::store($cache_id, 0);
			return 0;
		}

		if (!$carrier->active)
		{
			Cache::store($cache_id, $shipping_cost);
			return $shipping_cost;
		}

		// Free fees if free carrier
		if ($carrier->is_free == 1)
		{
			Cache::store($cache_id, 0);
			return 0;
		}

		// Select carrier tax
		if ($use_tax && !Tax::excludeTaxeOption())
		{
			$address = Address::initialize((int)$address_id);
			$carrier_tax = $carrier->getTaxesRate($address);
		}

		$configuration = Configuration::getMultiple(array(
			'PS_SHIPPING_FREE_PRICE',
			'PS_SHIPPING_HANDLING',
			'PS_SHIPPING_METHOD',
			'PS_SHIPPING_FREE_WEIGHT'
		));

		// Free fees
		$free_fees_price = 0;
		if (isset($configuration['PS_SHIPPING_FREE_PRICE']))
			$free_fees_price = Tools::convertPrice((float)$configuration['PS_SHIPPING_FREE_PRICE'], Currency::getCurrencyInstance((int)$cart->id_currency));
		$orderTotalwithDiscounts = $cart->getOrderTotal(true, Cart::BOTH_WITHOUT_SHIPPING, null, null, false);
		if ($orderTotalwithDiscounts >= (float)($free_fees_price) && (float)($free_fees_price) > 0)
		{
			Cache::store($cache_id, $shipping_cost);
			return $shipping_cost;
		}

		if (isset($configuration['PS_SHIPPING_FREE_WEIGHT'])
			&& $cart->getTotalWeight() >= (float)$configuration['PS_SHIPPING_FREE_WEIGHT']
			&& (float)$configuration['PS_SHIPPING_FREE_WEIGHT'] > 0)
		{
			Cache::store($cache_id, $shipping_cost);
			return $shipping_cost;
		}

		// Get shipping cost using correct method
		if ($carrier->range_behavior)
		{
			if (!isset($id_zone))
			{
				// Get id zone
				if (isset($cart->id_address_delivery)
					&& $cart->id_address_delivery
					&& Customer::customerHasAddress($cart->id_customer, $cart->id_address_delivery))
					$id_zone = Address::getZoneById((int)$cart->id_address_delivery);
				else
					$id_zone = (int)$default_country->id_zone;
			}

			if (($carrier->getShippingMethod() == Carrier::SHIPPING_METHOD_WEIGHT && !Carrier::checkDeliveryPriceByWeight($carrier->id, $cart->getTotalWeight(), (int)$id_zone))
				|| ($carrier->getShippingMethod() == Carrier::SHIPPING_METHOD_PRICE && !Carrier::checkDeliveryPriceByPrice($carrier->id, $total_package_without_shipping_tax_inc, $id_zone, (int)$cart->id_currency)
				))
				$shipping_cost += 0;
			else
			{
				if ($carrier->getShippingMethod() == Carrier::SHIPPING_METHOD_WEIGHT)
					$shipping_cost += $carrier->getDeliveryPriceByWeight($cart->getTotalWeight($product_list), $id_zone);
				else // by price
					$shipping_cost += $carrier->getDeliveryPriceByPrice($order_total, $id_zone, (int)$cart->id_currency);
			}
		}
		else
		{
			if ($carrier->getShippingMethod() == Carrier::SHIPPING_METHOD_WEIGHT)
				$shipping_cost += $carrier->getDeliveryPriceByWeight($cart->getTotalWeight($product_list), $id_zone);
			else
				$shipping_cost += $carrier->getDeliveryPriceByPrice($order_total, $id_zone, (int)$cart->id_currency);

		}

		// Adding handling charges
		if (isset($configuration['PS_SHIPPING_HANDLING']) && $carrier->shipping_handling)
			$shipping_cost += (float)$configuration['PS_SHIPPING_HANDLING'];

		// Additional Shipping Cost per product
		foreach ($products as $product)
			if (!$product['is_virtual'])
				$shipping_cost += $product['additional_shipping_cost'] * $product['cart_quantity'];


		$shipping_cost = Tools::convertPrice($shipping_cost, Currency::getCurrencyInstance((int)$cart->id_currency));


		//get external shipping cost from module
		if ($carrier->shipping_external)
		{
			$module_name = $carrier->external_module_name;
			$module = Module::getInstanceByName($module_name);

			if (Validate::isLoadedObject($module))
			{
				if (array_key_exists('id_carrier', $module))
					$module->id_carrier = $carrier->id;
				if ($carrier->need_range)
					if (method_exists($module, 'getPackageShippingCost'))
						$shipping_cost = $module->getPackageShippingCost($cart, $shipping_cost, $products);
					else
						$shipping_cost = $module->getOrderShippingCost($cart, $shipping_cost);
				else
					$shipping_cost = $module->getOrderShippingCostExternal($cart);

				// Check if carrier is available
				if ($shipping_cost === false)
				{
					Cache::store($cache_id, false);
					return false;
				}
			}
			else
			{
				Cache::store($cache_id, false);
				return false;
			}
		}

		// Apply tax
		if ($use_tax && isset($carrier_tax))
			$shipping_cost *= 1 + ($carrier_tax / 100);

		$shipping_cost = (float)Tools::ps_round((float)$shipping_cost, 2);
		Cache::store($cache_id, $shipping_cost);

		return $shipping_cost;
	}

	public function getOrderTotal($cart, $with_taxes = true, $type = Cart::BOTH, $products = null, $id_carrier = null, $use_cache = true)
	{
		$null = false;

		$type = (int)$type;
		$array_type = array(
			Cart::ONLY_PRODUCTS,
			Cart::ONLY_DISCOUNTS,
			Cart::BOTH,
			Cart::BOTH_WITHOUT_SHIPPING,
			Cart::ONLY_SHIPPING,
			Cart::ONLY_WRAPPING,
			Cart::ONLY_PRODUCTS_WITHOUT_SHIPPING,
			Cart::ONLY_PHYSICAL_PRODUCTS_WITHOUT_SHIPPING,
		);

		// Define virtual context to prevent case where the cart is not the in the global context
		$virtual_context = Context::getContext()->cloneContext();
		$virtual_context->cart = $cart;

		if (!in_array($type, $array_type))
			die(Tools::displayError());

		$with_shipping = in_array($type, array(Cart::BOTH, Cart::ONLY_SHIPPING));

		// if cart rules are not used
		if ($type == Cart::ONLY_DISCOUNTS && !CartRule::isFeatureActive())
			return 0;

		// no shipping cost if is a cart with only virtuals products
		$virtual = $cart->isVirtualCart();
		if ($virtual && $type == Cart::ONLY_SHIPPING)
			return 0;

		if ($virtual && $type == Cart::BOTH)
			$type = Cart::BOTH_WITHOUT_SHIPPING;

		if ($with_shipping || $type == Cart::ONLY_DISCOUNTS)
		{
			if (is_null($products) && is_null($id_carrier))
				$shipping_fees = $cart->getTotalShippingCost(null, (boolean)$with_taxes);
			else
				$shipping_fees = $cart->getPackageShippingCost($id_carrier, (bool)$with_taxes, null, $products);
		}
		else
			$shipping_fees = 0;

		if ($type == Cart::ONLY_SHIPPING)
			return $shipping_fees;

		if ($type == Cart::ONLY_PRODUCTS_WITHOUT_SHIPPING)
			$type = Cart::ONLY_PRODUCTS;

		$param_product = true;
		if (is_null($products))
		{
			$param_product = false;
			$products = $cart->getProducts();
		}

		if ($type == Cart::ONLY_PHYSICAL_PRODUCTS_WITHOUT_SHIPPING)
		{
			foreach ($products as $key => $product)
				if ($product['is_virtual'])
					unset($products[$key]);
			$type = Cart::ONLY_PRODUCTS;
		}

		$order_total = 0;
		if (Tax::excludeTaxeOption())
			$with_taxes = false;

		foreach ($products as $product) // products refer to the cart details
		{
			if ($virtual_context->shop->id != $product['id_shop'])
				$virtual_context->shop = new Shop((int)$product['id_shop']);

			if (Configuration::get('PS_TAX_ADDRESS_TYPE') == 'id_address_invoice')
				$address_id = (int)$cart->id_address_invoice;
			else
				$address_id = (int)$product['id_address_delivery']; // Get delivery address of the product from the cart
			if (!Address::addressExists($address_id))
				$address_id = null;

			if (PS_TAX_EXC == PS_TAX_EXC)
			{
				// Here taxes are computed only once the quantity has been applied to the product price
				$price = Product::getPriceStatic(
					(int)$product['id_product'],
					false,
					(int)$product['id_product_attribute'],
					2,
					null,
					false,
					true,
					$product['cart_quantity'],
					false,
					(int)$cart->id_customer ? (int)$cart->id_customer : null,
					(int)$cart->id,
					$address_id,
					$null,
					true,
					true,
					$virtual_context
				);

				$total_ecotax = $product['ecotax'] * (int)$product['cart_quantity'];
				$total_price = $price * (int)$product['cart_quantity'];

				if ($with_taxes)
				{
					$product_tax_rate = (float)Tax::getProductTaxRate((int)$product['id_product'], (int)$address_id, $virtual_context);
					$product_eco_tax_rate = Tax::getProductEcotaxRate((int)$address_id);

					$total_price = ($total_price - $total_ecotax) * (1 + $product_tax_rate / 100);
					$total_ecotax = $total_ecotax * (1 + $product_eco_tax_rate / 100);
					$total_price = Tools::ps_round($total_price + $total_ecotax, 2);
				}
			}
			else
			{
				if ($with_taxes)
					$price = Product::getPriceStatic(
						(int)$product['id_product'],
						true,
						(int)$product['id_product_attribute'],
						2,
						null,
						false,
						true,
						$product['cart_quantity'],
						false,
						((int)$cart->id_customer ? (int)$cart->id_customer : null),
						(int)$cart->id,
						((int)$address_id ? (int)$address_id : null),
						$null,
						true,
						true,
						$virtual_context
					);
				else
					$price = Product::getPriceStatic(
						(int)$product['id_product'],
						false,
						(int)$product['id_product_attribute'],
						2,
						null,
						false,
						true,
						$product['cart_quantity'],
						false,
						((int)$cart->id_customer ? (int)$cart->id_customer : null),
						(int)$cart->id,
						((int)$address_id ? (int)$address_id : null),
						$null,
						true,
						true,
						$virtual_context
					);

				$total_price = Tools::ps_round($price * (int)$product['cart_quantity'], 2);
			}
			$order_total += $total_price;
		}

		$order_total_products = $order_total;

		if ($type == Cart::ONLY_DISCOUNTS)
			$order_total = 0;

		// Wrapping Fees
		$wrapping_fees = 0;
		if ($cart->gift)
			$wrapping_fees = Tools::convertPrice(Tools::ps_round($cart->getGiftWrappingPrice($with_taxes), 2), Currency::getCurrencyInstance((int)$this->id_currency));
		if ($type == Cart::ONLY_WRAPPING)
			return $wrapping_fees;

		$order_total_discount = 0;
		if (!in_array($type, array(Cart::ONLY_SHIPPING, Cart::ONLY_PRODUCTS)) && CartRule::isFeatureActive())
		{
			// First, retrieve the cart rules associated to this "getOrderTotal"
			if ($with_shipping || $type == Cart::ONLY_DISCOUNTS)
				$cart_rules = $cart->getCartRules(CartRule::FILTER_ACTION_ALL);
			else
			{
				$cart_rules = $cart->getCartRules(CartRule::FILTER_ACTION_REDUCTION);
				// Cart Rules array are merged manually in order to avoid doubles
				foreach ($cart->getCartRules(CartRule::FILTER_ACTION_GIFT) as $tmp_cart_rule)
				{
					$flag = false;
					foreach ($cart_rules as $cart_rule)
						if ($tmp_cart_rule['id_cart_rule'] == $cart_rule['id_cart_rule'])
							$flag = true;
					if (!$flag)
						$cart_rules[] = $tmp_cart_rule;
				}
			}

			$id_address_delivery = 0;
			if (isset($products[0]))
				$id_address_delivery = (is_null($products) ? $cart->id_address_delivery : $products[0]['id_address_delivery']);
			$package = array('id_carrier' => $id_carrier, 'id_address' => $id_address_delivery, 'products' => $products);

			// Then, calculate the contextual value for each one
			foreach ($cart_rules as $cart_rule)
			{
				// If the cart rule offers free shipping, add the shipping cost
				if (($with_shipping || $type == Cart::ONLY_DISCOUNTS) && $cart_rule['obj']->free_shipping)
					$order_total_discount += Tools::ps_round($cart_rule['obj']->getContextualValue($with_taxes, $virtual_context, CartRule::FILTER_ACTION_SHIPPING, ($param_product ? $package : null), $use_cache), 2);

				// If the cart rule is a free gift, then add the free gift value only if the gift is in this package
				if ((int)$cart_rule['obj']->gift_product)
				{
					$in_order = false;
					if (is_null($products))
						$in_order = true;
					else
						foreach ($products as $product)
							if ($cart_rule['obj']->gift_product == $product['id_product'] && $cart_rule['obj']->gift_product_attribute == $product['id_product_attribute'])
								$in_order = true;

					if ($in_order)
						$order_total_discount += $cart_rule['obj']->getContextualValue($with_taxes, $virtual_context, CartRule::FILTER_ACTION_GIFT, $package, $use_cache);
				}

				// If the cart rule offers a reduction, the amount is prorated (with the products in the package)
				if ($cart_rule['obj']->reduction_percent > 0 || $cart_rule['obj']->reduction_amount > 0)
					$order_total_discount += Tools::ps_round($cart_rule['obj']->getContextualValue($with_taxes, $virtual_context, CartRule::FILTER_ACTION_REDUCTION, $package, $use_cache), 2);
			}
			$order_total_discount = min(Tools::ps_round($order_total_discount, 2), $wrapping_fees + $order_total_products + $shipping_fees);
			$order_total -= $order_total_discount;
		}

		if ($type == Cart::BOTH)
			$order_total += $shipping_fees + $wrapping_fees;

		if ($order_total < 0 && $type != Cart::ONLY_DISCOUNTS)
			return 0;

		if ($type == Cart::ONLY_DISCOUNTS)
			return $order_total_discount;

		return Tools::ps_round((float)$order_total, 2);
	}

	public function getProducts($id_lang, $start, $limit, $order_by, $order_way, $id_categories = false,
								$only_active = false, Context $context = null, $unique_id = false,
								$newer_than = false)
	{
		if (count(self::$export_categories) == 0) self::$export_categories =
			unserialize(Configuration::get('EASYMARKETING_EXPORT_CATEGORIES'));

		if (count(self::$google_category_names) == 0)
			self::$google_category_names = $this->getGoogleCategoryNames();

		if ($id_categories == false)
		{
			if (is_array(self::$export_categories) && (count(self::$export_categories) > 0))
			{
				$selected_cat_ids = array();
				foreach (self::$export_categories as $cat)
					$selected_cat_ids[] = $cat['id_category'];
				$id_categories = $selected_cat_ids;
			}
			else
				$id_categories = false;
		}

		if (!$context)
			$context = Context::getContext();



		if (count(self::$attr_mappings) == 0) self::$attr_mappings =
			unserialize(Configuration::get('EASYMARKETING_EXPORT_ATTRIBUTES_MAPPING'));

		$front = true;
		if (!in_array($context->controller->controller_type, array('front', 'modulefront')))
			$front = false;

		if (!Validate::isOrderBy($order_by) || !Validate::isOrderWay($order_way))
			die (Tools::displayError());
		if ($order_by == 'id_product' || $order_by == 'price' || $order_by == 'date_add' || $order_by == 'date_upd')
			$order_by_prefix = 'p';
		else if ($order_by == 'name')
			$order_by_prefix = 'pl';
		else if ($order_by == 'position')
			$order_by_prefix = 'c';

		if (strpos($order_by, '.') > 0)
		{
			$order_by = explode('.', $order_by);
			$order_by_prefix = $order_by[0];
			$order_by = $order_by[1];
		}

		// 1. **** get products ****
		$sql = new DbQuery();

		$sql->select('p.`id_product`, p.`condition`, product_shop.`id_shop`, pl.`name`, p.`is_virtual`,
						pl.`description_short`, pl.`available_now`, pl.`available_later`,
						product_shop.`id_category_default`, p.`id_supplier`,
						p.`id_manufacturer`, product_shop.`on_sale`, product_shop.`ecotax`,
						product_shop.`additional_shipping_cost`,
						product_shop.`available_for_order`, product_shop.`price`, product_shop.`active`,
						product_shop.`unity`, product_shop.`unit_price_ratio`,
						stock.`quantity` AS quantity_available, p.`width`, p.`height`, p.`depth`,
						stock.`out_of_stock`, p.`weight`,
						p.`date_add`, p.`date_upd`, IFNULL(stock.quantity, 0) as quantity, pl.`link_rewrite`,
						cl.`link_rewrite` AS category, product_shop.`wholesale_price`, product_shop.advanced_stock_management,
						ps.product_supplier_reference supplier_reference
						, IFNULL(sp.`reduction_type`, 0) AS reduction_type');

		// Build FROM
		$sql->from('product', 'p');

		// Build JOIN
		$sql->innerJoin('product_shop', 'product_shop', 'product_shop.`id_product` = p.`id_product` AND product_shop.id_shop='.(int)$context->shop->id);
		$sql->leftJoin('product_lang', 'pl', '
			p.`id_product` = pl.`id_product`
			AND pl.`id_lang` = '.(int)$id_lang.Shop::addSqlRestrictionOnLang('pl', 'product_shop.id_shop')
		);

		$sql->leftJoin('category_lang', 'cl', '
			product_shop.`id_category_default` = cl.`id_category`
			AND cl.`id_lang` = '.(int)$id_lang.Shop::addSqlRestrictionOnLang('cl', 'product_shop.id_shop')
		);

		$sql->leftJoin('specific_price', 'sp', 'sp.`id_product` = p.`id_product`'); // AND 'sp.`id_shop` = cp.`id_shop`

		// @todo test if everything is ok, then refactorise call of this method


		if ($id_categories)
			$sql->leftJoin('category_product', 'c', 'c.`id_product` = p.`id_product`');

		// Build WHERE clauses
		if ($unique_id)
			$sql->where('CONCAT(1, LPAD(p.`id_product`, 7, 0), LPAD(0, 7, 0))=\''.(int)$unique_id.'\'');

		$sql->where('p.`id_product` IS NOT NULL');

		if ($id_categories)
			if (is_array($id_categories) && (count($id_categories) > 0))
				$sql->where('p.`id_category_default` IN ('.implode(',', $id_categories).')');
			else
				$sql->where('p.`id_category_default` = '.(int)$id_categories);

		if ($front)
			$sql->where('product_shop.`visibility` IN ("both", "catalog")');

		if ($newer_than)
			$sql->where('UNIX_TIMESTAMP(p.`date_upd`) > '.(int)$newer_than);

		if ($only_active)
			$sql->where('product_shop.`active` = 1');

		// Build GROUP BY
		$sql->groupBy('unique_id');

		// Build ORDER BY
		$sql->orderBy((isset($order_by_prefix) ? pSQL($order_by_prefix).'.' : '').'`'.pSQL($order_by).'` '.pSQL($order_way));

		//if ($limit) {
		//$sql->limit((int)$limit, (int)$start);
		//}


		$sql->select('p.`condition`,
			 p.`reference` AS reference, p.`ean13`,
			 CONCAT(1, LPAD(p.`id_product`, 7, 0), LPAD(0, 7, 0)) AS unique_id,
			 p.`upc` AS upc, product_shop.`minimal_quantity` AS minimal_quantity'
		);
		$sql->join(Product::sqlStock('p'));
		$sql->leftJoin('product_supplier', 'ps', 'ps.`id_product` = p.`id_product` AND ps.`id_supplier` = p.`id_supplier`');

		//echo $sql->build();

		$rproducts = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);
		//print_r($rproducts);

		// 2. get combinations
		$rcombinations = array();
		if (Combination::isFeatureActive() && Configuration::get('EASYMARKETING_EXPORT_COMBINATIONS') == 1)
		{
			$sql = new DbQuery();

			$sql->select('p.`id_product`, p.`condition`, product_shop.`id_shop`, pl.`name`, p.`is_virtual`,
							pl.`description_short`, pl.`available_now`, pl.`available_later`,
							product_shop.`id_category_default`, p.`id_supplier`,
							p.`id_manufacturer`, product_shop.`on_sale`, product_shop.`ecotax`,
							product_shop.`additional_shipping_cost`,
							product_shop.`available_for_order`, product_shop.`price`, product_shop.`active`,
							product_shop.`unity`, product_shop.`unit_price_ratio`,
							stock.`quantity` AS quantity_available, p.`width`, p.`height`, p.`depth`,
							stock.`out_of_stock`, p.`weight`,
							p.`date_add`, p.`date_upd`, IFNULL(stock.quantity, 0) as quantity, pl.`link_rewrite`,
							cl.`link_rewrite` AS category, product_shop.`wholesale_price`, product_shop.advanced_stock_management,
							ps.product_supplier_reference supplier_reference
							, IFNULL(sp.`reduction_type`, 0) AS reduction_type');

			// Build FROM
			$sql->from('product', 'p');

			// Build JOIN
			$sql->innerJoin('product_shop', 'product_shop', 'product_shop.`id_product` = p.`id_product` AND product_shop.id_shop='.(int)$context->shop->id);
			$sql->leftJoin('product_lang', 'pl', '
				p.`id_product` = pl.`id_product`
				AND pl.`id_lang` = '.(int)$id_lang.Shop::addSqlRestrictionOnLang('pl', 'product_shop.id_shop')
			);

			$sql->leftJoin('category_lang', 'cl', '
				product_shop.`id_category_default` = cl.`id_category`
				AND cl.`id_lang` = '.(int)$id_lang.Shop::addSqlRestrictionOnLang('cl', 'product_shop.id_shop')
			);

			$sql->leftJoin('specific_price', 'sp', 'sp.`id_product` = p.`id_product`'); // AND 'sp.`id_shop` = cp.`id_shop`

			// @todo test if everything is ok, then refactorise call of this method


			if ($id_categories)
				$sql->leftJoin('category_product', 'c', 'c.`id_product` = p.`id_product`');

			// Build WHERE clauses
			if ($unique_id)
				$sql->where('CONCAT(1, LPAD(p.`id_product`, 7, 0), LPAD(IFNULL(pa.`id_product_attribute`, 0), 7, 0)) = \''.(int)$unique_id.'\'');

			$sql->where('p.`id_product` IS NOT NULL');

			if ($id_categories)
			{
				if (is_array($id_categories) && (count($id_categories) > 0))
					$sql->where('p.`id_category_default` IN ('.implode(',', $id_categories).')');
				else
					$sql->where('p.`id_category_default` = '.(int)$id_categories);
			}
			if ($front)
				$sql->where('product_shop.`visibility` IN ("both", "catalog")');

			if ($newer_than)
				$sql->where('UNIX_TIMESTAMP(p.`date_upd`) > '.(int)$newer_than);

			if ($only_active)
				$sql->where('product_shop.`active` = 1');


			// Build GROUP BY
			$sql->groupBy('unique_id');

			// Build ORDER BY
			$sql->orderBy((isset($order_by_prefix) ? pSQL($order_by_prefix).'.' : '').'`'.pSQL($order_by).'` '.pSQL($order_way));

			//if ($limit) {
			//$sql->limit((int)$limit, (int)$start);
			//}

			$sql->select('
				pa.`id_product_attribute`, product_attribute_shop.`price` AS price_attribute, product_attribute_shop.`ecotax` AS ecotax_attr,
				IF (IFNULL(pa.`reference`, \'\') = \'\', p.`reference`, pa.`reference`) AS reference,
				(p.`weight`+ pa.`weight`) weight_attribute,
				IF (IFNULL(pa.`ean13`, \'\') = \'\', p.`ean13`, pa.`ean13`) AS ean13,
				IF (IFNULL(pa.`upc`, \'\') = \'\', p.`upc`, pa.`upc`) AS upc,
				pai.`id_image` as pai_id_image, il.`legend` as pai_legend,
				IFNULL(product_attribute_shop.`minimal_quantity`, product_shop.`minimal_quantity`) as minimal_quantity,
				CONCAT(1, LPAD(p.`id_product`, 7, 0), LPAD(IFNULL(pa.`id_product_attribute`, 0), 7, 0)) AS unique_id
			');

			$sql->leftJoin('product_attribute', 'pa', 'pa.`id_product` = p.`id_product`');
			$sql->join(Product::sqlStock('p', 'pa'));

			$sql->leftJoin('product_attribute_shop', 'product_attribute_shop', '(product_attribute_shop.`id_product_attribute` = pa.`id_product_attribute`)');
			$sql->leftJoin('product_attribute_image', 'pai', 'pai.`id_product_attribute` = pa.`id_product_attribute`');
			$sql->leftJoin('image_lang', 'il', 'il.`id_image` = pai.`id_image` AND il.`id_lang` = '.(int)$id_lang);
			$sql->leftJoin('product_supplier', 'ps', 'ps.`id_product` = p.`id_product` AND ps.`id_product_attribute` = pa.`id_product_attribute` AND ps.`id_supplier` = p.`id_supplier`');


			$rcombinations = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);
		}

		// remove products which have combinations
		if (count($rcombinations))
		{
			foreach ($rcombinations as $c => $combination)
			{
				//remove parent product
				foreach ($rproducts as $k => $product)
				{
					if ($product['id_product'] == $combination['id_product'])
					{
						unset($rproducts[$k]);
						break;
					}
				}

				//name and attribute

				$sql = 'SELECT ag.`id_attribute_group`, agl.`name` AS group_name, al.`name`  AS attribute_name  ,
		                a.`id_attribute` FROM `'._DB_PREFIX_.'product_attribute` pa
			            LEFT JOIN `'._DB_PREFIX_.'product_attribute_combination` pac
			            ON  pac.`id_product_attribute` = pa.`id_product_attribute`
			            LEFT JOIN `'._DB_PREFIX_.'attribute` a
			            ON  a.`id_attribute` = pac.`id_attribute`
		                LEFT JOIN `'._DB_PREFIX_.'attribute_group` ag
						ON  ag.`id_attribute_group` = a.`id_attribute_group`
						LEFT JOIN `'._DB_PREFIX_.'attribute_lang` al
						ON  a.`id_attribute` = al.`id_attribute`
						LEFT JOIN `'._DB_PREFIX_.'attribute_group_lang` agl
						ON  ag.`id_attribute_group` = agl.`id_attribute_group`
						'.self::addShopSqlAssociation('product_attribute', 'pa', true, null, false,
						$this->context->shop->id).'
						WHERE al.`id_lang`='.(int)($id_lang).'
						AND  agl.`id_lang`='.(int)($id_lang).'
						AND  pa.`id_product_attribute` = '.(int)($combination['id_product_attribute']).'

						ORDER BY ag.`id_attribute_group` ASC';
				$rattr = Db::getInstance()->ExecuteS($sql);

				//print_r($rattr); exit;

				$newProductName = array();
				foreach ($rattr as $attribute)
				{
					$newProductName[] = trim($attribute['group_name']).': '.trim($attribute['attribute_name']);

					$rcombinations[$c]['attributes'][$attribute['id_attribute_group']] = $attribute;
				}

				$rcombinations[$c]['name']  = $rcombinations[$c]['name'].' ('.implode(', ', $newProductName).')';
			}
		}
		// merge arrays
		$rproducts = array_merge($rproducts, $rcombinations);
		unset($rcombinations);

		// sort by unique_id
		self::orderbyUniqueId($rproducts);

		// reindex
		$rproducts = array_values($rproducts);
		// truncate
		$size_rproducts = count($rproducts);

		if ((int)$limit <= 0) $limit = 1;
		$start_limit = $start + $limit;
		$result = array();
		for ($k = $start; $k < ((($size_rproducts) < $start_limit)?($size_rproducts):$start_limit); $k++)
		{
			if (!isset($rproducts[$k]['id_product_attribute'])) $rproducts[$k]['id_product_attribute'] = null;
			$result[] = $rproducts[$k];
		}
		unset($rproducts);

		foreach ($result as &$row)
			$row = Product::getTaxesInformations($row);

		//echo '<pre>'.print_r(self::$export_categories, true).'</pre>';
		//echo '<pre>'.print_r($result, true).'</pre>';
		return ($result);
	}

	public static function orderbyUniqueId(&$array)
	{
		uasort($array, 'cmpUniqueIdAsc');
	}

	public function getNewProductsIds($id_lang, $limit, $newer_than)
	{
		$products = self::getProducts($id_lang, 0, $limit, 'p.date_upd', 'DESC', false, true, null, false, $newer_than);
		$productIds = array();
		foreach ($products as $product)
			$productIds[] = $product['unique_id'];

		return $productIds;
	}

	public function getBestProducts($limit, $most_sold_since)
	{
		$product_res = array();

		$sales = $this->getBestSales($limit, $most_sold_since);

		foreach ($sales as $product)
			$product_res[] = array(
				'id' => $product['unique_id'],
				'sales' => (int)$product['sum']
			);

		return $product_res;
	}

	public function createUniqueId($id_product, $id_product_attribute = 0)
	{
		return '1'.str_pad($id_product, 7, '0', STR_PAD_LEFT ).str_pad($id_product_attribute, 7, '0', STR_PAD_LEFT );
	}

	public function getBestSales($limit = 10, $most_sold_since = false)
	{
		if ((int)$limit < 0) $limit = 0;
		if ((int)$most_sold_since < 0) $most_sold_since = 0;

		$rsales = array();
		if (Configuration::get('EASYMARKETING_EXPORT_COMBINATIONS') == 1)
			$rsales = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS(
				'SELECT CONCAT(1, LPAD(od.`product_id`, 7, 0), LPAD(od.`product_attribute_id`, 7, 0)) AS unique_id,
				od.product_id, od.product_attribute_id,	SUM(od.product_quantity) sum
				FROM '._DB_PREFIX_.'order_detail od, '._DB_PREFIX_.'orders o
				WHERE od.id_shop='.(int)$this->context->shop->id.' AND od.id_order=o.id_order
				AND UNIX_TIMESTAMP(o.`date_add`) > '.(int)$most_sold_since.
				' GROUP BY od.product_id, od.product_attribute_id ORDER BY sum DESC LIMIT '.(int)$limit);
		else
			$rsales = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS(
				'SELECT CONCAT(1, LPAD(od.`product_id`, 7, 0), LPAD(0, 7, 0)) AS unique_id,
				od.product_id,	SUM(od.product_quantity) sum
				FROM '._DB_PREFIX_.'order_detail od,'._DB_PREFIX_.'orders o
				WHERE od.id_shop='.(int)$this->context->shop->id.' AND od.id_order=o.id_order
				AND UNIX_TIMESTAMP(o.`date_add`) > '.(int)$most_sold_since.
				' GROUP BY od.product_id ORDER BY sum DESC LIMIT '.(int)$limit);

		return $rsales;
	}

	public function getProductInfo($product, $shipping_carriers, $id_lang, $currency)
	{
		//echo '<pre>'.print_r($product, true).'</pre>';

		$cart = $this->getCart($currency);
		$cover = Product::getCover($product['id_product']);

		$shipping = array();
		foreach ($shipping_carriers as $shipping_carrier)
		{
			$product_list = array(0 =>
				array(
					'id_product'            => $product['id_product'],
					'id_product_attribute'  => 0,
					'cart_quantity'         => 1,
					'ecotax'                => 0,
					'id_shop'               => $this->context->shop->id,
					'id_address_delivery'   => 0,
					'is_virtual'            => false,
					'weight'                => $product['weight'],
					'weight_attribute'      => 0,
					'additional_shipping_cost' => 0
				)
			);

			$shipping_price = $this->getPackageShippingCost($cart,
				$shipping_carrier['id_carrier'], true, new Country($shipping_carrier['id_country']),
				$product_list);

			$shipping[] = array(
				'country' => $shipping_carrier['country'],
				'service' => $shipping_carrier['service'],
				'price'   => (float)$shipping_price
			);
		}

		$prod = array(
			'id' => $product['unique_id'],
			'name' => $product['name'],
			'categories' => Product::getProductCategories($product['id_product']),
			'condition'  => $product['condition'],
			'availability' => (Product::getQuantity($product['id_product'], $product['id_product_attribute'], null) > 0)?'in stock':
					(Product::isAvailableWhenOutOfStock($product['out_of_stock'])?'out of stock':'preorder'),
			'price' => (float)Product::getPriceStatic($product['id_product'], true, null, 2, null, false,
					false),
			'currency' => $currency->iso_code,
			'url' => $this->context->link->getProductLink($product['id_product'], null, null, null,
					$id_lang),
			'image_url' => $this->context->link->getImageLink($product['link_rewrite'],
					$product['id_product'].'-'.$cover['id_image'], null),
			'shipping' => $shipping,
			'description' => $product['description_short'],
			'gtin' => $product['ean13'],

			//attributes are optional per product
			'google_category'=> isset(self::$google_category_names[$product['id_category_default']])?
					self::$google_category_names[$product['id_category_default']]:'',
			'adult'          => false,
			'brand'          => Manufacturer::getNameById($product['id_manufacturer']),
			'mpn'            => $product['reference'],
		);

		//Variant products - combinations
		if (isset($product['id_product_attribute']) && $product['id_product_attribute'] > 0)
		{
			/*
			 * Variant combinations are to be delivered as different products with different
			 * product-ids. In order to be able to group them together these products should
			 * contain the same parent_id which can freely be chosen but should not change. Preferably
			 * the product id of the parent's product should be used.
			 */
			$prod['parent_id'] = $this->createUniqueId($product['id_product'], 0);
			$prod['url'] = $this->context->link->getProductLink($product['id_product'], null, null, null,
				$id_lang, null, $product['id_product_attribute']);
			if (isset($product['pai_id_image']) && $product['pai_id_image'] > 0)
				$prod['image_url'] = $this->context->link->getImageLink($product['link_rewrite'],
					$product['id_product'].'-'.$product['pai_id_image'], null);

			//additional fields for attributes
			foreach (self::$fields_for_attributes_mapping as $field)
			{
				if (isset(self::$attr_mappings[$field]['id_attribute_group']) &&
					self::$attr_mappings[$field]['id_attribute_group'] > 0 &&
					isset($product['attributes'][self::$attr_mappings[$field]['id_attribute_group']]['attribute_name']))

					$prod[$field] = $product['attributes'][self::$attr_mappings[$field]['id_attribute_group']]['attribute_name'];
			}

			/*
			$prod['gender'] = '';   // String Herren, Damen or Unisex in the corresponding language etc.
			$prod['age_group'] = '';// String like Erwachsene, Kinder in the corresponding language etc.
			$prod['color'] = '';    // String like Rot, Grün in the corresponding language etc.
			$prod['size'] = '';    // The size String like L', XL
			$prod['size_type'] = '';    //String like Normalgröße', XL
			$prod['size_system'] = ''; //String like DE', EU, US
			*/
		}

		//Optional Attributes per module - Nice to have attributes
		if (false)
		{
			$prod['rrp'] = 0; // recommended retail price.
			$prod['margin'] = 0; //This is useful for us in order to better promote specific products.
			$prod['discount_absolute'] = 0; // shows that there is an absolute discount on a product.
			$prod['discount_percentage'] = 0;
		}
		return $prod;
	}

	public function getCurrency()
	{
		return Currency::getDefaultCurrency();
	}

	public function getCart($currency)
	{
		$cart = new Cart();
		$cart->id_currency = $currency->id;
		return $cart;
	}

	public function getShippingCarriers($id_lang)
	{
		$shipping_carriers = array();
		$deliveredCountries = Carrier::getDeliveredCountries($id_lang, true, true);
		foreach ($deliveredCountries as $deliveredCountry)
		{
			$carriers = Carrier::getCarriers($id_lang, true, false, $deliveredCountry['id_zone']);

			if (count($carriers) > 0)
			{
				foreach ($carriers as $carrier)
					$shipping_carriers[] = array(
						'country' =>  $deliveredCountry['iso_code'],
						'id_country' => $deliveredCountry['id_country'],
						'id_zone'   => $deliveredCountry['id_zone'],
						'service' =>  $carrier['name'],
						'id_carrier' => $carrier['id_carrier'],
					);
			}
		}
		return $shipping_carriers;
	}

	public static function addShopSqlAssociation($table, $alias, $inner_join = true, $on = null,
												$force_not_default = false, $id_shop = null)
	{
		$table_alias = $table.'_shop';
		if (strpos($table, '.') !== false)
			list($table_alias, $table) = explode('.', $table);

		$asso_table = Shop::getAssoTable($table);
		if ($asso_table === false || $asso_table['type'] != 'shop')
			return;
		$sql = (($inner_join) ? ' INNER' : ' LEFT').' JOIN '._DB_PREFIX_.$table.'_shop '.$table_alias.'
		ON ('.$table_alias.'.id_'.$table.' = '.$alias.'.id_'.$table;
		if ((int)$id_shop)
			$sql .= ' AND '.$table_alias.'.id_shop = '.(int)$id_shop;
		elseif (Shop::checkIdShopDefault($table) && !$force_not_default)
			$sql .= ' AND '.$table_alias.'.id_shop = '.$alias.'.id_shop_default';
		else
			$sql .= ' AND '.$table_alias.'.id_shop IN ('.implode(', ', Shop::getContextListShopID()).')';
		$sql .= (($on) ? ' AND '.$on : '').')';
		//echo '****'.$sql.'****';
		return $sql;
	}


	public function hookOrderConfirmation($params)
	{
		$return = '';
		if (Configuration::get('EASYMARKETING_CONVERSION_TRACKER_ENABLED'))
		{
			$conversion_tracker = Tools::jsonDecode(urldecode(Configuration::get('EASYMARKETING_CONVERSION_TRACKER')));

			if (isset($conversion_tracker->code))
				$return .= $conversion_tracker->code."\r\n";

			if (isset($conversion_tracker->fb_code))
				$return .= $conversion_tracker->fb_code."\r\n";
		}
		if (Configuration::get('EASYMARKETING_FACEBOOK_BADGE_CODE_ENABLED'))
		{
			$facebook_badge = urldecode(Configuration::get('EASYMARKETING_FACEBOOK_BADGE_CODE'));

			if (trim($facebook_badge) != '')
				$return .= $facebook_badge;
		}
		return $return;
	}

	public function hookFooter($params)
	{
		$return = '';

		if ((method_exists('Language', 'isMultiLanguageActivated') && Language::isMultiLanguageActivated())
			|| Language::countActiveLanguages() > 1)
			$multilang = (string)Tools::getValue('isolang').'/';
		else
			$multilang = '';

		$default_meta_order = Meta::getMetaByPage('order', $this->context->language->id);
		if (strpos($_SERVER['REQUEST_URI'], __PS_BASE_URI__.'order.php') === 0 ||
			strpos($_SERVER['REQUEST_URI'], __PS_BASE_URI__.$multilang.$default_meta_order['url_rewrite']) === 0)
		{
			if ((int)Tools::getValue('step') == 3)
			{
				if (Configuration::get('EASYMARKETING_LEAD_TRACKER_ENABLED'))
				{
					$lead_tracker = Tools::jsonDecode(urldecode(Configuration::get('EASYMARKETING_LEAD_TRACKER')));
					if (isset($lead_tracker->code))
					{
						$return .= '<!-- google_lead_tracker -->';
						$return .= $lead_tracker->code;
					}
					if (isset($lead_tracker->fb_code))
					{
						$return .= '<!-- fb_lead_tracker -->';
						$return .= $lead_tracker->fb_code;
					}
				}
			}
		}

		$default_meta_order = Meta::getMetaByPage('order-opc', $this->context->language->id);
		if (strpos($_SERVER['REQUEST_URI'], __PS_BASE_URI__.'order-opc.php') === 0 ||
			strpos($_SERVER['REQUEST_URI'], __PS_BASE_URI__.$multilang.$default_meta_order['url_rewrite']) === 0)
		{

			if (Configuration::get('EASYMARKETING_LEAD_TRACKER_ENABLED'))
			{

				$lead_tracker = Tools::jsonDecode(urldecode(Configuration::get('EASYMARKETING_LEAD_TRACKER')));
				if (isset($lead_tracker->code))
				{
					$return .= '<!-- google_lead_tracker -->';
					$return .= $lead_tracker->code;
				}
				if (isset($lead_tracker->fb_code))
				{
					$return .= '<!-- fb_lead_tracker -->';
					$return .= $lead_tracker->fb_code;
				}
			}
		}

		$default_meta_order = Meta::getMetaByPage('contact', $this->context->language->id);
		if (strpos($_SERVER['REQUEST_URI'], __PS_BASE_URI__.'contact.php') === 0 ||
			strpos($_SERVER['REQUEST_URI'], __PS_BASE_URI__.$multilang.$default_meta_order['url_rewrite']) === 0)
		{
			if (Configuration::get('EASYMARKETING_LEAD_TRACKER_ENABLED'))
			{
				$lead_tracker = Tools::jsonDecode(urldecode(Configuration::get('EASYMARKETING_LEAD_TRACKER')));
				if (isset($lead_tracker->code))
				{
					$return .= '<!-- google_lead_tracker -->';
					$return .= $lead_tracker->code;
				}
				if (isset($lead_tracker->fb_code))
				{
					$return .= '<!-- fb_lead_tracker -->';
					$return .= $lead_tracker->fb_code;
				}
			}

		}

		if (Configuration::get('EASYMARKETING_GOOGLE_REMARKETING_CODE_ENABLED'))
		{
			$remarketing_code = Tools::jsonDecode(urldecode(Configuration::get('EASYMARKETING_GOOGLE_REMARKETING_CODE')));
			if (isset($remarketing_code->code))
			{
				//echo '<pre>Page: '.print_r($this->context->controller->php_self, true).'</pre>';
				//echo '<pre>Code: '.print_r($remarketing_code->code, true).'</pre>';

				$ecomm_pagetype = 'other';
				$ecomm_prodid = '\'\'';
				$ecomm_totalvalue = '\'\'';
				$ecomm_others = array();

				if ($this->context->customer->logged)
				{
					$ecomm_others[] = 'hasaccount: \'y\',';

					if ($this->context->customer->id_gender == 1)
						$ecomm_others[] = 'g: \'m\',';
					elseif ($this->context->customer->id_gender == 2)
						$ecomm_others[] = 'g: \'f\',';

					$stats = $this->context->customer->getStats();
					if ($stats['nb_orders'] > 0)
						$ecomm_others[] = 'rp: \'y\',';
					else
						$ecomm_others[] = 'rp: \'n\',';
					if ($stats['age'] != '--')
						$ecomm_others[] = 'a: \''.$stats['age'].'\',';
				}
				else
					$ecomm_others[] = 'hasaccount: \'n\',';

				switch ($this->context->controller->php_self)
				{
					case 'index':
						$ecomm_pagetype = 'home';
						break;
					case 'search':
						$ecomm_pagetype = 'searchresults';
						break;
					case 'category':
						$ecomm_pagetype = 'category';
						// we can add ecomm_category
						break;
					case 'product':
						if ($id_product = (int)Tools::getValue('id_product'))
							$productObj = new Product($id_product, true, $this->context->language->id, $this->context->shop->id);

						if (Validate::isLoadedObject($productObj))
						{
							$id_product_attribute = (int)Product::getDefaultAttribute($id_product);
							$ecomm_pagetype = 'product';
							$ecomm_prodid = '\''.$this->createUniqueId($id_product, $id_product_attribute).'\'';
							$ecomm_totalvalue = '\''.Product::getPriceStatic((int)$id_product, false, $id_product_attribute, 2).'\'';

							// get recomended products
							$ecomm_rec_prodid = array();
							$accessories = Product::getAccessoriesLight($this->context->language->id, $id_product);
							if (is_array($accessories))
							{
								foreach ($accessories as $accessory)
								{
									$id_product_attribute_default = (int)Product::getDefaultAttribute($accessory['id_product']);
									$ecomm_rec_prodid[] = '\''.$this->createUniqueId($accessory['id_product'], $id_product_attribute_default).'\'';
								}
							}
							if (count($ecomm_rec_prodid) > 0)
								$ecomm_others[] = 'ecomm_rec_prodid: ['.implode(', ', $ecomm_rec_prodid).'],';
						}
						else
						{
							$ecomm_pagetype = 'product';
							$ecomm_prodid = '\'\'';
							$ecomm_totalvalue = '\'\'';
						}
						break;
					case 'order':
						$ecomm_pagetype = 'cart';
						if (isset($this->context->cart) &&
							($products = $this->context->cart->getProducts()) &&
							count($products) > 0)
						{
							$prod_ids = array();
							$prod_qty = array();
							$total = 0;
							foreach ($products as $product)
							{
								$prod_ids[] = '\''.$this->createUniqueId($product['id_product'], $product['id_product_attribute']).'\'';
								$prod_qty[] = '\''.$product['quantity'].'\'';
								$total += $product['total'];
							}
							$ecomm_prodid = '['.implode(', ', $prod_ids).']';
							$ecomm_totalvalue = '\''.$total.'\'';

							if (count($prod_qty) > 0)
								$ecomm_others[] = 'ecomm_quantity: ['.implode(', ', $prod_qty).'],';
						}
						break;
					case 'order-confirmation':
						$ecomm_pagetype = 'purchase';

						$id_order = Tools::getValue('id_order');
						$order = new Order((int)$id_order);
						if (Validate::isLoadedObject($order) &&
							($products = $order->getProducts()) &&
							count($products) > 0)
						{
							$prod_ids = array();
							$prod_qty = array();
							$total = 0;
							foreach ($products as $product)
							{
								$prod_ids[] = '\''.$this->createUniqueId($product['product_id'], $product['product_attribute_id']).'\'';
								$prod_qty[] = '\''.$product['quantity'].'\'';
								$total += $product['total_price_tax_excl'];
							}
							$ecomm_prodid = '['.implode(', ', $prod_ids).']';
							$ecomm_totalvalue = '\''.$total.'\'';
							if (count($prod_qty) > 0)
								$ecomm_others[] = 'ecomm_quantity: ['.implode(', ', $prod_qty).'],';
						}
						break;
				}
				$remarketing_code->code = str_replace(
					array('ecomm_prodid: \'REPLACE_WITH_VALUE\'',
						'ecomm_pagetype: \'REPLACE_WITH_VALUE\'',
						'ecomm_totalvalue: \'REPLACE_WITH_VALUE\'',
						'// INSERT_VALUES'),
					array('ecomm_prodid: '.$ecomm_prodid.'',
						'ecomm_pagetype: \''.$ecomm_pagetype.'\'',
						'ecomm_totalvalue: '.$ecomm_totalvalue.'',
						implode("\r\n", $ecomm_others)),
					$remarketing_code->code);
				//echo '<pre>Code: '.print_r($remarketing_code->code, true).'</pre>';
				$return .= $remarketing_code->code;
			}
		}

		return $return;
	}
}

function cmpUniqueIdAsc($a, $b)
{
	if ((float)$a['unique_id'] < (float)$b['unique_id'])
		return (-1);
	elseif ((float)$a['unique_id'] > (float)$b['unique_id'])
		return (1);
	return 0;
}