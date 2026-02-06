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

require_once 'includes/TextGrabber.php';

class GrabRevTags extends TextGrabber {

	/**
	 * Array of namespaces to grab deleted revisions
	 *
	 * @var Array
	 */
	protected $namespaces = null;

	public function __construct() {
		parent::__construct();
		$this->mDescription = "Grab tags for all revisions from an external wiki and import it into one of ours.";
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

		# Get revisions
		$this->output( "\nSaving revision tags...\n" );
		$revisions_processed = 0;

		$more = true;
		$arvcontinue = null;

		$params = [
			'list' => 'allrevisions',
			'arvlimit' => 'max',
			'arvdir' => 'newer',
			'arvprop' => 'ids|tags|timestamp',
		];

		while ( $more ) {
			if ( $arvcontinue === null ) {
				unset( $params['arvcontinue'] );
			} else {
				$params['arvcontinue'] = $arvcontinue;

			}
			$result = $this->bot->query( $params );

			$pageChunks = $result['query']['allrevisions'];
			if ( empty( $pageChunks ) ) {
				$this->output( "No revisions found.\n" );
				$more = false;
			}

			foreach ( $pageChunks as $pageChunk ) {
				$revisions_processed = $this->processRevisions( $pageChunk, $revisions_processed );
			}

			if ( isset( $result['query-continue'] ) && isset( $result['query-continue']['allrevisions'] ) ) {
				# Ancient way of api pagination
				# TODO: Document what is this for. Examples welcome
				$arvcontinue = str_replace( '&', '%26', $result['query-continue']['allrevisions']['arvcontinue'] );
				$params = array_merge( $params, $result['query-continue']['allrevisions'] );
			} elseif ( isset( $result['continue'] ) ) {
				# New pagination
				$arvcontinue = $result['continue']['arvcontinue'];
				$params = array_merge( $params, $result['continue'] );
			} else {
				$more = false;
			}
			$this->output( "arvcontinue = $arvcontinue\n" );
		}

		$this->output( "\n" );
		$this->output( "Saved $revisions_processed revisions' tags.\n" );

		# Done.
	}

	/**
	 * Add deleted revisions to the archive and text tables
	 * Takes results in chunks because that's how the API returns pages - with chunks of revisions.
	 *
	 * @param Array $pageChunk Chunk of revisions, represents a deleted page
	 * @param int $revisions_processed Count of revisions for progress reports
	 * @returns int $revisions_processed updated
	 */
	function processRevisions( $pageChunk, $revisions_processed ) {

		$this->output( "Processing {$pageChunk['title']}\n" );

		$revisions = $pageChunk['revisions'];
		foreach ( $revisions as $revision ) {
			if ( $revisions_processed % 500 == 0 && $revisions_processed !== 0 ) {
				$this->output( "$revisions_processed revisions inserted\n" );
			}
			# Stop if past the enddate
			$timestamp = wfTimestamp( TS_MW, $revision['timestamp'] );
			if ( $timestamp > $this->endDate ) {
				return $revisions_processed;
			}

			$revisionId = $revision['revid'];
			if ( !$revisionId ) {
				# Revision ID is mandatory with the new content tables and things will fail if not provided.
				$this->output( sprintf( "WARNING: Got revision without revision id, " .
					"with timestamp %s. Skipping!\n", $revision['timestamp'] ) );
				continue;
			}

			# Insert tags, if any
			if ( isset( $revision['tags'] ) && count( $revision['tags'] ) > 0 ) {
				$this->insertTags( $revision['tags'], $revisionId );
			}
			$revisions_processed++;
		}

		return $revisions_processed;
	}

}

$maintClass = 'GrabRevTags';
require_once RUN_MAINTENANCE_IF_MAIN;
