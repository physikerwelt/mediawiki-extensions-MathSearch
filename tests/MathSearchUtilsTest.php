<?php
/**
 * Test the MathSearchUtils script.
 *
 * @group MathSearch
 */
class MathUtilsTest extends MediaWikiTestCase {
	private $expectedOutput = '{| class="wikitable sortable"
|-
! s !! i
|-
| a
| 1
|-
| b
| 2
|}
';
	public function test() {
		$dbw = wfGetDB( DB_MASTER );
		if ( $dbw->getType() !== 'mysql' )
			$this->markTestSkipped( __METHOD__ . " supports MySql databases only." );
		$dbw->query( 'CREATE TEMPORARY TABLE IF NOT EXISTS tmp_math_util_test (s TEXT, i INT)' );
		$dbw->insert( "tmp_math_util_test", array(array('s'=>'a', 'i'=>1),array('s'=>'b','i'=>2)));
		$cols = array('s','i');
		$res = $dbw->select( 'tmp_math_util_test', $cols );
		$this->assertEquals($this->expectedOutput,MathSearchUtils::dbRowToWikiTable($res,$cols));
	}
}