<?php
/**
 * Maintenance script to grab revisions from a wiki and import it to another wiki.
 */

use MediaWiki\MediaWikiServices;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Page\PageIdentityValue;
use Wikimedia\Rdbms\SelectQueryBuilder;
use Wikimedia\Timestamp\ConvertibleTimestamp;

require_once 'includes/TextGrabber.php';

class GrabRevisions extends TextGrabber {

	public function __construct() {
		parent::__construct();
		$this->addDescription( "Grab revisions from an external wiki and import it into one of ours.\n" .
			"Don't use this on a large wiki unless you absolutely must; it will be incredibly slow." );
		$this->addOption( 'arvstart', 'Timestamp at which to continue, useful to grab new revisions', false, true );
		$this->addOption( 'arvend', 'Timestamp at which to end', false, true );
		$this->addOption( 'new-revisions', 'Resume from the latest revision\'s timestamp.' );
		$this->addOption( 'namespaces', 'Pipe-separated namespaces (ID) to grab. Defaults to all namespaces', false, true );
		$this->addOption( 'refreshlinks', 'Create refreshLinks jobs for changed pages.' );
		$this->addOption( 'skip-fandom-comments', 'Skip any pages that are Fandom comment pages (@comment-*)' );
	}

	public function execute() {
		parent::execute();

		$this->output( "\n" );

		# Get all pages as a list, start by getting namespace numbers...
		$this->output( "Retrieving namespaces list...\n" );

		$params = [
			'meta' => 'siteinfo',
			'siprop' => 'namespaces|statistics'
		];
		$result = $this->bot->query( $params );
		$siteinfo = $result['query'];

		# No data - bail out early
		if ( empty( $siteinfo ) ) {
			$this->fatalError( 'No siteinfo data found' );
		}

		$textNamespaces = [];
		if ( $this->hasOption( 'namespaces' ) ) {
			$grabFromAllNamespaces = false;
			$textNamespaces = explode( '|', $this->getOption( 'namespaces', '' ) );
			foreach ( $textNamespaces as $idx => $ns ) {
				# Ignore special
				if ( $ns < 0 || !isset( $siteinfo['namespaces'][$ns] ) ) {
					unset( $textNamespaces[$idx] );
				}
			}
			$textNamespaces = array_values( $textNamespaces );
		} else {
			$grabFromAllNamespaces = true;
			foreach ( array_keys( $siteinfo['namespaces'] ) as $ns ) {
				# Ignore special
				if ( $ns >= 0 ) {
					$textNamespaces[] = $ns;
				}
			}
		}
		if ( $this->getOption( 'skip-fandom-comments' ) ) {
			$grabFromAllNamespaces = false;
			$textNamespaces = array_diff( $textNamespaces, static::FANDOM_COMMENT_NAMESPACES );
		}
		if ( !$textNamespaces ) {
			$this->fatalError( 'Got no namespaces' );
		}

		if ( $grabFromAllNamespaces ) {
			# Get list of live pages from namespaces and continue from there
			$revCount = $siteinfo['statistics']['edits'];
			$this->output( "Generating revision list from all namespaces - $revCount expected...\n" );
		} else {
			$this->output( sprintf( "Generating revision list from %s namespaces...\n", count( $textNamespaces ) ) );
		}

		$arvstart = $this->getOption( 'arvstart' );
		$arvend = $this->getOption( 'arvend' );
		if ( $this->hasOption( 'new-revisions' ) ) {
			$dbr = $this->getDB( DB_REPLICA );
			$arvstart = $dbr->newSelectQueryBuilder()
				->select( 'rev_timestamp' )
				->from( 'revision' )
				->orderBy( 'rev_timestamp', SelectQueryBuilder::SORT_DESC )
				->caller( __METHOD__ )->fetchField();
			$this->output( "Latest revision's timestamp: $arvstart\n" );
		}

		$arvstart = ( new ConvertibleTimestamp( $arvstart ) )->sub( 'PT1S' )->getTimestamp( TS_MW );
		$pageCount = $this->processRevisionsFromNamespaces( implode( '|', $textNamespaces ), $arvstart, $arvend );
		$this->output( "\nDone - updated $pageCount total pages.\n" );
		# Done.
	}

