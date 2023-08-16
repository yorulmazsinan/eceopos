<?php
/**
 * @license MIT
 */
namespace EceoPos\Gateways;

use EceoPos\Client\HttpClient;
use EceoPos\DataMapper\AbstractRequestDataMapper;
use EceoPos\DataMapper\InterVirtualPosRequestDataMapper;
use EceoPos\Entity\Account\AbstractPosAccount;
use EceoPos\Entity\Account\InterVirtualPosAccount;
use EceoPos\Entity\Card\AbstractCreditCard;
use EceoPos\Exceptions\NotImplementedException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\HttpFoundation\Request;

/**
 * Deniz bankin desteklidigi Gateway
 * Class InterVirtualPos
 */
class InterVirtualPos extends AbstractGateway
{
	/**
	 * @const string
	 */
	public const NAME = 'InterVirtualPos';
	/**
	 * Response Codes
	 * @var array
	 */
	protected $codes = [
		'00'  => 'approved',
		'81'  => 'bank_call',
		'E31' => 'invalid_transaction',
		'E39' => 'invalid_transaction',
	];
	/** @var InterVirtualPosAccount */
	protected $account;
	/** @var AbstractCreditCard|null */
	protected $card;
	/** @var InterVirtualPosRequestDataMapper */
	protected $requestDataMapper;

	/**
	 * @param InterVirtualPosAccount $account
	 * @param InterVirtualPosRequestDataMapper $requestDataMapper
	 */
	public function __construct(
		array $config, AbstractPosAccount $account, AbstractRequestDataMapper $requestDataMapper, HttpClient $client, LoggerInterface $logger)
	{
		parent::__construct($config, $account, $requestDataMapper, $client, $logger);
	}

	/**
	 * @return InterVirtualPosAccount
	 */
	public function getAccount(): InterVirtualPosAccount
	{
		return $this->account;
	}

	/**
	 * {@inheritDoc}
	 */
	public function send($contents, ?string $url = null)
	{
		$url = $url ? : $this->getApiURL();
		$this->logger->log(LogLevel::DEBUG, 'sending request', ['url' => $url]);
		$response = $this->client->post($url, ['form_params' => $contents]);
		$this->logger->log(LogLevel::DEBUG, 'request completed', ['status_code' => $response->getStatusCode()]);
		//genelde ;; delimiter kullanilmis, ama bazen arasinda ;;; boyle delimiter de var.
		$resultValues = preg_split('/(;;;|;;)/', $response->getBody()->getContents());
		$result = [];
		foreach ($resultValues as $val) {
			[$key, $value] = explode('=', $val);
			$result[$key] = $value;
		}
		$this->data = $result;
		return $this->data;
	}

