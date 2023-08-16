<?php
namespace EceoPos\Client;

use function http_build_query;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

class HttpClient
{
	/** @var ClientInterface */
	protected $client;
	/** @var RequestFactoryInterface */
	protected $requestFactory;
	/** @var StreamFactoryInterface */
	protected $streamFactory;

	/**
	 * @param ClientInterface $client
	 * @param RequestFactoryInterface $requestFactory
	 * @param StreamFactoryInterface $streamFactory
	 */
	public function __construct(ClientInterface $client, RequestFactoryInterface $requestFactory, StreamFactoryInterface $streamFactory)
	{
		$this->client = $client;
		$this->requestFactory = $requestFactory;
		$this->streamFactory = $streamFactory;
	}

	public function post(string $path, ?array $payload = []): ResponseInterface
	{
		return $this->send('POST', $path, $payload);
	}

	private function send(string $method, $path, ?array $payload = []): ResponseInterface
	{
		$request = $this->createRequest($method, $path, $payload);
		return $this->client->sendRequest($request);
	}

	private function createRequest(string $method, string $url, ?array $payload = []): RequestInterface
	{
		$request = $this->requestFactory->createRequest($method, $url);
		if ('POST' == $method) {
			$body = null;
			if (isset($payload['form_params'])) {
				$request = $request->withHeader('Content-Type', 'application/x-www-form-urlencoded');
				$payload['body'] = http_build_query($payload['form_params']);
			}
			if (isset($payload['body'])) {
				$body = $this->streamFactory->createStream($payload['body']);
			}
			$request = $request->withBody($body);
		}
		if (isset($payload['headers'])) {
			foreach ($payload['headers'] as $key => $value) {
				$request = $request->withHeader($key, $value);
			}
		}
		return $request;
	}
}