	/**
	 * @param PageIdentity $page
	 * @return int page_latest
	 */
	protected function checkPage( PageIdentity $page ) {
		$pageRow = $this->dbw->selectRow(
			'page',
			[ 'page_latest', 'page_namespace', 'page_title' ],
			[ 'page_id' => $page->getId() ],
			__METHOD__
		);

		# If page is not present, check if title is present, because we can't insert
		# a duplicate title. That would mean the page was moved leaving a redirect but
		# we haven't processed the move yet
		if ( $pageRow === false ||
			$pageRow->page_namespace != $page->getNamespace() ||
			$pageRow->page_title != $page->getDBkey()
		) {
			$conflictingPageID = $this->getPageID( $page->getNamespace(), $page->getDBkey() );
			if ( $conflictingPageID ) {
				# Whoops...
				$this->resolveConflictingTitle( $page->getNamespace(), $page->getDBkey(), $page->getId(), $conflictingPageID );
			}
		}

		return $pageRow ? (int)$pageRow->page_latest : 0;
	}

	protected function insertOrUpdatePage( array $pageInfo ) {
		$pageID = $pageInfo['pageid'];
		$lastRevision = $pageInfo['revisions'][count( $pageInfo['revisions'] ) - 1];
		$pageDBKey = $this->sanitiseTitle( $pageInfo['ns'], $pageInfo['title'] );
		$page_e = [
			'namespace' => $pageInfo['ns'],
			# Trim and convert displayed title to database page title
			'title' => $pageDBKey,
			'is_redirect' => false,
			'is_new' => false,
			'random' => wfRandom(),
			'touched' => wfTimestampNow(),
			'len' => $lastRevision['size'],
			'content_model' => null,
			'latest' => $lastRevision['revid'],
		];

		# We kind of need this to resume...
		$this->output( "Title: {$page_e['title']} in namespace {$page_e['namespace']}\n" );
		$title = Title::makeTitle( $page_e['namespace'], $page_e['title'] );

		# Get other information from api info
		$defaultModel = null;
		if ( isset( $pageInfo['contentmodel'] ) ) {
			# This would be the most accurate way of getting the content model for a page.
			# However it calls hooks and can be incredibly slow or cause errors
			#$defaultModel = ContentHandler::getDefaultModelFor( $title );
			$defaultModel = MediaWikiServices::getInstance()->getNamespaceInfo()
				->getNamespaceContentModel( $pageInfo['ns'] ) ?? CONTENT_MODEL_WIKITEXT;
			# Set only if not the default content model
			if ( $defaultModel != $pageInfo['contentmodel'] ) {
				$page_e['content_model'] = $pageInfo['contentmodel'];
			}
		}

		# Check if page is present
		$pageIdent = new PageIdentityValue(
			$pageInfo['pageid'], $pageInfo['ns'], $pageDBKey, PageIdentityValue::LOCAL
		);
		$pageLatest = $this->checkPage( $pageIdent );

		$insert_fields = [
			'page_namespace' => $page_e['namespace'],
			'page_title' => $page_e['title'],
			'page_is_redirect' => $page_e['is_redirect'],
			'page_is_new' => $page_e['is_new'],
			'page_random' => $page_e['random'],
			'page_touched' => $page_e['touched'],
			'page_latest' => $page_e['latest'],
			'page_len' => $page_e['len'],
			'page_content_model' => $page_e['content_model']
		];
		if ( !$pageLatest ) {
			# insert if not present
			$this->output( "Inserting page entry $pageID\n" );
			$insert_fields['page_id'] = $pageID;
			$this->dbw->insert(
				'page',
				$insert_fields,
				__METHOD__
			);
		} elseif ( $pageLatest < (int)$page_e['latest'] ) {
			# update existing
			$this->output( "Updating page entry $pageID\n" );
			$this->dbw->update(
				'page',
				$insert_fields,
				[ 'page_id' => $pageID ],
				__METHOD__
			);
		} else {
			// $this->output( "No need to update page entry for $pageID\n" );
		}
	}

