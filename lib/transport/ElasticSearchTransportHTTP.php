<?php

if (!defined('CURLE_OPERATION_TIMEDOUT')) {
	define('CURLE_OPERATION_TIMEDOUT', 28);
}

class ElasticSearchTransportHTTPException extends ElasticSearchException
{
	protected $data = array(
		'payload' => null,
		'protocol' => null,
		'port' => null,
		'host' => null,
		'url' => null,
		'method' => null,
		'response' => null,
	);

	public function __construct(
			$url,
			$method = 'GET',
			$payload = null,
			$response = null,
			$host = 'localhost',
			$port = 9200,
			$protocol = 'http',
			$message = '')
	{
		$this->data['url'] = $url;
		$this->data['method'] = $method;
		$this->data['payload'] = $payload;
		$this->data['response'] = $response;
		$this->data['host'] = $host;
		$this->data['port'] = $port;
		$this->data['protocol'] = $protocol;

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
		$curlCall = 'curl -X';
		$curlCall .= $this->method;
		$curlCall .= ' "';
		$curlCall .= $this->protocol;
		$curlCall .= '://';
		$curlCall .= $this->host;
		$curlCall .= ':';
		$curlCall .= $this->port;
		$curlCall .= $this->url;
		$curlCall .= '" -d "';
		$curlCall .= json_encode($this->payload);
		$curlCall .= '"';

		return $curlCall;
	}
}

class ElasticSearchTransportHTTP extends ElasticSearchTransport
{
	/**
	 * How long before timing out CURL call
	 */
	protected $timeout = 5;

	/**
	 * What host to connect to for server
	 * @var string
	 */
	protected $host = "";

	/**
	 * Port to connect on
	 * @var int
	 */
	protected $port = 9200;

	/**
	 * curl handler which is needed for reusing existing http connection
	 * @var resource
	 */
	protected $ch;


