<?php

class ElasticSearchTransportMemcached extends ElasticSearchTransport
{
	private $host, $port;

	public function __construct($host = "127.0.0.1", $port = 11311)
	{
		$this->host = $host;
		$this->port = $port;
		$this->conn = new Memcache();
		$this->conn->connect($host, $port);
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
		if ($id === false) {
			$msg = "Memcached transport requires id when indexing";
			throw new Exception($msg);
		}

		$document = json_encode($document);
		$url = $this->buildUrl(
				array(
					$this->type,
					$id,
				),
				$params);
		$response = $this->conn->set($url, $document);
		return array(
			'ok' => $response,
		);
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
			if (array_key_exists("query", $query)) {
				$dsl = new ElasticSearchDSLStringify($query);
				$q = (string) $dsl;
				$url = $this->buildUrl(
						array(
							$this->type,
							"_search?q=" . $q,
						),
						$params);
				$result = json_decode($this->conn->get($url), true);
				return $result;
			} else {
				$msg = "Memcached protocol doesnt support the full DSL,";
			   	$msg .= " only query";
				throw new Exception($msg);
			}
		} else if (is_string($query)) {
			/**
			 * String based search means http query string search
			 */
			$url = $this->buildUrl(
					array(
						$this->type,
						"_search?q=".$query
					),
					$params);
			$result = json_decode($this->conn->get($url), true);

			return $result;
		}
	}

	/**
	 * Search
	 *
	 * @return array
	 * @param mixed $query String or array to use as criteria for delete
	 * @param array $options Parameters to pass to delete action
	 */
	public function deleteByQuery($query, array $params = array())
	{
		if (is_array($query)) {
			/**
			 * Array implies using the JSON query DSL
			 */
			return;
			$url = $this->buildUrl(
					array(
						$this->type,
						"_query",
					),
					$params);
			$result = $this->call($url, "DELETE", $query);
		} else if (is_string($query)) {
			/**
			 * String based search means http query string search
			 */
			return;
			$url = $this->buildUrl(
					array(
						$this->type,
						"_query?q=".$query,
					),
					$params);
			$result = $this->call($url, "DELETE");
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
			$payload=false,
			array $params = array())
	{
		$url = $this->buildUrl($path, $params);
		switch ($method) {
			case 'GET':
				$result = $this->conn->get($url);
				break;
			case 'DELETE':
				$result = $this->conn->delete($url);
				break;
		}

		return json_decode($result);
	}

	/**
	 * Flush this index/type combination
	 *
	 * @return array
	 * @param array $options Parameters to pass to delete action
	 */
	public function delete($id=false, array $params = array())
	{
		if ($id) {
			return $this->request(
					array(
						$this->type,
						$id,
					),
					"DELETE",
					null,
					$params);
		} else {
			return $this->request(false, "DELETE", null, $params);
		}
	}

	/**
	 * Perform a http call against an url with an optional payload
	 *
	 * @return array
	 * @param string $url
	 * @param string $method (GET/POST/PUT/DELETE)
	 * @param array $payload The document/instructions to pass along
	 */
	private function call($url, $method="GET", $payload=false)
	{
		$conn = curl_init();
		curl_setopt($conn, CURLOPT_URL, "http://" . $this->host . $url);
		curl_setopt($conn, CURLOPT_PORT, $this->port);
		curl_setopt($conn, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($conn, CURLOPT_CUSTOMREQUEST, strtoupper($method));

        if (is_array($payload) && count($payload) > 0) {
            curl_setopt($conn, CURLOPT_POSTFIELDS, json_encode($payload));
        } else if (is_string($payload)) {
            curl_setopt($conn, CURLOPT_POSTFIELDS, $payload);
        }

		$data = curl_exec($conn);
		if ($data !== false) {
			$data = json_decode($data, true);
		} else {
			throw new Exception("Transport call to API failed");
		}

		if (array_key_exists('error', $data)) {
			$this->handleError($url, $method, $payload, $data);
		}

		return $data;
	}

	public function bulk($bulk_queue)
	{
		$url = '/_bulk';
		$method = 'POST';
		$payload = $bulk_queue->getPayload();
		if ($bulk_queue->getParams()) {
			$url .= '?'.http_build_query($bulk_queue->getParams());
		}

		$conn = curl_init();
		curl_setopt($conn, CURLOPT_URL, "http://" . $this->host . $url);
		curl_setopt($conn, CURLOPT_PORT, $this->port);
		curl_setopt($conn, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($conn, CURLOPT_CUSTOMREQUEST, strtoupper($method));

		curl_setopt($conn, CURLOPT_POSTFIELDS, $payload);

		$data = curl_exec($conn);
		if ($data !== false) {
			$data = json_decode($data, true);
		} else {
			throw new Exception("Transport call to API failed");
		}

		if (array_key_exists('error', $data)) {
			$this->handleError($url, $method, $payload, $data);
		}

		return $data;
	}

	private function handleError($url, $method, $payload, $response)
	{
		$err = "Request: \n";
		$err .= "curl -X$method http://{$this->host}:{$this->port}$url";
		if ($payload) {
			$err .=  " -d '" . json_encode($payload) . "'";
		}
		$err .= "\n";
		$err .= "Triggered some error: \n";
		$err .= $response['error'] . "\n";
		throw new Exception($err);
	}
}
