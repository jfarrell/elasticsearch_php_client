<?php

require_once $GLOBALS['THRIFT_ROOT'].'/Thrift.php';
require_once $GLOBALS['THRIFT_ROOT'].'/protocol/TBinaryProtocol.php';
require_once $GLOBALS['THRIFT_ROOT'].'/transport/TSocket.php';
require_once $GLOBALS['THRIFT_ROOT'].'/transport/TBufferedTransport.php';

require_once $GLOBALS['THRIFT_ROOT'].'/packages/elasticsearch/Rest.php';

class ElasticSearchTransportThriftException extends ElasticSearchException
{
	protected $data = array(
		'payload' => null,
		'port' => null,
		'host' => null,
		'url' => null,
		'method' => null,
		'params' => null,
		'response' => null,
	);

	public function __construct(
			$url,
			$method = 'GET',
			$payload = null,
			$params = null,
			$response = null,
			$host = 'localhost',
			$port = 9200,
			$message = '')
	{
		$this->data['url'] = $url;
		$this->data['method'] = $method;
		$this->data['payload'] = $payload;
		$this->data['params'] = $params;
		$this->data['response'] = $response;
		$this->data['host'] = $host;
		$this->data['port'] = $port;

		if (strlen($message) > 0) {
			$message .= "\n\n";
		}
		$message .= $this->getCLICommand()."\n";

		parent::__construct($message);
	}

	public function __set($key, $value)
	{
		if (array_key_exists($key, $this->data)) {
			$this->data[$key] = $value;
		}
	}

	public function __get($key)
	{
		if (array_key_exists($key, $this->data)) {
			return $this->data[$key];
		} else {
			return false;
		}
	}

	public function getCLICommand()
	{
		$curlCall = "curl -X";
		$curlCall .= $this->method;
		$curlCall .= " 'http://";
		$curlCall .= $this->host;
		$curlCall .= ":";
		$curlCall .= $this->port;
		$curlCall .= $this->url;
		if (count($this->params) > 0) {
			$curlCall .= "?";
			$curlCall .= http_build_query($this->params);
		}
		$curlCall .= "' -d '";
		$curlCall .= json_encode($this->payload);
		$curlCall .= "'";

		return $curlCall;
	}
}

class ElasticSearchTransportThrift extends ElasticSearchTransport
{
	protected $host = "";
	protected $port = 0;

	protected $socket = NULL;
	protected $transport = NULL;
	protected $protocol = NULL;
	protected $client = NULL;


	public function __construct($host, $port)
	{
		try {
			$this->host = $host;
			$this->port = $port;

			$this->socket = new TSocket($host, $port);
			$this->transport = new TBufferedTransport($this->socket);
			$this->protocol = new TBinaryProtocol($this->transport);
			$this->client = new RestClient($this->protocol);
			$this->transport->open();
		} catch (Exception $e) {
			$msg = 'Error while attempting to open Thrift ';
			$msg .= 'connection to ElasticSearch: ';
			$msg .= "$e";
			throw new ElasticSearchTransportThriftException($msg);
		}
	}

	public function __destruct()
	{
		$this->transport->close();
	}

	public function createIndex($indexName, array $settings = array())
	{
		if (!array_key_exists('settings', $settings)) {
			$settings = array(
				'settings' => $settings,
			);
		}

		$this->call('/'.$indexName, 'POST', $settings);
	}

	/**
	 * Index a new document or update it if existing
	 *
	 * @return array
	 * @param array $document
	 * @param mixed $id Optional
	 */
	public function index($document, $id=false, array $params = array())
	{
		$url = $this->buildUrl(array($this->type, $id));
		$method = ($id == false) ? "POST" : "PUT";
		try {
			$response = $this->call($url, $method, $document, $params);
		} catch (ElasticSearchTransportThriftException $e) {
			throw $e;
		}

		return $response;
	}

	/**
	 * Search
	 *
	 * @return array
	 * @param mixed $id Optional
	 */
	public function search($query, array $params = array())
	{
		if ($query == null) {
			throw new ElasticSearchTransportThriftException();
		} else if (is_array($query)) {
			/**
			 * Array implies using the JSON query DSL
			 */
			$url = $this->buildUrl(array($this->type, "_search"));
			try {
				$result = $this->call($url, "GET", $query, $params);
			} catch (ElasticSearchTransportThriftException $e) {
				throw $e;
			}
		} else if (is_string($query)) {
			/**
			 * String based search means http query string search
			 */
			$url = $this->buildUrl(array($this->type, "_search"));
			$params['q'] = $query;
			try {
				$result = $this->call($url, "GET", null, $params);
			} catch (ElasticSearchTransportThriftException $e) {
				throw $e;
			}
		}

		return $result;
	}

