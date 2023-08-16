<?php
/**
 * @license MIT
 */
namespace EceoPos\Gateways;

use DOMDocument;
use DOMNodeList;
use Exception;
use EceoPos\Client\HttpClient;
use EceoPos\DataMapper\AbstractRequestDataMapper;
use EceoPos\DataMapper\KuveytVirtualPosRequestDataMapper;
use EceoPos\Entity\Account\AbstractPosAccount;
use EceoPos\Entity\Account\KuveytVirtualPosAccount;
use EceoPos\Entity\Card\AbstractCreditCard;
use EceoPos\Exceptions\NotImplementedException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\HttpFoundation\Request;

/**
 * Kuveyt banki desteleyen Gateway
 */
class KuveytVirtualPos extends AbstractGateway
{
	public const NAME = 'KuveytVirtualPos';
	/**
	 * Response Codes
	 * @var array
	 */
	protected $codes = [
		'00'                => 'approved',
		'ApiUserNotDefined' => 'invalid_transaction',
		'EmptyMDException'  => 'invalid_transaction',
		'HashDataError'     => 'invalid_transaction',
	];
	/** @var KuveytVirtualPosAccount */
	protected $account;
	/** @var AbstractCreditCard|null */
	protected $card;
	/** @var KuveytVirtualPosRequestDataMapper */
	protected $requestDataMapper;

	/**
	 * @param KuveytVirtualPosAccount $account
	 * @param KuveytVirtualPosRequestDataMapper $requestDataMapper
	 */
	public function __construct(
		array $config, AbstractPosAccount $account, AbstractRequestDataMapper $requestDataMapper, HttpClient $client, LoggerInterface $logger)
	{
		parent::__construct($config, $account, $requestDataMapper, $client, $logger);
	}

	/**
	 * {@inheritDoc}
	 */
	public function createXML(array $nodes, string $encoding = 'ISO-8859-1', bool $ignorePiNode = false): string
	{
		return parent::createXML(['KuveytTurkVPosMessage' => $nodes], $encoding, $ignorePiNode);
	}

	/**
	 * @return KuveytVirtualPosAccount
	 */
	public function getAccount(): KuveytVirtualPosAccount
	{
		return $this->account;
	}

	/**
	 * {@inheritDoc}
	 */
	public function send($contents, string $url = null)
	{
		$url = $url ? : $this->getApiURL();
		$this->logger->log(LogLevel::DEBUG, 'sending request', ['url' => $url]);
		$body = [
			'body'    => $contents,
			'headers' => [
				'Content-Type' => 'text/xml; charset=UTF-8',
			],
		];
		$response = $this->client->post($url, $body);
		$this->logger->log(LogLevel::DEBUG, 'request completed', ['status_code' => $response->getStatusCode()]);
		$responseBody = $response->getBody()->getContents();
		try {
			$this->data = $this->XMLStringToArray($responseBody);
		} catch (Exception $e) {
			if (!$this->isHTML($responseBody)) {
				throw new Exception($responseBody);
			}
			//icinde form olan HTML response dondu
			$this->data = $responseBody;
		}
		return $this->data;
	}

