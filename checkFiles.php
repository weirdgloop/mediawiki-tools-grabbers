<?php
/**
 * Maintenance script to check the integrity of the files in the database. This doesn't modify the database -
 * it simply logs each missing file.
 *
 * Realistically, you should probably pipe this script's output to a file for some more in-depth analysis.
 *
 * @file
 * @ingroup Maintenance
 * @author Jayden Bailey <jayden@weirdgloop.org>
 * @version 1.0
 * @date 10 September 2023
 */

require_once 'includes/FileGrabber.php';

class CheckFiles extends FileGrabber {

	/**
	 * The report interval
	 *
	 * @var int
	 */
	protected $reportInterval = 5000;

	/**
	 * End date
	 *
	 * @var string
	 */
	protected $endDate;

	public function __construct() {
		parent::__construct();
		$this->addDescription('Checks that our database contains all of the remote wiki\'s files');
		$this->addOption( 'enddate', 'Any file after this time will not be checked on the remote wiki', false, true );
		$this->addOption( 'report', 'Report position after every n revisions processed (default is 5000)', false, true );
	}

	public function execute() {
		parent::execute();
		$this->reportInterval = intval( $this->getOption( 'report', 5000 ) );

		$this->endDate = $this->getOption( 'enddate' );
		if ( $this->endDate ) {
			if (!wfTimestamp(TS_ISO_8601, $this->endDate)) {
				$this->fatalError('Invalid end date format.');
			}
		}

		$params = [
			'action' => 'query',
			'generator' => 'allimages',
			'gailimit' => 'max',
			'prop' => 'imageinfo',
			'iiprop' => 'timestamp|user|userid|comment|url|size|sha1|mime|archivename|bitdepth|mediatype',
			'iilimit' => 'max',
		];

		$gaifrom = null;
		$more = true;
		$count = 0;
		$missing = 0;
		$checkpoint = $this->reportInterval;

		if ( $gaifrom !== null ) {
			$params['gaifrom'] = $gaifrom;
		}

		$this->output( "Checking files...\n" );
		while ( $more ) {
			$req = $this->externalWikiService->fetch( $params );
			if ( !$req->isOK() ) {
				$this->fatalError( "Unable to fetch file list: {$req->getMessages()[0]->getKey()}" );
			}
			$result = $req->getValue();
			if ( empty( $result['query']['pages'] ) ) {
				$this->fatalError( 'No files found...' );
			}

			$files = array_merge( ...array_map( $this->flattenFileEntry( ... ), $result['query']['pages'] ) );

			foreach ( $files as $file ) {
				if ( array_key_exists( 'archivename', $file['info'] ) ) {
					$res = $this->dbw->newSelectQueryBuilder()
						->select( '1' )
						->from( 'oldimage' )
						->where( [
							'oi_name' => $file['name'],
							'oi_archive_name' => $file['info']['archivename'],
							'oi_timestamp' => wfTimestamp( TS_MW, $file['info']['timestamp'] ),
						] )
						->caller( __METHOD__ )
						->fetchField();
					if ( !$res ) {
						$this->output( "{$file['info']['timestamp']} | {$file['name']} [old]\n" );
						$missing++;
					}
				} else {
					$res = $this->dbw->newSelectQueryBuilder()
						->select( '1' )
						->from( 'image' )
						->where( [
							'img_name' => $file['name'],
							'img_timestamp' => wfTimestamp( TS_MW, $file['info']['timestamp'] ),
						] )
						->caller( __METHOD__ )
						->fetchField();
					if ( !$res ) {
						$this->output( "{$file['info']['timestamp']} | {$file['name']} [current]\n" );
						$missing++;
					}
				}
				$count++;

				if ( $count >= $checkpoint ) {
					$this->output( "{$count} files processed.\n" );
					$checkpoint = $checkpoint + $this->reportInterval;
				}
			}

			if ( isset( $result['query-continue'] ) ) {
				$gaifrom = $result['query-continue']['allimages']['gaifrom'];
			} elseif ( isset( $result['continue'] ) ) {
				$params = array_merge( $params, $result['continue'] );
			} else {
				$more = false;
			}
		}

		$this->output( "Done.\n\n$count files checked.\n$missing files missing.\n" );
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array{old:bool,name:string,info:array<string,mixed>}[]
	 */
	private function flattenFileEntry( array $entry ): array {
		$name = $this->sanitiseTitle( $entry['ns'], $entry['title'] );

		if ( !$entry['imageinfo'] ) {
			return [];
		}

		$count = 0;
		$result = [];
		foreach ( $entry['imageinfo'] as $fileInfo ) {
			// Skip missing file version.
			if ( isset( $fileInfo['filemissing'] ) ) {
				continue;
			}

			# Check for Wikia's videos
			if ( $this->isWikiaVideo( $fileInfo ) ) {
				return [];
			}

			$result[] = [
				'old' => $count > 0,
				'name' => $name,
				'info' => $fileInfo
			];
			$count++;
		}

		return $result;
	}
}

$maintClass = 'CheckFiles';
require_once RUN_MAINTENANCE_IF_MAIN;
