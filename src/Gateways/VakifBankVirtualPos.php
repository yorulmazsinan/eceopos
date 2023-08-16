<?php
namespace EceoPos\Gateways;

use Exception;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use EceoPos\Client\HttpClient;
use EceoPos\DataMapper\AbstractRequestDataMapper;
use EceoPos\DataMapper\VakifBankVirtualPosRequestDataMapper;
use EceoPos\Entity\Account\AbstractPosAccount;
use EceoPos\Entity\Account\VakifBankVirtualPosAccount;
use EceoPos\Entity\Card\AbstractCreditCard;
use EceoPos\Exceptions\UnsupportedPaymentModelException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;

/**
 * Class VakifBankVirtualPos
 */
class VakifBankVirtualPos extends AbstractGateway
{
	/**
	 * @const string
	 */
	public const NAME = 'VakifPOS';
	/**
	 * @var VakifBankVirtualPosAccount
	 */
	protected $account;
	/**
	 * @var AbstractCreditCard
	 */
	protected $card;
	/**
	 * Response Codes
	 * @var array
	 */
	protected $codes = ['0000' => 'approved'];
	/** @var VakifBankVirtualPosRequestDataMapper */
	protected $requestDataMapper;

	/**
	 * @param VakifBankVirtualPosAccount $account
	 * @param VakifBankVirtualPosRequestDataMapper $requestDataMapper
	 */
	public function __construct(array $config, AbstractPosAccount $account, AbstractRequestDataMapper $requestDataMapper, HttpClient $client, LoggerInterface $logger)
	{
		parent::__construct($config, $account, $requestDataMapper, $client, $logger);
	}

	/**
	 * @return VakifBankVirtualPosAccount
	 */
	public function getAccount()
	{
		return $this->account;
	}

	/**
	 * {@inheritDoc}
	 */
	public function make3DPayment(Request $request)
	{
		$request = $request->request;
		$gatewayResponse = $this->emptyStringsToNull($request->all());
		// 3D yetkilendirme başarısız olmuşsa:
		if ('Y' !== $gatewayResponse['Status'] && 'A' !== $gatewayResponse['Status']) {
			$this->response = $this->map3DPaymentData($gatewayResponse, (object)[]);
			return $this;
		}
		if ('A' === $gatewayResponse['Status']) {
			$this->response = $this->map3DPaymentData($gatewayResponse, (object)[]);
			return $this;
		}
		$this->logger->log(LogLevel::DEBUG, 'finishing payment', ['md_status' => $request->get('Status')]);
		$contents = $this->create3DPaymentXML($gatewayResponse);
		$bankResponse = $this->send($contents);
		$this->response = $this->map3DPaymentData($gatewayResponse, $bankResponse);
		$this->logger->log(LogLevel::DEBUG, 'finished 3D payment', ['mapped_response' => $this->response]);
		return $this;
	}

	/**
	 * {@inheritDoc}
	 */
	public function make3DPayPayment(Request $request)
	{
		throw new UnsupportedPaymentModelException();
	}

	/**
	 * {@inheritDoc}
	 */
	public function make3DHostPayment(Request $request)
	{
		throw new UnsupportedPaymentModelException();
	}

	/**
	 * {@inheritDoc}
	 */
	public function history(array $meta)
	{
		throw new UnsupportedPaymentModelException();
	}

	/**
	 * 3D model için gerekli form verilerini döndürür.
	 * @return array
	 * @throws Exception
	 */
	public function get3DFormData(): array
	{
		if (!$this->card || !$this->order) {
			$this->logger->log(LogLevel::ERROR, 'Siparişi ayarlamadan 3D form verilerini alamazsınız!', [
				'order'         => $this->order,
				'card_provided' => (bool)$this->card,
			]);
			return [];
		}
		$data = $this->sendEnrollmentRequest();
		$data = parent::emptyStringsToNull($data);
		$status = $data['Message']['VERes']['Status'];
		/**
		 * Status values:
		 * Y: Kart "3D Secure" programına dâhil.
		 * N: Kart "3D Secure" programına dâhil değil.
		 * U: İşlem gerçekleştirilemiyor.
		 * E: Hata durumu.
		 */
		if ('E' === $status) {
			$this->logger->log(LogLevel::ERROR, 'Kayıt Hatası Yanıtı', $data);
			throw new Exception($data['ErrorMessage'], $data['MessageErrorCode']);
		}
		if ('N' === $status) {
			//half secure olarak devam et yada satisi iptal et.
			$this->logger->log(LogLevel::ERROR, 'Kayıt Hatası Yanıtı', $data);
			throw new Exception('Kart "3D Secure" programına dâhil değil.');
		}
		if ('U' === $status) {
			$this->logger->log(LogLevel::ERROR, 'Kayıt Hatası Yanıtı', $data);
			throw new Exception('İşlem gerçekleştirilemiyor.');
		}
		$this->logger->log(LogLevel::DEBUG, '3D Form Verilerini Hazırlama');
		return $this->requestDataMapper->create3DFormData($this->account, $this->order, $this->type, '', $data['Message']['VERes']);
	}

