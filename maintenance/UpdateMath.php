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

/**
 * Class UpdateMath
 */
class UpdateMath extends Maintenance {
	const RTI_CHUNK_SIZE = 100;
	public $purge = false;
	/** @var boolean */
	private $verbose;
	/** @var DatabaseBase */
	public $dbw;
	/** @var DatabaseBase */
	private $db;
	/** @var MathRenderer  */
	private $current;
	private $time = 0.0; // microtime( true );
	private $performance = array();
	private $renderingMode = 7; // MW_MATH_LATEXML

	/**
	 *
	 */
	public function __construct() {
		parent::__construct();
		$this->mDescription = 'Updates the index of Mathematical formulae.';
		$this->addOption( 'purge', "If set all formulae are rendered again without using caches. (Very time consuming!)", false, false, "f" );
		$this->addArg( 'min', "If set processing is started at the page with rank(pageID)>min", false );
		$this->addArg( 'max', "If set processing is stopped at the page with rank(pageID)<=max", false );
		$this->addOption( 'verbose', "If set output for successful rendering will produced",false,false,'v' );
		$this->addOption( 'SVG', "If set SVG images will be produced", false, false );
		$this->addOption( 'hoooks', "If set hooks will be skipped, but index will be updated.", false, false );
		$this->addOption( 'texvccheck', "If set texvccheck will be skipped", false, false );
		$this->addOption( 'mode' , 'Rendering mode to be used (0 = PNG, 5= MathML, 7=MathML)',false,true,'m');
	}

	/**
	 * Measures time in ms.
	 * In order to have a formula centric evaluation, we can not just the build in profiler
	 * @param string $category
	 *
	 * @return int
	 */
	private function time( $category = 'default' ){
		global $wgMathDebug;
		$delta = ( microtime( true ) - $this->time ) * 1000;
		if (isset ($this->performance[$category] ))
			$this->performance[$category] += $delta;
		else
			$this->performance[$category] = $delta;
		if($wgMathDebug){
			$this->db->insert('mathperformance',array(
				'math_inputhash' => $this->current->getInputHash(),
				'mathperformance_name' => substr($category,0,10),
				'mathperformance_time' => $delta,
			    'mathperformance_mode' => $this->renderingMode
			));

		}
		$this->time = microtime(true);

		return (int) $delta;
	}

	/**
	 * Populates the search index with content from all pages
	 *
	 * @param int $n
	 * @param int $cMax
	 *
	 * @throws DBUnexpectedError
	 */
	protected function populateSearchIndex( $n = 0, $cMax = -1 ) {
		$res = $this->db->select( 'page', 'MAX(page_id) AS count' );
		$s = $this->db->fetchObject( $res );
		$count = $s->count;
		if ( $cMax > 0 && $count > $cMax ) {
			$count = $cMax;
		}
		$this->output( "Rebuilding index fields for {$count} pages with option {$this->purge}...\n" );
		$fCount = 0;
		//return;
		while ( $n < $count ) {
			if ( $n ) {
				$this->output( $n . " of $count \n" );
			}
			$end = min( $n + self::RTI_CHUNK_SIZE - 1, $count );

			$res = $this->db->select( array( 'page', 'revision', 'text' ),
					array( 'page_id', 'page_namespace', 'page_title', 'old_flags', 'old_text', 'rev_id' ),
					array( "rev_id BETWEEN $n AND $end", 'page_latest = rev_id', 'rev_text_id = old_id' ),
					__METHOD__
			);
			$this->dbw->begin();
			// echo "before" +$this->dbw->selectField('mathindex', 'count(*)')."\n";
			$i = $n;
			foreach ( $res as $s ) {
				echo "\np$i:";
				$revText = Revision::getRevisionText( $s );
				$fCount += $this->doUpdate( $s->page_id, $revText, $s->page_title, $s->rev_id );
				$i++;
			}
			// echo "before" +$this->dbw->selectField('mathindex', 'count(*)')."\n";
			$start = microtime( true );
			$this->dbw->commit();
			echo " committed in " . ( microtime( true ) -$start ) . "s\n\n";
			var_dump($this->performance);
			// echo "after" +$this->dbw->selectField('mathindex', 'count(*)')."\n";
			$n += self::RTI_CHUNK_SIZE;
		}
		$this->output( "Updated {$fCount} formulae!\n" );
	}