	/**
	 * todo implement method
	 * @param AbstractPosAccount $account
	 * @param array $data
	 * @return bool
	 */
	public function check3DHash(AbstractPosAccount $account, array $data): bool
	{
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function make3DPayment(Request $request)
	{
		$gatewayResponse = $request->request->get('AuthenticationResponse');
		$gatewayResponse = urldecode($gatewayResponse);
		$gatewayResponse = $this->XMLStringToArray($gatewayResponse);
		$bankResponse = null;
		$procReturnCode = $this->getProcReturnCode($gatewayResponse);
		if ($this->check3DHash($this->account, $gatewayResponse)) {
			if ('00' === $procReturnCode) {
				$this->logger->log(LogLevel::DEBUG, 'finishing payment');
				$contents = $this->create3DPaymentXML($gatewayResponse);
				$bankResponse = $this->send($contents);
			} else {
				$this->logger->log(LogLevel::ERROR, '3d auth fail', ['proc_return_code' => $procReturnCode]);
			}
		}
		$authorizationResponse = $this->emptyStringsToNull($bankResponse);
		$this->response = (object)$this->map3DPaymentData($gatewayResponse, $authorizationResponse);
		$this->logger->log(LogLevel::DEBUG, 'finished 3D payment', ['mapped_response' => $this->response]);
		return $this;
	}

	/**
	 * {@inheritDoc}
	 */
	public function make3DPayPayment(Request $request)
	{
		throw new NotImplementedException();
	}

	/**
	 * {@inheritDoc}
	 */
	public function make3DHostPayment(Request $request)
	{
		return $this->make3DPayPayment($request);
	}

	/**
	 * Deniz bank dokumantasyonunda history sorgusu ile alakali hic bir bilgi yok
	 * {@inheritDoc}
	 */
	public function history(array $meta)
	{
		throw new NotImplementedException();
	}

	/**
	 * {@inheritDoc}
	 */
	public function get3DFormData(): array
	{
		$gatewayUrl = $this->get3DGatewayURL();
		$this->logger->log(LogLevel::DEBUG, 'preparing 3D form data');
		return $this->getCommon3DFormData($this->account, $this->order, $this->type, $gatewayUrl, $this->card);
	}

	/**
	 * {@inheritDoc}
	 */
	public function create3DPaymentXML($responseData)
	{
		$data = $this->requestDataMapper->create3DPaymentRequestData($this->account, $this->order, $this->type, $responseData);
		return $this->createXML($data);
	}

	/**
	 * {@inheritDoc}
	 */
	public function createRegularPaymentXML()
	{
		throw new NotImplementedException();
	}

	/**
	 * {@inheritDoc}
	 */
	public function createRegularPostXML()
	{
		throw new NotImplementedException();
	}

	/**
	 * {@inheritDoc}
	 */
	public function createHistoryXML($customQueryData)
	{
		throw new NotImplementedException();
	}

	/**
	 * {@inheritDoc}
	 */
	public function createStatusXML()
	{
		throw new NotImplementedException();
	}

	/**
	 * {@inheritDoc}
	 */
	public function createCancelXML()
	{
		throw new NotImplementedException();
	}

	/**
	 * {@inheritDoc}
	 */
	public function createRefundXML()
	{
		throw new NotImplementedException();
	}

	/**
	 * Get ProcReturnCode
	 * @param array $response
	 * @return string|null
	 */
	protected function getProcReturnCode(array $response): ?string
	{
		return $response['ResponseCode'] ?? null;
	}

	/**
	 * Get Status Detail Text
	 * @param string|null $procReturnCode
	 * @return string|null
	 */
	protected function getStatusDetail(?string $procReturnCode): ?string
	{
		return $procReturnCode ? ($this->codes[$procReturnCode] ?? $procReturnCode) : null;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function map3DPaymentData($raw3DAuthResponseData, $rawPaymentResponseData): array
	{
		$this->logger->log(LogLevel::DEBUG, 'mapping 3D payment data', [
			'3d_auth_response'   => $raw3DAuthResponseData,
			'provision_response' => $rawPaymentResponseData,
		]);
		$threeDResponse = $this->tDPayResponseCommon($raw3DAuthResponseData);
		if (empty($rawPaymentResponseData)) {
			return array_merge($this->getDefaultPaymentResponse(), $threeDResponse);
		}
		$paymentResponseData = $this->mapPaymentResponse($rawPaymentResponseData);
		return $this->mergeArraysPreferNonNullValues($threeDResponse, $paymentResponseData);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function mapPaymentResponse($responseData): array
	{
		$this->logger->log(LogLevel::DEBUG, 'mapping payment response', [$responseData]);
		$responseData = (array)$responseData;
		if (isset($responseData['VPosMessage'])) {
			$responseData['VPosMessage'] = (array)$responseData['VPosMessage'];
		}
		$responseData = $this->emptyStringsToNull($responseData);
		$status = 'declined';
		$procReturnCode = $this->getProcReturnCode($responseData);
		if ('00' === $procReturnCode) {
			$status = 'approved';
		}
		$result = $this->getDefaultPaymentResponse();
		$result['proc_return_code'] = $procReturnCode;
		$result['code'] = $procReturnCode;
		$result['status'] = $status;
		$result['status_detail'] = $this->getStatusDetail($procReturnCode);
		$result['all'] = $responseData;
		if ('approved' !== $status) {
			$result['error_code'] = $procReturnCode;
			$result['error_message'] = $responseData['ResponseMessage'];
			$result['response'] = 'Declined';
			$this->logger->log(LogLevel::DEBUG, 'mapped payment response', $result);
			return $result;
		}
		$result['id'] = $responseData['ProvisionNumber'];
		$result['auth_code'] = $responseData['ProvisionNumber'];
		$result['order_id'] = $responseData['MerchantOrderId'];
		$result['host_ref_num'] = $responseData['RRN'];
		$result['amount'] = $responseData['VPosMessage']['Amount'];
		$result['currency'] = array_search($responseData['VPosMessage']['CurrencyCode'], $this->requestDataMapper->getCurrencyMappings());
		$result['masked_number'] = $responseData['VPosMessage']['CardNumber'];
		$this->logger->log(LogLevel::DEBUG, 'mapped payment response', $result);
		return $result;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function map3DPayResponseData($raw3DAuthResponseData)
	{
		return $this->map3DPaymentData($raw3DAuthResponseData, $raw3DAuthResponseData);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function mapRefundResponse($rawResponseData)
	{
		throw new NotImplementedException();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function mapCancelResponse($rawResponseData)
	{
		throw new NotImplementedException();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function mapStatusResponse($rawResponseData)
	{
		throw new NotImplementedException();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function mapHistoryResponse($rawResponseData)
	{
		return $rawResponseData;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function preparePaymentOrder(array $order)
	{
		return (object)array_merge($order, [
			'installment' => $order['installment'] ?? 0,
			'currency'    => $order['currency'] ?? 'TRY',
		]);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function preparePostPaymentOrder(array $order)
	{
		throw new NotImplementedException();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function prepareStatusOrder(array $order)
	{
		return (object)$order;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function prepareHistoryOrder(array $order)
	{
		return (object)$order;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function prepareCancelOrder(array $order)
	{
		return (object)$order;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function prepareRefundOrder(array $order)
	{
		return (object)$order;
	}

	/**
	 * @param KuveytVirtualPosAccount $account
	 * @param    $order
	 * @param string $txType
	 * @param string $gatewayURL
	 * @param AbstractCreditCard|null $card
	 * @return array
	 * @throws Exception
	 */
	private function getCommon3DFormData(KuveytVirtualPosAccount $account, $order, string $txType, string $gatewayURL, ?AbstractCreditCard $card = null): array
	{
		if (!$order) {
			return [];
		}
		$formData = $this->requestDataMapper->create3DEnrollmentCheckRequestData($account, $order, $txType, $card);
		$xml = $this->createXML($formData);
		$bankResponse = $this->send($xml, $gatewayURL);
		return $this->transformReceived3DFormData($bankResponse);
	}

	/**
	 * Diger Gateway'lerden farkli olarak bu gateway HTML form olan bir response doner.
	 * Kutupahenin islem akisina uymasi icin bu HTML form verilerini array'e donusturup, kendimiz post ediyoruz.
	 * @param string $response
	 * @return array
	 */
	private function transformReceived3DFormData(string $response): array
	{
		$dom = new DOMDocument();
		$dom->loadHTML($response);
		$gatewayURL = '';
		$formNode = $dom->getElementsByTagName('form')->item(0);
		for ($i = 0; $i < $formNode->attributes->length; $i++) {
			if ('action' === $formNode->attributes->item($i)->name) {
				/**
				 * banka onayladiginda gatewayURL=bankanin gateway url
				 * onaylanmadiginda (hatali istek oldugunda) ise gatewayURL = istekte yer alan failURL
				 */
				$gatewayURL = $formNode->attributes->item($i)->value;
				break;
			}
		}
		$els = $dom->getElementsByTagName('input');
		$inputs = $this->builtInputsFromHTMLDoc($els);
		return [
			'gateway' => $gatewayURL,
			'inputs'  => $inputs,
		];
	}

	/**
	 * html form'da gelen input degeleri array'e donusturur
	 * @param DOMNodeList $inputNodes
	 * @return array
	 */
	private function builtInputsFromHTMLDoc(DOMNodeList $inputNodes): array
	{
		$inputs = [];
		foreach ($inputNodes as $el) {
			$key = null;
			$value = null;
			for ($i = 0; $i < $el->attributes->length; $i++) {
				if ('name' === $el->attributes->item($i)->name) {
					$key = $el->attributes->item($i)->value;
				}
				if ('value' === $el->attributes->item($i)->name) {
					$value = $el->attributes->item($i)->value;
				}
			}
			if ($key && $value) {
				$inputs[$key] = $value;
			}
		}
		unset($inputs['submit']);
		return $inputs;
	}

	/**
	 * @param array $raw3DAuthResponseData
	 * @return array
	 */
	private function tDPayResponseCommon(array $raw3DAuthResponseData): array
	{
		$raw3DAuthResponseData = $this->emptyStringsToNull($raw3DAuthResponseData);
		$procReturnCode = $this->getProcReturnCode($raw3DAuthResponseData);
		$status = 'declined';
		$response = 'Declined';
		if ('00' === $procReturnCode) {
			$status = 'approved';
			$response = 'Approved';
		}
		$transactionSecurity = 'MPI fallback';
		if (isset($raw3DAuthResponseData['VPosMessage'])) {
			$orderId = $raw3DAuthResponseData['VPosMessage']['MerchantOrderId'];
		} else {
			$orderId = $raw3DAuthResponseData['MerchantOrderId'];
		}
		$default = [
			'order_id'             => $orderId,
			'response'             => $response,
			'transaction_type'     => $this->type,
			'transaction'          => empty($this->type) ? null : $this->requestDataMapper->mapTxType($this->type),
			'transaction_security' => $transactionSecurity,
			'proc_return_code'     => $procReturnCode,
			'code'                 => $procReturnCode,
			'md_status'            => null,
			'status'               => $status,
			'status_detail'        => $this->getStatusDetail($procReturnCode),
			'hash'                 => null,
			'rand'                 => null,
			'hash_params'          => null,
			'hash_params_val'      => null,
			'amount'               => null,
			'currency'             => null,
			'tx_status'            => null,
			'error_code'           => 'approved' !== $status ? $procReturnCode : null,
			'md_error_message'     => 'approved' !== $status ? $raw3DAuthResponseData['ResponseMessage'] : null,
			'3d_all'               => $raw3DAuthResponseData,
		];
		if ('approved' === $status) {
			$default['hash'] = $raw3DAuthResponseData['VPosMessage']['HashData'] ?? $raw3DAuthResponseData['HashData'];
			$default['amount'] = $raw3DAuthResponseData['VPosMessage']['Amount'];
			$default['currency'] = array_search($raw3DAuthResponseData['VPosMessage']['CurrencyCode'], $this->requestDataMapper->getCurrencyMappings());
			$default['masked_number'] = $raw3DAuthResponseData['VPosMessage']['CardNumber'];
		}
		return $default;
	}
}