	/**
	 * Müşteriden kredi kartı bilgilerini aldıktan sonra GET 7/24 MPI'a kart "Kredi Kartı Kayıt Durumu"nun (Enrollment Status) sorulması, yani kart "3D Secure" programına dâhil mi yoksa değil mi sorgusu.
	 * @return object
	 * @throws Exception
	 */
	public function sendEnrollmentRequest()
	{
		$requestData = $this->requestDataMapper->create3DEnrollmentCheckRequestData($this->account, $this->order, $this->card);
		return $this->send($requestData, $this->get3DGatewayURL());
	}

	/**
	 * {@inheritDoc}
	 */
	public function createXML(array $nodes, string $encoding = 'UTF-8', bool $ignorePiNode = true): string
	{
		return parent::createXML(['VposRequest' => $nodes], $encoding, $ignorePiNode);
	}

	/**
	 * {@inheritDoc}
	 */
	public function send($contents, ?string $url = null)
	{
		$url = $url ? : $this->getApiURL();
		$this->logger->log(LogLevel::DEBUG, 'İstek Gönderme', ['url' => $url]);
		$isXML = is_string($contents);
		$body = $isXML ? ['form_params' => ['prmstr' => $contents]] : ['form_params' => $contents];
		$response = $this->client->post($url, $body);
		$this->logger->log(LogLevel::DEBUG, 'İstek Tamamlandı', ['status_code' => $response->getStatusCode()]);
		$responseBody = $response->getBody()->getContents();
		try {
			$this->data = $this->XMLStringToObject($responseBody);
		} catch (NotEncodableValueException $e) {
			if ($this->isHTML($responseBody)) {
				// Bir şeyler ters giderse sonucu HTML içeriğiyle birlikte döndür.
				throw new Exception($responseBody);
			}
			$this->data = (object)json_decode($responseBody);
		}
		$this->data = $this->emptyStringsToNull($this->data);
		return $this->data;
	}

	/**
	 * {@inheritDoc}
	 */
	public function createRegularPaymentXML()
	{
		$requestData = $this->requestDataMapper->createNonSecurePaymentRequestData($this->account, $this->order, $this->type, $this->card);
		return $this->createXML($requestData);
	}

	/**
	 * {@inheritDoc}
	 */
	public function createRegularPostXML()
	{
		$requestData = $this->requestDataMapper->createNonSecurePostAuthPaymentRequestData($this->account, $this->order);
		return $this->createXML($requestData);
	}

	/**
	 * NOT: Vakıfbank'a ait kredi kartı bilgileri bu aşamada isteniyor.
	 * {@inheritDoc}
	 */
	public function create3DPaymentXML($responseData)
	{
		$requestData = $this->requestDataMapper->create3DPaymentRequestData($this->account, $this->order, $this->type, $responseData, $this->card);
		return $this->createXML($requestData);
	}

	/**
	 * {@inheritDoc}
	 */
	public function createStatusXML()
	{
		$this->requestDataMapper->createStatusRequestData($this->account, $this->order);
	}

	/**
	 * {@inheritDoc}
	 */
	public function createHistoryXML($customQueryData)
	{
		$this->requestDataMapper->createHistoryRequestData($this->account, $this->order, $customQueryData);
	}

	/**
	 * {@inheritDoc}
	 */
	public function createRefundXML()
	{
		$requestData = $this->requestDataMapper->createRefundRequestData($this->account, $this->order);
		return $this->createXML($requestData);
	}

