<?php
require_once 'lib/ElasticSearchException.php';
require_once 'lib/ElasticSearchDSLStringify.php';

require_once 'lib/builder/ElasticSearchDSLBuilder.php';

require_once 'lib/ElasticSearchBulkQueue.php';

require_once 'lib/transport/ElasticSearchTransport.php';
require_once 'lib/transport/ElasticSearchTransportHTTP.php';
require_once 'lib/transport/ElasticSearchTransportMemcached.php';

if (isset($GLOBALS['THRIFT_ROOT'])) {
	require_once 'lib/transport/ElasticSearchTransportThrift.php';
}

class ElasticSearchClient
{
	private $transport, $index, $type, $bulk_queue;

	/**
	 * Construct search client
	 *
	 * @return ElasticSearch
	 * @param ElasticSearchTransport $transport
	 * @param string $index
	 * @param string $type
	 */
	public function __construct($transport, $index, $type)
	{
		$this->index = $index;
		$this->type = $type;
		$this->transport = $transport;
		$this->setIndex($index);
		$this->setType($type);
		$this->bulk_queue = new ElasticSearchBulkQueue();
	}

	/**
	 * Change what index to go against
	 * @return void
	 * @param mixed $index
	 */
	public function setIndex($index)
	{
		if (is_array($index)) {
			$index = implode(",", array_filter($index));
		}
		$this->index = $index;
		$this->transport->setIndex($index);
	}

	/**
	 * Change what types to act against
	 * @return void
	 * @param mixed $type
	 */
	public function setType($type)
	{
		if (is_array($type)) {
			$type = implode(",", array_filter($type));
		}
		$this->type = $type;
		$this->transport->setType($type);
	}

	/**
	 * Fetch a document by its id
	 *
	 * @return array
	 * @param mixed $id Optional
	 */
	public function get($id, $verbose=false)
	{
		if (empty($id)) {
			throw new Exception('empty id on get call');
		}
		$response = $this->transport->request(
				array(
					$this->type,
					$id,
				),
				"GET");

		return ($verbose) ? $response : $response['_source'];
	}

	/**
	 * Perform a request
	 *
	 * @return array
	 * @param mixed $id Optional
	 */
	public function request($path, $method, $payload, $verbose=false)
	{
		$path = array_merge((array) $this->type, (array) $path);

		$response = $this->transport->request($path, $method, $payload);

        if (array_key_exists('_source', $response)) {
          return ($verbose) ? $response : $response['_source'];
        } else {
          return $response;
        }
	}

	/**
	 * Index a new document or update it if existing
	 *
	 * @return array
	 * @param array $document
	 * @param mixed $id Optional
	 * @param array $options Allow sending query parameters to control indexing further
	 *		_refresh_ *bool* If set to true, immediately refresh the shard after indexing
	 */
	public function index($document, $id=false, array $options = array())
	{
		return $this->transport->index($document, $id, $options);
	}

	/**
	 * Perform search, this is the sweet spot
	 *
	 * @return array
	 * @param array $document
	 */
	public function search($query)
	{
		$start = $this->getMicroTime();
		$result = $this->transport->search($query);
		$result['time'] = $this->getMicroTime() - $start;

		return $result;
	}

	/**
	 * Flush this index/type combination
	 *
	 * @return array
	 * @param mixed $id If id is supplied, delete that id for this index
	 *				  if not wipe the entire index
	 * @param array $options Parameters to pass to delete action
	 */
	public function delete($id=false, array $options = array())
	{
		return $this->transport->delete($id, $options);
	}

	/**
	 * Flush this index/type combination
	 *
	 * @return array
	 * @param mixed $query Text or array based query to delete everything that matches
	 * @param array $options Parameters to pass to delete action
	 */
	public function deleteByQuery($query, array $options = array())
	{
		return $this->transport->deleteByQuery($query, $options);
	}

	/**
	 * Get the number of items currently in the bulk queue.
	 *
	 * @return int The number of items in the bulk queue.
	 */
	public function getBulkCount()
	{
		return $this->bulk_queue->queueLength();
	}

	/**
	 * Get the bulk queue itself.
	 *
	 * @return array The raw bulk queue.
	 */
	public function getBulkQueue()
	{
		return $this->bulk_queue->getQueue();
	}

	/**
	 * Add an index operation to the bulk queue.
	 * Use just as you would the regular index,
	 * but be sure to submit the queue!
	 */
	public function bulkIndex(
			$document,
			$id = false,
			array $params = array())
	{
		foreach (explode(',', $this->index) as $index) {
			foreach (explode(',', $this->type) as $type) {
				$this->bulk_queue->index(
						$document,
						$index,
						$this->type,
						$id,
						$params);
			}
		}
	}

	/**
	 * Add a delete operation to the bulk queue.
	 * Use just as you would the regular delete,
	 * but be sure to submit the queue!
	 */
	public function bulkDelete($id, array $params = array())
	{
		foreach (explode(',', $this->index) as $index) {
			foreach (explode(',', $this->type) as $type) {
				$this->bulk_queue->delete(
						$index,
						$this->type,
						$id,
						$params);
			}
		}
	}

	/**
	 * Submit the bulk queue for processing.
	 */
	public function bulkSubmit(array $params = array())
	{
		$this->bulk_queue->setParams($params);
		$result = $this->transport->bulk($this->bulk_queue);
		$this->bulk_queue = new ElasticSearchBulkQueue();

		return $result;
	}

	private function getMicroTime()
	{
		list($usec, $sec) = explode(" ", microtime());

		return ((float)$usec + (float)$sec);
	}

}
