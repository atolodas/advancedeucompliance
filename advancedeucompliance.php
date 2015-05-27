<?php
/**
 * 2007-2015 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
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
 *  @author 	PrestaShop SA <contact@prestashop.com>
 *  @copyright  2007-2015 PrestaShop SA
 *  @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 *  International Registered Trademark & Property of PrestaShop SA
 */

if (!defined('_PS_VERSION_'))
	exit;

/* Include required entities */
include_once dirname(__FILE__).'/entities/AeucCMSRoleEmailEntity.php';
include_once dirname(__FILE__).'/entities/AeucEmailEntity.php';

class Advancedeucompliance extends Module
{
	/* Class members */
	protected $config_form = false;
	private $entity_manager;
	private $filesystem;
	private $emails;
	protected $_errors;
	protected $_warnings;

	/* Constants used for LEGAL/CMS Management */
	const LEGAL_NO_ASSOC		= 'NO_ASSOC';
	const LEGAL_NOTICE			= 'LEGAL_NOTICE';
	const LEGAL_CONDITIONS 		= 'LEGAL_CONDITIONS';
	const LEGAL_REVOCATION 		= 'LEGAL_REVOCATION';
	const LEGAL_REVOCATION_FORM = 'LEGAL_REVOCATION_FORM';
	const LEGAL_PRIVACY 		= 'LEGAL_PRIVACY';
	const LEGAL_ENVIRONMENTAL 	= 'LEGAL_ENVIRONMENTAL';
	const LEGAL_SHIP_PAY 		= 'LEGAL_SHIP_PAY';

	public function __construct(Core_Foundation_Database_EntityManager $entity_manager,
								Core_Foundation_FileSystem_FileSystem $fs,
								Core_Business_Email_EmailLister $email)
	{

		$this->name = 'advancedeucompliance';
		$this->tab = 'administration';
		$this->version = '1.0.0';
		$this->author = 'PrestaShop';
		$this->need_instance = 0;
		$this->bootstrap = true;

		parent::__construct();

		/* Register dependencies to module */
		$this->entity_manager = $entity_manager;
		$this->filesystem = $fs;
		$this->emails = $email;

		$this->displayName = $this->l('Advanced EU Compliance');
		$this->description = $this->l('This module helps European merchants to get compliant with their countries e-commerce laws.');
		$this->confirmUninstall = $this->l('Are you sure you want to uninstall this module?');

		/* Init errors var */
		$this->_errors = array();
	}

	/**
	 * Don't forget to create update methods if needed:
	 * http://doc.prestashop.com/display/PS16/Enabling+the+Auto-Update
	 */
	public function install()
	{
		return parent::install() &&
				$this->loadTables() &&
				$this->registerHook('header') &&
				$this->registerHook('displayProductPriceBlock') &&
				$this->registerHook('overrideTOSDisplay') &&
				$this->registerHook('actionEmailAddAfterContent') &&
				$this->registerHook('advancedPaymentOptions') &&
				$this->createConfig();
	}

	public function uninstall()
	{
		return parent::uninstall() &&
				$this->dropConfig() &&
				$this->unloadTables();
	}

	public function createConfig()
	{
		$delivery_time_available_values = array();
		$delivery_time_oos_values = array();
		$langs_repository = $this->entity_manager->getRepository('Language');
		$langs = $langs_repository->findAll();

		foreach ($langs as $lang) {
			$delivery_time_available_values[(int)$lang->id] = $this->l('Delivery: 1 to 3 weeks');
			$delivery_time_oos_values[(int)$lang->id] = $this->l('Delivery: 3 to 6 weeks');
		}

		$this->processAeucFeatTellAFriend(false);
		$this->processAeucFeatReorder(false);
		$this->processAeucFeatAdvPaymentApi(false);
		$this->processAeucLabelRevocationTOS(false);
		$this->processAeucLabelSpecificPrice(true);
		$this->processAeucLabelTaxIncExc(true);
		$this->processAeucLabelShippingIncExc(false);
		$this->processAeucLabelWeight(true);
		$this->processAeucLabelCombinationFrom(true);

		return Configuration::updateValue('AEUC_FEAT_TELL_A_FRIEND', false) &&
				Configuration::updateValue('AEUC_FEAT_REORDER', false) &&
				Configuration::updateValue('AEUC_FEAT_ADV_PAYMENT_API', false) &&
				Configuration::updateValue('AEUC_LABEL_DELIVERY_TIME_AVAILABLE', $delivery_time_available_values) &&
				Configuration::updateValue('AEUC_LABEL_DELIVERY_TIME_OOS', $delivery_time_oos_values) &&
				Configuration::updateValue('AEUC_LABEL_SPECIFIC_PRICE', true) &&
				Configuration::updateValue('AEUC_LABEL_TAX_INC_EXC', true) &&
				Configuration::updateValue('AEUC_LABEL_WEIGHT', true) &&
				Configuration::updateValue('AEUC_LABEL_REVOCATION_TOS', false) &&
				Configuration::updateValue('AEUC_LABEL_SHIPPING_INC_EXC', false) &&
				Configuration::updateValue('AEUC_LABEL_COMBINATION_FROM', true);
	}

	public function unloadTables()
	{
		$state = true;
		$sql = require dirname(__FILE__).'/install/sql_install.php';
		foreach ($sql as $name => $v) {
			$state &= Db::getInstance()->execute('DROP TABLE IF EXISTS ' . $name);
		}

		return $state;
	}

