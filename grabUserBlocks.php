<?php
/**
 * Maintenance script to grab the user block data from a wiki (to which we have
 * only read-only access instead of full database access).
 *
 * @file
 * @ingroup Maintenance
 * @author Jack Phoenix
 * @author Jesús Martínez <martineznovo@gmail.com>
 * @version 1.1
 * @date 5 August 2019
 * @note Based on code by:
 * - Legoktm & Uncyclopedia development team, 2013 (blocks_table.py)
 */

use MediaWiki\MediaWikiServices;
use Wikimedia\IPUtils;

require_once 'includes/ExternalWikiGrabber.php';

class GrabUserBlocks extends ExternalWikiGrabber {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Grabs user block data from a pre-existing wiki into a new wiki.' );
		$this->addOption( 'startdate', 'Start point (20121222142317, 2012-12-22T14:23:17Z, etc).', false, true );
		$this->addOption( 'enddate', 'End point (20121222142317, 2012-12-22T14:23:17Z, etc); defaults to current timestamp.', false, true );
		$this->addOption( 'truncate', 'Delete existing user block data from the new wiki.', false, false );
	}

	public function execute() {
		parent::execute();

		if ( $this->hasOption( 'truncate' ) ) {
			$this->output( "Deleting existing user block entries...\n" );
			$this->dbw->truncateTable( 'block', __METHOD__ );
			$this->dbw->truncateTable( 'block_target',  __METHOD__ );
		}

		$startDate = $this->getOption( 'startdate' );
		if ( $startDate ) {
			if ( !wfTimestamp( TS_ISO_8601, $startDate ) ) {
				$this->fatalError( 'Invalid startdate format.' );
			}
		}
		$endDate = $this->getOption( 'enddate' );
		if ( $endDate ) {
			if ( !wfTimestamp( TS_ISO_8601, $endDate ) ) {
				$this->fatalError( 'Invalid enddate format.' );
			}
		} else {
			$endDate = wfTimestampNow();
		}

		$params = [
			'list' => 'blocks',
			'bkdir' => 'newer',
			'bkend' => $endDate,
			'bklimit' => 'max',
			'bkprop' => 'id|user|userid|by|byid|timestamp|expiry|reason|range|flags',
		];

		if ( $startDate !== null ) {
			$params['bkstart'] = $startDate;
		}

		$more = true;
		$i = 0;

		$this->output( "Grabbing blocks...\n" );
		do {
			$result = $this->bot->query( $params );

			if ( empty( $result['query']['blocks'] ) ) {
				$this->output( "No blocks, hence nothing to do. Aborting the mission.\n" );
				return;
			}

			foreach ( $result['query']['blocks'] as $logEntry ) {
				// Skip autoblocks, nothing we can do about 'em
				if ( !isset( $logEntry['automatic'] ) ) {
					$this->processEntry( $logEntry );
					$i++;
				}

				if ( isset( $result['query-continue'] ) && isset( $result['query-continue']['blocks'] ) ) {
					$params = array_merge( $params, $result['query-continue']['blocks'] );
					$this->output( "{$i} entries processed.\n" );
				} elseif ( isset( $result['continue'] ) ) {
					$params = array_merge( $params, $result['continue'] );
					$this->output( "{$i} entries processed.\n" );
				} else {
					$more = false;
				}
			}

			# Readd the AUTO_INCREMENT here
		} while ( $more );

		$this->output( "Done: $i entries processed\n" );
	}

	public function processEntry( $entry ) {
		$ts = wfTimestamp( TS_MW, $entry['timestamp'] );

		$commentStore = MediaWikiServices::getInstance()->getCommentStore();
		$commentFields = $commentStore->insert( $this->dbw, 'bl_reason', $entry['reason'] );

		$isUser = $entry['userid'] !== 0;
		$ipHex = null;
		if ( isset( $entry['rangestart'] ) ) {
			$ipHex = $entry['rangestart'];
		} elseif ( !$isUser ) {
			$ipHex = IPUtils::toHex( $entry['user'] );
		}

		$targetData = [
			'bt_address' => $isUser ? null : $entry['user'],
			'bt_user' => $isUser ? $entry['userid'] : null,
			'bt_user_text' => $isUser ? $entry['user'] : null,
			'bt_auto' => 0,
			'bt_range_start' => $entry['rangestart'] ?? null,
			'bt_range_end' => $entry['rangeend'] ?? null,
			'bt_ip_hex' => $ipHex,
			'bt_count' => 1
		];
		$this->dbw->insert( 'block_target', $targetData, __METHOD__ );
		$targetRowId = $this->dbw->insertId();

		$data = [
			'bl_id' => $entry['id'],
			'bl_target' => $targetRowId,
			'bl_by_actor' => $this->getActorFromUser( $entry['byid'], $entry['by'] ),
			'bl_timestamp' => $ts,
			'bl_anon_only' => isset( $entry['anononly'] ),
			'bl_create_account' => isset( $entry['nocreate'] ),
			'bl_enable_autoblock' => isset( $entry['autoblock'] ),
			'bl_expiry' => ( $entry['expiry'] == 'infinity' ? $this->dbw->getInfinity() : wfTimestamp( TS_MW, $entry['expiry'] ) ),
			'bl_deleted' => isset( $entry['hidden'] ),
			'bl_block_email' => isset( $entry['noemail'] ),
			'bl_allow_usertalk' => isset( $entry['allowusertalk'] ),
		] + $commentFields;
		$this->dbw->insert( 'block', $data, __METHOD__ );
		$this->dbw->commit();
	}
}

$maintClass = 'GrabUserBlocks';
require_once RUN_MAINTENANCE_IF_MAIN;
