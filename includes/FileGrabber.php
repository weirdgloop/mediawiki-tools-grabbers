<?php
/**
 * Base class used for file grabbers
 *
 * @file
 * @ingroup Maintenance
 * @author Jesús Martínez <martineznovo@gmail.com>
 * @date 6 December 2023
 * @version 1.1
 * @note Based on code by Calimonious the Estrange, Misza, Jack Phoenix and Edward Chernenko.
 */

use GuzzleHttp\Psr7\LazyOpenStream;
use MediaWiki\FileRepo\File\File;
use MediaWiki\FileRepo\LocalRepo;
use MediaWiki\MediaWikiServices;
use Wikimedia\Mime\MimeAnalyzer;
use Wikimedia\Timestamp\TimestampFormat;

require_once 'ExternalWikiGrabber.php';

abstract class FileGrabber extends ExternalWikiGrabber {

	/**
	 * Local file repository
	 *
	 * @var LocalRepo
	 */
	protected $localRepo;

	/**
	 * Mime type analyzer
	 *
	 * @var MimeAnalyzer
	 */
	protected $mimeAnalyzer;

	/**
	 * The target wiki is on Wikia
	 *
	 * @var boolean
	 */
	protected $isWikia;

	public function __construct() {
		parent::__construct();
		$this->addOption( 'wikia', 'Set this param if the target wiki is on Wikia/Fandom, which needs to handle URLs in a special way', false, false );
		$this->addOption( 'ignore-sha', 'Ignore SHA-1 checksum mismatches. May be required for CDN hosts that do image optimisations.' );
	}

	public function execute() {
		parent::execute();

		$this->isWikia = $this->getOption( 'wikia' );
		if ( !$this->isWikia && preg_match( '/\.(fandom|wikia|gamepedia)\.com/',  $this->getOption( 'url', '' ) ) ) {
			$this->output( "--wikia was not set but detected from URL - enabling the flag anyway\n" );
			$this->isWikia = true;
		}

		$services = MediaWikiServices::getInstance();
		$this->localRepo = $services->getRepoGroup()->getLocalRepo();
		$this->mimeAnalyzer = $services->getMimeAnalyzer();
	}

	/**
	 * Uploads the file from a given file returned by the api
	 * and registers it on the image table
	 * File must not exist in the image table
	 *
	 * @param string $name File name to upload
	 * @param array $fileVersion Image info data returned from the api
	 * @return Status Status of the file operation
	 */
	function newUpload( $name, $fileVersion ) {
		$this->output( "Uploading $name..." );
		if ( !isset( $fileVersion['url'] ) ) {
			# If the file is supressed and we don't have permissions,
			# we won't get URL nor MIME.
			# Skip the file revision instead of crashing
			$this->output( "File suppressed, skipping it\n" );
			$status = Status::newFatal( 'SKIPPED' ); # Not an existing message but whatever
			return $status;
		}
		$fileurl = $this->sanitiseUrl( $fileVersion['url'] );

		$comment = $fileVersion['comment'];
		if ( !$comment ) {
			$comment = '';
		}

		$file_e = [
			'name' => $name,
			'size' => $fileVersion['size'],
			'width' => $fileVersion['width'],
			'height' => $fileVersion['height'],
			'bits' => $fileVersion['bitdepth'],
			'description' => $comment,
			'user' => $fileVersion['userid'],
			'user_text' => $fileVersion['user'],
			'timestamp' =>  wfTimestamp( TS_MW, $fileVersion['timestamp'] ),
			'media_type' => $fileVersion['mediatype'],
			'deleted' => 0,
			'sha1' => Wikimedia\base_convert( $fileVersion['sha1'], 16, 36, 31 ),
			'metadata' => serialize( [] ),
		];

		$mime = $fileVersion['mime'];
		$mimeBreak = strpos( $mime, '/' );
		$file_e['major_mime'] = substr( $mime, 0, $mimeBreak );
		$file_e['minor_mime'] = substr( $mime, $mimeBreak + 1 );

		$actor = $this->getActorFromUser( (int)$file_e['user'], $file_e['user_text'] );

		$commentFields = $this->commentStore->insert( $this->dbw, 'img_description', $comment );

		# Current version
		$e = [
			'img_name' => $name,
			'img_size' => $file_e['size'],
			'img_width' => $file_e['width'],
			'img_height' => $file_e['height'],
			'img_bits' => $file_e['bits'],
			#'img_description' => $file_e['description'],
			#'img_user' => $file_e['user'],
			#'img_user_text' => $file_e['user_text'],
			'img_actor' => $actor,
			'img_timestamp' => $file_e['timestamp'],
			'img_media_type' => $file_e['media_type'],
			'img_sha1' => $file_e['sha1'],
			'img_metadata' => $file_e['metadata'],
			'img_major_mime' => $file_e['major_mime'],
			'img_minor_mime' => $file_e['minor_mime'],
		] + $commentFields;

		$rowExists = $this->dbw->selectField(
			'image',
			'img_name',
			[
				'img_name' => $name,
			],
			__METHOD__
		);

		if ( !$rowExists ) {
			$this->dbw->insert( 'image', $e, __METHOD__ );
		} else {
			$this->output( "already exists in image table..." );
		}

		$status = $this->storeFileFromURL( $name, $fileurl, false, $fileVersion['sha1'] );

		// Refresh image metadata
		if ( $status->isOK() ) {
			$file = $this->localRepo->newFile( $name );
			$file->upgradeRow();
		}

		$this->output( "Done\n" );
		return $status;
	}