	public function loadTables()
	{
		$state = true;

		// Create module's table
		$sql = require dirname(__FILE__).'/install/sql_install.php';
		foreach ($sql as $s) {
			$state &= Db::getInstance()->execute($s);
		}

		// Fillin CMS ROLE
		$roles_array = $this->getCMSRoles();
		$roles = array_keys($roles_array);
		$cms_role_repository = $this->entity_manager->getRepository('CMSRole');

		foreach ($roles as $role) {
			if (!$cms_role_repository->findOneByName($role)) {
				$cms_role = $cms_role_repository->getNewEntity();
				$cms_role->id_cms = 0; // No assoc at this time
				$cms_role->name = $role;
				$state &= (bool)$cms_role->save();
			}
		}

		$default_path_email = _PS_MAIL_DIR_.'en'.DIRECTORY_SEPARATOR;
		// Fill-in aeuc_mail table
		foreach ($this->emails->getAvailableMails($default_path_email) as $mail) {
			$new_email = new AeucEmailEntity();
			$new_email->filename = (string)$mail;
			$new_email->display_name = $this->emails->getCleanedMailName($mail);
			$new_email->save();
			unset($new_email);
		}
		return $state;
	}


	public function dropConfig()
	{
		// Remove roles
		$roles_array = $this->getCMSRoles();
		$roles = array_keys($roles_array);
		$cms_role_repository = $this->entity_manager->getRepository('CMSRole');
		$cleaned = true;

		foreach ($roles as $role) {
			$cms_role_tmp = $cms_role_repository->findOneByName($role);
			if ($cms_role_tmp) {
				$cleaned &= $cms_role_tmp->delete();
			}
		}

		return Configuration::deleteByName('AEUC_FEAT_TELL_A_FRIEND') &&
				Configuration::deleteByName('AEUC_FEAT_REORDER') &&
				Configuration::deleteByName('AEUC_FEAT_ADV_PAYMENT_API') &&
				Configuration::deleteByName('AEUC_LABEL_DELIVERY_TIME_AVAILABLE') &&
				Configuration::deleteByName('AEUC_LABEL_DELIVERY_TIME_OOS') &&
				Configuration::deleteByName('AEUC_LABEL_SPECIFIC_PRICE') &&
				Configuration::deleteByName('AEUC_LABEL_TAX_INC_EXC') &&
				Configuration::deleteByName('AEUC_LABEL_WEIGHT') &&
				Configuration::deleteByName('AEUC_LABEL_REVOCATION_TOS') &&
				Configuration::deleteByName('AEUC_LABEL_SHIPPING_INC_EXC') &&
				Configuration::deleteByName('AEUC_LABEL_COMBINATION_FROM');
	}

	/* This hook is present to maintain backward compatibility */
	public function hookAdvancedPaymentOptions($param)
	{
		$legacyOptions = Hook::exec('displayPaymentEU', array(), null, true);
		$newOptions = array();

		foreach ($legacyOptions as $module_name => $legacyOption) {

			if (is_null($legacyOption) || $legacyOption === false) {
				continue;
			}

			foreach (Core_Business_Payment_PaymentOption::convertLegacyOption($legacyOption) as $option) {
				$option->setModuleName($module_name);
				$to_be_cleaned = $option->getForm();
				if ($to_be_cleaned) {
					$cleaned = str_replace('@hiddenSubmit', '', $to_be_cleaned);
					$option->setForm($cleaned);
				}
				$newOptions[] = $option;
			}
		}

		return $newOptions;
	}

	public function hookActionEmailAddAfterContent($param)
	{
		if (!isset($param['template']) || !isset($param['template_html']) || !isset($param['template_txt']))
			return;

		$tpl_name = (string)$param['template'];
		$tpl_name_exploded = explode('.', $tpl_name);
		if (is_array($tpl_name_exploded))
			$tpl_name = (string)$tpl_name_exploded[0];

		$id_lang = (int)$param['id_lang'];
		$mail_id = AeucEmailEntity::getMailIdFromTplFilename($tpl_name);

		if (!isset($mail_id['id_mail']))
			return;

		$mail_id = (int)$mail_id['id_mail'];
		$cms_role_ids = AeucCMSRoleEmailEntity::getCMSRoleIdsFromIdMail($mail_id);

		if (!$cms_role_ids)
			return;

		$tmp_cms_role_list = array();
		foreach ($cms_role_ids as $cms_role_id)
			$tmp_cms_role_list[] = $cms_role_id['id_cms_role'];

		$cms_role_repository = $this->entity_manager->getRepository('CMSRole');
		$cms_roles = $cms_role_repository->findByIdCmsRole($tmp_cms_role_list);

		if (!$cms_roles)
			return;

		$cms_repo = $this->entity_manager->getRepository('CMS');
		$cms_contents = array();

		foreach ($cms_roles as $cms_role) {
			$cms_page = $cms_repo->i10nFindOneById((int)$cms_role->id_cms, $id_lang, $this->context->shop->id);

			if (!isset($cms_page->content))
				continue;

			$cms_contents[] = $cms_page->content;
			$param['template_txt'] .= strip_tags($cms_page->content, true);
		}

		$this->context->smarty->assign(array('cms_contents' => $cms_contents));
		$final_content = $this->context->smarty->fetch($this->local_path.'views/templates/hook/hook-email-wrapper.tpl');
		$param['template_html'] .= $final_content;
	}

	public function hookHeader($param)
	{
		if (isset($this->context->controller->php_self) && ($this->context->controller->php_self === 'index' ||
				$this->context->controller->php_self === 'product')) {
			$this->context->controller->addCSS($this->_path.'assets/css/aeuc_front.css', 'all');
		}

	}

