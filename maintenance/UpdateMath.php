<?php
/**
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @ingroup Maintenance
 */

require_once( dirname( __FILE__ ) . '/../../../maintenance/Maintenance.php' );

class UpdateMath extends Maintenance {
	const RTI_CHUNK_SIZE = 500;
	var $purge = false;
	var $dbw = null;
	private $time = 0;//microtime( true );
	private $performance = array();

	/**
	 * @var DatabaseBase
	 */
	private $db;
	/**
	 *
	 */
	public function __construct() {
		parent::__construct();
		$this->mDescription = 'Outputs page text to stdout';
		$this->addOption( 'purge', "If set all formulae are rendered again from strech. (Very time consuming!)", false, false, "f" );
		$this->addArg( 'min', "If set processing is started at the page with rank(pageID)>min", false );
		$this->addArg( 'max', "If set processing is stopped at the page with rank(pageID)<=max", false );
	}
	private function time($category='default'){
		$delta = (microtime(true) - $this->time)*1000;
		if (isset ($this->performance[$category] ))
			$this->performance[$category] += $delta;
		else
			$this->performance[$category] = $delta;
		$this->time = microtime(true);
		return (int) $delta;
	}
	/**
	 * Populates the search index with content from all pages
	 */
	protected function populateSearchIndex( $n = 0, $cmax = -1 ) {
		$res = $this->db->select( 'page', 'MAX(page_id) AS count' );
		$s = $this->db->fetchObject( $res );
		$count = $s->count;
		if ( $cmax > 0 && $count > $cmax ) {
			$count = $cmax;
		}
		$this->output( "Rebuilding index fields for {$count} pages with option {$this->purge}...\n" );
		$fcount = 0;

		while ( $n < $count ) {
			if ( $n ) {
				$this->output( $n . " of $count \n" );
			}
			$end = $n + self::RTI_CHUNK_SIZE - 1;

			$res = $this->db->select( array( 'page', 'revision', 'text' ),
					array( 'page_id', 'page_namespace', 'page_title', 'old_flags', 'old_text' ),
					array( "page_id BETWEEN $n AND $end", 'page_latest = rev_id', 'rev_text_id = old_id' ),
					__METHOD__
			);
			$this->dbw->begin();
			// echo "before" +$this->dbw->selectField('mathindex', 'count(*)')."\n";
			$i = $n;
			foreach ( $res as $s ) {
				echo "\np$i:";
				$revtext = Revision::getRevisionText( $s );
				$fcount += self::doUpdate( $s->page_id, $revtext, $s->page_title, $this->purge, $this->dbw );
				$i++;
			}
			// echo "before" +$this->dbw->selectField('mathindex', 'count(*)')."\n";
			$start = microtime( true );
			$this->dbw->commit();
			echo " committed in " . ( microtime( true ) -$start ) . "s\n\n";
			// echo "after" +$this->dbw->selectField('mathindex', 'count(*)')."\n";
			$n += self::RTI_CHUNK_SIZE;
		}
		$this->output( "Updated {$fcount} formulae!\n" );
	}
	/**
	 * @param unknown $pId
	 * @param unknown $pText
	 * @param string $pTitle
	 * @param string $purge
	 * @return number
	 */
	private static function doUpdate( $pid, $pText, $pTitle = "", $purge = false , $dbw ) {
		// TODO: fix link id problem
		$anchorID = 0;
		$res = "";
		$pText = Sanitizer::removeHTMLcomments( $pText );
		$matches = preg_match_all( "#<math>(.*?)</math>#s", $pText, $math );
		if ( $matches ) {
			echo( "\t processing $matches math fields for {$pTitle} page\n" );
			foreach ( $math[1] as $formula ) {
				$tstart = microtime(true);
				$renderer = MathRenderer::getRenderer( $formula, array(), MW_MATH_MATHML ); 
				if ( $renderer->checkTex() ){
					$renderer->render( $purge );
				}else{
					echo "texvcheck error:" . $renderer->getLastError();
					continue;
				}
				$time = (microtime(true) - $tstart)*1000;
				//echo ( "\n\t\t rendered in $time ms.");
				$tstart = microtime(true);
				// Enable indexing of math formula
				wfRunHooks( 'MathFormulaRendered', array( &$renderer , &$notused, $pid, $anchorID ) );
				$time = (microtime(true) - $tstart)*1000;
				//echo ( "\n\t\t hook run in $time ms.");
				$tstart = microtime(true);
				$anchorID++;
				if ( $time -$tstart > 2 ) {
					echo( "\t\t slow equation " . ( $anchorID -1 ) .
						"beginning with" . substr( $formula, 0, 10 ) . "rendered in " . ( $tend -$tstart ) . "s. \n" );
				}
				$renderer->writeCache($dbw);
				$time = (microtime(true) - $tstart)*1000;
				//echo ( "\n\t\t cache writing prepared in $time ms.");
				if ( $renderer->getLastError() ) {
					echo "\n\t\t". $renderer->getLastError() ;
					echo "\nF:\t\t equation " . ( $anchorID -1 ) .
						"-failed beginning with\n\t\t'" . substr( $formula, 0, 100 )
						. "'\n\t\tmathml:" . substr($renderer->getMathml(),0,10) ."\n ";
				}
			}
			return $matches;
		}
		return 0;
	}
	/**
	 *
	 */
	public function execute() {
		$this->dbw = wfGetDB( DB_MASTER );
		$this->purge = $this->getOption( "purge", false );
		$this->db = wfGetDB( DB_MASTER );
		$this->output( "Done.\n" );
		$this->populateSearchIndex( $this->getArg( 0, 0 ), $this->getArg( 1, -1 ) );
	}
}

$maintClass = "UpdateMath";
require_once( RUN_MAINTENANCE_IF_MAIN );