	/**
	 * {@inheritDoc}
	 */
	public function createCancelXML()
	{
		$requestData = $this->requestDataMapper->createCancelRequestData($this->account, $this->order);
		return $this->createXML($requestData);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function map3DPaymentData($raw3DAuthResponseData, $rawPaymentResponseData)
	{
		$this->logger->log(LogLevel::DEBUG, '3D Ödeme Verilerini İşleme', [
			'3d_auth_response'   => $raw3DAuthResponseData,
			'provision_response' => $rawPaymentResponseData,
		]);
		$threeDAuthStatus = ('Y' === $raw3DAuthResponseData['Status']) ? 'approved' : 'declined';
		$paymentResponseData = [];
		if ('approved' === $threeDAuthStatus) {
			$paymentResponseData = $this->mapPaymentResponse($rawPaymentResponseData);
		}
		$threeDResponse = [
			'id'            => null,
			'eci'           => $raw3DAuthResponseData['Eci'],
			'cavv'          => $raw3DAuthResponseData['Cavv'],
			'auth_code'     => null,
			'order_id'      => $raw3DAuthResponseData['VerifyEnrollmentRequestId'],
			'status'        => $threeDAuthStatus,
			'status_detail' => null,
			'error_code'    => 'declined' === $threeDAuthStatus ? $raw3DAuthResponseData['ErrorCode'] : null,
			'error_message' => 'declined' === $threeDAuthStatus ? $raw3DAuthResponseData['ErrorMessage'] : null,
			'all'           => $rawPaymentResponseData,
			'3d_all'        => $raw3DAuthResponseData,
		];
		if (empty($paymentResponseData)) {
			return (object)array_merge($this->getDefaultPaymentResponse(), $threeDResponse);
		}
		return (object)array_merge($threeDResponse, $paymentResponseData);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function map3DPayResponseData($raw3DAuthResponseData)
	{
		return [];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function mapRefundResponse($rawResponseData)
	{
		return $this->mapCancelResponse($rawResponseData);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function mapCancelResponse($rawResponseData)
	{
		$status = 'declined';
		$resultCode = $rawResponseData->ResultCode;
		if ('0000' === $resultCode) {
			$status = 'approved';
		}
		return (object)[
			'order_id'         => $rawResponseData->TransactionId ?? null,
			'auth_code'        => ('declined' !== $status) ? $rawResponseData->AuthCode : null,
			'host_ref_num'     => $rawResponseData->Rrn ?? null,
			'proc_return_code' => $resultCode,
			'trans_id'         => $rawResponseData->TransactionId ?? null,
			'error_code'       => ('declined' === $status) ? $rawResponseData->ResultDetail : null,
			'error_message'    => ('declined' === $status) ? $rawResponseData->ResultDetail : null,
			'status'           => $status,
			'status_detail'    => $rawResponseData->ResultDetail,
			'all'              => $rawResponseData,
		];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function mapPaymentResponse($responseData): array
	{
		$this->logger->log(LogLevel::DEBUG, 'Eşlenmiş Ödeme Yanıtı', [$responseData]);
		$commonResponse = $this->getCommonPaymentResponse($responseData);
		if ('approved' === $commonResponse['status']) {
			$commonResponse['id'] = $responseData->AuthCode;
			$commonResponse['trans_id'] = $responseData->TransactionId;
			$commonResponse['auth_code'] = $responseData->AuthCode;
			$commonResponse['host_ref_num'] = $responseData->Rrn;
			$commonResponse['order_id'] = $responseData->OrderId;
			$commonResponse['transaction_type'] = $responseData->TransactionType;
			$commonResponse['eci'] = $responseData->ECI;
		}
		$this->logger->log(LogLevel::DEBUG, 'Eşlenmiş Ödeme Yanıtı', $commonResponse);
		return $commonResponse;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function mapStatusResponse($rawResponseData)
	{
		return [];
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
			'amount'      => $order['amount'],
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
			'ip'       => $order['ip'],
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
		return (object)['id' => $order['id'] ?? null];
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
	 * @param $responseData
	 * @return array
	 */
	private function getCommonPaymentResponse($responseData): array
	{
		$status = 'declined';
		$resultCode = $responseData->ResultCode;
		if ('0000' === $resultCode) {
			$status = 'approved';
		}
		return [
			'id'               => null,
			'trans_id'         => null,
			'auth_code'        => null,
			'host_ref_num'     => null,
			'order_id'         => null,
			'transaction'      => empty($this->type) ? null : $this->requestDataMapper->mapTxType($this->type),
			'transaction_type' => $this->type,
			'response'         => null,
			'eci'              => null,
			'proc_return_code' => $resultCode,
			'code'             => $resultCode,
			'status'           => $status,
			'status_detail'    => $responseData->ResultDetail,
			'error_code'       => ('declined' === $status) ? $resultCode : null,
			'error_message'    => ('declined' === $status) ? $responseData->ResultDetail : null,
			'all'              => $responseData,
		];
	}

	/**
	 * Bankadan gelen yanıtlarda boş string'ler varsa bunları null olarak döndürüyoruz.
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
		} elseif (is_object($data)) {
			foreach ($data as $key => $value) {
				$data->{$key} = '' === $value ? null : $value;
			}
		}
		return $data;
	}
}