	public function __construct($host, $port, $timeout = 5)
	{
		$this->host = $host;
		$this->port = $port;
		$this->timeout = $timeout;
		$this->ch = curl_init();
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
		$url = $this->buildUrl(
				array(
					$this->type,
					$id,
				),
				$params);

		$method = ($id == false) ? "POST" : "PUT";
		try {
			$response = $this->call($url, $method, $document);
		} catch (ElasticSearchTransportHTTPException $e) {
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
		if (is_array($query)) {
			/**
			 * Array implies using the JSON query DSL
			 */
			$url = $this->buildUrl(
					array(
						$this->type,
						"_search",
					),
					$params);

			try {
				$result = $this->call($url, "POST", $query);
			} catch (ElasticSearchTransportHTTPException $e) {
				throw $e;
			}
		} else if (is_string($query)) {
			/**
			 * String based search means http query string search
			 */
			$query_struct = array(
				'query' => array(
					'query_string' => array(
						'query' => $query,
					),
				),
			);

			$url = $this->buildUrl(
					array(
						//$this->type, "_search?q=".$query
						$this->type,
						"_search",
					),
					$params);

			try {
				$result = $this->call($url, "POST", $query_struct);
			} catch (ElasticSearchTransportHTTPException $e) {
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
	 * @param array $options Parameters to pass to delete action
	 */
	public function deleteByQuery($query, array $params = array())
	{
		if (is_array($query)) {
			/**
			 * Array implies using the JSON query DSL
			 */
			$url = $this->buildUrl(
					array(
						$this->type,
						"_query",
					),
					$params);

			try {
				$result = $this->call($url, "DELETE", $query);
			} catch (ElasticSearchTransportHTTPException $e) {
				throw $e;
			}
		} else if (is_string($query)) {
			/**
			 * String based search means http query string search
			 */
			$query_struct = array(
				'query' => array(
					'query_string' => array(
						'query' => $query,
					),
				),
			);

			$url = $this->buildUrl(
					array(
						//$this->type, "_query?q=".$query
						$this->type,
						"_query",
					),
					$params);

			try {
				$result = $this->call($url, "DELETE", $query_struct);
			} catch (ElasticSearchTransportHTTPException $e) {
				throw $e;
			}
		}

		return $result['ok'];
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
	 * Basic http call
	 *
	 * @return array
	 * @param mixed $id Optional
	 */
	public function request(
			$path,
			$method="GET",
			$payload=false,
			array $params = array())
	{
		$url = $this->buildUrl($path, $params);
		try {
			$result = $this->call($url, $method, $payload);
		} catch (ElasticSearchTransportHTTPException $e) {
			throw $e;
		}
		return $result;
	}

	/**
	 * Flush this index/type combination
	 *
	 * @return array
	 * @param mixed $id Id of document to delete
	 * @param array $options Parameters to pass to delete action
	 */
	public function delete($id = false, array $params = array())
	{
		$result = null;
		try {
			if ($id) {
				$result = $this->request(
						array(
							$this->type,
							$id,
						),
						"DELETE",
						null,
						$params);
			} else {
				$result = $this->request(false, "DELETE", null, $params);
			}
		} catch (ElasticSearchTransportHTTPException $e) {
			if ($e->response) {
				if ($e->response['status'] != 404) {
					throw $e;
				}
			} else {
				throw $e;
			}
		}

		return $result;
	}

	/**
	 * Perform a http call against an url with an optional payload
	 *
	 * @return array
	 * @param string $url
	 * @param string $method (GET/POST/PUT/DELETE)
	 * @param array $payload The document/instructions to pass along
	 */
	protected function call($url, $method="GET", $payload=false)
	{
		$conn = $this->ch;
		$protocol = "http";
		$requestURL = $protocol . "://" . $this->host . $url;

		curl_setopt($conn, CURLOPT_URL, $requestURL);
		curl_setopt($conn, CURLOPT_TIMEOUT, $this->timeout);
		curl_setopt($conn, CURLOPT_PORT, $this->port);
		curl_setopt($conn, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($conn, CURLOPT_CUSTOMREQUEST, strtoupper($method));
		curl_setopt($conn, CURLOPT_FORBID_REUSE , 0);

        if (is_array($payload) && count($payload) > 0) {
            curl_setopt($conn, CURLOPT_POSTFIELDS, json_encode($payload));
        } else if (is_string($payload)) {
            curl_setopt($conn, CURLOPT_POSTFIELDS, $payload);
        }

		$data = curl_exec($conn);

		if ($data !== false) {
			$data = json_decode($data, true);
		} else {
			/**
			 * cUrl error code reference can be found here:
			 * http://curl.haxx.se/libcurl/c/libcurl-errors.html
			 */
			$errno = curl_errno($conn);
			switch ($errno)
			{
				case CURLE_UNSUPPORTED_PROTOCOL:
					$error = "Unsupported protocol [$protocol]";
					break;
				case CURLE_FAILED_INIT:
					$error = "Internal cUrl error?";
					break;
				case CURLE_URL_MALFORMAT:
					$error = "Malformed URL [$requestURL] -d ";
					$error .= json_encode($payload);
					break;
				case CURLE_COULDNT_RESOLVE_PROXY:
					$error = "Couldnt resolve proxy";
					break;
				case CURLE_COULDNT_RESOLVE_HOST:
					$error = "Couldnt resolve host";
					break;
				case CURLE_COULDNT_CONNECT:
					$error = 'Couldnt connect to host [';
					$error .= $this->host;
					$error .= '], ElasticSearch down?';
					break;
				case CURLE_OPERATION_TIMEDOUT:
					$error = "Operation timed out on [$requestURL]";
					break;
				default:
					$error = "Unknown error";
					if ($errno == 0) {
						$error .= ". Non-cUrl error";
					}
					break;
			}

			throw new ElasticSearchTransportHTTPException(
					$requestURL,
					$method,
					$payload,
					null,
					$this->host,
					$this->port,
					$protocol,
					$error);
		}

		if (array_key_exists('error', $data)) {
			$this->handleError($url, $method, $payload, $data);
		}

		return $data;
	}

	public function bulk($bulk_queue)
	{
		$url = "/_bulk";
		$method = 'POST';
		$payload = $bulk_queue->getPayload();
		if ($bulk_queue->getParams()) {
			$url .= '?'.http_build_query($bulk_queue->getParams());
		}

		$conn = $this->ch;
		$protocol = "http";
		$requestURL = $protocol . "://" . $this->host . $url;

		curl_setopt($conn, CURLOPT_URL, $requestURL);
		curl_setopt($conn, CURLOPT_TIMEOUT, $this->timeout);
		curl_setopt($conn, CURLOPT_PORT, $this->port);
		curl_setopt($conn, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($conn, CURLOPT_CUSTOMREQUEST, strtoupper($method));
		curl_setopt($conn, CURLOPT_FORBID_REUSE , 0);

		curl_setopt($conn, CURLOPT_POSTFIELDS, $payload);

		$data = curl_exec($conn);

		if ($data !== false) {
			$data = json_decode($data, true);
		} else {
			/**
			 * cUrl error code reference can be found here:
			 * http://curl.haxx.se/libcurl/c/libcurl-errors.html
			 */
			$errno = curl_errno($conn);
			switch ($errno)
			{
				case CURLE_UNSUPPORTED_PROTOCOL:
					$error = "Unsupported protocol [$protocol]";
					break;
				case CURLE_FAILED_INIT:
					$error = "Internal cUrl error?";
					break;
				case CURLE_URL_MALFORMAT:
					$error = "Malformed URL [$requestURL] -d ";
					$error .= json_encode($payload);
					break;
				case CURLE_COULDNT_RESOLVE_PROXY:
					$error = "Couldnt resolve proxy";
					break;
				case CURLE_COULDNT_RESOLVE_HOST:
					$error = "Couldnt resolve host";
					break;
				case CURLE_COULDNT_CONNECT:
					$error = 'Couldnt connect to host [';
					$error .= $this->host;
					$error .= '], ElasticSearch down?';
					break;
				case CURLE_OPERATION_TIMEDOUT:
					$error = "Operation timed out on [$requestURL]";
					break;
				default:
					$error = "Unknown error";
					if ($errno == 0) {
						$error .= ". Non-cUrl error";
					}
					break;
			}

			throw new ElasticSearchTransportHTTPException(
					$requestURL,
					$method,
					$payload,
					null,
					$this->host,
					$this->port,
					$protocol,
					$error);
		}

		if (array_key_exists('error', $data)) {
			$this->handleError($url, $method, $payload, $data);
		}

		return $data;
	}

	protected function handleError(
			$url,
			$method,
			$payload,
			$response)
	{
		$error = null;
		if (is_array($response)) {
			if (array_key_exists('error', $response)) {
				$error = $response['error'];
			}
		}

		throw new ElasticSearchTransportHTTPException(
				$url,
				$method,
				$payload,
				$response,
				$this->host,
				$this->port,
				'http',
				$error);
	}
}

?>