	/**
	 * Grabs all revisions from a given namespace
	 *
	 * @param string $ns Namespaces to process, separate by '|'.
	 * @param string $arvstart Timestamp to start from (optional).
	 * @param string $arvend Timestamp to end with (optional).
	 * @return int Number of pages processed.
	 */
	protected function processRevisionsFromNamespaces( $ns, $arvstart = null, $arvend = null ) {
		$this->output( "Processing pages from namespace $ns...\n" );

		$params = [
			'list' => 'allrevisions',
			'arvlimit' => 'max',
			'arvdir' => 'newer', // Grab old revisions first
			'arvprop' => 'ids|flags|timestamp|user|userid|comment|content|tags|contentmodel|size',
		];
		if ( $this->hasOption( 'namespaces' ) ) {
			$params['arvnamespace'] = $ns;
		}
		if ( $this->getOption( 'arvstart' ) || $this->getOption( 'new-revisions' ) ) {
			$params['arvstart'] = $arvstart;
		}
		if ( $arvend ) {
			$params['arvend'] = $arvend;
		}

		$pageMap = [];
		$revisionCount = 0;
		$misserModeCount = 0;
		$lastTimestamp = '';
		while ( true ) {
			$result = $this->bot->query( $params );

			$pages = $result['query']['allrevisions'];
			// Deal with miser mode
			if ( $pages ) {
				$misserModeCount = $resultsCount = 0;
				foreach ( $pages as $pageInfo ) {
					if ( $this->getOption( 'skip-fandom-comments' ) && preg_match( '/^(.*)(\/@comment-.*-20\d{12}){1,2}$/', $pageInfo['title'] ) ) {
						// Fandom's comment system creates a new page for each comment, which is terrible.
						$this->output( "Skipped page \"${pageInfo['title']}\": Fandom comment page.\n" );
						continue;
					}

					$pageDBKey = $this->sanitiseTitle( $pageInfo['ns'], $pageInfo['title'] );
					$pageIdent = PageIdentityValue::localIdentity(
						$pageInfo['pageid'], $pageInfo['ns'], $pageDBKey
					);
					foreach ( $pageInfo['revisions'] as $revision ) {
						$this->processRevision( $revision, $pageInfo['pageid'], $pageIdent );
						$resultsCount++;
						$lastTimestamp = $revision['timestamp'];
					}
					$this->insertOrUpdatePage( $pageInfo );
					$pageMap[$pageInfo['pageid']] = true;
				}
				$revisionCount += $resultsCount;
				$this->output( "$resultsCount/$revisionCount, arvstart: $lastTimestamp\n" );
			} else {
				$misserModeCount++;
				$this->output( "No result in this query due to misser mode.\n" );
				// Just in case if too far to scroll
				if ( $lastTimestamp && $misserModeCount % 10 === 0 ) {
					$this->output( "Last arvstart: $lastTimestamp\n" );
				}
			}
			if ( !isset( $result['continue'] ) ) {
				break;
			}

			// Add continuation parameters
			$params = array_merge( $params, $result['continue'] );
		}

		$this->output( "$revisionCount revisions processed in namespace $ns.\n" );

		if ( $this->getOption( 'refreshlinks' ) ) {
			$this->output( "Adding refreshLinks jobs for changed pages.\n" );
			foreach ( $pageMap as $id => $unused ) {
				$title = Title::newFromId( $id );
				$job = new RefreshLinksJob( $title, [] );
				MediaWikiServices::getInstance()->getJobQueueGroup()->push( $job );
			}
		}

		return count( $pageMap );
	}
}

$maintClass = GrabRevisions::class;
require_once RUN_MAINTENANCE_IF_MAIN;