	/**
	 * Process and upload both new and old files.
	 *
	 * @param array{name:string,info:array<string,mixed>}[] $newFiles
	 * @param array{name:string,info:array<string,mixed>}[] $oldFiles
	 * @return array{name:string,status:StatusValue}[]
	 * @throws Exception
	 */
	protected function uploadFiles( array $newFiles, array $oldFiles ): array {
		$this->output( "Starting upload:\n" );
		foreach ( $newFiles as $file ) {
			$this->output( " - " . $file['name'] . "\n" );
		}
		foreach ( $oldFiles as $file ) {
			$this->output( " - " . $file['name'] . '(' . ( $file['info']['timestamp'] ?? 'old' ) . ")\n" );
		}

		$result = [];
		$filesToStore = array_merge(
			$this->processNewFiles( $newFiles, $result ),
			$this->processOldFiles( $oldFiles, $result ),
		);

		$storeResults = $this->storeFilesFromURLs( $filesToStore );
		foreach ( $storeResults as $data ) {
			if ( $data['status']->isOK() ) {
				if ( isset( $data['archivename'] ) ) {
					$file = $this->localRepo->newFromArchiveName( $data['name'], $data['archivename'] );
				} else {
					$file = $this->localRepo->newFile( $data['name'] );
				}
				$file->upgradeRow();
			}
		}

		$this->output( "Batch done\n" );
		return array_merge( $result, $storeResults );
	}

