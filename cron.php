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

require(dirname(__FILE__).'/../../config/config.inc.php');
require_once(_PS_ROOT_DIR_.'/init.php');
require_once(dirname(__FILE__).'/easymarketing.php');

$module = new Easymarketing();

$return = true;
$return &= $module->downloadConversionTracker();
$return &= $module->downloadLeadTracker();
$return &= $module->downloadGoogleRemarketingCode();
$return &= $module->downloadFacebookBadge();

$log_type = 'cron';
$message = '===== '.date('Y.m.d h:i:s').' ====='."\r\n";
$message .= 'Return: '.print_r($return, true)."\r\n";
Easymarketing::logToFile($message, $log_type);
?>
