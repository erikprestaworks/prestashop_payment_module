<?php
/**
 * Maksuturva Payment Module
 * Creation date: 01/12/2011
 */
if (!defined('_PS_VERSION_')) {
	exit;
}

/**
 * Main class for gateway payments
 * @author Maksuturva
 */
class Maksuturva extends PaymentModule
{
	private $_html = '';
	private $_postErrors = array();

	/**
	 * Module configuration values
	 * @var array
	 */
	public  $config = array();

	// variables from GET on payment return
    private $_mandatoryFields = array(
    	"pmt_action",
    	"pmt_version",
    	"pmt_id",
    	"pmt_reference",
    	"pmt_amount",
    	"pmt_currency",
    	"pmt_sellercosts",
    	"pmt_paymentmethod",
    	"pmt_escrow",
    	"pmt_hash"
    );

	/**
	 * Class constructor: assign some configuration values
	 */
	public function __construct()
	{
		$this->name = 'maksuturva';
		$this->tab = 'payments_gateways';
		$this->version = '1.0';
		$this->author = 'Maksuturva';

		$this->currencies = true;
		$this->currencies_mode = 'checkbox';

		$this->_checkConfig(false);
		$this->displayName = $this->l('Maksuturva/eMaksut');
		$this->description = $this->l('Accepts payments using Maksuturva/eMaksut');
		$this->_errors = array();

		parent::__construct();
		$this->confirmUninstall = $this->l('Are you sure you want to delete Maksuturva/eMaksut module?');

		/* For 1.4.3 and less compatibility */
		$updateConfig = array(
			'PS_OS_CHEQUE' => 1,
			'PS_OS_PAYMENT' => 2,
			'PS_OS_PREPARATION' => 3,
			'PS_OS_SHIPPING' => 4,
			'PS_OS_DELIVERED' => 5,
			'PS_OS_CANCELED' => 6,
			'PS_OS_REFUND' => 7,
			'PS_OS_ERROR' => 8,
			'PS_OS_OUTOFSTOCK' => 9,
			'PS_OS_BANKWIRE' => 10,
			'PS_OS_PAYPAL' => 11,
			'PS_OS_WS_PAYMENT' => 12
		);
		foreach ($updateConfig as $u => $v) {
			if (!Configuration::get($u) || (int)Configuration::get($u) < 1) {
				if (defined('_'.$u.'_') && (int)constant('_'.$u.'_') > 0) {
					Configuration::updateValue($u, constant('_'.$u.'_'));
				} else {
					Configuration::updateValue($u, $v);
				}
			}
		}

		$this->_checkConfig();
	}

	/**
	 * Retrieves the configuration parameters and checks the existence of
	 * all required configuration entries.
	 * @return boolean
	 */
	private function _checkConfig($warn = true)
	{
		$fail = false;
		$config = Configuration::getMultiple($this->_getConfigKeys());
		foreach ($this->_getConfigKeys() as $key) {
			if (isset($config[$key])) {
				$this->config[$key] = $config[$key];
			} else {
				if ($warn) {
					$this->warning .= $this->l($key . '_WARNING') . ', ';
				}
				$fail = true;
			}
		}
		if (!sizeof(Currency::checkPaymentCurrencies($this->id))) {
			if ($warn) {
				$this->warning .= $this->l('No currency set for this module') . ', ';
			}
			$fail = true;
		}
		if ($warn) {
			$this->warning = trim($this->warning,  ', ');
		}
		return !$fail;
	}

    /**
     * Module keys are fetched using this method
     * @return array
     */
    private function _getConfigKeys()
    {
    	return array(
	      	'MAKSUTURVA_SELLER_ID',
	      	'MAKSUTURVA_SECRET_KEY',
    		'MAKSUTURVA_SECRET_KEY_VERSION',
	        'MAKSUTURVA_URL',
	      	'MAKSUTURVA_ENCODING',
	      	'MAKSUTURVA_SANDBOX',
	      	'MAKSUTURVA_EMAKSUT',
    		'MAKSUTURVA_OS_AUTHORIZATION',
      	);
    }