	/**
	 * @param array{name:string,info:array<string,mixed>}[] $files
	 * @param StatusValue[] &$result
	 * @return array{name:string,fileUrl:string,sha1:string,archiveName:string,info:array<string,mixed>}[]
	 */
	protected function processOldFiles( array $files, array &$result ): array {
		$this->output( 'Processing ' . count( $files ) . " old files...\n" );
		$rows = [];
		$filesToStore = [];
		foreach ( $files as [ 'name' => $fileName, 'info' => $fileInfo ] ) {
			if ( !isset( $fileInfo['url'] ) ) {
				$this->output( "File $fileName is suppressed, skipping it\n" );
				$result[] = [
					'name' => $fileName,
					'status' => StatusValue::newFatal( new RawMessage( 'SKIPPED' ) ),
				];
				continue;
			}

			// Sloppy handler for revdeletions; just fills them in with dummy text
			// and sets bitfield thingy
			$fileDeleted = 0;
			if ( isset( $fileInfo['userhidden'] ) ) {
				$fileDeleted |= File::DELETED_USER;
				if ( !isset( $fileInfo['user'] ) ) {
					// Username removed
					$fileInfo['user'] = '';
				}
				if ( !isset( $fileInfo['userid'] ) ) {
					$fileInfo['userid'] = 0;
				}
			}
			if ( isset( $fileInfo['commenthidden'] ) ) {
				$fileDeleted |= File::DELETED_COMMENT;
				// Edit summary removed
				$comment = '';
			} else {
				$comment = $fileInfo['comment'] ?: '';
			}
			if ( isset( $fileInfo['filehidden'] ) ) {
				$fileDeleted |= File::DELETED_FILE;
			}
			if ( isset( $fileInfo['suppressed'] ) ) {
				$fileDeleted |= File::DELETED_RESTRICTED;
			}

			$fileUrl = $this->sanitiseUrl( $fileInfo['url'] );
			$mime = $fileInfo['mime'];
			$mimeBreak = strpos( $mime, '/' );
			$commentFields = $this->commentStore->insert( $this->dbw, 'oi_description', $comment );

			$row = [
				'oi_name' => $fileName,
				'oi_archive_name' => $fileInfo['archivename'],
				'oi_size' => $fileInfo['size'],
				'oi_width' => $fileInfo['width'],
				'oi_height' => $fileInfo['height'],
				'oi_bits' => $fileInfo['bitdepth'],
				'oi_actor' => $this->getActorFromUser( (int)$fileInfo['userid'], $fileInfo['user'] ),
				'oi_timestamp' => wfTimestamp( TimestampFormat::MW, $fileInfo['timestamp'] ),
				'oi_media_type' => $fileInfo['mediatype'],
				'oi_deleted' => $fileDeleted,
				'oi_sha1' => Wikimedia\base_convert( $fileInfo['sha1'], 16, 36, 31 ),
				'oi_metadata' => serialize( [] ),
				'oi_major_mime' => substr( $mime, 0, $mimeBreak ),
				'oi_minor_mime' => substr( $mime, $mimeBreak + 1 ),
			] + $commentFields;

			$historyExists = $this->dbw->newSelectQueryBuilder()
				->select( '1' )
				->from( 'oldimage' )
				->where( [
					'oi_name' => $row['oi_name'],
					'oi_archive_name' => $row['oi_archive_name'],
					'oi_timestamp' => $row['oi_timestamp'],
				] )
				->caller( __METHOD__ )
				->fetchField();

			if ( !$historyExists ) {
				$rows[] = $row;
			}

			$filesToStore[] = [
				'name' => $fileName,
				'fileUrl' => $fileUrl,
				'sha1' => $fileInfo['sha1'],
				'archiveName' => $fileInfo['archivename'],
				'info' => $fileInfo,
			];
		}

		if ( $rows ) {
			$this->dbw->newInsertQueryBuilder()
				->insertInto( 'oldimage' )
				->rows( $rows )
				->caller( __METHOD__ )
				->execute();
		}

		$amount = count( $rows );
		$this->output( "Inserted $amount rows into the oldimage table.\n" );

		return $filesToStore;
	}

	/**
	 * @param array{name:string,info:array<string,mixed>}[] $files
	 * @param StatusValue[] &$result
	 * @return array{name:string,fileUrl:string,sha1:string,info:array<string,mixed>}[]
	 */
	protected function processNewFiles( array $files, array &$result ): array {
		$this->output( 'Processing ' . count( $files ) . " new files...\n" );
		$rows = [];
		$filesToStore = [];
		foreach ( $files as [ 'name' => $fileName, 'info' => $fileInfo ] ) {
			if ( !isset( $fileInfo['url'] ) ) {
				$this->output( "File $fileName is suppressed, skipping it\n" );
				$result[] = [
					'name' => $fileName,
					'status' => StatusValue::newFatal( new RawMessage( 'SKIPPED' ) ),
				];
				continue;
			}

			$fileUrl = $this->sanitiseUrl( $fileInfo['url'] );
			$comment = $fileInfo['comment'] ?: '';
			$mime = $fileInfo['mime'];
			$mimeBreak = strpos( $mime, '/' );
			$actor = $this->getActorFromUser( (int)$fileInfo['userid'], $fileInfo['user'] );
			$commentFields = $this->commentStore->insert( $this->dbw, 'img_description', $comment );

			// Use file names as the keys, as there shouldn't be two new files with the same name
			$rows[$fileName] = [
				'img_name' => $fileName,
				'img_size' => $fileInfo['size'],
				'img_width' => $fileInfo['width'],
				'img_height' => $fileInfo['height'],
				'img_bits' => $fileInfo['bitdepth'],
				'img_actor' => $actor,
				'img_timestamp' => wfTimestamp( TimestampFormat::MW, $fileInfo['timestamp'] ),
				'img_media_type' => $fileInfo['mediatype'],
				'img_sha1' => Wikimedia\base_convert( $fileInfo['sha1'], 16, 36, 31 ),
				'img_metadata' => serialize( [] ),
				'img_major_mime' => substr( $mime, 0, $mimeBreak ),
				'img_minor_mime' => substr( $mime, $mimeBreak + 1 ),
			] + $commentFields;
			$filesToStore[] = [
				'name' => $fileName,
				'fileUrl' => $fileUrl,
				'sha1' => $fileInfo['sha1'],
				'info' => $fileInfo,
			];
		}

		if ( $rows ) {
			$existingFiles = $this->dbw->newSelectQueryBuilder()
				->select( 'img_name' )
				->from( 'image' )
				->where( [ 'img_name' => array_keys( $rows ) ] )
				->caller( __METHOD__ )
				->fetchFieldValues();

			foreach ( $filesToStore as [ 'name' => $fileName ] ) {
				if ( in_array( $fileName, $existingFiles ) ) {
					$this->output( "$fileName already exists in image table...\n" );
					unset( $rows[$fileName] );
				}
			}

			if ( $rows ) {
				$this->dbw->newInsertQueryBuilder()
					->insertInto( 'image' )
					->rows( array_values( $rows ) )
					->caller( __METHOD__ )
					->execute();
			}
		}
		$amount = count( $rows );
		$this->output( "Inserted $amount rows into the image table.\n" );

		return $filesToStore;
	}

