<?php
/**
 * Grabs deleted files from a pre-existing wiki into a new wiki. Only works with mw1.17+.
 * Also only works with target wikis using the default hashing structure. (Wikia's do.)
 *
 * @file
 * @ingroup Maintenance
 * @author Calimonious the Estrange
 * @date 15 March 2019
 * @note Based on code by Jack Phoenix and Edward Chernenko.
 */

require_once 'includes/FileGrabber.php';

class GrabDeletedFiles extends FileGrabber {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Grabs deleted files from a pre-existing wiki into a new wiki.' );
		$this->addOption( 'imagesurl', 'URL to the target wiki\'s images directory', false, true, 'i' );
		$this->addOption( 'scrape', 'Use screenscraping instead of the API?'
			. ' (note: you don\'t want to do this unless you really have to.)', false, false, 's' );
		$this->addOption( 'fafrom', 'Start point from which to continue with metadata.', false, true, 'start' );
		$this->addOption( 'skipmetadata', 'If you\'ve already populated oldarchive and just need to resume file downloads,'
			. ' use this to avoid duplicating the metadata db entries', false, false, 'm' );
		$this->addOption( 'from', 'Start point from which to continue file downloads. Must match an actual file record in the database.', false, true );
	}

	public function execute() {
		parent::execute();

		$imagesurl = $this->getOption( 'imagesurl' );
		$scrape = $this->hasOption( 'scrape' );
		if ( !$imagesurl && !$scrape ) {
			$this->fatalError( 'Unless we\'re screenscraping it, the URL to the target wiki\'s images directory is required.' );
		}

		$skipMetaData = $this->hasOption( 'skipmetadata' );

		if ( !$skipMetaData ) {
			$params = [
				'list' => 'filearchive',
				'falimit' => 'max',
				'faprop' => 'sha1|timestamp|user|size|dimensions|description|mime|metadata|bitdepth'
			];

			$fafrom = $this->getOption( 'fafrom' );
			if ( $fafrom !== null ) {
				$params['fafrom'] = $fafrom;
			}
			$more = true;
			$count = 0;

			$this->output( "Processing file metadata...\n" );
			while ( $more ) {
				$result = $this->bot->query( $params );
				if ( empty( $result['query']['filearchive'] ) ) {
					$this->fatalError( 'No files found...' );
				}

				foreach ( $result['query']['filearchive'] as $fileVersion ) {
					if ( ( $count % 500 ) == 0 ) {
						$this->output( "$count\n" );
					}
					$this->processFile( $fileVersion );
					$count++;
				}

				if ( isset( $result['query-continue'] ) && isset( $result['query-continue']['filearchive'] ) ) {
					$params = array_merge( $params, $result['query-continue']['filearchive'] );
				} elseif ( isset( $result['continue'] ) ) {
					$params = array_merge( $params, $result['continue'] );
				} else {
					$more = false;
				}
			}
			$this->output( "$count files found.\n" );

			$this->output( "\n" );
		}

		$this->output( "Downloading files... missing ones may have been deleted, or may be a sign of script failure. You may want to check via Special:Undelete.\n" );
		$count = 0;
		$dbr = wfGetDB( DB_REPLICA );
		$result = $dbr->select(
			'filearchive',
			[ 'fa_storage_key', 'fa_name' ],
			[],
			__METHOD__
		);

		$from = $this->getOption( 'from' );

		if ( $scrape ) {
			# Get the URL for this
			$queryGeneral = $this->bot->query( [ 'meta' => 'siteinfo' ] )['query']['general'];
			$articlePath = $queryGeneral['articlepath'];
			$serverURL = $queryGeneral['server'];
		}

		foreach ( $result as $row ) {
			$fileName = $row->fa_name;

			# This is really stupid, but, er, whatever.
			if ( $from !== null && $fileName !== $from ) {
				continue;
			} else {
				$from = null;
			}

			$file = $row->fa_storage_key;
			$path = $this->localRepo->getZonePath( 'deleted' ) . "/$file";

			$this->output( "Processing $fileName" );

			if ( $this->localRepo->fileExists( $path ) ) {
				// Skip re-downloading.
				$this->output("\n");
				continue;
			} elseif ( !$scrape ) {
				# $imagesurl should be something like http://images.wikia.com/uncyclopedia/images
				# Example image: http://images.wikia.com/uncyclopedia/images/deleted/a/b/c/abcblahhash.png
				$fileurl = $imagesurl . '/deleted/' . $file[0] . '/' . $file[1] . '/' . $file[2] . '/' . $file;
				$fileContent = @file_get_contents( $fileurl );

				if ( !$fileContent ) {
					$this->output( ": not found on remote server.\n" );
					continue;
				}
			} else {
				# Oh shit we gotta screenscrape Special:Undelete
				$undeletePage = $serverURL . substr( $articlePath, 0, -2 ) . 'Special:Undelete/File:' . urlencode( $fileName );

				$fileContent = $this->scrapeFileContent( $undeletePage, $fileName, $file, $serverURL );

				if ( !$fileContent ) {
					continue;
				}
			}

			$tmpPath = tempnam( wfTempDir(), 'grabfile' );
			file_put_contents( $tmpPath, $fileContent );
			$this->localRepo->quickImport( $tmpPath, $path );
			unlink( $tmpPath );

			$this->output( ": successfully saved as $file\n" );
			if ( ( $count % 500 ) == 0 && $count !== 0 ) {
				$this->output( "$count\n" );
			}
			$count++;
		}
	}

	# Dumb scrape thing
	function scrapeFileContent( $undeletePage, $fileName, $file, $serverURL ) {
		global $wgUploadDirectory;

		# $this->output( "\nRequesting undelete page: $undeletePage" );
		$specialUndeletePage = $this->bot->curl_get( $undeletePage );

		if ( $specialUndeletePage[0] ) {
			$numMatches = preg_match_all(
				'/<a href="([^"]+\?target=.*file=.*token=[a-zA-Z0-9%]*)"/',
				$specialUndeletePage[1], $matches, PREG_SET_ORDER
			);

			if ( !$numMatches ) {
				$this->output( "\nScraping: No target revisions for $fileName found.\n" );
				file_put_contents( $wgUploadDirectory . '/lastfailedundeletepage.html', $specialUndeletePage[1] );

				return false;
			} else {
				$fileContent = [ false, "$file: revision not found" ];

				foreach ( $matches as $result ) {
					$url = $result[1];

					# The only thing we actually need to change in $url is to convert '&amp;'
					# into actual ampersands. So let's do that.
					$url = str_replace( '&amp;', '&', $url );

					# Because the overall logic of this script doesn't actually expect this
					# approach, we're actually just looking for the specific one...
					if ( strpos( $url, urlencode( $file ) ) === false ) {
						continue;
					}

					# Sometimes they randomly have the fullurl ?!
					if ( substr( $url, 0, 1 ) == '/' ) {
						$downloadTarget = $serverURL . $url;
					} else {
						$downloadTarget = $url;
					}

					# $this->output( "\nDownloading file content: $downloadTarget" );

					$fileContent = $this->bot->curl_get( $downloadTarget );

					# if ( !$fileContent[0] ) {
					# 	$this->output( "$fileContent[1] for $downloadTarget\n" );
					# }

					break;
				}
				if ( !$fileContent[0] ) {
					$this->output( "\n$fileContent[1]\n" );
					return false;
				} else {
					# Errors handled; set to just actual content now
					$fileContent = $fileContent[1];
					# For debugging: quick visual check if it's even actually a file
					$this->output( " (first four characters: " . substr( $fileContent, 0, 4 ) . ")" );

					return $fileContent;
				}
			}
		} else {
			$this->output( "$specialUndeletePage[1]\n" );
			return false;
		}
	}

	function processFile( $entry ) {
		// Can't view this file.
		if ( !isset( $entry['size'] ) ) {
			return;
		}

		if ( isset( $entry['user'] ) ) {
			$actor = $this->getActorFromUser( (int)$entry['userid'], $entry['user'] );
		} else {
			$actor = 0;
		}

		$comment = $entry['description'] ?? '';
		$commentFields = $this->commentStore->insert( $this->dbw, 'fa_description', $comment );

		$e = [
			'fa_name' => $entry['name'],
			'fa_size' => $entry['size'],
			'fa_width' => $entry['width'],
			'fa_height' => $entry['height'],
			'fa_bits' => $entry['bitdepth'],
			'fa_actor' => $actor,
			'fa_timestamp' => wfTimestamp( TS_MW, $entry['timestamp'] ),
			'fa_storage_group' => 'deleted',
			'fa_media_type' => null,
			'fa_deleted' => 0
		] + $commentFields;

		$mime = $entry['mime'];
		$mimeBreak = strpos( $mime, '/' );
		$e['fa_major_mime'] = substr( $entry['mime'], 0, $mimeBreak );
		$e['fa_minor_mime'] = substr( $entry['mime'], $mimeBreak + 1 );

		$e['fa_metadata'] = serialize( $entry['metadata'] );

		$ext = strtolower( pathinfo( $entry['name'], PATHINFO_EXTENSION ) );
		# Because that doesn't actually resolve extension aliases, and we need these keys to match the actual files
		# TODO: are there others we should be checking? Hasn't there gotta be a better way to do this?
		if ( $ext == 'jpeg' ) {
			$ext = 'jpg';
		}
		if ( $ext == 'ogv' ) {
			$ext = 'ogg';
		}
		$e['fa_storage_key'] = ltrim( Wikimedia\base_convert( $entry['sha1'], 16, 36, 40 ), '0' ) .
			'.' . $ext;

		# We could get these other fields from logging, but they appear to have no purpose so SCREW IT.
		$e['fa_deleted_user'] = 0;
		$e['fa_deleted_timestamp'] = null;
		$e['fa_deleted_reason_id'] = 0;
		$e['fa_archive_name'] = null; # UN:N; MediaWiki figures it out anyway.

		// Avoid adding duplicate entries.
		$row = $this->dbw->selectRow(
			'filearchive',
			[
				'1',
			],
			$e,
			__METHOD__
		);
		if ( !$row ) {
			$this->dbw->insert( 'filearchive', $e, __METHOD__ );
		}

		# $this->output( "Changes committed to the database!\n" );
	}
}

$maintClass = 'GrabDeletedFiles';
require_once RUN_MAINTENANCE_IF_MAIN;