	/**
	 * @param int     $pid
	 * @param string  $pText
	 * @param string  $pTitle
	 * @param int     $revId
	 *
	 * @return number
	 */
	private function doUpdate( $pid, $pText, $pTitle = "", $revId = 0) {
		$notused = '';
		$eId = 0;
		$math = MathObject::extractMathTagsFromWikiText( $pText );
		$matches = sizeof( $math );
		if ( $matches ) {
			echo( "\t processing $matches math fields for {$pTitle} page\n" );
			foreach ( $math as $formula ) {
				$this->time = microtime(true);
				$renderer = MathRenderer::getRenderer( $formula[1], $formula[2], $this->renderingMode );
				$this->current = $renderer;
				$this->time("loadClass");
				if ( $this->getOption( "texvccheck", false ) ) {
					$checked = true;
				} else {
					$checked = $renderer->checkTex();
					$this->time("checkTex");
				}
				if ( $checked ) {
					if( ! $renderer->isInDatabase() || $this->purge ) {
						$renderer->render( $this->purge );
						if( $renderer->getMathml() ){
							$this->time("render");
						} else {
							$this->time("Failing");
						}
						if ( $this->getOption( "SVG", false ) ) {
							$svg = $renderer->getSvg();
							if ( $svg ) {
								$this->time( "SVG-Rendering" );
							} else {
								$this->time( "SVG-Fail" );
							}
						}
					} else {
						$this->time('checkInDB');
					}
				} else {
					$this->time("checkTex-Fail");
					echo "\nF:\t\t".$renderer->getMd5()." texvccheck error:" . $renderer->getLastError();
					continue;
				}
				if ( ! $this->getOption( "hooks", false ) ) {
					wfRunHooks( 'MathFormulaRendered', array( &$renderer, &$notused, $pid, $eId ) );
					$this->time( "hooks" );
					$eId++;
				} else {
					MathSearchHooks::writeMathIndex( $revId, $eId, $renderer->getInputHash(), '' );
					$this->time( "index" );
					$eId++;
				}
				$renderer->writeCache($this->dbw);
				$this->time("write Cache");
				if ( $renderer->getLastError() ) {
					echo "\n\t\t". $renderer->getLastError() ;
					echo "\nF:\t\t".$renderer->getMd5()." equation " . ( $eId -1 ) .
						"-failed beginning with\n\t\t'" . substr( $formula, 0, 100 )
						. "'\n\t\tmathml:" . substr($renderer->getMathml(),0,10) ."\n ";
				} else{
					if($this->verbose){
						echo "\nS:\t\t".$renderer->getMd5();
					}
				}
			}
			return $matches;
		}
		return 0;
	}

	public function execute() {
		global $wgMathValidModes;
		$this->dbw = wfGetDB( DB_MASTER );
		$this->purge = $this->getOption( "purge", false );
		$this->verbose = $this->getOption("verbose",false);
		$this->renderingMode = $this->getOption( "mode" , 7);
		$this->db = wfGetDB( DB_MASTER );
		$wgMathValidModes[] = $this->renderingMode;
		$this->output( "Loaded.\n" );
		$this->time = microtime( true );
		$this->populateSearchIndex( $this->getArg( 0, 0 ), $this->getArg( 1, -1 ) );
	}
}

$maintClass = "UpdateMath";
/** @noinspection PhpIncludeInspection */
require_once( RUN_MAINTENANCE_IF_MAIN );