	/**
	 * Uploads the file from a given file returned by the api as an old
	 * version of the file and registers it on the oldimage table
	 *
	 * @param string $name File name to upload
	 * @param array $fileVersion Image info data returned from the api
	 * @return Status Status of the file operation
	 */
	function oldUpload( $name, $fileVersion ) {
		$this->output( "Uploading $name version {$fileVersion['timestamp']}..." );
		if ( !isset( $fileVersion['url'] ) ) {
			# If the file is supressed and we don't have permissions,
			# we won't get URL nor MIME.
			# Skip the file revision instead of crashing
			$this->output( "File supressed, skipping it\n" );
			$status = Status::newFatal( 'SKIPPED' ); # Not an existing message but whatever
			return $status;
		}

		# Sloppy handler for revdeletions; just fills them in with dummy text
		# and sets bitfield thingy
		$filedeleted = 0;
		if ( isset( $fileVersion['userhidden'] ) ) {
			$filedeleted = $filedeleted | File::DELETED_USER;
			if ( !isset( $fileVersion['user'] ) ) {
				$fileVersion['user'] = ''; # username removed
			}
			if ( !isset( $fileVersion['userid'] ) ) {
				$fileVersion['userid'] = 0;
			}
		}
		if ( isset( $fileVersion['commenthidden'] ) ) {
			$filedeleted = $filedeleted | File::DELETED_COMMENT;
			$comment = ''; # edit summary removed
		} else {
			$comment = $fileVersion['comment'];
			if ( !$comment ) {
				$comment = '';
			}
		}
		if ( isset( $fileVersion['filehidden'] ) ) {
			$filedeleted = $filedeleted | File::DELETED_FILE;
		}
		if ( isset ( $fileVersion['suppressed'] ) ) {
			$filedeleted = $filedeleted | File::DELETED_RESTRICTED;
		}

		$fileurl = $this->sanitiseUrl( $fileVersion['url'] );

		$file_e = [
			'name' => $name,
			'size' => $fileVersion['size'],
			'width' => $fileVersion['width'],
			'height' => $fileVersion['height'],
			'bits' => $fileVersion['bitdepth'],
			'description' => $comment,
			'user' => $fileVersion['userid'],
			'user_text' => $fileVersion['user'],
			'timestamp' =>  wfTimestamp( TS_MW, $fileVersion['timestamp'] ),
			'media_type' => $fileVersion['mediatype'],
			'deleted' => $filedeleted,
			'sha1' => Wikimedia\base_convert( $fileVersion['sha1'], 16, 36, 31 ),
			'metadata' => serialize( [] ),
		];

		$mime = $fileVersion['mime'];
		$mimeBreak = strpos( $mime, '/' );
		$file_e['major_mime'] = substr( $mime, 0, $mimeBreak );
		$file_e['minor_mime'] = substr( $mime, $mimeBreak + 1 );

		$commentFields = $this->commentStore->insert( $this->dbw, 'oi_description', $comment );

		# Old version
		$e = [
			'oi_name' => $name,
			'oi_archive_name' => $fileVersion['archivename'],
			'oi_size' => $file_e['size'],
			'oi_width' => $file_e['width'],
			'oi_height' => $file_e['height'],
			'oi_bits' => $file_e['bits'],
			#'oi_description' => $file_e['description'],
			#'oi_user' => $file_e['user'],
			#'oi_user_text' => $file_e['user_text'],
			'oi_actor' => $this->getActorFromUser( (int)$file_e['user'], $file_e['user_text'] ),
			'oi_timestamp' => $file_e['timestamp'],
			'oi_media_type' => $file_e['media_type'],
			'oi_deleted' => $file_e['deleted'],
			'oi_sha1' => $file_e['sha1'],
			'oi_metadata' => $file_e['metadata'],
			'oi_major_mime' => $file_e['major_mime'],
			'oi_minor_mime' => $file_e['minor_mime'],
		] + $commentFields;

		$historyExists = $this->dbw->selectField(
			'oldimage',
			'1',
			[
				'oi_name' => $e['oi_name'],
				'oi_archive_name' => $e['oi_archive_name'],
				'oi_timestamp' => $e['oi_timestamp'],
			],
			__METHOD__
		);

		if ( !$historyExists ) {
			$this->dbw->insert( 'oldimage', $e, __METHOD__ );
		}

		$status = $this->storeFileFromURL( $name, $fileurl, $file_e['timestamp'], $fileVersion['sha1'], $fileVersion['archivename'] );

		// Refresh image metadata
		if ( $status->isOK() ) {
			$file = $this->localRepo->newFromArchiveName( $name, $fileVersion['archivename'] );
			$file->upgradeRow();
		}

		$this->output( "Done\n" );
		return $status;
	}