	/**
	 * Check 3D Hash
	 * @param AbstractPosAccount $account
	 * @param array $data
	 * @return bool
	 */
	public function check3DHash(AbstractPosAccount $account, array $data): bool
	{
		$hashParams = $data['HASHPARAMS'];
		$actualHashParamsVal = $data['HASHPARAMSVAL'];
		$actualHash = $data['HASH'];
		$calculatedHashParamsVal = '';
		$hashParamsArr = explode(':', $hashParams);
		foreach ($hashParamsArr as $value) {
			if (!empty($value) && isset($data[$value])) {
				$calculatedHashParamsVal .= $data[$value];
			}
		}
		$hashStr = $calculatedHashParamsVal . $account->getStoreKey();
		$hash = $this->hashString($hashStr);
		if ($actualHash === $hash) {
			$this->logger->log(LogLevel::DEBUG, 'hash check is successful');
			return true;
		}
		$this->logger->log(LogLevel::ERROR, 'hash check failed', [
			'data'           => $data,
			'generated_hash' => $hash,
			'expected_hash'  => $actualHash,
		]);
		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function make3DPayment(Request $request)
	{
		$bankResponse = null;
		$request = $request->request;
		$gatewayResponse = $this->emptyStringsToNull($request->all());
		$procReturnCode = $this->getProcReturnCode($gatewayResponse);
		if ($this->check3DHash($this->account, $gatewayResponse)) {
			if ('00' !== $procReturnCode) {
				$this->logger->log(LogLevel::ERROR, '3d auth fail', ['proc_return_code' => $procReturnCode]);
				/**
				 * TODO hata durumu ele alinmasi gerekiyor
				 */
			}
			$this->logger->log(LogLevel::DEBUG, 'finishing payment');
			$contents = $this->create3DPaymentXML($gatewayResponse);
			$bankResponse = $this->send($contents);
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
		$gatewayResponse = $this->emptyStringsToNull($request->request->all());
		$this->response = $this->map3DPayResponseData($gatewayResponse);
		return $this;
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
		if (!$this->order) {
			$this->logger->log(LogLevel::ERROR, 'tried to get 3D form data without setting order');
			return [];
		}
		$gatewayUrl = $this->get3DHostGatewayURL();
		if (self::MODEL_3D_SECURE === $this->account->getModel()) {
			$gatewayUrl = $this->get3DGatewayURL();
		} elseif (self::MODEL_3D_PAY === $this->account->getModel()) {
			$gatewayUrl = $this->get3DGatewayURL();
		}
		$this->logger->log(LogLevel::DEBUG, 'preparing 3D form data');
		return $this->requestDataMapper->create3DFormData($this->account, $this->order, $this->type, $gatewayUrl, $this->card);
	}

	/**
	 * {@inheritDoc}
	 */
	public function createRegularPaymentXML()
	{
		return $this->requestDataMapper->createNonSecurePaymentRequestData($this->account, $this->order, $this->type, $this->card);
	}

	/**
	 * {@inheritDoc}
	 */
	public function createRegularPostXML()
	{
		return $this->requestDataMapper->createNonSecurePostAuthPaymentRequestData($this->account, $this->order);
	}

	/**
	 * {@inheritDoc}
	 */
	public function create3DPaymentXML($responseData)
	{
		return $this->requestDataMapper->create3DPaymentRequestData($this->account, $this->order, $this->type, $responseData);
	}

	/**
	 * {@inheritDoc}
	 */
	public function createHistoryXML($customQueryData)
	{
		return $this->requestDataMapper->createHistoryRequestData($this->account, $this->order, $customQueryData);
	}

	/**
	 * {@inheritDoc}
	 */
	public function createStatusXML()
	{
		return $this->requestDataMapper->createStatusRequestData($this->account, $this->order);
	}

	/**
	 * {@inheritDoc}
	 */
	public function createCancelXML()
	{
		return $this->requestDataMapper->createCancelRequestData($this->account, $this->order);
	}

	/**
	 * {@inheritDoc}
	 */
	public function createRefundXML()
	{
		return $this->requestDataMapper->createRefundRequestData($this->account, $this->order);
	}

	/**
	 * Get ProcReturnCode
	 * @param $response
	 * @return string|null
	 */
	protected function getProcReturnCode($response): ?string
	{
		return $response['ProcReturnCode'] ?? null;
	}

	/**
	 * Get Status Detail Text
	 * @param string|null $procReturnCode
	 * @return string|null
	 */
	protected function getStatusDetail(?string $procReturnCode): ?string
	{
		return $procReturnCode ? ($this->codes[$procReturnCode] ?? null) : null;
	}

	/**
	 * todo test with success
	 * {@inheritDoc}
	 */
	protected function map3DPaymentData($raw3DAuthResponseData, $rawPaymentResponseData)
	{
		$this->logger->log(LogLevel::DEBUG, 'mapping 3D payment data', [
			'3d_auth_response'   => $raw3DAuthResponseData,
			'provision_response' => $rawPaymentResponseData,
		]);
		$status = $raw3DAuthResponseData['mdStatus'];
		$transactionSecurity = 'MPI fallback';
		$procReturnCode = $this->getProcReturnCode($raw3DAuthResponseData);
		if ($this->getProcReturnCode($rawPaymentResponseData) === '00') {
			if ('1' === $status) {
				$transactionSecurity = 'Full 3D Secure';
			} elseif (in_array($status, ['2', '3', '4'])) {
				$transactionSecurity = 'Half 3D Secure';
			}
		}
		$paymentResponseData = $this->mapPaymentResponse($rawPaymentResponseData);
		$threeDResponse = [
			'order_id'             => $paymentResponseData['order_id'] ?? $raw3DAuthResponseData['OrderId'],
			'proc_return_code'     => $paymentResponseData['proc_return_code'] ?? $procReturnCode,
			'code'                 => $paymentResponseData['proc_return_code'] ?? $procReturnCode,
			'host_ref_num'         => $paymentResponseData['host_ref_num'] ?? $raw3DAuthResponseData['HostRefNum'],
			'transaction_security' => $transactionSecurity,
			'md_status'            => $status,
			'hash'                 => $raw3DAuthResponseData['HASH'],
			'rand'                 => null,
			'hash_params'          => $raw3DAuthResponseData['HASHPARAMS'],
			'hash_params_val'      => $raw3DAuthResponseData['HASHPARAMSVAL'],
			'masked_number'        => $raw3DAuthResponseData['Pan'],
			'month'                => null,
			'year'                 => null,
			'amount'               => $raw3DAuthResponseData['PurchAmount'],
			'currency'             => array_search($raw3DAuthResponseData['Currency'], $this->requestDataMapper->getCurrencyMappings()),
			'eci'                  => $raw3DAuthResponseData['Eci'],
			'tx_status'            => $raw3DAuthResponseData['TxnStat'],
			'cavv'                 => null,
			'xid'                  => $raw3DAuthResponseData['OrderId'],
			'md_error_message'     => $raw3DAuthResponseData['ErrorMessage'],
			'error_code'           => $paymentResponseData['error_code'] ?? $raw3DAuthResponseData['ErrorCode'],
			'error_message'        => $paymentResponseData['error_message'] ?? $raw3DAuthResponseData['ErrorMessage'],
			'status_detail'        => $paymentResponseData['status_detail'] ?? $this->getStatusDetail($procReturnCode),
			'3d_all'               => $raw3DAuthResponseData,
		];
		return array_merge($paymentResponseData, $threeDResponse);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function map3DPayResponseData($raw3DAuthResponseData)
	{
		return (object)$this->map3DPaymentData($raw3DAuthResponseData, $raw3DAuthResponseData);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function mapRefundResponse($rawResponseData)
	{
		$rawResponseData = $this->emptyStringsToNull($rawResponseData);
		$procReturnCode = $this->getProcReturnCode($rawResponseData);
		$status = 'declined';
		if ('00' === $procReturnCode) {
			$status = 'approved';
		}
		return (object)[
			'order_id'         => $rawResponseData['OrderId'],
			'group_id'         => null,
			'response'         => null,
			'auth_code'        => null,
			'host_ref_num'     => $rawResponseData['HostRefNum'],
			'proc_return_code' => $procReturnCode,
			'code'             => $procReturnCode,
			'trans_id'         => $rawResponseData['TransId'],
			'error_code'       => $rawResponseData['ErrorCode'],
			'error_message'    => $rawResponseData['ErrorMessage'],
			'status'           => $status,
			'status_detail'    => $this->getStatusDetail($procReturnCode),
			'all'              => $rawResponseData,
		];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function mapCancelResponse($rawResponseData)
	{
		$rawResponseData = $this->emptyStringsToNull($rawResponseData);
		$status = 'declined';
		$procReturnCode = $this->getProcReturnCode($rawResponseData);
		if ('00' === $procReturnCode) {
			$status = 'approved';
		}
		return (object)[
			'order_id'         => $rawResponseData['OrderId'],
			'group_id'         => null,
			'response'         => null,
			'auth_code'        => $rawResponseData['AuthCode'],
			'host_ref_num'     => $rawResponseData['HostRefNum'],
			'proc_return_code' => $procReturnCode,
			'code'             => $procReturnCode,
			'trans_id'         => $rawResponseData['TransId'],
			'error_code'       => $rawResponseData['ErrorCode'],
			'error_message'    => $rawResponseData['ErrorMessage'],
			'status'           => $status,
			'status_detail'    => $this->getStatusDetail($procReturnCode),
			'all'              => $rawResponseData,
		];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function mapStatusResponse($rawResponseData)
	{
		$rawResponseData = $this->emptyStringsToNull($rawResponseData);
		$procReturnCode = $this->getProcReturnCode($rawResponseData);
		$status = 'declined';
		if ('00' === $procReturnCode) {
			$status = 'approved';
		}
		$rawResponseData = $this->emptyStringsToNull($rawResponseData);
		return (object)[
			'order_id'         => $rawResponseData['OrderId'],
			'response'         => null,
			'proc_return_code' => $procReturnCode,
			'code'             => $procReturnCode,
			'trans_id'         => $rawResponseData['TransId'],
			'error_message'    => $rawResponseData['ErrorMessage'],
			'host_ref_num'     => null,
			'order_status'     => null, //todo success cevap alindiginda eklenecek
			'refund_amount'    => $rawResponseData['RefundedAmount'],
			'capture_amount'   => null, //todo success cevap alindiginda eklenecek
			'status'           => $status,
			'status_detail'    => $this->getStatusDetail($procReturnCode),
			'capture'          => null, //todo success cevap alindiginda eklenecek
			'all'              => $rawResponseData,
		];
	}

	/**
	 * todo test for success
	 * {@inheritDoc}
	 */
	protected function mapPaymentResponse($responseData): array
	{
		$this->logger->log(LogLevel::DEBUG, 'mapping payment response', [$responseData]);
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
		if (!$responseData) {
			return $result;
		}
		$result['id'] = $responseData['AuthCode'];
		$result['order_id'] = $responseData['OrderId'];
		$result['trans_id'] = $responseData['TransId'];
		$result['auth_code'] = $responseData['AuthCode'];
		$result['host_ref_num'] = $responseData['HostRefNum'];
		$result['error_code'] = $responseData['ErrorCode'];
		$result['error_message'] = $responseData['ErrorMessage'];
		$this->logger->log(LogLevel::DEBUG, 'mapped payment response', $result);
		return $result;
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
		return (object)[
			'id'       => $order['id'],
			'amount'   => $order['amount'],
			'currency' => $order['currency'] ?? 'TRY',
		];
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
	 * bankadan gelen response'da bos string degerler var.
	 * bu metod ile bos string'leri null deger olarak degistiriyoruz
	 * @param string|object|array $data
	 * @return string|object|array
	 */
	protected function emptyStringsToNull($data)
	{
		if (is_string($data)) {
			$data = '' === $data ? null : $data;
		} elseif (is_array($data)) {
			foreach ($data as $key => $value) {
				$data[$key] = '' === $value ? null : $value;
			}
		}
		return $data;
	}
}
