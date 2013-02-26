<?php
class MathObject extends MathRenderer {
	protected $anchorID = 0;
	protected $pageID = 0;
	protected $index_timestamp=null;
	public function getAnchorID() {
		return $this->anchorID;
	}
	public function setAnchorID( $ID ) {
		$this->anchorID = $ID;
	}
	public function getPageID() {
		return $this->pageID;
	}
	public function setPageID( $ID ) {
		$this->pageID = $ID;
	}
	public function getIndexTimestamp(){
		return $this->index_timestamp;
	}
	public static function constructformpagerow($res){
		global $wgDebugMath;
		if($res->mathindex_page_id>0){
		$instance = new self();
		$instance->setPageID($res->mathindex_page_id);
		$instance->setAnchorID($res->mathindex_anchor);
		if ($wgDebugMath){
			$instance->index_timestamp=$res->mathindex_timestamp;
		}
		$instance->inputHash=$res->mathindex_inputhash;
		$instance->readDatabaseEntry();
		wfDebugLog("MathSearch",'got'.var_export($instance,true));
		return $instance;
		} else {
			return false;
		}
	}
	public static function findSimilarPages($pid){
		global $wgOut;
		$out="";
		$dbr=wfGetDB(DB_SLAVE);
		try{
		$res=$dbr->select('mathpagesimilarity',
			array('pagesimilarity_A as A','pagesimilarity_B as B','pagesimilarity_Value as V'),
			"pagesimilarity_A=$pid OR pagesimilarity_B=$pid",
			__METHOD__,
			array("ORDER BY"=>'V DESC', "LIMIT"=>10)
			);
		foreach($res as $row){
			if($row->A==$pid){
				$other=$row->B;
			} else {
				$other = $row->A;
			}
			$article = WikiPage::newFromId( $other);
			$out.='# [['.$article->getTitle().']] similarity '.
					$row->V * 100 . "%\n";
					//.' ( pageid'.$other.'/'.$row->A.')' );
		}
		$wgOut->addWikiText($out);
		}catch(Exception $e){
			return "DatabaseProblem";
		}
	}
	public function getObservations(){
		global $wgOut;
		$dbr=wfGetDB(DB_SLAVE);
		try{
		$res=$dbr->select(array("mathobservation","mathvarstat",'mathpagestat')
				, array("mathobservation_featurename", "mathobservation_featuretype",'varstat_featurecount',
						'pagestat_featurecount', "count(*) as localcnt"),
				array("mathobservation_inputhash"=>$this->getInputHash(),
						'varstat_featurename = mathobservation_featurename',
						'varstat_featuretype = mathobservation_featuretype',
						'pagestat_pageid'=>$this->getPageID(),
						'pagestat_featureid = varstat_id'
						)
				,__METHOD__,
				array('GROUP BY'=>'mathobservation_featurename',
						'ORDER BY'=>'varstat_featurecount')
				);
		}catch(Exception $e){
			return "DatabaseProblem";
		}
		if ($res){
		foreach($res as $row){
			$wgOut->addWikiText('*'.$row->mathobservation_featuretype.' <code>'.
					utf8_decode($row->mathobservation_featurename).'</code> ('.$row->localcnt.'/'
					.$row->pagestat_featurecount."/".$row->varstat_featurecount.')' );
		}}
	}
	
	public function updateObservations($dbw=null){
		$this->readFromDB();
		preg_match_all("#<(mi|mo)( ([^>].*?))?>(.*?)</\\1>#u", $this->mathml,$rule,PREG_SET_ORDER);
		if($dbw==null){
			$dbgiven=false;
			$dbw = wfGetDB( DB_MASTER );
			$dbw->begin();
		} else {
			$dbgiven=true;
		}
		$dbw->delete("mathobservation", array("mathobservation_inputhash"=>$this->getInputHash()));
		foreach($rule as $feature){
			$dbw->insert("mathobservation", array(
					"mathobservation_inputhash"=>$this->getInputHash(),
					"mathobservation_featurename"=>utf8_encode($feature[4]),
					"mathobservation_featuretype"=>utf8_encode($feature[1]),
			));
		if(!$dbgiven){
			$dbw->commit();
			}
			
		}
		
	}
	public static function constructformpage($pid,$eid){
		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->selectRow(
				array('mathindex'),
				self::dbIndexFieldsArray(),
				'mathindex_page_id = ' . $pid
				.' AND mathindex_anchor= ' . $eid
		);
		wfDebugLog("MathSearch",var_export($res,true));
		return self::constructformpagerow($res);
	}
	
	/**
	 * Gets all occurences of the tex.
	 * @return array(MathObject)
	 */
	public function getAllOccurences(){
		$out=array();
		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->select(
				'mathindex',
				self::dbIndexFieldsArray(),
				array('mathindex_inputhash'=>$this->getInputHash())
		);
		
		foreach($res as $row){
			wfDebugLog("MathSearch",var_export($row,true));
			$var=self::constructformpagerow($row);
			if ($var){
				$var->printLink2Page(false);
				array_push($out, $var);
			}
		}
		return $out;
	}
	public function getPageTitle(){
		$article = Article::newFromId( $this->getPageID());
		return (string)$article->getTitle();
	}
	
	public function printLink2Page($hidePage=true){
		global $wgOut;
		$wgOut->addHtml( "&nbsp;&nbsp;&nbsp;" );
		$pageString=$hidePage?"":$this->getPageTitle()." ";
		$wgOut->addWikiText( "[[".$this->getPageTitle()."#math".$this->getAnchorID()
				."|".$pageString."Eq: ".$this->getAnchorID()."]] ", false );
		//$wgOut->addHtml( MathLaTeXML::embedMathML( $this->mathml ) );
		$wgOut->addHtml( "<br />" );
	}
	
	/**
	 * @return Ambigous <multitype:, multitype:unknown number string mixed >
	 */
	private static function dbIndexFieldsArray(){
		global $wgDebugMath;
		$in= array(
				'mathindex_page_id',
				'mathindex_anchor'  ,
				'mathindex_inputhash');
		if ($wgDebugMath){
			$debug_in= array(
					'mathindex_timestamp');
			$in=array_merge($in,$debug_in);
		}
		return $in;
	}
	
	public function render($purge = false){
		
	}
}
/*
 * $sql = "INSERT INTO varstat (\n"
    . "`varstat_featurename` ,\n"
    . "` varstat_featuretype` ,\n"
    . "`varstat_featurecount`\n"
    . ") SELECT `mathobservation_featurename`,`mathobservation_featuretype`, count(*) as CNT FROM `mathobservation` JOIN mathindex on `mathobservation_inputhash` =mathindex_inputhash GROUP by `mathobservation_featurename`, `mathobservation_featuretype` ORDER BY CNT DESC";
    
    
    $sql = "INSERT INTO mathpagestat(`pagestat_featurename`,`pagestat_featuretype`,`pagestat_pageid`,`pagestat_featurecount`)\n"
    . "SELECT `mathobservation_featurename`,`mathobservation_featuretype`,mathindex_page_id, count(*) as CNT FROM `mathobservation` JOIN mathindex on `mathobservation_inputhash` =mathindex_inputhash GROUP by `mathobservation_featurename`, `mathobservation_featuretype`,mathindex_page_id ORDER BY CNT DESC";
 */