	/**
	 * Stores the file from the URL to the local repository
	 *
	 * @param string $name Name of the file
	 * @param string $fileurl URL of the file to be downloaded
	 * @param int|boolean timestamp in case of old file or false otherwise
	 * @param string $sha1 sha of the file to ensure that it's not corrupt
	 * @return Status status of the operation
	 */
	function storeFileFromURL( $name, $fileurl, $timestamp, $sha1, $archiveName = null ) {
		// Check for existing file in repo. Can't use LocalFile/OldLocalFile as that uses the DB.
		if ( $archiveName ) {
			$path = $this->localRepo->getZonePath( 'public' ) . "/archive/$archiveName";
		} else {
			$path = $this->localRepo->getZonePath( 'public' ) . "/$name";
		}
		if ( $this->localRepo->fileExists( $path ) ) {
			$eSha = $this->localRepo->getBackend()->getFileStat( [ 'src' => $path, 'latest' => 1, 'requireSHA1' => 1 ] )['sha1'];
			if ( $eSha === $sha1 ) {
				return Status::newGood();
			} else {
				$this->output( sprintf( " File %s doesn't match expected sha1.\n", $name ) );
				$this->localRepo->quickPurge( $path );
			}
		} else {
			$this->output( sprintf( " File %s doesn't exist in the local file repo.\n", $name ) );
		}

		$maxRetries = 3; # Just an arbitrary value
		$status = Status::newFatal( 'UNKNOWN' );
		$tmpPath = tempnam( wfTempDir(), 'grabfile' );

		# Add a cache buster to get the latest version of a file. Fandom uses 'cb' already so we'll use 'purge'.
		$time = time();
		if ( strpos( $fileurl, '?' ) !== false ) {
			$targeturl = "{$fileurl}&purge=$time";
		} else {
			$targeturl = "{$fileurl}?purge=$time";
		}

		# Retry in case of download failure
		for ( $retries = 0; !$status->isOK() && $retries < $maxRetries; $retries++ ) {
			if ( $retries > 0 ) {
				# Wait some time in case the server is temporarily unavailable
				sleep( 5 * $retries );
			}
			$status = $this->downloadFile( $targeturl, $tmpPath, $name, $sha1 );
		}
		if ( $status->isOK() ) {
			$status = $this->localRepo->quickImport( $tmpPath, $path );
			if ( !$status->isOK() ) {
				$this->output( sprintf( " Error when publishing file %s to the local file repo: %s\n",
					$name, $status->getWikiText() ) );
			}
		} else {
			$this->output( sprintf( " Failed to save file %s from URL %s\n", $name, $fileurl ) );
		}
		unlink( $tmpPath );
		return $status;
	}

