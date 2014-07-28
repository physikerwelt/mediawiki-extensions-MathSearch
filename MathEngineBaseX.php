<?php

/**
 * MediaWiki MathSearch extension
 *
 * (c) 2014 Moritz Schubotz
 * GPLv2 license; info in main package.
 *
 * @file
 * @ingroup extensions
 */
class MathEngineBaseX {
	/** @var MathQueryObject the query to be answered*/
	protected $query;
	protected $size = false;
	protected $resultSet;
	protected $relevanceMap;

    /**
	 * 
	 * @return MathQueryObject
	 */
	public function getQuery() {
		return $this->query;
	}

	function __construct(MathQueryObject $query) {
		$this->query = $query;
	}
	public function getSize() {
		return $this->size;
	}

	public function getResultSet() {
		return $this->resultSet;
	}

	public function getRelevanceMap() {
		return $this->relevanceMap;
	}

		/**
	 * 
	 * @param MathQueryObject $query
	 * @return \MathSearchEngine
	 */
	public function setQuery(MathQueryObject $query) {
		$this->query = $query;
		return $this;
	}


	/**
	 * Posts the query to BaseX and evaluates the results
	 * @return boolean
	 */
	function postQuery() {
        global $wgMathSearchBaseXSupport, $wgMathSearchBaseXDatabaseName;
		if ( ! $wgMathSearchBaseXSupport) {
			throw new MWException( 'BaseX support is disabled.' );
		}
		$session = new BaseXSession();
		$session->execute("open $wgMathSearchBaseXDatabaseName");
		$res = $session->execute( "xquery ".$this->query->getXQuery() );
		$this->relevanceMap = array();
		$this->resultSet = array();
		if( $res ){
			$baseXRegExp = "/<a .*? href=\"http.*?curid=(\d+)#math(\d?)\"/";
			preg_match_all( $baseXRegExp , $res, $matches,PREG_SET_ORDER);
			foreach($matches as $match){
				$mo = MathObject::constructformpage($match[1],$match[2]);
				$this->relevanceMap[(string) $mo->getPageID()]=true;
				$this->resultSet[(string) $mo->getPageID()][(string) $mo->getAnchorID()][] = array( "xpath" => '/', "mappings" => array() ); // ,"original"=>$page->asXML()
			}
		} else {
			$this->size = 0;
		}
		return true;
	}
}