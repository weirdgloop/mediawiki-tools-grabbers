<?php
/**
 * Maintenance script to grab text from a wiki and import it to another wiki.
 * Translated from Edward Chernenko's Perl version (text.pl).
 *
 * @file
 * @ingroup Maintenance
 * @author Jack Phoenix <jack@shoutwiki.com>
 * @author Calimonious the Estrange
 * @author Jesús Martínez <martineznovo@gmail.com>
 * @version 1.1
 * @date 5 August 2019
 */

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;

require_once 'includes/TextGrabber.php';

class GrabDeletedText extends TextGrabber {

	/**
	 * Array of namespaces to grab deleted revisions
	 *
	 * @var Array
	 */
	protected $namespaces = null;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Grab deleted text from an external wiki and import it into one of ours.' );
		# $this->addOption( 'start', 'Revision at which to start', false, true );
		#$this->addOption( 'startdate', 'Not yet implemented.', false, true );
		$this->addOption( 'adrcontinue', 'API continue to restart deleted revision process', false, true );
		$this->addOption( 'namespaces', 'Pipe-separated namespaces (ID) to grab. Defaults to all namespaces', false, true );
		$this->addOption( 'skip-fandom-comments', 'Skip any pages that are Fandom comment pages (@comment-*)' );
	}

	public function execute() {
		parent::execute();

		# End date isn't necessarily supported by source wikis, but we'll deal with that later.
		$this->endDate = $this->getOption( 'enddate' );
		if ( $this->endDate ) {
			$this->endDate = wfTimestamp( TS_MW, $this->endDate );
			if ( !$this->endDate ) {
				$this->fatalError( 'Invalid enddate format.' );
			}
		} else {
			$this->endDate = wfTimestampNow();
		}

		$this->output( "Retrieving namespaces list...\n" );

		$params = [
			'meta' => 'siteinfo',
			'siprop' => 'namespaces'
		];
		$result = $this->bot->query( $params );
		$siteinfo = $result['query'];

		# No data - bail out early
		if ( empty( $siteinfo ) ) {
			$this->fatalError( 'No siteinfo data found...' );
		}

		$textNamespaces = [];
		if ( $this->hasOption( 'namespaces' ) ) {
			$textNamespaces = explode( '|', $this->getOption( 'namespaces', '' ) );
		} else {
			foreach ( array_keys( $siteinfo['namespaces'] ) as $ns ) {
				# Ignore special
				if ( $ns >= 0 ) {
					$textNamespaces[] = $ns;
				}
			}
		}
		if ( $this->getOption( 'skip-fandom-comments' ) ) {
			$textNamespaces = array_diff( $textNamespaces, static::FANDOM_COMMENT_NAMESPACES );
		}
		if ( !$textNamespaces ) {
			$this->fatalError( 'Got no namespaces...' );
		}

		# Get deleted revisions
		$this->output( "\nSaving deleted revisions...\n" );
		$revisions_processed = 0;

		foreach ( $textNamespaces as $ns ) {
			$more = true;
			$adrcontinue = $this->getOption( 'adrcontinue' );
			if ( !$adrcontinue ) {
				$adrcontinue = null;
			} else {
				# Parse start namespace from input string and use
				# Length of namespace number
				$nsStart = strpos( $adrcontinue, '|' );
				# Namespsace number
				if ( $nsStart == 0 ) {
					$nsStart = 0;
				} else {
					$nsStart = substr( $adrcontinue, 0, $nsStart );
				}
				if ( $ns < $nsStart ) {
					$this->output( "Skipping $ns\n" );
					continue;
				} elseif ( $nsStart != $ns ) {
					$adrcontinue = null;
				}
			}
			# Count revisions
			$nsRevisions = 0;

			$params = [
				'list' => 'alldeletedrevisions',
				'adrnamespace' => $ns,
				'adrlimit' => 'max',
				'adrdir' => 'newer',
				'adrprop' => 'ids|user|userid|comment|flags|content|tags|timestamp',
			];

			while ( $more ) {
				if ( $adrcontinue === null ) {
					unset( $params['adrcontinue'] );
				} else {
					$params['adrcontinue'] = $adrcontinue;

				}
				$result = $this->bot->query( $params );
				if ( $result && isset( $result['error'] ) ) {
					$this->fatalError( "User does not have required rights to fetch deleted revisions." );
				}
				if ( empty( $result ) ) {
					sleep( .5 );
					$this->output( "Bad result.\n" );
					continue;
				}

				$pageChunks = $result['query']['alldeletedrevisions'];
				if ( empty( $pageChunks ) ) {
					$this->output( "No revisions found.\n" );
					$more = false;
				}

				foreach ( $pageChunks as $pageChunk ) {
					if ( $this->getOption( 'skip-fandom-comments' ) && preg_match( '/^(.*)(\/@comment-.*-20\d{12}){1,2}$/', $pageChunk['title'] ) ) {
						// Fandom's comment system creates a new page for each comment, which is terrible.
						$this->output( "Skipped page \"${pageChunk['title']}\": Fandom comment page.\n" );
						continue;
					}

					$nsRevisions = $this->processDeletedRevisions( $pageChunk, $nsRevisions );
				}

				if ( isset( $result['query-continue'] ) && isset( $result['query-continue']['alldeletedrevisions'] ) ) {
					# Ancient way of api pagination
					# TODO: Document what is this for. Examples welcome
					$adrcontinue = str_replace( '&', '%26', $result['query-continue']['alldeletedrevisions']['adrcontinue'] );
					$params = array_merge( $params, $result['query-continue']['alldeletedrevisions'] );
				} elseif ( isset( $result['continue'] ) ) {
					# New pagination
					$adrcontinue = $result['continue']['adrcontinue'];
					$params = array_merge( $params, $result['continue'] );
				} else {
					$more = false;
				}
				$this->output( "adrcontinue = $adrcontinue\n" );
			}
			$this->output( "$nsRevisions chunks of revisions processed in namespace $ns.\n" );
			$revisions_processed += $nsRevisions;
		}

		$this->output( "\n" );
		$this->output( "Saved $revisions_processed deleted revisions.\n" );

		# Done.
	}

	/**
	 * Add deleted revisions to the archive and text tables
	 * Takes results in chunks because that's how the API returns pages - with chunks of revisions.
	 *
	 * @param Array $pageChunk Chunk of revisions, represents a deleted page
	 * @param int $nsRevisions Count of deleted revisions for this namespace, for progress reports
	 * @returns int $nsRevisions updated
	 */
	function processDeletedRevisions( $pageChunk, $nsRevisions ) {

		$ns = $pageChunk['ns'];
		$title = $this->sanitiseTitle( $ns, $pageChunk['title'] );
		$defaultModel = MediaWikiServices::getInstance()->getNamespaceInfo()->getNamespaceContentModel( $ns ) ?? CONTENT_MODEL_WIKITEXT;

		$this->output( "Processing {$pageChunk['title']}\n" );

		$revisions = $pageChunk['revisions'];
		foreach ( $revisions as $revision ) {
			if ( $nsRevisions % 500 == 0 && $nsRevisions !== 0 ) {
				$this->output( "$nsRevisions revisions inserted\n" );
			}
			# Stop if past the enddate
			$timestamp = wfTimestamp( TS_MW, $revision['timestamp'] );
			if ( $timestamp > $this->endDate ) {
				return $nsRevisions;
			}

			$revisionId = $revision['revid'];
			if ( !$revisionId ) {
				# Revision ID is mandatory with the new content tables and things will fail if not provided.
				$this->output( sprintf( "WARNING: Got revision without revision id, " .
					"with timestamp %s. Skipping!\n", $revision['timestamp'] ) );
				continue;
			}

			if ( !isset( $revision['contentmodel'] ) ) {
				$revision['contentmodel'] = $defaultModel;
			}

			$titleObj = Title::makeTitle( $ns, $title );
			if ( $this->insertArchivedRevision( $revision, $titleObj ) ) {
				$nsRevisions++;
			}
		}

		return $nsRevisions;
	}

}

$maintClass = 'GrabDeletedText';
require_once RUN_MAINTENANCE_IF_MAIN;
