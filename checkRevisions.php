<?php
/**
 * Maintenance script to check the integrity of the revisions in the database. This doesn't modify the database -
 * it simply logs each bad revision.
 *
 * Realistically, you should probably pipe this script's output to a file for some more in-depth analysis.
 *
 * @file
 * @ingroup Maintenance
 * @author Jayden Bailey <jayden@weirdgloop.org>
 * @version 1.0
 * @date 10 September 2023
 */

use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\MutableRevisionRecord;
use MediaWiki\Revision\RevisionAccessException;
use MediaWiki\Revision\SlotRecord;

require_once 'includes/TextGrabber.php';

class CheckRevisions extends TextGrabber {

	/**
	 * The report interval
	 *
	 * @var int
	 */
	protected $reportInterval = 5000;

	/**
	 * The current revision count
	 *
	 * @var int
	 */
	protected $revCount = 0;

	/**
	 * The current missing revisions count
	 *
	 * @var int
	 */
	protected $missingCount = 0;

	/**
	 * The current hash mismatch count
	 *
	 * @var int
	 */
	protected $mismatchCount = 0;

	/**
	 * The current replaced revisions count
	 *
	 * @var int
	 */
	protected $replacedCount = 0;

	/**
	 * Start date
	 *
	 * @var string
	 */
	protected $startDate;

	/**
	 * End date
	 *
	 * @var string
	 */
	protected $endDate;

	/**
	 * Dry run
	 *
	 * @var bool
	 */
	protected $dry;

	public function __construct() {
		parent::__construct();
		$this->addDescription('Checks that our database contains all of the remote wiki\'s revisions');
		$this->addOption( 'startdate', 'Any revision before this time will not be checked on the remote wiki', false, true );
		$this->addOption( 'enddate', 'Any revision after this time will not be checked on the remote wiki', false, true );
		$this->addOption( 'report', 'Report position after every n revisions processed (default is 5000)', false, true );
		$this->addOption( 'dry', 'Perform a dry-run, where the database is not modified', false, false );
		$this->addOption( 'namespaces', 'Pipe-separated namespaces (ID) to check. Defaults to all namespaces', false, true );
		$this->addOption( 'skip-fandom-comments', 'Skip any pages that are Fandom comment pages (@comment-*)' );
	}

	public function execute() {
		parent::execute();
		$this->reportInterval = intval( $this->getOption( 'report', 5000 ) );
		$this->dry = $this->getOption( 'dry', false );

		$params = [
			'list' => 'allrevisions',
			'arvprop' => 'ids|timestamp|sha1',
			'arvlimit' => 'max',
			'arvdir' => 'newer',
		];

		$this->startDate = $this->getOption( 'startdate' );
		if ( $this->startDate ) {
			if (!wfTimestamp(TS_ISO_8601, $this->startDate)) {
				$this->fatalError('Invalid start date format.');
			}
			$params['arvstart'] = $this->startDate;
		}

		$this->endDate = $this->getOption( 'enddate' );
		if ( $this->endDate ) {
			if (!wfTimestamp(TS_ISO_8601, $this->endDate)) {
				$this->fatalError('Invalid end date format.');
			}
			$params['arvend'] = $this->endDate;
		}

		$this->output( "Retrieving namespaces list...\n" );

		$siparams = [
			'meta' => 'siteinfo',
			'siprop' => 'namespaces'
		];
		$result = $this->bot->query( $siparams );
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

		$params['arvnamespace'] = implode( '|', $textNamespaces );

		$more = true;
		$checkpoint = $this->reportInterval;

		$this->output( "Checking revisions...\n" );
		do {
			$result = $this->bot->query( $params );

			if ( isset( $result['continue'] ) ) {
				$params = array_merge( $params, $result['continue'] );
			} else {
				$more = false;
			}

			if ( empty( $result['query']['allrevisions'] ) ) {
				if ( $more ) {
					$this->output( "No result in this query due to miser mode.\n" );
					continue;
				}
				$this->fatalError( 'No revisions found on remote wiki.' );
			}

			foreach ( $result['query']['allrevisions'] as $page ) {
				if ( $this->getOption( 'skip-fandom-comments' ) && preg_match( '/^(.*)(\/@comment-.*-20\d{12}){1,2}$/', $page['title'] ) ) {
					// Fandom's comment system creates a new page for each comment, which is terrible.
					//$this->output( "Skipped page \"{$page['title']}\": Fandom comment page.\n" );
					continue;
				}

				foreach ( $page['revisions'] as $rev ) {
					$this->handleRevision( $rev, $page['pageid'], Title::makeTitle( $page['ns'], $page['title'] ) );
					$this->revCount++;
					if ( $this->revCount >= $checkpoint ) {
						$this->output( "{$this->revCount} revisions processed.\n" );
						$checkpoint = $checkpoint + $this->reportInterval;
					}
				}
			}
		} while ( $more );

		$this->output( "Done.\n\n$this->revCount revisions checked.\n$this->missingCount revisions missing.\n$this->mismatchCount hash mismatches.\n$this->replacedCount revisions replaced.\n" );
	}

	public function fetchRemoteRevision( $revId ) {
		$params = [
			'prop' => 'revisions',
			'rvprop' => 'ids|timestamp|sha1|content|contentmodel|comment|user|userid',
			'revids' => $revId
		];
		$result = $this->bot->query( $params );

		if ( empty( $result['query']['pages'] ) ) {
			$this->error( "Could not fetch data for $revId on remote wiki: bad API call.\n" );
			return;
		}

		$remoteRev = $result['query']['pages'][array_key_first( $result['query']['pages'] )]['revisions'][0];
		return $remoteRev;
	}

