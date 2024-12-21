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

require_once 'includes/TextGrabber.php';

class GrabText extends TextGrabber {

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			"Grab text from an external wiki and import it into one of ours.\nDon't use this on a large wiki unless you absolutely must; it will be incredibly slow."
		);
		$this->addOption( 'start', 'Page at which to start, useful if the script stopped at this point', false, true );
		$this->addOption( 'namespaces', 'Pipe-separated namespaces (ID) to grab. Defaults to all namespaces', false, true );
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
			$textNamespaces = explode( '|', $this->getOption( 'namespaces', '' ) );
			$grabFromAllNamespaces = false;
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
			$pageCount = $siteinfo['statistics']['pages'];
			$this->output( "Generating page list from all namespaces - $pageCount expected...\n" );
		} else {
			$this->output( sprintf( "Generating page list from %s namespaces...\n", count( $textNamespaces ) ) );
		}

		$start = $this->getOption( 'start' );
		if ( $start ) {
			$title = Title::newFromText( $start );
			if ( is_null( $title ) ) {
				$this->fatalError( 'Invalid title provided for the start parameter' );
			}
			$this->output( sprintf( "Trying to resume import from page %s\n", $title ) );
		}

		$pageCount = 0;
		foreach ( $textNamespaces as $ns ) {
			$continueTitle = null;
			if ( isset( $title ) && !is_null( $title ) ) {
				if ( $title->getNamespace() === (int)$ns ) {
					# The apfrom parameter doesn't have namespace!!
					$continueTitle = $title->getText();
					$title = null;
				} else {
					continue;
				}
			}
			$pageCount += $this->processPagesFromNamespace( (int)$ns, $continueTitle );
		}
		$this->output( "\nDone - found $pageCount total pages.\n" );
		# Done.
	}

	/**
	 * Grabs all pages from a given namespace
	 *
	 * @param int $ns Namespace to process.
	 * @param string $continueTitle Title to start from (optional).
	 * @return int Number of pages processed.
	 */
	function processPagesFromNamespace( $ns, $continueTitle = null ) {
		$this->output( "Processing pages from namespace $ns...\n" );
		$doneCount = 0;
		$nsPageCount = 0;
		$more = true;
		$params = [
			'generator' => 'allpages',
			'gaplimit' => 'max',
			'prop' => 'info',
			'inprop' => 'protection',
			'gapnamespace' => $ns
		];
		if ( $continueTitle ) {
			$params['gapfrom'] = $continueTitle;
		}
		do {
			$result = $this->bot->query( $params );

			# Skip empty namespaces
			if ( isset( $result['query'] ) ) {
				$pages = $result['query']['pages'];

				$resultsCount = 0;
				foreach ( $pages as $page ) {
					if ( $this->getOption( 'skip-fandom-comments' ) && preg_match( '/^(.*)(\/@comment-.*-20\d{12}){1,2}$/', $page['title'] ) ) {
						// Fandom's comment system creates a new page for each comment, which is terrible.
						$this->output( "Skipped page \"{$page['title']}\": Fandom comment page.\n" );
					} else {
						$this->processPage( $page );
					}

					$doneCount++;
					if ( $doneCount % 500 === 0 ) {
						$this->output( "$doneCount\n" );
					}
					$resultsCount++;
				}
				$nsPageCount += $resultsCount;

				# Add continuation parameters
				if ( isset( $result['query-continue'] ) && isset( $result['query-continue']['allpages'] ) ) {
					$params = array_merge( $params, $result['query-continue']['allpages'] );
				} elseif ( isset( $result['continue'] ) ) {
					$params = array_merge( $params, $result['continue'] );
				} else {
					$more = false;
				}
			} else {
				$more = false;
			}
		} while ( $more );

		$this->output( "$nsPageCount pages found in namespace $ns.\n" );

		return $nsPageCount;
	}

	/**
	 * Handle an individual page.
	 *
	 * @param array $page: Array retrieved from the API, containing pageid,
	 *     page title, namespace, protection status and more...
	 */
	function processPage( $page ) {
		$pageID = $page['pageid'];

		$this->output( "Processing page id $pageID...\n" );

		$params = [
			'prop' => 'info|revisions',
			'rvlimit' => 'max',
			'rvprop' => 'ids|flags|timestamp|user|userid|comment|content|tags|contentmodel',
			'rvdir' => 'newer',
			'rvend' => wfTimestamp( TS_ISO_8601, $this->endDate )
		];
		$params['pageids'] = $pageID;

		$result = $this->bot->query( $params );

		if ( !$result || isset( $result['error'] ) ) {
			$this->fatalError( "Error getting revision information from API for page id $pageID." );
			return;
		}

		$info_pages = array_values( $result['query']['pages'] );
		if ( isset( $info_pages[0]['missing'] ) ) {
			$this->output( "Page id $pageID not found.\n" );
			return;
		}

		$page_e = [
			'namespace' => null,
			'title' => null,
			'is_redirect' => 0,
			'is_new' => 0,
			'random' => wfRandom(),
			'touched' => wfTimestampNow(),
			'len' => 0,
			'content_model' => null
		];
		# Trim and convert displayed title to database page title
		# Get it from the returned value from api
		$page_e['namespace'] = $info_pages[0]['ns'];
		$page_e['title'] = $this->sanitiseTitle( $info_pages[0]['ns'], $info_pages[0]['title'] );

		# We kind of need this to resume...
		$this->output( "Title: {$page_e['title']} in namespace {$page_e['namespace']}\n" );
		$title = Title::makeTitle( $page_e['namespace'], $page_e['title'] );

		# Get other information from api info
		$page_e['is_redirect'] = ( isset( $info_pages[0]['redirect'] ) ? 1 : 0 );
		$page_e['is_new'] = ( isset( $info_pages[0]['new'] ) ? 1 : 0 );
		$page_e['len'] = $info_pages[0]['length'];
		$page_e['latest'] = $info_pages[0]['lastrevid'];
		$defaultModel = null;
		if ( isset( $info_pages[0]['contentmodel'] ) ) {
			# This would be the most accurate way of getting the content model for a page.
			# However it calls hooks and can be incredibly slow or cause errors
			#$defaultModel = ContentHandler::getDefaultModelFor( $title );
			$defaultModel = MediaWikiServices::getInstance()->getNamespaceInfo()->
				getNamespaceContentModel( $info_pages[0]['ns'] ) ?? CONTENT_MODEL_WIKITEXT;
			# Set only if not the default content model
			if ( $defaultModel != $info_pages[0]['contentmodel'] ) {
				$page_e['content_model'] = $info_pages[0]['contentmodel'];
			}
		}

		# Check if page is present
		$pageRow = $this->dbw->selectRow(
			'page',
			[ 'page_namespace', 'page_title' ],
			[ 'page_id' => $pageID ],
			__METHOD__
		);

		# If page is not present, check if title is present, because we can't insert
		# a duplicate title. That would mean the page was moved leaving a redirect but
		# we haven't processed the move yet
		# Also, check the destination title before moving the page
		if ( $pageRow === false ||
			$pageRow->page_namespace != $page_e['namespace'] ||
			$pageRow->page_title != $page_e['title']
		) {
			$conflictingPageID = $this->getPageID( $page_e['namespace'], $page_e['title'] );
			if ( $conflictingPageID ) {
				# Whoops...
				$this->resolveConflictingTitle( $page_e['namespace'], $page_e['title'], $pageID, $conflictingPageID );
			}
		}

		# Update page_restrictions (only if requested)
		if ( isset( $page['protection'] ) ) {
			$this->output( "Setting page_restrictions on page_id $pageID.\n" );
			# Delete first any existing protection
			$this->dbw->delete(
				'page_restrictions',
				[ 'pr_page' => $pageID ],
				__METHOD__
			);
			# insert current restrictions
			foreach ( $page['protection'] as $prot ) {
				# Skip protections inherited from cascade protections
				if ( !isset( $prot['source'] ) ) {
					$expiry = $prot['expiry'] == 'infinity' ? 'infinity' : wfTimestamp( TS_MW, $prot['expiry'] );
					$this->dbw->insert(
						'page_restrictions',
						[
							'pr_page' => $pageID,
							'pr_type' => $prot['type'],
							'pr_level' => $prot['level'],
							'pr_cascade' => (int)isset( $prot['cascade'] ),
							'pr_expiry' => $expiry
						],
						__METHOD__
					);
				}
			}
		}

		$revisionsProcessed = false;
		while ( true ) {
			foreach ( $info_pages[0]['revisions'] as $revision ) {
				$revisionsProcessed = $this->processRevision( $revision, $pageID, $title ) || $revisionsProcessed;
			}

			# Add continuation parameters
			if ( isset( $result['query-continue'] ) && isset( $result['query-continue']['revisions'] ) ) {
				$params = array_merge( $params, $result['query-continue']['revisions'] );
			} elseif ( isset( $result['continue'] ) ) {
				$params = array_merge( $params, $result['continue'] );
			} else {
				break;
			}

			$result = $this->bot->query( $params );
			if ( !$result || isset( $result['error'] ) ) {
				$this->fatalError( "Error getting revision information from API for page id $pageID." );
				return;
			}

			$info_pages = array_values( $result['query']['pages'] );
		}

		if ( !$revisionsProcessed ) {
			# We already processed the page before? page doesn't need updating, then
			return;
		}

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
		if ( $pageRow === false ) {
			# insert if not present
			$this->output( "Inserting page entry $pageID\n" );
			$insert_fields['page_id'] = $pageID;
			$this->dbw->insert(
				'page',
				$insert_fields,
				__METHOD__
			);
		} else {
			# update existing
			$this->output( "Updating page entry $pageID\n" );
			$this->dbw->update(
				'page',
				$insert_fields,
				[ 'page_id' => $pageID ],
				__METHOD__
			);
		}
		$this->dbw->commit();
	}
}

$maintClass = 'GrabText';
require_once RUN_MAINTENANCE_IF_MAIN;
