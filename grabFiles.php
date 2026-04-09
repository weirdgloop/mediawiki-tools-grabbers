<?php
/**
 * Grabs files from a pre-existing wiki into a new wiki.
 * Merge back into grabImages or something later.
 *
 * @file
 * @ingroup Maintenance
 * @author Calimonious the Estrange
 * @author Jesús Martínez <martineznovo@gmail.com>
 * @date 31 December 2012
 * @version 1.0
 * @note Based on code by Misza, Jack Phoenix and Edward Chernenko.
 */

use Wikimedia\Timestamp\TimestampFormat;

require_once 'includes/FileGrabber.php';

class GrabFiles extends FileGrabber {

	protected ?string $endDate;

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Grabs files from a pre-existing wiki into a new wiki, using file upload configuration of the local wiki.'
		);
		$this->addOption( 'from', 'Name of file to start from', false, true );
		$this->addOption( 'to', 'Name of file to end at', false, true );
		$this->addOption( 'enddate', 'Date after which to ignore new files (20121222142317, 2012-12-22T14:23:17Z, etc)', false, true );

		$this->setBatchSize( 10 );
	}

	public function execute() {
		parent::execute();

		$this->endDate = $this->getOption( 'enddate' );
		if ( $this->endDate ) {
			$this->endDate = wfTimestamp( TS_MW, $this->endDate );
			if ( !$this->endDate ) {
				$this->fatalError( 'Invalid enddate format.' );
			}
		} else {
			$this->endDate = wfTimestampNow();
		}

		$params = [
			'generator' => 'allimages',
			'gailimit' => 'max',
			'prop' => 'imageinfo',
			'iiprop' => 'timestamp|user|userid|comment|url|size|sha1|mime|archivename|bitdepth|mediatype',
			'iilimit' => 'max',
		];

		$gaifrom = $this->getOption( 'from' );
		$gaito = $this->getOption( 'to' );
		$more = true;
		$count = 0;

		if ( $gaifrom !== null ) {
			$params['gaifrom'] = $gaifrom;
		}

		if ( $gaito !== null ) {
			$params['gaito'] = $gaito;
		}

		$this->output( "Processing and downloading files...\n" );
		while ( $more ) {
			$result = $this->bot->query( $params );
			if ( empty( $result['query']['pages'] ) ) {
				$this->fatalError( 'No files found...' );
			}

			$files = array_merge( ...array_map( $this->flattenFileEntry( ... ), $result['query']['pages'] ) );

			foreach ( array_chunk( $files, $this->getBatchSize() ) as $batch ) {
				$newFiles = array_filter( $batch, static fn ( $file ) => !$file['old'] );
				$oldFiles = array_filter( $batch, static fn ( $file ) => $file['old'] );
				$this->output( 'Batch-uploading ' . count( $batch ) . " files...\n" );
				$results = $this->uploadFiles( $newFiles, $oldFiles );
				foreach ( $results as [ 'status' => $status, 'name' => $name ] ) {
					if ( $status->isOK() ) {
						$count++;
					} else {
						$this->output( "Error occurred while processing $name:\n" );
						$this->error( $status );
					}
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
		$this->output( "$count files downloaded.\n" );
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array{old:bool,name:string,info:array<string,mixed>}[]
	 */
	private function flattenFileEntry( array $entry ): array {
		$name = $this->sanitiseTitle( $entry['ns'], $entry['title'] );
		$this->output( "Processing $name...\n" );

		if ( !$entry['imageinfo'] ) {
			$this->output( "...no imageinfo!\n" );
			return [];
		}

		$count = 0;
		$result = [];
		foreach ( $entry['imageinfo'] as $fileInfo ) {
			// Skip missing file version.
			if ( isset( $fileInfo['filemissing'] ) ) {
				$this->output( "Skipping missing file version...\n" );
				continue;
			}

			// Api returns file revisions from new to old.
			// WARNING: If a new version of a file is uploaded after the start of the script
			// (or endDate), the file and all its previous revisions would be skipped,
			// potentially leaving pages that were using the old image with redlinks.
			// To prevent this, we'll skip only more recent versions, and mark the first
			// one before the end date as the latest
			if ( !$count && wfTimestamp( TimestampFormat::MW, $fileInfo['timestamp'] ) > $this->endDate ) {
				continue;
			}

			# Check for Wikia's videos
			if ( $this->isWikiaVideo( $fileInfo ) ) {
				$this->output( "...this appears to be a video, skipping it.\n" );
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

$maintClass = 'GrabFiles';
require_once RUN_MAINTENANCE_IF_MAIN;