	/**
	 * @param array{name:string,fileUrl:string,sha1:string,info:array<string,mixed>,archiveName?:string}[] $files
	 * @return array{name:string,status:StatusValue,archiveName?:string}[]
	 * @throws Exception
	 */
	protected function storeFilesFromURLs( array $files ): array {
		$results = [];
		$filesToDownload = [];
		$paths = [];
		$archiveNames = [];

		foreach ( $files as $data ) {
			$fileName = $data['name'];
			$fileData = $data['info'];
			// Check for existing file in repo. Can't use LocalFile/OldLocalFile as that uses the DB.
			$archiveName = $fileData['archivename'] ?? null;
			if ( $archiveName ) {
				$path = $this->localRepo->getZonePath( 'public' ) . "/archive/$archiveName";
			} else {
				$path = $this->localRepo->getZonePath( 'public' ) . "/$fileName";
			}

			if ( $this->localRepo->fileExists( $path ) ) {
				$eSha = $this->localRepo->getBackend()->getFileStat( [
					'src' => $path, 'latest' => 1, 'requireSHA1' => 1,
				] )['sha1'] ?? null;
				if ( $eSha !== null && $eSha === $fileData['sha1'] ) {
					$results[] = [
						'name' => $fileName,
						'status' => StatusValue::newGood(),
						'archiveName' => $archiveName,
					];
					continue;
				} else {
					$this->output( " File $fileName doesn't match expected sha1.\n", $fileName );
					$this->localRepo->quickPurge( $path );
				}
			} else {
				$this->output( " File $fileName doesn't exist in the local file repo.\n" );
			}

			$tempFile = tempnam( wfTempDir(), 'grabfile' );
			if ( $archiveName ) {
				$archiveNames[$tempFile] = $archiveName;
			}
			$paths[$tempFile] = $path;
			$filesToDownload[] = [
				'fileUrl' => $fileData['url'],
				'targetTempFile' => $tempFile,
				'relatedFileName' => $fileName,
				'sha1' => $fileData['sha1'],
			];
		}

		$maxRetries = 3;
		$retries = 0;
		while ( $filesToDownload ) {
			if ( $retries > 0 ) {
				$delay = 5 * $retries;
				$this->output( "Encountered failures. Retrying and sleeping for $delay seconds...\n" );
				sleep( $delay );
			}
			if ( $retries >= $maxRetries ) {
				// Add failures from the last attempt to the results, if there are any
				$results = array_merge( $results, $downloadResults ?? [] );
				break;
			}

			// Attempt to download the files, store all successful results and retry only those that failed
			$downloadResults = $this->downloadFiles( $filesToDownload );
			$successfulDownloads = array_filter( $downloadResults, static fn ( $s ) => $s['status']->isOK() );
			$results = array_merge( $results, $successfulDownloads );
			// Hacky...
			$toRemove = [];
			foreach ( $downloadResults as [ 'name' => $name, 'status' => $status ] ) {
				if ( $status->isOK() ) {
					$toRemove[] = $status->getValue();
				} else {
					$this->output( "Error when trying to download $name:\n" );
					$this->error( $status );
				}
			}
			$filesToDownload = array_filter(
				$filesToDownload,
				static fn ( $f ) => !in_array( $f['targetTempFile'], $toRemove )
			);
			$retries++;
		}

		foreach ( $results as &$res ) {
			[ 'status' => $status ] = $res;
			if ( $status->isOK() ) {
				$tempFile = $status->getValue();
				if ( isset( $archiveNames[$tempFile] ) ) {
					$res['archiveName'] = $archiveNames[$tempFile];
				}
				$importStatus = $this->localRepo->quickImport( $tempFile, $paths[$tempFile] );
				if ( !$importStatus->isOK() ) {
					$formattedErrors = $this->formatStatusErrors( $importStatus );
					$this->output( " Error when publishing file to the local file repo: $formattedErrors\n" );
					$status->merge( $importStatus );
				}
			} else {
				$formattedErrors = $this->formatStatusErrors( $status );
				$this->output( " Failed to save file: $formattedErrors\n" );
			}
			unlink( $status->getValue() );
		}

		return $results;
	}

	private function formatStatusErrors( StatusValue $status ): string {
		$errors = array_map(
			static fn ( $msg ) => wfMessage( $msg )->text(),
			$status->getMessages( 'error' )
		);
		return implode( "\n", $errors );
	}