    /**
     * Installs the module (non-PHPdoc)
     * @see PaymentModuleCore::install()
     */
	public function install()
	{
		// hooks
		if (!parent::install()
			OR !$this->registerHook('payment')
			OR !$this->registerHook('paymentReturn')
			OR !$this->registerHook('rightColumn')
			OR !$this->registerHook('adminOrder')) {
			return false;
		}

		// config keys/values
		if (!Configuration::updateValue('MAKSUTURVA_SELLER_ID', '') ||
			!Configuration::updateValue('MAKSUTURVA_SECRET_KEY', '') ||
			!Configuration::updateValue('MAKSUTURVA_SECRET_KEY_VERSION', '001') ||
			!Configuration::updateValue('MAKSUTURVA_URL', 'https://www.maksuturva.fi') ||
			!Configuration::updateValue('MAKSUTURVA_ENCODING', 'UTF-8') ||
			!Configuration::updateValue('MAKSUTURVA_SANDBOX', '1') ||
			!Configuration::updateValue('MAKSUTURVA_EMAKSUT', '0')) {
			return false;
		}

		/* Set database - this table is not removed later (if audit is needed) */
		$dbCreate = Db::getInstance()->execute(
			'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'mk_status` (
			  `id_cart` int(10) unsigned NOT NULL,
			  `id_order` int(10) unsigned DEFAULT NULL,
			  `payment_status` int(10) unsigned NOT NULL DEFAULT 0,
			  PRIMARY KEY (`id_cart`)
			) ENGINE='._MYSQL_ENGINE_.'  DEFAULT CHARSET=utf8;'
		);
		if (!$dbCreate) {
			return false;
		}

		// insert maksuturva's logo to order status for better management
		if (!Configuration::get('MAKSUTURVA_OS_AUTHORIZATION'))
		{
			$orderState = new OrderState();
			$orderState->name = array();
			foreach (Language::getLanguages() AS $language)
			{
				if (strtolower($language['iso_code']) == 'fr') {
					$orderState->name[$language['id_lang']] = 'En attendant la confirmation de Maksuturva';
				} else {
					$orderState->name[$language['id_lang']] = 'Pending confirmation from Maksuturva';
				}
			}
			$orderState->send_email = false;
			$orderState->color = '#DDEEFF';
			$orderState->hidden = false;
			$orderState->delivery = false;
			$orderState->logable = true;
			$orderState->invoice = true;
			if ($orderState->add()) {
				copy(dirname(__FILE__).'/logo.gif', dirname(__FILE__).'/../../img/os/'.(int)$orderState->id.'.gif');
			}
			Configuration::updateValue('MAKSUTURVA_OS_AUTHORIZATION', (int)$orderState->id);
		}
		return true;
	}
	
	public function execPayment($cart)
	{
		global $cookie, $smarty, $customer;
		
		if (!$this->active) {
			return;
		}
		if (!$this->checkCurrency($cart)) {
			Tools::redirectLink(__PS_BASE_URI__.'order.php');
		}

		// build up the "post to pay" form
		require_once dirname(__FILE__) . "/MaksuturvaGatewayImplementation.php";
		$gateway = new MaksuturvaGatewayImplementation($cart->id, $cart, Configuration::get('MAKSUTURVA_ENCODING'), $this);

		// insert the order in mk_status to be verified later
		$this->updateCartInMkStatusByIdCart($cart->id);

		$smarty->assign(
			array(
				'nbProducts' => $cart->nbProducts(),
				'cust_currency' => $cart->id_currency,
				'currencies' => $this->getCurrency((int)$cart->id_currency),
				'total' => $cart->getOrderTotal(true, Cart::BOTH),
				'this_path' => $this->_path,
				'this_path_ssl' => Tools::getShopDomainSsl(true, true).__PS_BASE_URI__.'modules/'.$this->name.'/',
				'form_action' => MaksuturvaGatewayImplementation::getPaymentUrl(Configuration::get('MAKSUTURVA_URL')),
				'maksuturva_fields' => $gateway->getFieldArray(),
				'emaksut'  => Configuration::get('MAKSUTURVA_EMAKSUT'),
			)
		);

		return $this->display(__FILE__, 'payment_execution.tpl');
	}
	
	/**
	 * Uninstalls the module (non-PHPdoc)
	 * @see PaymentModuleCore::uninstall()
	 */
	public function uninstall()
	{
		foreach ($this->_getConfigKeys() as $key) {
			if (Configuration::get($key)) {
				if (!Configuration::deleteByName($key)) {
					return false;
				}
			}
		}
		if (!parent::uninstall()) {
			return false;
		}
		return true;
	}

	/**
	 * Administration:
	 * This method is responsible for providing
	 * content to administration area
	 */
	public function getContent()
	{
		$this->_html = "";

		$this->_postProcess();
		$this->_setConfigurationForm();
		return $this->_html;
	}

	/**
	 * Administration
	 * Renders the configuration form
	 */
	private function _setConfigurationForm()
	{
		$sandboxMode = (int)(Tools::getValue('sandbox_mode', Configuration::get('MAKSUTURVA_SANDBOX')));
		$encoding = (Tools::getValue('mks_encoding', Configuration::get('MAKSUTURVA_ENCODING')));
		$emaksut = (int)(Tools::getValue('mks_emaksut', Configuration::get('MAKSUTURVA_EMAKSUT')));

		$this->_html .= '
		<style>.tab-row .tab { width: 180px; }</style>
		<form method="post" action="'.htmlentities($_SERVER['REQUEST_URI']).'">
			<div id="cfg-pane-1" style="width:70%; margin: 10px auto;">
				 <div class="tab-page" id="step1">
					<h4 class="tab">'.$this->l('Maksuturva/eMaksut configuration').'</h4>
					<h2>'.$this->l('Settings').'</h2>

					<label>'.$this->l('Seller ID').':</label>
					<div class="margin-form" style="padding-top:2px;">
						<input type="text" name="seller_id_mks" value="'.htmlentities(Tools::getValue('seller_id_mks', Configuration::get('MAKSUTURVA_SELLER_ID')), ENT_COMPAT, 'UTF-8').'" size="10" />
					</div>
					<div class="clear"></div>

					<label>'.$this->l('Secret Key').':</label>
					<div class="margin-form" style="padding-top:2px;">
						<input type="text" name="secret_key_mks" value="'.htmlentities(Tools::getValue('secret_key_mks', Configuration::get('MAKSUTURVA_SECRET_KEY')), ENT_COMPAT, 'UTF-8').'" size="30" />
					</div>
					<div class="clear"></div>

					<label>'.$this->l('Secret Key Version').':</label>
					<div class="margin-form" style="padding-top:2px;">
						<input type="text" name="secret_key_version_mks" value="'.htmlentities(Tools::getValue('secret_key_version_mks', Configuration::get('MAKSUTURVA_SECRET_KEY_VERSION')), ENT_COMPAT, 'UTF-8').'" size="5" />
					</div>
					<div class="clear"></div>

					<label>'.$this->l('Communication Encoding').':</label>
					<div class="margin-form" style="padding-top:2px;">
						<input type="radio" name="mks_encoding" id="mks_utf" value="UTF-8" '.($encoding != "ISO-8859-1" ? 'checked="checked" ' : '').'/> <label for="mks_utf" class="t">'.$this->l('UTF-8').'</label>
						<input type="radio" name="mks_encoding" id="mks_iso" value="ISO-8859-1" style="margin-left:15px;" '.($encoding == "ISO-8859-1" ? 'checked="checked" ' : '').'/> <label for="mks_iso" class="t">'.$this->l('ISO-8859-1').'</label>
					</div>
					<div class="clear"></div>

					<label>'.$this->l('eMaksut').':</label>
					<div class="margin-form" style="padding-top:2px;">
						<input type="radio" name="mks_emaksut" id="mks_emaksut_1" value="1" '.($emaksut? 'checked="checked" ' : '').'/> <label for="mks_emaksut_1" class="t">'.$this->l('Active').'</label>
						<input type="radio" name="mks_emaksut" id="mks_emaksut_0" value="0" style="margin-left:15px;" '.(!$emaksut ? 'checked="checked" ' : '').'/> <label for="mks_emaksut_0" class="t">'.$this->l('Inactive').'</label>
					</div>
					<div class="clear"></div>

					<label>'.$this->l('Sandbox mode (tests)').':</label>
					<div class="margin-form" style="padding-top:2px;">
						<input type="radio" name="sandbox_mode" id="sandbox_mode_1" value="1" '.($sandboxMode ? 'checked="checked" ' : '').'/> <label for="sandbox_mode_1" class="t">'.$this->l('Active').'</label>
						<input type="radio" name="sandbox_mode" id="sandbox_mode_0" value="0" style="margin-left:15px;" '.(!$sandboxMode ? 'checked="checked" ' : '').'/> <label for="sandbox_mode_0" class="t">'.$this->l('Inactive').'</label>
					</div>
					<div class="clear"></div>

					<label>'.$this->l('Communication URL').':</label>
					<div class="margin-form" style="padding-top:2px;">
						<input type="text" name="mks_communication_url" value="'.htmlentities(Tools::getValue('mks_communication_url', Configuration::get('MAKSUTURVA_URL')), ENT_COMPAT, 'UTF-8').'" size="30" />
					</div>
					<div class="clear"></div>

					<div class="clear"></div>
					<p class="center"><input class="button" type="submit" name="submitMaksuturva" value="'.$this->l('Save settings').'" /></p>
				</div>
			</div>
			<div class="clear"></div>
		</form>';
	}

	/**
	 * Handles the form POSTing
	 * 1) administration area, configurations
	 */
	private function _postProcess()
	{
		// administration section, config
		if (Tools::isSubmit('submitMaksuturva')) {
			$this->_postProcessConfigurations();
		}

		// any other administration content handling comes here
		// (for expansion purposes)
	}

	/**
	 * Administration:
	 * Handles the configuration administration updates
	 * and validations
	 */
	private function _postProcessConfigurations()
	{
		// seller id and secret key are validated if not in sandbox mode
		if (Tools::getValue('sandbox_mode') == "0") {
			if (strlen(Tools::getValue('seller_id_mks')) > 15 || strlen(Tools::getValue('seller_id_mks')) == 0) {
				$this->_errors[] = $this->l('Invalid Seller ID');
			}
			if (strlen(Tools::getValue('secret_key_mks')) == 0) {
				$this->_errors[] = $this->l('Invalid Secret Key');
			}
			if (!preg_match('/^[0-9]{3}$/', Tools::getValue('secret_key_version_mks'))) {
				$this->_errors[] = $this->l('Invalid Secret Key Version (should be numeric, 3 digits long)');
			}
		}
		if (!Validate::isUnsignedInt(Tools::getValue('secret_key_version_mks'))) {
			$this->_errors[] = $this->l('Invalid Secret Key Version');
		}
		if (Tools::getValue('mks_encoding') != "UTF-8" && Tools::getValue('mks_encoding') != "ISO-8859-1") {
			$this->_errors[] = $this->l('Invalid Encoding');
		}
		if (Tools::getValue('mks_emaksut') != "0" && Tools::getValue('mks_emaksut') != "1") {
			$this->_errors[] = $this->l('Invalid eMaksut flag');
		}
		if (Tools::getValue('sandbox_mode') != "0" && Tools::getValue('sandbox_mode') != "1") {
			$this->_errors[] = $this->l('Invalid Sandbox flag');
		}
		if (Tools::getValue('mks_communication_url') != NULL AND !Validate::isUrl(Tools::getValue('mks_communication_url'))) {
			$this->_errors[] = $this->l('Communication url is invalid');
		}

		if (!sizeof($this->_errors)) {
			Configuration::updateValue('MAKSUTURVA_SELLER_ID', Tools::getValue('seller_id_mks'));
			Configuration::updateValue('MAKSUTURVA_SECRET_KEY', trim(Tools::getValue('secret_key_mks')));
			Configuration::updateValue('MAKSUTURVA_SECRET_KEY_VERSION', Tools::getValue('secret_key_version_mks'));
			Configuration::updateValue('MAKSUTURVA_ENCODING', trim(Tools::getValue('mks_encoding')));
			Configuration::updateValue('MAKSUTURVA_EMAKSUT', trim(Tools::getValue('mks_emaksut')));
			Configuration::updateValue('MAKSUTURVA_SANDBOX', trim(Tools::getValue('sandbox_mode')));
			Configuration::updateValue('MAKSUTURVA_URL', trim(Tools::getValue('mks_communication_url')));
			$this->_html = $this->displayConfirmation($this->l('Settings updated'));
		} else {
			$error_msg = '';
			foreach ($this->_errors AS $error) {
				$error_msg .= $error.'<br />';
			}
			$this->_html = $this->displayError($error_msg);
		}
	}

	/**
	 * Processes the payment
	 * @param array $params
	 */
	public function hookPayment($params)
	{
		global $smarty;
		
		if (!$this->active) {
			return;
		}

		// only EUR is supported - we validate it against
		// 1) shop (if it has EUR)
		// 2) cart (if it has only EUR products within)
		if (!$this->checkCurrency($params['cart'])) {
			return;
		}

		// render the form
		$smarty->assign(
			array(
				'this_path' => $this->_path,
				'this_path_ssl' => Tools::getShopDomainSsl(true, true).__PS_BASE_URI__.'modules/'.$this->name.'/',
				'emaksut'  => Configuration::get('MAKSUTURVA_EMAKSUT'),
			)
		);

		return $this->display(__FILE__, 'payment.tpl');
	}

	/**
	 * Validates a paid or not-paid order, and redirect the
	 * user to the order confirmation with the updated status (paid, processing, error)
	 * @param Cart $cart
	 * @param Customer $customer
	 * @param array $parameters
	 */
	public function validatePayment($cart, $customer, $parameters)
	{
		// accumulate the errors
		$errors = array();
		$action = "ok";
		if (isset($parameters["delayed"]) && $parameters["delayed"] == "1") {
			$action = "delayed";
		} else if (isset($parameters["cancel"]) && $parameters["cancel"] == "1") {
			$action = "cancel";
		} else if (isset($parameters["error"]) && $parameters["error"] == "1") {
			$action = "error";
		}

		// test the currency: EUR only
		if (!$this->checkCurrency($cart)) {
			$errors[] = $this->l("The cart currency is not supported");
		}

		$totalPaid = 0;
		// regular payment
  	    switch ($action) {
  	    	case "cancel":
  	    		break;

  	    	case "error":
  	    		$errors[] = $this->l("There was an error processing your payment. Please, try again or contact Maksuturva/eMaksut.");
  	    		break;

  	    	case "delayed":
  	    		break;

  	    	// the default case tries to validate everything
  	    	case "ok":
  	    	default:
	  	    	$values = array();
	            // fields are mandatory, so we discard the request if it is empty
	            // Also when return through the error url given to maksuturva
	            foreach ($this->_mandatoryFields as $field) {
	            	if (isset($parameters[$field])) {
	            	    $values[$field] = $parameters[$field];
	                } else {
	                	$errors[] = $this->l("Missing payment field in response:") . " " . $field;
	                }
	            }

	  	    	// first, check if the cart id exists with the payment id provided
	      	    if (!isset($values['pmt_id']) || (intval($values['pmt_id']) - 100) != $cart->id) {
	      	    	$errors[] = $this->l("The payment didnt match any order.");
	    	    }

	    	    // then, check if the mk_status knows of such cart_id
	    	    if (count($this->getCartInMkStatusByIdCart($cart->id)) != 1) {
	    	    	$errors[] = $this->l("Could not find an order related to Maksuturva/eMaksut.");
	    	    }

	    		// now, validate the hash
	            require_once dirname(__FILE__) . '/MaksuturvaGatewayImplementation.php';
	            // instantiate the gateway with the original order
	        	$gateway = new MaksuturvaGatewayImplementation($cart->id, $cart, Configuration::get('MAKSUTURVA_ENCODING'), $this);
	    		// calculate the hash for order
	        	$calculatedHash = $gateway->generateReturnHash($values);
	        	// test the hash
	        	if (!($calculatedHash == $values['pmt_hash'])) {
	        		$errors[] = $this->l("The payment verification code does not match.");
	        	}

	        	// validate amounts, values, etc
	        	// fields which will be ignored
	        	$ignore = array("pmt_hash", "pmt_paymentmethod", "pmt_reference");
	        	foreach ($values as $key => $value) {
	        		// just pass if ignore is on
	        		if (in_array($key, $ignore)) {
	        			continue;
	        		}
	        		if ($gateway->{$key} != $value) {
	        			$errors[] = $this->l("The following field differs from your order: ") .
	        				$key .
	        				" (" . $this->l("obtained") . " " . $value . ", " . $this->l("expecting") . " " . $gateway->{$key} . ")";
	        		}
	        	}
	        	// pmt_reference is calculated
	        	if ($gateway->calcPmtReferenceCheckNumber() != $values["pmt_reference"]) {
	        		$errors[] = $this->l("One or more verification parameters could not be validated");
	        	}
	        	$totalPaid = (($gateway->pmt_amount != "") ? floatval(str_replace(",", ".", $gateway->pmt_amount)) : 0);
	        	break;
  	    }

  	    $message = "";
  	    // for actions "ok" and "error"
  	    if (count($errors) > 0) {
  	    	$id_order_state = Configuration::get('PS_OS_ERROR');
  	    	// assembly the error message
  	    	foreach ($errors as $error) {
  	    		$message .= $error . ". ";
  	    	}
  	    } else if ($action == "delayed") {
  	    	$id_order_state = Configuration::get('MAKSUTURVA_OS_AUTHORIZATION');
  	    	$message = $this->l("Your payment is awaiting confirmation");
  	    	$totalPaid = $cart->getOrderTotal();
  	    } else if ($action == "cancel") {
  	    	$id_order_state = Configuration::get('PS_OS_CANCELED');
  	    	$message = $this->l("Your payment was canceled");
  	    } else {
  	    	$id_order_state = Configuration::get('PS_OS_PAYMENT');
  	    	$message = $this->l("Your payment was successfully registered");
  	    }
		// Get current reference number
		require_once dirname(__FILE__) . '/MaksuturvaGatewayImplementation.php';
		$gateway = new MaksuturvaGatewayImplementation($cart->id, $cart, Configuration::get('MAKSUTURVA_ENCODING'), $this);
		$this->displayName .= ' PMT: ' . $gateway->getReferenceNumber($cart->id);
		
  	    // convert the message
  	    $message = Tools::htmlentitiesUTF8(str_replace('\'', '', $message));
  	    // finally, validate the order with error or not
  	    $this->validateOrder($cart->id, $id_order_state, $cart->getOrderTotal(), $this->displayName , $message, array(), $cart->id_currency, false, $customer->secure_key);
		// fetch the recent-created order
		$order = new Order((int)($this->currentOrder));
		// attatch to mk_status
		$this->updateCartInMkStatusByIdCart($cart->id, (int)($this->currentOrder), $id_order_state);
		// redirect to display messages for this given order
		Tools::redirectLink(__PS_BASE_URI__.'order-confirmation.php?id_cart='.(int)($cart->id).'&id_module='.(int)$this->id.'&id_order='.(int)($this->currentOrder).'&key='.$customer->secure_key.'&mks_msg=' . $message);
	}

	/**
	 * Used in order-confirmation.tpl to display a payment successful (or pending) message
	 * @param array $params
	 */
	public function hookPaymentReturn($params)
	{
		global $smarty;

		if (!$this->active) {
			return;
		}

		$state = $params['objOrder']->getCurrentState();
		switch ($state) {
			case Configuration::get('MAKSUTURVA_OS_AUTHORIZATION'):
				$status = "pending";
				break;

			case Configuration::get('PS_OS_PAYMENT'):
				$status = "ok";
				break;

			case Configuration::get('PS_OS_CANCELED'):
				$status = "cancel";
				break;

			case Configuration::get('PS_OS_ERROR'):
			default:
				$status = "error";
				break;

		}
		$smarty->assign(array(
			'this_path' => $this->_path,
			'this_path_ssl' => Tools::getShopDomainSsl(true, true).__PS_BASE_URI__.'modules/'.$this->name.'/',
			'emaksut'  => Configuration::get('MAKSUTURVA_EMAKSUT'),
			'status' => $status,
			'message' => str_replace('. ', '.<br/>', Tools::getValue('mks_msg'))
		));
		return $this->display(__FILE__, 'payment_return.tpl');
	}

	/**
	 * Automatically verifies the order status in maksuturva
	 * @param array $params
	 */
	public function hookAdminOrder($params)
	{
		global $smarty;

		$mkStatus = $this->getCartInMkStatusByIdOrder($params["id_order"]);
		if (!$mkStatus || count($mkStatus) != 1) {
			return;
		}
		
		$order = new Order(intval($params["id_order"]));
		$cart = new Cart(intval($order->id_cart));

		$status = $mkStatus[0];
		$checkAgain = false;
		switch ($status["payment_status"]) {
			// only when not set (NULL or 0) or auth
			case Configuration::get('MAKSUTURVA_OS_AUTHORIZATION'):
			case "":
			case "0":
				require_once dirname(__FILE__) . "/MaksuturvaGatewayImplementation.php";
				$gateway = new MaksuturvaGatewayImplementation($cart->id, $cart, Configuration::get('MAKSUTURVA_ENCODING'), $this);

				$newStatus = $status["payment_status"];
				$messages = array();

				try {
		    		$response = $gateway->statusQuery();
		    	} catch (Exception $e) {
		    		$response = false;
		    	}

		    	// errors
		    	if ($response === false) {
		    		$messages[] = $this->l("Error while communicating with maksuturva: Invalid hash or network error.");
		    		$checkAgain = true;
		    	} else {
			    	switch ($response["pmtq_returncode"]) {
			    		// set as paid if not already set
			    		case MaksuturvaGatewayImplementation::STATUS_QUERY_PAID:
			    		case MaksuturvaGatewayImplementation::STATUS_QUERY_PAID_DELIVERY:
			    		case MaksuturvaGatewayImplementation::STATUS_QUERY_COMPENSATED:
			    			$this->updateCartInMkStatusByIdCart($cart->id, $params["id_order"], Configuration::get('PS_OS_PAYMENT'));
			    			// try to change order's status
			    			if (intval($status["id_order"]) != 0) {
			    				$order = new Order(intval($status["id_order"]));
			    				$order->setCurrentState(Configuration::get('PS_OS_PAYMENT'));
			    			} else {
			    				$confirmMessage = $this->l("Payment confirmed by Maksuturva");
			    				$this->validateOrder($cart->id, Configuration::get('PS_OS_PAYMENT'), $cart->getOrderTotal(), $this->displayName, $confirmMessage, array(), $cart->id_currency, false, $customer->secure_key);
			    			}
			    			$messages[] = $this->l("The payment confirmation was received - payment accepted");
			    			break;

			    		// set payment cancellation with the notice
			    		// stored in response_text
			    		case MaksuturvaGatewayImplementation::STATUS_QUERY_PAYER_CANCELLED:
		    			case MaksuturvaGatewayImplementation::STATUS_QUERY_PAYER_CANCELLED_PARTIAL:
		    			case MaksuturvaGatewayImplementation::STATUS_QUERY_PAYER_CANCELLED_PARTIAL_RETURN:
		    			case MaksuturvaGatewayImplementation::STATUS_QUERY_PAYER_RECLAMATION:
		    			case MaksuturvaGatewayImplementation::STATUS_QUERY_CANCELLED:
		    				$this->updateCartInMkStatusByIdCart($cart->id, $params["id_order"], Configuration::get('PS_OS_CANCELED'));
					    	// try to change order's status
			    			if (intval($status["id_order"]) != 0) {
			    				$order = new Order(intval($status["id_order"]));
			    				$order->setCurrentState(Configuration::get('PS_OS_CANCELED'));
			    			} else {
			    				$confirmMessage = $this->l("Payment canceled in Maksuturva");
			    				$this->validateOrder($cart->id, Configuration::get('PS_OS_CANCELED'), $cart->getOrderTotal(), $this->displayName, $confirmMessage, array(), $cart->id_currency, false, $customer->secure_key);
			    			}

			    			$messages[] = $this->l("The payment was canceled in Maksuturva");
		    				break;

		    			// this is the case where the buyer changed the payment method while the
		    			// mk_status was created: we stop checking the order
		    			case MaksuturvaGatewayImplementation::STATUS_QUERY_NOT_FOUND:
		    				$messages[] = $this->l("The payment could not be tracked by Maksuturva. Check if the customer selected Maksuturva as payment method");
		    				$this->updateCartInMkStatusByIdCart($cart->id, $params["id_order"], Configuration::get('PS_OS_CANCELED'));
		    				break;

		    	        // no news for buyer and seller
			    		case MaksuturvaGatewayImplementation::STATUS_QUERY_FAILED:
			    		case MaksuturvaGatewayImplementation::STATUS_QUERY_WAITING:
		    			case MaksuturvaGatewayImplementation::STATUS_QUERY_UNPAID:
		    			case MaksuturvaGatewayImplementation::STATUS_QUERY_UNPAID_DELIVERY:
		    			default:
		    				$messages[] = $this->l("The payment is still awaiting for confirmation");
		    				$checkAgain = true;
			    			break;
			    	}
		    	}
				break;

			case Configuration::get('PS_OS_PAYMENT'):
				$messages[] = $this->l("The payment was confirmed by Maksuturva/eMaksut");
				break;

			case Configuration::get('PS_OS_CANCELED'):
				if (intval($status["id_order"]) != 0) {
					$order = new Order(intval($status["id_order"]));
					if ($order->getCurrentState() != Configuration::get('PS_OS_CANCELED')) {
						$messages[] = $this->l("The payment could not be tracked by Maksuturva. Check if the customer selected Maksuturva as payment method.");
					} else {
						$messages[] = $this->l("The payment was canceled by the customer");
					}
				} else {
					$messages[] = $this->l("The payment was canceled by the customer");
				}
				break;

			case Configuration::get('PS_OS_ERROR'):
			default:
				$messages[] = $this->l("An error occurred and the payment was not confirmed. Please check manually.");
				$checkAgain = true;
				break;
		}

		$messageHtml = "";
		foreach ($messages as $message) {
			$messageHtml .= "<p style='font-weight: bold;'>" . $message . "</p>";
		}
		if ($checkAgain) {
			$messageHtml .= "<p style='text-decoration: underline;'>" . $this->l("Refresh this page to check again.") . "</p>";
		}

		$html = "<br/>
		<fieldset>
		    <legend>
		    	<img src='" . $this->_path . "/logo.png' width='20'/>" . $this->l("Payment status update") . "</legend>
		    " . $messageHtml . "
		</fieldset>
		";
		return $html;
	}

	/**
	 * Validates if cart's currency is given in Euros
	 * @param Cart $cart
	 * @return boolean
	 */
	public function checkCurrency($cart)
	{
		$currency_order = new Currency($cart->id_currency);
		$currencies_module = $this->getCurrency($cart->id_currency);

		// only euro is available
		if (is_array($currencies_module)) {
			foreach ($currencies_module as $currency_module) {
				if ($currency_order->id == $currency_module['id_currency'] && strtoupper($currency_order->iso_code) == "EUR") {
					return true;
				}
			}
		}
		return false;
	}


	/**
	 * Tries to insert or update an order for follow up within mk_status
	 * @param int $id_cart
	 * @param int $id_order
	 * @param int $status
	 */
	public function updateCartInMkStatusByIdCart($id_cart, $id_order = NULL, $status = 0)
	{
		// if already in DB
		if (count($this->getCartInMkStatusByIdCart($id_cart)) == 1) {
			$this->_updateCartInMkStatusByColumn("id_cart", $id_cart, $id_order, $status);
		// create, otherwise
		} else {
			Db::getInstance()->execute(
				'INSERT INTO `'._DB_PREFIX_.'mk_status` (`id_cart`, `id_order`, `payment_status`) ' .
				'VALUES ( ' .
					intval($id_cart) . ', '.
					($id_order == NULL ? "NULL" : intval($id_order)) . ', ' .
					intval($status) .
				' );'
			);
		}
	}

	/**
	 * Updates an entry in mk_status given a cart
	 * @param string $col Column name
	 * @param int $id_cart
	 * @param int $id_order
	 * @param int $status
	 */
	private function _updateCartInMkStatusByColumn($col, $id_cart, $id_order = NULL, $status = 0)
	{
		if ($col == "id_order") {
			$where = 'id_order = ' . intval($id_cart);
		} else {
			$where = 'id_cart = ' . intval($id_cart);
		}

		Db::getInstance()->execute(
			'UPDATE `'._DB_PREFIX_.'mk_status` SET ' .
			 	'id_cart = ' . intval($id_cart) . ', '.
			 	'id_order = ' . ($id_order == NULL ? "NULL" : intval($id_order)) . ', ' .
				'payment_status = ' . intval($status) . ' ' .
			'WHERE ' . $where
		);
	}

	/**
	 * Fetches a follow up item from mk_status given a cart
	 * @param int $id_cart
	 */
	public function getCartInMkStatusByIdCart($id_cart)
	{
		return Db::getInstance()->s('SELECT * FROM `'._DB_PREFIX_.'mk_status` WHERE id_cart = ' . intval($id_cart) . ';');
	}

	/**
	 * Fetches a follow up item from mk_status given an order
	 * @param int $id_cart
	 */
	public function getCartInMkStatusByIdOrder($id_order)
	{
		return Db::getInstance()->s('SELECT * FROM `'._DB_PREFIX_.'mk_status` WHERE id_order = ' . intval($id_order) . ';');
	}

	/**
	 * Fetches the rows with a given status
	 * @param int $status
	 */
	public function getCartsInMkStatusByStatus($status)
	{
		return Db::getInstance()->s('SELECT * FROM `'._DB_PREFIX_.'mk_status` WHERE status = ' . intval($status) . ';');
	}
}