	public function replaceRevision( $rev, $remoteRev ) {
		// If it is empty, then replace it with the content from the remote wiki.
		// We fetch the revision text again separately here, because getting "content" is expensive
		// to do all the time in the original API call.
		$remoteRev = $this->fetchRemoteRevision( $remoteRev['revid'] );
		$content = ContentHandler::makeContent( $remoteRev['*'], null, $remoteRev['contentmodel'], $remoteRev['contentformat'] );
		$revId = $remoteRev['revid'];

		// TODO: do it in a better way than the next lines of code, which feel jank...
		$updatedRev = MutableRevisionRecord::newUpdatedRevisionRecord( $rev, [] );
		$updatedRev->setContent( SlotRecord::MAIN, $content );
		$updatedRev->setTimestamp( $rev->getTimestamp() );
		$updatedRev->setMinorEdit( $rev->isMinor() );
		$updatedRev->setComment( $rev->getComment() );
		$updatedRev->setVisibility( $rev->getVisibility() );

		if ( $this->dry ) {
			$this->output( "[DRY]: Would have replaced revision $revId with content from remote wiki\n" );
		} else {
			// Delete our version of the revision and save the new version.
			$this->dbw->delete(
				'revision',
				[ 'rev_id' => $revId ],
				__METHOD__
			);
			$this->dbw->delete(
				'slots',
				[ 'slot_revision_id' => $revId ],
				__METHOD__
			);
			$this->dbw->delete(
				'ip_changes',
				[ 'ipc_rev_id' => $remoteRev['revid'] ],
				__METHOD__
			);

			$this->revisionStore->insertRevisionOn( $updatedRev, $this->dbw );
		}

		$this->output("Replaced revision $rev with content from remote wiki\n");
	}

	public function handleRevision( $remoteRev, $pageID, $title ) {
		$rev = $this->revisionStore->getRevisionById( $remoteRev['revid'] );

		if ( is_null( $rev ) ) {
			// The revision is missing from our database.
			$this->output( "Bad revision (missing): {$remoteRev['revid']}\n" );

			$remoteRev = $this->fetchRemoteRevision( $remoteRev['revid'] );

			# Sloppy handler for revdeletions; just fills them in with dummy text
			# and sets bitfield thingy
			$revdeleted = 0;
			if ( isset( $remoteRev['userhidden'] ) ) {
				$revdeleted = $revdeleted | RevisionRecord::DELETED_USER;
				if ( !isset( $remoteRev['user'] ) ) {
					$remoteRev['user'] = ''; # username removed
				}
				if ( !isset( $remoteRev['userid'] ) ) {
					$remoteRev['userid'] = 0;
				}
			}
			$comment = $remoteRev['comment'] ?? '';
			if ( isset( $remoteRev['commenthidden'] ) ) {
				$revdeleted = $revdeleted | RevisionRecord::DELETED_COMMENT;
			}
			$text = $remoteRev['*'] ?? '';
			if ( isset( $remoteRev['texthidden'] ) ) {
				$revdeleted = $revdeleted | RevisionRecord::DELETED_TEXT;
			}
			if ( isset ( $remoteRev['suppressed'] ) ) {
				$revdeleted = $revdeleted | RevisionRecord::DELETED_RESTRICTED;
			}

			$rev = new MutableRevisionRecord( $title );
			$content = ContentHandler::makeContent( $text, null, $remoteRev['contentmodel'], $remoteRev['contentformat'] ?? null );
			$rev->setId( $remoteRev['revid'] );
			$rev->setComment( CommentStoreComment::newUnsavedComment( $comment ) );
			$rev->setContent( SlotRecord::MAIN, $content );
			$rev->setVisibility( $revdeleted );
			$rev->setTimestamp( $remoteRev['timestamp'] );
			$rev->setMinorEdit( isset( $remoteRev['minor'] ) );
			$userIdentity = $this->getUserIdentity( $remoteRev['userid'], $remoteRev['user'] );
			$rev->setUser( $userIdentity );
			$rev->setPageId( $pageID );
			if ( isset( $remoteRev['parentid'] ) ) {
				$rev->setParentId( $remoteRev['parentid'] );
			}

			if ( $this->dry ) {
				$this->output( "[DRY]: Would have inserted revision {$remoteRev['revid']} on {$rev->getPageId()} with content from {$remoteRev['user']} from remote wiki\n" );
			} else {
				$this->revisionStore->insertRevisionOn( $rev, $this->dbw );
			}

			$this->missingCount++;
		} else {
			try {
				$sha = Wikimedia\base_convert( $rev->getSha1(), 36, 16, 40 );
				if ( !is_null( $sha ) && array_key_exists( 'sha1', $remoteRev ) && $sha != $remoteRev['sha1'] ) {
					// The checksum of our revision and the remote revision doesn't match.
					$this->output( "Bad revision (hash): {$remoteRev['revid']} (ours: {$sha} | theirs: {$remoteRev['sha1']})\n" );
					$this->mismatchCount++;

					// Check whether our revision is an empty revision, because sites like Fandom often have empty
					// revision content on a random number of pages inside their XML dumps.
					if ( $rev->getSize() === 0 ) {
						$this->replaceRevision( $rev, $remoteRev );
						$this->replacedCount++;
					}
				}
			} catch ( RevisionAccessException $e ) {
				// SHA doesn't exist, or something went wrong, so let's just play safe and do nothing.
				$this->output( "Problem processing revision {$remoteRev['revid']}: {$e->getMessage()}\n" );
			}
		}
	}
}

$maintClass = 'CheckRevisions';
require_once RUN_MAINTENANCE_IF_MAIN;