	/**
	 * Download multiple files concurrently.
	 * Array keys in the input array will be preserved in the returned array.
	 * @param array{fileUrl:string,targetTempFile:string,relatedFileName:string,sha1?:string}[] $files
	 * @return array{name:string,status:StatusValue}[]
	 * @throws Exception
	 */
	protected function downloadFiles( array $files, bool $enableCacheBuster = true ): array {
		$client = $this->getServiceContainer()->getHttpRequestFactory()->createMultiClient( [
			'reqTimeout' => 90,
		] );

		$streams = [];
		$results = [];
		$requests = [];
		$fileOptionsByUrl = [];
		foreach ( $files as $options ) {
			$url = $options['fileUrl'];
			if ( $enableCacheBuster ) {
				$time = time();
				// Add a cache buster to get the latest version of a file.
				// Fandom uses 'cb' already so we'll use 'purge'.
				if ( str_contains( $url, '?' ) ) {
					$url .= "&purge=$time";
				} else {
					$url .= "?purge=$time";
				}
			}

			$stream = fopen( $options['targetTempFile'], 'w' );
			if ( !$stream ) {
				$results[] = [
					'name' => $options['relatedFileName'],
					'status' => StatusValue::newFatal( new RawMessage(
						"Failed to open temporary file {$options['targetTempFile']} for {$options['relatedFileName']}!"
					) ),
				];
				continue;
			}

			$streams[] = $stream;
			$requests[] = [
				'method' => 'GET',
				'url' => $url,
				'stream' => $stream,
				'headers' => [
					'Accept' => $this->getRelevantAcceptHeader( $options['relatedFileName'] ),
				],
			];
			$fileOptionsByUrl[$url] = $options;
		}

		$responses = $client->runMulti( $requests );

		foreach ( $responses as $response ) {
			$options = $fileOptionsByUrl[$response['url']];
			$fileUrl = $options['fileUrl'];
			$status = StatusValue::newGood( $options['targetTempFile'] );

			if ( isset( $response['error'] ) ) {
				$status->fatal( $response['error'] );
			}
			if ( isset( $response['reason'] ) ) {
				$status->fatal( $response['reason'] );
			}

			if ( !$status->isOK() ) {
				$formattedErrors = $this->formatStatusErrors( $status );
				$this->output( " Error when saving contents of URL $fileUrl: $formattedErrors\n" );
			}

			if ( $status->isOK() && isset( $options['sha1'] ) ) {
				$sha1 = $options['sha1'];
				$storedSha1 = sha1_file( $options['targetTempFile'] );
				if ( $storedSha1 !== $sha1 ) {
					$this->output( " File from URL $fileUrl doesn't match the expected sha1.\n" );
					$this->output( " Expected: $sha1. Actual: $storedSha1\n" );

					if ( !$this->getOption( 'ignore-sha' ) && !$this->isWikia ) {
						$status->fatal( new RawMessage( 'FILECORRUPT' ) );
					}
				}
			}

			$results[] = [
				'name' => $options['relatedFileName'],
				'status' => $status,
			];
		}

		foreach ( $streams as $stream ) {
			fclose( $stream );
		}

		return $results;
	}

	/**
	 * Downloads a URL to a specified temporal file
	 *
	 * @param string $fileurl URL of the file to be downloaded
	 * @param string $targetTempFile path for the downloaded file
	 * @param string $relatedFileName File name, for mime type hints with the file extension
	 * @param string $sha1 sha of the file to ensure that it's not corrupt (optional)
	 * @return Status status of the operation
	 */
	function downloadFile( $fileurl, $targetTempFile, $relatedFileName, $sha1 = null ) {
		// The request class since 1.33 based on Guzzle supports the sink option.
		$req = MediaWikiServices::getInstance()->getHttpRequestFactory()
			->create( $fileurl, [
				'timeout' => 90,
				'sink' => new LazyOpenStream( $targetTempFile, 'w' ),
			], __METHOD__ );
		$this->setRelevantAcceptHeader( $req, $relatedFileName );
		$status = $req->execute();
		if ( $status->isOK() ) {
			if ( is_null( $sha1 ) ) {
				return $status;
			}
			$storedSha1 = sha1_file( $targetTempFile );
			if ( $storedSha1 == $sha1 ) {
				return $status;
			}
			$this->output( sprintf( " File from URL %s doesn't match the expected sha1. Expected: %s. Actual: %s\n",
				$fileurl, $sha1, $storedSha1 ) );

			if ( $this->getOption( 'ignore-sha' ) || $this->isWikia ) {
				// we have already logged the SHA mismatch, but we'll proceed regardless since we are ignoring them
				return $status;
			} else {
				$status = Status::newFatal( 'FILECORRUPT' ); # Not an existing message but whatever
			}
		} else {
			$this->output( sprintf( " Error when saving contents of URL %s: %s\n",
				$fileurl, $status->getWikiText() ) );
		}
		return $status;
	}