	public function hookOverrideTOSDisplay($param)
	{
		$has_tos_override_opt = (bool)Configuration::get('AEUC_LABEL_REVOCATION_TOS');
		$cms_repository = $this->entity_manager->getRepository('CMS');
		// Check first if LEGAL_REVOCATION CMS Role is been set before doing anything here
		$cms_role_repository = $this->entity_manager->getRepository('CMSRole');
		$cms_page_associated = $cms_role_repository->findOneByName(Advancedeucompliance::LEGAL_REVOCATION);

		if (!$has_tos_override_opt || !$cms_page_associated instanceof CMSRole || (int)$cms_page_associated->id_cms == 0)
			return false;

		// Get IDs of CMS pages required
		$cms_conditions_id = (int)Configuration::get('PS_CONDITIONS_CMS_ID');
		$cms_revocation_id = (int)$cms_page_associated->id_cms;

		// Get misc vars
		$id_lang = (int)$this->context->language->id;
		$id_shop = (int)$this->context->shop->id;
		$is_ssl_enabled = (bool)Configuration::get('PS_SSL_ENABLED');
		$checkedTos = $this->context->cart->checkedTos ? true : false;

		// Get CMS OBJs
		$cms_conditions = $cms_repository->i10nFindOneById($cms_conditions_id, $id_lang, $id_shop);
		$cms_revocations = $cms_repository->i10nFindOneById($cms_revocation_id, $id_lang, $id_shop);

		// Get links to these pages
		$link_conditions = $this->context->link->getCMSLink($cms_conditions, $cms_conditions->link_rewrite, $is_ssl_enabled);
		$link_revocations = $this->context->link->getCMSLink($cms_revocations, $cms_revocations->link_rewrite, $is_ssl_enabled);

		if (!strpos($link_conditions, '?'))
			$link_conditions .= '?content_only=1';
		else
			$link_conditions .= '&content_only=1';

		if (!strpos($link_revocations, '?'))
			$link_revocations .= '?content_only=1';
		else
			$link_revocations .= '&content_only=1';

		$this->context->smarty->assign(array(
			'checkedTOS' => $checkedTos,
			'link_conditions' => $link_conditions,
			'link_revocations' => $link_revocations
		));

		$content = $this->context->smarty->fetch($this->local_path.'views/templates/hook/hookOverrideTOSDisplay.tpl');
		return $content;
	}

	public function hookDisplayProductPriceBlock($param)
	{
		if (!isset($param['product']) || !isset($param['type'])) {
			return;
		}

		$product = $param['product'];

		if (is_array($product)) {
			$product_repository = $this->entity_manager->getRepository('Product');
			$product = $product_repository->findOne((int)$product['id_product']);
		}
		if (!Validate::isLoadedObject($product)) {
			return;
		}

		$smartyVars = array();

		/* Handle Product Combinations label */
		if ($param['type'] == 'before_price' && (bool)Configuration::get('AEUC_LABEL_SPECIFIC_PRICE') === true) {
			if ($product->hasAttributes()) {
				$smartyVars['before_price'] = array();
				$smartyVars['before_price']['from_str_i18n'] = $this->l('From', 'advancedeucompliance');
				return $this->dumpHookDisplayProductPriceBlock($smartyVars);
			}
		}

		/* Handle Specific Price label*/
		if ($param['type'] == 'old_price' && (bool)Configuration::get('AEUC_LABEL_SPECIFIC_PRICE') === true) {
			$smartyVars['old_price'] = array();
			$smartyVars['old_price']['before_str_i18n'] = $this->l('Before', 'advancedeucompliance');
			return $this->dumpHookDisplayProductPriceBlock($smartyVars);
		}

		/* Handle taxes  Inc./Exc. and Shipping Inc./Exc.*/
		if ($param['type'] == 'price')	{
			$smartyVars['price'] = array();

			if ((bool)Configuration::get('AEUC_LABEL_TAX_INC_EXC') === true) {

				if ((bool)Configuration::get('PS_TAX') === true) {
					$smartyVars['price']['tax_str_i18n'] = $this->l('Tax included', 'advancedeucompliance');
				} else {
					$smartyVars['price']['tax_str_i18n'] = $this->l('Tax excluded', 'advancedeucompliance');
				}
			}
			if ((bool)Configuration::get('AEUC_LABEL_SHIPPING_INC_EXC') === true) {

				if (!$product->is_virtual) {
					$cms_role_repository = $this->entity_manager->getRepository('CMSRole');
					$cms_repository = $this->entity_manager->getRepository('CMS');
					$cms_page_associated = $cms_role_repository->findOneByName(Advancedeucompliance::LEGAL_SHIP_PAY);

					if (isset($cms_page_associated->id_cms) && $cms_page_associated->id_cms != 0)	{

						$cms_ship_pay_id = (int)$cms_page_associated->id_cms;
						$cms_revocations = $cms_repository->i10nFindOneById($cms_ship_pay_id, $this->context->language->id,
							$this->context->shop->id);
						$is_ssl_enabled = (bool)Configuration::get('PS_SSL_ENABLED');
						$link_ship_pay = $this->context->link->getCMSLink($cms_revocations, $cms_revocations->link_rewrite, $is_ssl_enabled);

						if (!strpos($link_ship_pay, '?')) {
							$link_ship_pay .= '?content_only=1';
						}
						else {
							$link_ship_pay .= '&content_only=1';
						}

						$smartyVars['ship'] = array();
						$smartyVars['ship']['link_ship_pay'] = $link_ship_pay;
						$smartyVars['ship']['ship_str_i18n'] = $this->l('Shipping Excluded', 'advancedeucompliance');
						$smartyVars['ship']['js_ship_fancybx'] = '<script type="text/javascript">
																	$(document).ready(function(){
																		if (!!$.prototype.fancybox)
																			$("a.iframe").fancybox({
																				"type": "iframe",
																				"width": 600,
																				"height": 600
																			});
																	})
																</script>';
					}
				}
			}
			return $this->dumpHookDisplayProductPriceBlock($smartyVars);
		}

		/* Handles product's weight */
		if ($param['type'] == 'weight' && (bool)Configuration::get('PS_DISPLAY_PRODUCT_WEIGHT') === true &&
		isset($param['hook_origin']) && $param['hook_origin'] == 'product_sheet')
		{
			if ((int)$product->weight)
			{
				$smartyVars['weight'] = array();
				$rounded_weight = round((float)$product->weight, Configuration::get('PS_PRODUCT_WEIGHT_PRECISION'));
				$smartyVars['weight']['rounded_weight_str_i18n'] = $rounded_weight.' '.Configuration::get('PS_WEIGHT_UNIT');
				return $this->dumpHookDisplayProductPriceBlock($smartyVars);
			}
		}

		/* Handle Estimated delivery time label */
		if ($param['type'] == 'after_price') {
			$context_id_lang = $this->context->language->id;
			$is_product_available = (Product::getRealQuantity($product->id) >= 1) ? true : false;
			$smartyVars['after_price'] = array();
			if ($is_product_available) {
				$contextualized_content = Configuration::get('AEUC_LABEL_DELIVERY_TIME_AVAILABLE', (int)$context_id_lang);
				$smartyVars['after_price']['delivery_str_i18n'] = $contextualized_content;
			} else {
				$contextualized_content = Configuration::get('AEUC_LABEL_DELIVERY_TIME_OOS', (int)$context_id_lang);
				$smartyVars['after_price']['delivery_str_i18n'] = $contextualized_content;
			}
			return $this->dumpHookDisplayProductPriceBlock($smartyVars);
		}

		return;
	}

