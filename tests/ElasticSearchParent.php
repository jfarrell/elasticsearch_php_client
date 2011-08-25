<?php // vim:set ts=4 sw=4 et:
require_once 'PHPUnit/Framework/TestCase.php';
require_once 'helper.php';
/**
 * These tests cover the union of every transports api
 */
abstract class ElasticSearchParent extends PHPUnit_Framework_TestCase {

    protected $search = null;

    protected function generateDocument($words, $len=4) {
        $sentence = "";
        while ($len > 0) {
            shuffle($words);
            $sentence .= $words[0] . " ";
            $len--;
        }
        return array('title' => $sentence, 'rank' => rand(1, 10));
    }
    protected function addDocuments($indexes=array("test-index"), $num=3, $rand=false) {
        $words = array("cool", "dog", "lorem", "ipsum", "dolor", "sit", "amet");
        // Generate documents
        $options = array(
            'refresh' => true
        );
        foreach ($indexes as $ind) {
            $this->search->setIndex($ind);
            $tmpNum = $num;

            // Index documents
            while ($tmpNum > 0) {
                $tmpNum--;
                if ($rand) {
                    $doc = $this->generateDocument($words, 5);
                } else {
                    $doc = array('title' => 'One cool document', 'rank' => rand(1,10));
                }
                $this->search->index($doc, $tmpNum + 1, $options);
            }
        }
    }

    protected function addDocumentsBulk($indexes=array("test-index"), $num=3, $rand=false) {
        $words = array("cool", "dog", "lorem", "ipsum", "dolor", "sit", "amet");
        // Generate documents
        $options = array(
            'refresh' => true
        );
        foreach ($indexes as $ind) {
            $this->search->setIndex($ind);
            $tmpNum = $num;

            // Index documents
            while ($tmpNum > 0) {
                $tmpNum--;
                if ($rand)
                    $doc = $this->generateDocument($words, 5);
                else
                    $doc = array('title' => 'One cool document', 'rank' => rand(1,10));
                $this->search->bulkIndex($doc, $tmpNum + 1);
            }
        }
        $this->search->bulkSubmit($options);
    }

    /**
     * Test indexing a new document
     */
    public function testIndexingDocument() {
        $doc = array(
            'title' => 'One cool document',
            'tag' => 'cool'
        );
        $resp = $this->search->index($doc, 1);

        $this->assertTrue($resp['ok'] == 1);
    }

    /**
     * Test bulk indexing
     */
    public function testBulkIndexingDocument() {
        $doc = array(
            'title' => 'One cool document',
            'tag' => 'cool'
        );
        $this->search->bulkIndex($doc, 1);

        $options = array(
            'refresh' => true
        );
        $resp = $this->search->bulkSubmit($options);

        $this->assertTrue($resp['items'][0]['index']['ok'] == 1);
    }

    /**
     * Test regular string search
     */
    public function testStringSearch() {
        $this->addDocuments();
        $hits = $this->search->search("title:cool");
        $this->assertEquals(3, $hits['hits']['total']);
    }

    /**
     * Test string search with bulk indexing
     */
    public function testStringSearchBulk() {
        $this->addDocumentsBulk();
        $hits = $this->search->search("title:cool");
        $this->assertEquals(3, $hits['hits']['total']);
    }

    /**
     * Test multi index search
     */
    public function testSearchMultipleIndexes() {
        $indexes = array("test-index", "test2");
        $this->addDocuments($indexes);

        // Use both indexes when searching
        $this->search->setIndex($indexes);
        $hits = $this->search->search('title:cool');
        $this->assertEquals(count($indexes) * 3, $hits['hits']['total']);

        foreach ($indexes as $ind) {
            $this->search->setIndex($ind);
            $this->search->delete();
        }
    }

    /**
     * Test multi index search with bulk
     */
    public function testSearchMultipleIndexesBulk() {
        $indexes = array("test-index", "test2");
        $this->addDocumentsBulk($indexes);

        // Use both indexes when searching
        $this->search->setIndex($indexes);
        $hits = $this->search->search('title:cool');
        $this->assertEquals(count($indexes) * 3, $hits['hits']['total']);

        foreach ($indexes as $ind) {
            $this->search->setIndex($ind);
            $this->search->delete();
        }
    }


    /**
     * Try searching using the dsl
     */
    public function testSearch() {
        $this->addDocuments();

        $hits = $this->search->search(array(
            'query' => array(
                'term' => array('title' => 'cool')
           )
        ));
        $this->assertEquals(3, $hits['hits']['total']);
    }

    /**
     * Try searching using the dsl with bulk
     */
    public function testSearchBulk() {
        $this->addDocumentsBulk();

        $hits = $this->search->search(array(
            'query' => array(
                'term' => array('title' => 'cool')
           )
        ));
        $this->assertEquals(3, $hits['hits']['total']);
    }

    /**
     * Test sort
     */
    public function testSort() {
        $this->addDocuments();

        $arr = array(
            'sort' => array(
                array('rank' => array('reverse'=>true)),
                array('rank' => 'asc'),
                'rank'
            ),
            'query' => array(
                'term' => array('title' => 'cool')
            )
        );
        $hits = $this->search->search($arr);
        $this->assertEquals(3, $hits['hits']['total']);
    }

    /**
     * Test sort with bulk
     */
    public function testSortBulk() {
        $this->addDocumentsBulk();

        $arr = array(
            'sort' => array(
                array('rank' => array('reverse'=>true)),
                array('rank' => 'asc'),
                'rank'
            ),
            'query' => array(
                'term' => array('title' => 'cool')
            )
        );
        $hits = $this->search->search($arr);
        $this->assertEquals(3, $hits['hits']['total']);
    }
}