	/**
	 * Formats metadata to the original format stored by MediaWiki
	 * The api returns an array of objects {name: paramName, value: paramValue}
	 * but we want to store {paramName: paramValue}
	 *
	 * @param $metadata Array as retrieved from the api
	 * @returns array
	 */
	function processMetaData( $metadata ) {
		$result = [];
		if ( !is_array( $metadata ) ) {
			return $result;
		}
		foreach ( $metadata as $namevalue ) {
			$name = $namevalue['name'];
			$value = $namevalue['value'];
			if ( is_array( $value ) ) {
				$result[$name] = $this->processMetaData( $value );
			} else {
				$result[$name] = $value;
			}
		}
		return $result;
	}

	/**
	 * Check for Wikia's videos
	 *
	 * @param $fileVersion Array as retrieved from the imageinfo api
	 * @returns bool true if it's an external video
	 */
	function isWikiaVideo( $fileVersion ) {
		# This is somewhat dumb as it only checks for YouTube videos, but
		# without the MIME check it catches .ogv etc.
		# A better check would be to check the mediatype and for the lack
		# of a known file extension in the title, but I don't wanna mess
		# with regex right now. --ashley, 17 April 2016
		if ( $this->isWikia &&
			isset( $fileVersion['mime'] ) &&
			$fileVersion['mime'] == 'video/youtube' &&
			isset( $fileVersion['mediatype'] ) &&
			strtoupper( $fileVersion['mediatype'] ) == 'VIDEO'
		) {
			return true;
		}
		return false;
	}

	/**
	 * Sanitise file URL. Just checking for wikia's madness for now
	 *
	 * @param $fileurl string URL of the file
	 * @returns string sanitised URL
	 */
	function sanitiseUrl( $fileurl ) {
		if ( $this->isWikia ) {
			# Wikia is now serving "optimised" lossy images instead of the
			# originals. See http://community.wikia.com/wiki/Thread:1200407
			if ( stripos( $fileurl, '.webp' ) !== false ) {
				# December 2023, WebP images downloaded using format=original
				# URL parameters are served as PNG (they're converting it
				# backwards!), thus failing the sha1 check. When not using
				# such URL parameter, they get downloaded in the correct
				# format, but ONLY when the Accept: header contains image/webp
				return $fileurl;
			}
			# Add format=original to the URL to hopefully force it to download the original
			if ( strpos( $fileurl, '?' ) !== false ) {
				$fileurl .= '&format=original';
			} else {
				$fileurl .= '?format=original';
			}
		}
		return $fileurl;
	}

	/**
	 * Sets an Accept: header on the request object with relevant mime type
	 * from the file name, since some servers are trying to be *annoyingly*
	 * smart about the files they serve based on the Accept: header, serving
	 * a different format than the original file.
	 * For example, wikia will return WEBP files as PNG unless we explicitly
	 * list webp in the Accept: header
	 *
	 * @param MWHttpRequest $req MediaWiki HTTP request object
	 * @param string $relatedFileName File name hint to extract file extension
	 */
	private function setRelevantAcceptHeader( $req, $relatedFileName ) {
		$value = $this->getRelevantAcceptHeader( $relatedFileName );
		if ( $value === null ) {
			return;
		}
		$req->setHeader( 'Accept', $value );
	}

	private function getRelevantAcceptHeader( $relatedFileName ): ?string {
		if ( !$relatedFileName ) {
			return null;
		}
		$bits = explode( '.', $relatedFileName );
		$ext = array_pop( $bits );
		$mime = $this->mimeAnalyzer->getMimeTypeFromExtensionOrNull( $ext );
		if ( !$mime ) {
			return null;
		}
		# Use the expected mime type first, or anything as a fallback
		return "$mime,*/*;q=0.8";
	}
}