	/**
	 * Search
	 *
	 * @return array
	 * @param mixed $id Optional
	 */
	public function deleteByQuery($query, array $params = array())
	{
		if (is_array($query)) {
			/**
			 * Array implies using the JSON query DSL
			 */
			$url = $this->buildUrl(array($this->type, "_query"));
			try {
				$result = $this->call($url, "DELETE", $query, $params);
			} catch (ElasticSearchTransportThriftException $e) {
				throw $e;
			}
		} else if (is_string($query)) {
			/**
			 * String based search means http query string search
			 */
			$url = $this->buildUrl(array($this->type, "_query"));
			$params['q'] = $query;
			try {
				$result = $this->call($url, "DELETE", null, $params);
			} catch (ElasticSearchTransportThriftException $e) {
				throw $e;
			}
		}

		return $result['ok'];
	}

	/**
	 * Basic http call
	 *
	 * @return array
	 * @param mixed $id Optional
	 */
	public function request(
			$path,
			$method="GET",
			$payload = null,
			array $params = array())
	{
		$url = $this->buildUrl($path);
		try {
			$result = $this->call($url, $method, $payload, $params);
		} catch (ElasticSearchTransportThriftException $e) {
			throw $e;
		}

		return $result;
	}

	/**
	 * Flush this index/type combination
	 *
	 * @return array
	 */
	public function delete($id=false, array $params = array())
	{
		$resp = null;
		try {
			if ($id) {
				$resp = $this->request(
						array(
							$this->type,
							$id,
						),
						"DELETE",
						null,
						$params);
			} else {
				$resp = $this->request(
						false,
						"DELETE",
						null,
						$params);
			}
		} catch (ElasticSearchTransportThriftException $e) {
			if (is_array($e->response)) {
				if ($e->response['status'] != 404) {
					throw $e;
				}
			} else {
				throw $e;
			}
		}

		return $resp;
	}

	/** Submit a bulk API call.
	 *
	 * @param ElasticSearchBulkQueue $bulk_queue
	 * @return array
	 */
	public function bulk($bulk_queue)
	{
		$method = 'POST';
		$url = '/_bulk';
		$payload = $bulk_queue->getPayload();
		$params = $bulk_queue->getParams();
		$req = array(
			"method" => $GLOBALS['E_Method'][$method],
			"uri" => $url,
			'parameters' => $params,
		);

		$req["body"] = $payload;

		$result = $this->client->execute(new RestRequest($req));

		if (!isset($result->status)){
			$exception = new ElasticSearchTransportThriftException();
			$exception->payload = $payload;
			$exception->port = $this->port;
			$exception->host = $this->host;
			$exception->method = $method;
			throw $exception;
		}

		$data = json_decode($result->body, true);

		if (array_key_exists('error', $data)) {
			$this->handleError($url, $method, $payload, $data);
		}

		return $data;
	}

	/**
	 * Perform a http call against an url with an optional payload
	 *
	 * @return array
	 * @param string $url
	 * @param string $method (GET/POST/PUT/DELETE)
	 * @param array $payload The document/instructions to pass along
	 */
	protected function call(
			$url,
			$method="GET",
			$payload=false,
			array $params = array())
	{
		$req = array(
			"method" => $GLOBALS['E_Method'][$method],
			"uri" => $url,
			'parameters' => $params,
		);

        if (is_array($payload) && count($payload) > 0) {
            $req["body"] = json_encode($payload);
        } else if (is_string($payload)) {
            $req["body"] = $payload;
        }

		$result = $this->client->execute(new RestRequest($req));

		if (!isset($result->status)){
			throw new ElasticSearchTransportThriftException(
					$url,
					$method,
					$payload,
					$params,
					$result,
					$this->host,
					$this->port);
		}

		$data = json_decode($result->body, true);

		if (array_key_exists('error', $data)) {
			$this->handleError($url, $method, $payload, $params, $data);
		}

		return $data;
	}

	protected function handleError(
			$url,
			$method,
			$payload,
			array $params = array(),
			$response = null)
	{
		$error = null;
		if (is_array($response)) {
			if (array_key_exists('error', $response)) {
				$error = $response['error'];
			}
		}

		throw new ElasticSearchTransportThriftException(
				$url,
				$method,
				$payload,
				$params,
				$response,
				$this->host,
				$this->port,
				$error);
	}

	/**
	 * Build a callable url
	 *
	 * @return string
	 * @param array $path
	 */
	protected function buildUrl($path=false)
	{
		$url = "/" . $this->index;
		if ($path && is_array($path) && count($path) > 0) {
			$url .= "/" . implode("/", array_filter($path));
		} else if ($path && is_string($path)) {
			$url .= '/' . $path;
		}
		if (substr($url, -1) == "/") {
			$url = substr($url, 0, -1);
		}

		return $url;
	}
}