	private function dumpHookDisplayProductPriceBlock(array $smartyVars)
	{
		$this->context->smarty->assign(array('smartyVars' => $smartyVars));
		return $this->context->smarty->fetch($this->local_path.'views/templates/hook/hookDisplayProductPriceBlock.tpl');
	}

	/**
	 * Load the configuration form
	 */
	public function getContent()
	{
		$success_band = $this->_postProcess();
		$this->context->smarty->assign('module_dir', $this->_path);
		$this->context->smarty->assign('errors', $this->_errors);
		$this->context->controller->addCSS($this->_path.'assets/css/configure.css', 'all');
		// Render all required form for each 'part'
		$formLabelsManager = $this->renderFormLabelsManager();
		$formFeaturesManager = $this->renderFormFeaturesManager();
		$formLegalContentManager = $this->renderFormLegalContentManager();
		$formEmailAttachmentsManager = $this->renderFormEmailAttachmentsManager();

		return	$success_band.
				$formLabelsManager.
				$formFeaturesManager.
				$formLegalContentManager.
				$formEmailAttachmentsManager;
	}

	/**
	 * Save form data.
	 */
	protected function _postProcess()
	{
        $has_processed_something = false;

        $post_keys_switchable = array_keys(
            array_merge(
                $this->getConfigFormLabelsManagerValues(),
                $this->getConfigFormFeaturesManagerValues()
            )
        );

		$post_keys_complex = array(
			'AEUC_legalContentManager',
			'AEUC_emailAttachmentsManager'
		);

		$received_values = Tools::getAllValues();

        foreach (array_keys($received_values) as $key_received)
        {
			/* Case its one of form with only switches in it */
			if (in_array($key_received, $post_keys_switchable)) {
				$is_option_active = Tools::getValue($key_received);
				$key = Tools::strtolower($key_received);
				$key = Tools::toCamelCase($key);
				if (method_exists($this, 'process' . $key))
				{

					$this->{'process' . $key}($is_option_active);
					$has_processed_something = true;
				}
				continue;
			}
			/* Case we are on more complex forms */
			if (in_array($key_received, $post_keys_complex))
			{
				// Clean key
				$key = Tools::strtolower($key_received);
				$key = Tools::toCamelCase($key, true);

				if (method_exists($this, 'process' . $key))
				{
					$this->{'process' . $key}();
					$has_processed_something = true;
				}
			}

        }

		if ($has_processed_something) {
			$this->_clearCache('product.tpl');
			return (count($this->_errors) ? $this->displayError($this->_errors) : '') .
			(count($this->_warnings) ? $this->displayWarning($this->_warnings) : '') .
			$this->displayConfirmation($this->l('Settings saved successfully!'));
		} else {
			return (count($this->_errors) ? $this->displayError($this->_errors) : '') .
			(count($this->_warnings) ? $this->displayWarning($this->_warnings) : '') .
			'';
		}
	}


	protected function processAeucLabelCombinationFrom($is_option_active)
	{
		if ((bool)$is_option_active) {
			Configuration::updateValue('AEUC_LABEL_COMBINATION_FROM', true);
		}
		else {
			Configuration::updateValue('AEUC_LABEL_COMBINATION_FROM', false);
		}
	}

	protected function processAeucLabelSpecificPrice($is_option_active)
	{
		if ((bool)$is_option_active) {
			Configuration::updateValue('AEUC_LABEL_SPECIFIC_PRICE', true);
		}
		else {
			Configuration::updateValue('AEUC_LABEL_SPECIFIC_PRICE', false);
		}
	}

	protected function processAeucEmailAttachmentsManager()
	{
		$json_attach_assoc = json_decode(Tools::getValue('emails_attach_assoc'));

		if (!$json_attach_assoc)
			return;

		// Empty previous assoc to make new ones
		AeucCMSRoleEmailEntity::truncate();

		foreach ($json_attach_assoc as $assoc)
		{
			$assoc_obj = new AeucCMSRoleEmailEntity();
			$assoc_obj->id_mail = $assoc->id_mail;
			$assoc_obj->id_cms_role = $assoc->id_cms_role;

			if (!$assoc_obj->save())
				$this->_errors[] = $this->l('An email attachment to a CMS role has failed.', 'advancedeucompliance');
		}
	}

