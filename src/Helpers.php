<?php
use EceoPos\Factory\AccountFactory;
use EceoPos\Gateways\AbstractGateway;
use App\Models\SiteSetting;

if (! function_exists('checkCardType')) {
	function checkCardType($number)
	{
		$number = str_replace(' ', '', $number);

		$cardType = [
			'visa' => '/^4[0-9]{12}(?:[0-9]{3})?$/',
			'master' => '/^5[1-5][0-9]{14}$/',
			'amex' => '/^3[47][0-9]{13}$/',
			'troy' => "/^(?:9792|65\d{2}|36|2205)\d{12}$/",
		];

		foreach ($cardType as $key => $value) {
			if (preg_match($value, $number)) {
				$type = $key;
				break;
			} else {
				$type = null;
			}
		}

		return $type;
	}
}

if (! function_exists('bankSettings')) {
	function bankSettings($name)
	{
		$value = SiteSetting::where('name', $name);

		if ($value->count() > 0) {
			return $value->first()->value;
		} else {
			return null;
		}
	}
}

if (! function_exists('createPosAccount')) {
	function createPosAccount($bank, $status)
	{
		$config = require config_path('pos-settings.php'); // Ayarları config/pos-settings.php dosyasından çekiyor. Kurulumdan sonra config/pos-settings.php dosyasını düzenleyin.

		if ($bank == 'halkbank') {
			$account = AccountFactory::createESTVirtualPosAccount(
				$bank,
				$config['banks'][$bank]['accounts'][$status]['client_id'],
				$config['banks'][$bank]['accounts'][$status]['username'],
				$config['banks'][$bank]['accounts'][$status]['password'],
				AbstractGateway::MODEL_3D_SECURE,
				$config['banks'][$bank]['accounts'][$status]['store_key'],
				AbstractGateway::LANG_TR
			);
		} elseif ($bank == 'akbank') {
			$account = AccountFactory::createESTVirtualPosAccount(
				$bank,
				$config['banks'][$bank]['accounts'][$status]['client_id'],
				$config['banks'][$bank]['accounts'][$status]['username'],
				$config['banks'][$bank]['accounts'][$status]['password'],
				AbstractGateway::MODEL_3D_SECURE,
				$config['banks'][$bank]['accounts'][$status]['store_key'],
				AbstractGateway::LANG_TR
			);
		} elseif ($bank == 'isbank') {
			$account = AccountFactory::createESTVirtualPosAccount(
				$bank,
				$config['banks'][$bank]['accounts'][$status]['client_id'],
				$config['banks'][$bank]['accounts'][$status]['username'],
				$config['banks'][$bank]['accounts'][$status]['password'],
				AbstractGateway::MODEL_3D_SECURE,
				$config['banks'][$bank]['accounts'][$status]['store_key'],
				AbstractGateway::LANG_TR
			);
		} elseif ($bank == 'qnbfinansbank-payfor') {
			$account = AccountFactory::createPayForVirtualPosAccount(
				$bank,
				$config['banks'][$bank]['accounts'][$status]['client_id'],
				$config['banks'][$bank]['accounts'][$status]['username'],
				$config['banks'][$bank]['accounts'][$status]['password'],
				AbstractGateway::MODEL_3D_SECURE,
				$config['banks'][$bank]['accounts'][$status]['store_key']
			);
		} elseif ($bank == 'garanti') {
			$account = AccountFactory::createGarantiVirtualPosAccount(
				$bank,
				$config['banks'][$bank]['accounts'][$status]['client_id'],
				$config['banks'][$bank]['accounts'][$status]['username'],
				$config['banks'][$bank]['accounts'][$status]['password'],
				$config['banks'][$bank]['accounts'][$status]['terminal_number'],
				AbstractGateway::MODEL_3D_SECURE,
				$config['banks'][$bank]['accounts'][$status]['store_key']
			);
		} elseif ($bank == 'yapikredi') {
			$account = AccountFactory::createPosNetVirtualPosAccount(
				$bank,
				$config['banks'][$bank]['accounts'][$status]['merchant_number'],
				$config['banks'][$bank]['accounts'][$status]['username'],
				$config['banks'][$bank]['accounts'][$status]['password'],
				$config['banks'][$bank]['accounts'][$status]['terminal_number'],
				$config['banks'][$bank]['accounts'][$status]['posnet_id'],
				AbstractGateway::MODEL_3D_SECURE,
				$config['banks'][$bank]['accounts'][$status]['enc_key']
			);
		}

		if (isset($account)) {
			return $account;
		} else {
			return null;
		}
	}
}
