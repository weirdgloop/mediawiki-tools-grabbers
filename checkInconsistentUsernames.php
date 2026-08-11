<?php

require_once 'includes/ExternalWikiGrabber.php';

class CheckInconsistentUsernames extends ExternalWikiGrabber {

	/**
	 * The report interval
	 *
	 * @var int
	 */
	protected $reportInterval = 500;

	public function __construct() {
		parent::__construct();
		$this->addDescription('Checks that the user names in our database match the remote wiki');
		$this->addOption( 'dry','Dry run, do not rename users', false, false );
	}

	public function execute() {
		parent::execute();

		if ( $this->platform ) {
			// Using GUM
			$sqb = $this->dbw->newSelectQueryBuilder()
				->select( [ 'user_id', 'user_name', 'gup_remote_id', 'actor_id', 'actor_name' ] )
				->from( 'gum_user_platforms', 'gup' )
				->join( 'user', 'u', 'gup.gup_user = u.user_id' )
				->join( 'actor', 'a', 'u.user_id = a.actor_user' )
				->where( [
					'gup_platform' => $this->platform,
					'user_password' => ''
				] )
				->caller( __METHOD__ );
		} else {
			$sqb = $this->dbw->newSelectQueryBuilder()
				->select( [ 'user_id', 'user_name', 'actor_id', 'actor_name' ] )
				->from( 'user', 'u' )
				->join( 'actor', 'a', 'u.user_id = a.actor_user' )
				->where( [
					'user_password' => ''
				] )
				->caller( __METHOD__ );
		}

		$iterator = new BatchRowIterator(
			$this->dbw,
			$sqb,
			[ 'user_id' ],
			50
		);

		$count = 0;
		$checkpoint = $this->reportInterval;

		$this->output( "Checking users - this may take a while...\n" );

		foreach ( $iterator as $batch ) {
			$usersToFetch = [];
			foreach ( $batch as $row ) {
				$remoteId = $row->gup_remote_id ?? $row->user_id;
				$usersToFetch[ $remoteId ] = $row;
			}

			// Remote API's list=users allows us to pass at least 50 user IDs at a time
			$result = $this->externalWikiService->fetch( [
				'action' => 'query',
				'list' => 'users',
				'ususerids' => implode( '|', array_keys( $usersToFetch ) )
			] );

			if ( !$result->isOK() || !isset( $result->getValue()['query']['users'] ) ) {
				$this->fatalError( 'Problem with API call to remote wiki' );
			}

			$remoteUsers = $result->getValue()['query']['users'];

			foreach ( $remoteUsers as $user ) {
				$remoteName = $user['name'];
				$row = $usersToFetch[ $user['userid'] ];
				if ( $remoteName !== $row->user_name ) {
					$localUserId = $row->user_id;

					if ( $this->hasOption( 'dry' ) ) {
						$this->output( "Would rename user $localUserId - $row->user_name => $remoteName\n" );
						continue;
					}

					$row->actor_user = $localUserId;
					$userIdentity = $this->actorStore->newActorFromRow( $row );

					# Clear all related cache
					$this->userFactory->newFromUserIdentity( $userIdentity )->invalidateCache();
					$this->actorStore->deleteUserIdentityFromCache( $userIdentity );

					$conflictingid = (int)$this->dbw->selectField(
						'user',
						'user_id',
						[
							'user_name' => $remoteName,
						],
						__METHOD__
					);
					if ( $conflictingid && $conflictingid !== $localUserId ) {
						$this->output( "Notice: User name $remoteName is already in use by ID $conflictingid, keeping user name $row->user_name for $localUserId\n" );
						continue;
					}
					# Adapt from RenameuserSQL::rename(), do we need other parts?
					$this->dbw->update(
						'user',
						[ 'user_name' => $remoteName, 'user_touched' => $this->dbw->timestamp() ],
						[ 'user_id' => $localUserId ],
						__METHOD__
					);
					$this->dbw->update(
						'actor',
						[ 'actor_name' => $remoteName ],
						[ 'actor_user' => $localUserId ],
						__METHOD__
					);

					$this->output( "Renamed user $localUserId to match remote - $row->user_name => $remoteName\n" );
				}

				$count++;

				if ( $count >= $checkpoint ) {
					$this->output( "{$count} users checked.\n" );
					$checkpoint = $checkpoint + $this->reportInterval;
				}
			}
		}

		$this->output( "\n\nDone. $count users checked.\n" );
	}
}

$maintClass = 'CheckInconsistentUsernames';
require_once RUN_MAINTENANCE_IF_MAIN;