	protected function processAeucLabelRevocationTOS($is_option_active)
	{
		$cms_role_repository = $this->entity_manager->getRepository('CMSRole');
		$cms_page_associated = $cms_role_repository->findOneByName(Advancedeucompliance::LEGAL_REVOCATION);
		$cms_roles = $this->getCMSRoles();

		if (!$cms_page_associated instanceof CMSRole || (int)$cms_page_associated->id_cms == 0) {
			$this->_errors[] = sprintf(
					$this->l('\'Revocation Terms within ToS\' label cannot be activated unless you associate "%s" role with a CMS Page.',
							'advancedeucompliance'),
					(string)$cms_roles[Advancedeucompliance::LEGAL_REVOCATION]
			);
			return;
		}

		if ((bool)$is_option_active) {
			Configuration::updateValue('AEUC_LABEL_REVOCATION_TOS', true);
		} else {
			Configuration::updateValue('AEUC_LABEL_REVOCATION_TOS', false);
		}
	}

	protected function processAeucLabelShippingIncExc($is_option_active)
	{
		// Check first if LEGAL_REVOCATION CMS Role has been set before doing anything here
		$cms_role_repository = $this->entity_manager->getRepository('CMSRole');
		$cms_page_associated = $cms_role_repository->findOneByName(Advancedeucompliance::LEGAL_SHIP_PAY);
		$cms_roles = $this->getCMSRoles();

		if (!$cms_page_associated instanceof CMSRole || (int)$cms_page_associated->id_cms === 0) {
			$this->_errors[] = sprintf(
				$this->l('Shipping fees label cannot be activated unless you associate "%s" role with a CMS Page',
					'advancedeucompliance'),
				(string)$cms_roles[Advancedeucompliance::LEGAL_SHIP_PAY]
			);
			return;
		}

		if ((bool)$is_option_active) {
			Configuration::updateValue('AEUC_LABEL_SHIPPING_INC_EXC', true);
		} else {
			Configuration::updateValue('AEUC_LABEL_SHIPPING_INC_EXC', false);
		}

	}

	protected function processAeucLabelTaxIncExc($is_option_active)
	{
		$id_lang = (int)Configuration::get('PS_LANG_DEFAULT');
		$countries = Country::getCountries($id_lang, true, false, false);
		foreach ($countries as $id_country => $country_details)	{
			$country = new Country((int)$country_details['id_country']);
			if (Validate::isLoadedObject($country)) {
				$country->display_tax_label = !(int)$is_option_active;
				if (!$country->update())
					$this->_errors[] = $this->l('A country could not be updated for \'Tax inc./excl.\' label', 'advancedeucompliance');
			}
		}
		Configuration::updateValue('AEUC_LABEL_TAX_INC_EXC', (bool)$is_option_active);
	}

	protected function processAeucFeatAdvPaymentApi($is_option_active)
	{
		if ((bool)$is_option_active) {
			Configuration::updateValue('PS_ADVANCED_PAYMENT_API', true);
			Configuration::updateValue('AEUC_FEAT_ADV_PAYMENT_API', true);
		}
		else {
			Configuration::updateValue('PS_ADVANCED_PAYMENT_API', false);
			Configuration::updateValue('AEUC_FEAT_ADV_PAYMENT_API', false);
		}
	}

	protected function processPsAtcpShipWrap($is_option_active)
	{
		Configuration::updateValue('PS_ATCP_SHIPWRAP', $is_option_active);
	}

    protected function processAeucFeatTellAFriend($is_option_active)
    {
        $staf_module = Module::getInstanceByName('sendtoafriend');

        if ((bool)$is_option_active) {
			$staf_module->enable();
		} else {
			$staf_module->disable();
		}
    }

    protected function processAeucFeatReorder($is_option_active)
	{
        if ((bool)$is_option_active) {
			Configuration::updateValue('PS_DISALLOW_HISTORY_REORDERING', false);
			Configuration::updateValue('AEUC_FEAT_REORDER', true);
		} else {
			Configuration::updateValue('PS_DISALLOW_HISTORY_REORDERING', true);
			Configuration::updateValue('AEUC_FEAT_REORDER', false);
		}
    }

	protected function processAeucLabelWeight($is_option_active)
	{
		if ((bool)$is_option_active) {
			Configuration::updateValue('PS_DISPLAY_PRODUCT_WEIGHT', true);
			Configuration::updateValue('AEUC_LABEL_WEIGHT', true);
		}
		elseif (!(bool)$is_option_active) {
			Configuration::updateValue('PS_DISPLAY_PRODUCT_WEIGHT', false);
			Configuration::updateValue('AEUC_LABEL_WEIGHT', false);
		}
	}

	protected function processAeucLegalContentManager()
	{

		$posted_values = Tools::getAllValues();
		$cms_role_repository = $this->entity_manager->getRepository('CMSRole');

		foreach ($posted_values as $key_name => $assoc_cms_id) {
			if (strpos($key_name, 'CMSROLE_') !== false) {
				$exploded_key_name = explode('_', $key_name);
				$cms_role = $cms_role_repository->findOne((int)$exploded_key_name[1]);
				$cms_role->id_cms = (int)$assoc_cms_id;
				$cms_role->update();
			}
		}
		unset($cms_role);
	}

	protected function getCMSRoles()
	{
		return array(
			Advancedeucompliance::LEGAL_NOTICE 			=> $this->l('Legal notice', 'advancedeucompliance'),
			Advancedeucompliance::LEGAL_CONDITIONS 		=> $this->l('Terms of Service (ToS)', 'advancedeucompliance'),
			Advancedeucompliance::LEGAL_REVOCATION 		=> $this->l('Revocation terms', 'advancedeucompliance'),
			Advancedeucompliance::LEGAL_REVOCATION_FORM => $this->l('Revocation form', 'advancedeucompliance'),
			Advancedeucompliance::LEGAL_PRIVACY 		=> $this->l('Privacy', 'advancedeucompliance'),
			Advancedeucompliance::LEGAL_ENVIRONMENTAL 	=> $this->l('Environmental notice', 'advancedeucompliance'),
			Advancedeucompliance::LEGAL_SHIP_PAY		=> $this->l('Shipping and payment', 'advancedeucompliance')
		);
	}

	/**
	 * Create the form that will let user choose all the wording options
	 */
	protected function renderFormLabelsManager()
	{
		$helper = new HelperForm();

		$helper->show_toolbar = false;
		$helper->table = $this->table;
		$helper->module = $this;
		$helper->default_form_language = $this->context->language->id;
		$helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

		$helper->identifier = $this->identifier;
		$helper->submit_action = 'submitAEUC_labelsManager';
		$helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
			.'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
		$helper->token = Tools::getAdminTokenLite('AdminModules');

		$helper->tpl_vars = array(
			'fields_value' => $this->getConfigFormLabelsManagerValues(), /* Add values for your inputs */
			'languages' => $this->context->controller->getLanguages(),
			'id_language' => $this->context->language->id,
		);

		return $helper->generateForm(array($this->getConfigFormLabelsManager()));
	}

	/**
	 * Create the structure of your form.
	 */
	protected function getConfigFormLabelsManager()
	{
		return array(
			'form' => array(
				'legend' => array(
				'title' => $this->l('Labels', 'advancedeucompliance'),
				'icon' => 'icon-tags',
				),
				'input' => array(
					array(
						'type' => 'text',
						'lang' => true,
						'label' => $this->l('Estimated delivery time label (available products)', 'advancedeucompliance'),
						'name' => 'AEUC_LABEL_DELIVERY_TIME_AVAILABLE',
						'desc' => $this->l('Indicate the estimated delivery time for your in-stock products.
						Leave the field empty to disable.', 'advancedeucompliance'),
					),
					array(
						'type' => 'text',
						'lang' => true,
						'label' => $this->l('Estimated delivery time label (out-of-stock products)', 'advancedeucompliance'),
						'name' => 'AEUC_LABEL_DELIVERY_TIME_OOS',
						'desc' => $this->l('Indicate the estimated delivery time for your out-of-stock products.
						Leave the field empty to disable.', 'advancedeucompliance'),
					),
					array(
						'type' => 'switch',
						'label' => $this->l('\'Before\' Base price label', 'advancedeucompliance'),
						'name' => 'AEUC_LABEL_SPECIFIC_PRICE',
						'is_bool' => true,
						'desc' => $this->l('When a product is on sale, displays the base price with a \'Before\' label.',
							'advancedeucompliance'),
						'values' => array(
							array(
								'id' => 'active_on',
								'value' => true,
								'label' => $this->l('Enabled', 'advancedeucompliance')
							),
							array(
								'id' => 'active_off',
								'value' => false,
								'label' => $this->l('Disabled', 'advancedeucompliance')
							)
						),
					),
					array(
						'type' => 'switch',
						'label' => $this->l('Tax \'inc./excl.\' label', 'advancedeucompliance'),
						'name' => 'AEUC_LABEL_TAX_INC_EXC',
						'is_bool' => true,
						'desc' => $this->l('Display whether the tax is included next to the product price
						(\'Tax included/excluded\' label).', 'advancedeucompliance'),
						'values' => array(
							array(
								'id' => 'active_on',
								'value' => true,
								'label' => $this->l('Enabled', 'advancedeucompliance')
							),
							array(
								'id' => 'active_off',
								'value' => false,
								'label' => $this->l('Disabled', 'advancedeucompliance')
							)
						),
					),
					array(
						'type' => 'switch',
						'label' => $this->l('Shipping fees \'Inc./Excl.\' label', 'advancedeucompliance'),
						'name' => 'AEUC_LABEL_SHIPPING_INC_EXC',
						'is_bool' => true,
						'desc' => $this->l('Display whether the shipping fees are included, next to the product
						price (\'Shipping fees included / excluded\').', 'advancedeucompliance'),
						'hint' => $this->l('If enabled, make sure the Shipping terms are associated with a CMS page
						below (Legal Content Management). The label will link to this content.', 'advancedeucompliance'),
						'values' => array(
							array(
								'id' => 'active_on',
								'value' => true,
								'label' => $this->l('Enabled', 'advancedeucompliance')
							),
							array(
								'id' => 'active_off',
								'value' => false,
								'label' => $this->l('Disabled', 'advancedeucompliance')
							)
						),
					),
					array(
						'type' => 'switch',
						'label' => $this->l('Product weight label', 'advancedeucompliance'),
						'name' => 'AEUC_LABEL_WEIGHT',
						'is_bool' => true,
						'desc' => $this->l('Display the weight of a product (when information is available).',
							'advancedeucompliance'),
						'values' => array(
							array(
								'id' => 'active_on',
								'value' => true,
								'label' => $this->l('Enabled', 'advancedeucompliance')
							),
							array(
								'id' => 'active_off',
								'value' => false,
								'label' => $this->l('Disabled', 'advancedeucompliance')
							)
						),
					),
					array(
						'type' => 'switch',
						'label' => $this->l('Revocation Terms within ToS', 'advancedeucompliance'),
						'name' => 'AEUC_LABEL_REVOCATION_TOS',
						'is_bool' => true,
						'desc' => $this->l('Include content from the Revocation Terms CMS page within the
						Terms of Services (ToS). ', 'advancedeucompliance'),
						'hint' => $this->l('If enabled, make sure the Revocation Terms are associated with a CMS page
						below (Legal Content Management).', 'advancedeucompliance'),
						'disable' => true,
						'values' => array(
							array(
								'id' => 'active_on',
								'value' => true,
								'label' => $this->l('Enabled', 'advancedeucompliance')
							),
							array(
								'id' => 'active_off',
								'value' => false,
								'label' => $this->l('Disabled', 'advancedeucompliance')
							)
						),
					),
					array(
						'type' => 'switch',
						'label' => $this->l('\'From\' price label (when combinations)'),
						'name' => 'AEUC_LABEL_COMBINATION_FROM',
						'is_bool' => true,
						'desc' => $this->l('Display a \'From\' label before the price on products with combinations.',
							'advancedeucompliance'),
						'hint' => $this->l('As prices can vary from a combination to another, this label indicates
						that the final price may be higher.', 'advancedeucompliance'),
						'disable' => true,
						'values' => array(
							array(
								'id' => 'active_on',
								'value' => true,
								'label' => $this->l('Enabled', 'advancedeucompliance')
							),
							array(
								'id' => 'active_off',
								'value' => false,
								'label' => $this->l('Disabled', 'advancedeucompliance')
							)
						),
					),
				),
				'submit' => array(
					'title' => $this->l('Save', 'advancedeucompliance'),
				),
			),
		);
	}

	/**
	 * Set values for the inputs.
	 */
	protected function getConfigFormLabelsManagerValues()
	{
		$delivery_time_available_values = array();
		$delivery_time_oos_values = array();
		$langs = Language::getLanguages(false, false);

		foreach ($langs as $lang) {
			$tmp_id_lang = (int)$lang['id_lang'];
			$delivery_time_available_values[$tmp_id_lang] = Configuration::get('AEUC_LABEL_DELIVERY_TIME_AVAILABLE', $tmp_id_lang);
			$delivery_time_oos_values[$tmp_id_lang] = Configuration::get('AEUC_LABEL_DELIVERY_TIME_OOS', $tmp_id_lang);
		}

		return array(
			'AEUC_LABEL_DELIVERY_TIME_AVAILABLE' => $delivery_time_available_values,
			'AEUC_LABEL_DELIVERY_TIME_OOS' => $delivery_time_oos_values,
			'AEUC_LABEL_SPECIFIC_PRICE' => Configuration::get('AEUC_LABEL_SPECIFIC_PRICE'),
			'AEUC_LABEL_TAX_INC_EXC' => Configuration::get('AEUC_LABEL_TAX_INC_EXC'),
			'AEUC_LABEL_WEIGHT' => Configuration::get('AEUC_LABEL_WEIGHT'),
			'AEUC_LABEL_REVOCATION_TOS' => Configuration::get('AEUC_LABEL_REVOCATION_TOS'),
			'AEUC_LABEL_SHIPPING_INC_EXC' => Configuration::get('AEUC_LABEL_SHIPPING_INC_EXC'),
			'AEUC_LABEL_COMBINATION_FROM' => Configuration::get('AEUC_LABEL_COMBINATION_FROM')
		);
	}

	/**
	 * Create the form that will let user choose all the wording options
	 */
	protected function renderFormFeaturesManager()
	{
		$helper = new HelperForm();

		$helper->show_toolbar = false;
		$helper->table = $this->table;
		$helper->module = $this;
		$helper->default_form_language = $this->context->language->id;
		$helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

		$helper->identifier = $this->identifier;
		$helper->submit_action = 'submitAEUC_featuresManager';
		$helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
			.'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
		$helper->token = Tools::getAdminTokenLite('AdminModules');

		$helper->tpl_vars = array(
			'fields_value' => $this->getConfigFormFeaturesManagerValues(), /* Add values for your inputs */
			'languages' => $this->context->controller->getLanguages(),
			'id_language' => $this->context->language->id,
		);

		return $helper->generateForm(array($this->getConfigFormFeaturesManager()));
	}

	/**
	 * Create the structure of your form.
	 */
	protected function getConfigFormFeaturesManager()
	{
		return array(
			'form' => array(
				'legend' => array(
					'title' => $this->l('Features'),
					'icon' => 'icon-cogs',
				),
				'input' => array(
					array(
						'type' => 'switch',
						'label' => $this->l('Enable \'Tell A Friend\' feature'),
						'name' => 'AEUC_FEAT_TELL_A_FRIEND',
						'is_bool' => true,
						'desc' => $this->l('Make sure you comply with your local legislation before enabling:
						it can be regarded as an unsolicited commercial email.'),
						'hint' => $this->l('If enabled, the \'Send to a Friend\' module allows customers to send to a
						friend an email with a link to a product\'s page.', 'advancedeucompliance'),
						'values' => array(
							array(
								'id' => 'active_on',
								'value' => true,
								'label' => $this->l('Enabled')
							),
							array(
								'id' => 'active_off',
								'value' => false,
								'label' => $this->l('Disabled')
							)
						),
					),
					array(
						'type' => 'switch',
						'label' => $this->l('Enable \'Reordering\' feature'),
						'hint' => $this->l('If enabled, the \'Reorder\' option allows customers to reorder in one
						click from their Order History page.', 'advancedeucompliance'),
						'name' => 'AEUC_FEAT_REORDER',
						'is_bool' => true,
						'desc' => $this->l('Make sure you comply with your local legislation before enabling:
						it can be regarded as inertia selling.', 'advancedeucompliance'),
						'values' => array(
							array(
								'id' => 'active_on',
								'value' => true,
								'label' => $this->l('Enabled')
							),
							array(
								'id' => 'active_off',
								'value' => false,
								'label' => $this->l('Disabled')
							)
						),
					),
					array(
						'type' => 'switch',
						'label' => $this->l('Enable \'Advanced checkout page\''),
						'hint' => $this->l('The advanced checkout page displays the following sections: payment methods,
						address summary, ToS agreement, cart summary, and an \'Order with Obligation to Pay\' button.',
							'advancedeucompliance'),
						'name' => 'AEUC_FEAT_ADV_PAYMENT_API',
						'is_bool' => true,
						'desc' => $this->l('To address some of the latest European legal requirements,
						the advanced checkout page displays additional information (terms of service, payment methods,
						etc) in one single page.', 'advancedeucompliance'),
						'values' => array(
							array(
								'id' => 'active_on',
								'value' => true,
								'label' => $this->l('Enabled')
							),
							array(
								'id' => 'active_off',
								'value' => false,
								'label' => $this->l('Disabled')
							)
						),
					),
					array(
						'type' => 'switch',
						'label' => $this->l('Use average tax of cart products for Shipping and Wrapping'),
						'name' => 'PS_ATCP_SHIPWRAP',
						'is_bool' => true,
						'values' => array(
							array(
								'id' => 'active_on',
								'value' => true,
								'label' => $this->l('Enabled')
							),
							array(
								'id' => 'active_off',
								'value' => false,
								'label' => $this->l('Disabled')
							)
						),
					),
				),
				'submit' => array(
					'title' => $this->l('Save'),
				),
			),
		);
	}

	/**
	 * Set values for the inputs.
	 */
	protected function getConfigFormFeaturesManagerValues()
	{
		return array(
			'AEUC_FEAT_TELL_A_FRIEND' => Configuration::get('AEUC_FEAT_TELL_A_FRIEND'),
			'AEUC_FEAT_REORDER' => Configuration::get('AEUC_FEAT_REORDER'),
			'AEUC_FEAT_ADV_PAYMENT_API' => Configuration::get('AEUC_FEAT_ADV_PAYMENT_API'),
			'PS_ATCP_SHIPWRAP' => Configuration::get('PS_ATCP_SHIPWRAP'),
		);
	}

	/**
	 * Create the form that will let user manage his legal page trough "CMS" feature
	 */
	protected function renderFormLegalContentManager()
	{
		$cms_roles_aeuc = $this->getCMSRoles();
		$cms_repository = $this->entity_manager->getRepository('CMS');
		$cms_role_repository = $this->entity_manager->getRepository('CMSRole');
		$cms_roles = $cms_role_repository->findByName(array_keys($cms_roles_aeuc));
		$cms_roles_assoc = array();
		$id_lang = Context::getContext()->employee->id_lang;
		$id_shop = Context::getContext()->shop->id;

		foreach ($cms_roles as $cms_role) {

			if ((int)$cms_role->id_cms !== 0) {
				$cms_entity = $cms_repository->findOne((int)$cms_role->id_cms);
				$assoc_cms_name = $cms_entity->meta_title[(int)$id_lang];
			}
			else {
				$assoc_cms_name = $this->l('-- Select associated CMS page --', 'advancedeucompliance');
			}

			$cms_roles_assoc[(int)$cms_role->id] = array('id_cms' => (int)$cms_role->id_cms,
														'page_title' => (string)$assoc_cms_name,
														'role_title' => (string)$cms_roles_aeuc[$cms_role->name]
														);
		}

		$cms_pages = $cms_repository->i10nFindAll($id_lang, $id_shop);
		$fake_object =  new stdClass();
		$fake_object->id = 0;
		$fake_object->meta_title = $this->l('-- Select associated CMS page -- ', 'advancedeucompliance');
		$cms_pages[0] = $fake_object;
		unset($fake_object);

		$this->context->smarty->assign(array(
			'cms_roles_assoc' => $cms_roles_assoc,
			'cms_pages' => $cms_pages,
			'form_action' => '#',
			'add_cms_link' => $this->context->link->getAdminLink('AdminCMS')
		));
		$content = $this->context->smarty->fetch($this->local_path.'views/templates/admin/legal_cms_manager_form.tpl');
		return $content;
	}

	protected function renderFormEmailAttachmentsManager()
	{
		$cms_roles_aeuc = $this->getCMSRoles();
		$cms_role_repository = $this->entity_manager->getRepository('CMSRole');
		$cms_roles_associated = $cms_role_repository->getCMSRolesAssociated();
		$cms_roles_full = $cms_role_repository->findByName(array_keys($cms_roles_aeuc));
		$incomplete_cms_role_association_warning = false;
		$legal_options = array();
		$cleaned_mails_names = array();

		if (count($cms_roles_associated) != count($cms_roles_full)) {
			$incomplete_cms_role_association_warning = $this->displayWarning(
				$this->l('Your legal content is not linked to any CMS page yet (see above section).
				Please make sure your content is associated before managing emails attachements.', 'advancedeucompliance')
			);
		}

		foreach ($cms_roles_associated as $role) {
			$list_id_mail_assoc = AeucCMSRoleEmailEntity::getIdEmailFromCMSRoleId((int)$role->id);
			$clean_list = array();

			foreach ($list_id_mail_assoc as $list_id_mail_assoc) {
				$clean_list[] = $list_id_mail_assoc['id_mail'];
			}

			$legal_options[$role->name] = array(
				'name' => $cms_roles_aeuc[$role->name],
				'id' => $role->id,
				'list_id_mail_assoc' => $clean_list
			);
		}

		foreach (AeucEmailEntity::getAll() as $email) {
			$cleaned_mails_names[] = $email;
		}

		$this->context->smarty->assign(array(
			'has_assoc' => $cms_roles_associated,
			'incomplete_cms_role_association_warning' => $incomplete_cms_role_association_warning,
			'mails_available' => $cleaned_mails_names,
			'legal_options' => $legal_options
		));

		$content = $this->context->smarty->fetch($this->local_path.'views/templates/admin/email_attachments_form.tpl');
		// Insert JS in the page
		$this->context->controller->addJS(($this->_path).'assets/js/email_attachement.js');

		return $content;
	}

}
