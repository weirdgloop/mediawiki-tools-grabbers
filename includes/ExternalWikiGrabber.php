<?php
/**
 * Base class used for grabbers that connect to external wikis
 *
 * @file
 * @ingroup Maintenance
 * @author Jack Phoenix <jack@shoutwiki.com>
 * @author Calimonious the Estrange
 * @author Jesús Martínez <martineznovo@gmail.com>
 * @date 5 August 2019
 * @version 1.0
 */

use MediaWiki\CommentStore\CommentStore;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\PageStore;
use MediaWiki\User\ActorStore;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityValue;
use MediaWiki\User\UserNameUtils;
use Wikimedia\Rdbms\IDatabase;

require_once __DIR__ . '/../../maintenance/Maintenance.php';
require_once 'mediawikibot.class.php';

abstract class ExternalWikiGrabber extends Maintenance {

	/**
	 * Handle to the primary database connection
	 */
	protected IDatabase $dbw;

	protected MediaWikiBot $bot;

	protected ActorStore $actorStore;

	protected CommentStore $commentStore;

	protected PageStore $pageStore;

	protected UserFactory $userFactory;

	protected UserNameUtils $userNameUtils;

	/**
	 * Array of [ userid => newname ] pairs.
	 *
	 * Cache the new user name for uncompleted user rename (inconsistent database)
	 * on old sites without the actor system, where each revision has a rev_user_text
	 * field to be updated and the process fails.
	 */
	protected array $userMappings = [];

	public function __construct() {
		parent::__construct();
		$this->addOption( 'url', 'URL to the target wiki\'s api.php', true /* required? */, true /* withArg */, 'u' );
		$this->addOption( 'username', 'Username to log into the target wiki', false, true, 'n' );
		$this->addOption( 'password', 'Password on the target wiki', false, true, 'p' );
		$this->addOption( 'db', 'Database name, if we don\'t want to write to $wgDBname', false, true );
	}

	public function execute() {
		global $wgDBname;

		$url = $this->getOption( 'url' );
		if ( !$url ) {
			$this->error( 'The URL to the target wiki\'s api.php is required.', 1 );
		}

		$user = $this->getOption( 'username' );
		$password = $this->getOption( 'password' );

		# Initialise MediaWikiBot class
		$this->bot = new MediaWikiBot(
			$url,
			'json',
			$user ?? '',
			$password ?? '',
			'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:13.0) Gecko/20100101 Firefox/13.0.1'
		);
		if ( $user && $password ) {
			$error = $this->bot->login();
			if ( !$error ) {
				$this->output( "Logged in as $user...\n" );
			} else {
				$this->fatalError( sprintf( "Failed to log in as %s: %s",
					$user, $error['login']['reason'] ) );
			}
		}

		# Get a single DB_PRIMARY connection
		$this->dbw = $this->getDB( DB_PRIMARY, [], $this->getOption( 'db', $wgDBname ) );

		$services = MediaWikiServices::getInstance();
		$this->userFactory = $services->getUserFactory();
		$this->pageStore = $services->getPageStore();
		$this->actorStore = $services->getActorStore();
		$this->commentStore = $services->getCommentStore();
		$this->userNameUtils = $services->getUserNameUtils();
	}

	/**
	 * For use with deleted crap that chucks the id; spotty at best.
	 *
	 * @param int $ns Namespace number
	 * @param string $title Title of the page without the namespace
	 */
	function getPageID( $ns, $title ) {
		$page = $this->pageStore->getPageByName( $ns, $title );
		return $page ? $page->getId() : null;
	}

	/**
	 * Strips the namespace from the title, if namespace number is different than 0,
	 *  and converts spaces to underscores. For use in database
	 *
	 * @param int $ns Namespace number
	 * @param string $title Title of the page with the namespace
	 */
	function sanitiseTitle( $ns, $title ) {
		if ( $ns != 0 ) {
			$title = preg_replace( '/^[^:]*?:/', '', $title );
		}
		$title = str_replace( ' ', '_', $title );
		return $title;
	}

	/**
	 * Looks for an actor name in the actor table, otherwise creates the actor
	 *  by assigning a new id
	 *
	 * @param int $id User id, or 0
	 * @param string $name User name or IP address
	 */
	function getUserIdentity( $id, $name ) {
		if ( empty( $name ) ) {
			return $this->actorStore->getUnknownActor();
		}

		# Old imported revisions might be assigned to anon users.
		# We also need to prefix system users if they really have no user ID
		# on the remote site, so we are not using ::isUsable() as ActorStore do.
		if ( !$id && $this->userNameUtils->isValid( $name ) ) {
			$name = 'imported>' . $name;
		} elseif ( $id ) {
			$name = $this->userMappings[$id] ?? $name;
			$userIdentity = $this->actorStore->getUserIdentityByUserId( $id );
			if ( $userIdentity && $userIdentity->getName() !== $name ) {
				$oldname = $userIdentity->getName();
				# Cache the new user name for uncompleted user rename.
				$this->userMappings[$id] = $name = $this->getAndUpdateUserName( $userIdentity );
				$this->output( "Notice: We encountered a user rename on ID $id, $oldname => $name\n" );
			} elseif ( $userIdentity ) {
				return $userIdentity;
			}
		}

		$userIdentity = new UserIdentityValue( $id, $name );
		$this->actorStore->acquireActorId( $userIdentity, $this->dbw );

		return $userIdentity;
	}

	/**
	 * Get the current user name of the given user from the remote site,
	 * and update our database.
	 *
	 * @param UserIdentity $user
	 * @return string The new user name
	 */
	private function getAndUpdateUserName( UserIdentity $userIdentity ) {
		$userid = $userIdentity->getId();
		$params = [
			'list' => 'users',
			'ususerids' => $userid,
		];
		$result = $this->bot->query( $params );
		if ( !isset( $result['query']['users'][0]['name'] ) ) {
			$this->fatalError( "User ID $userid not found, is that a suppressed user now?" );
		}

		$newname = $result['query']['users'][0]['name'];
		$user = $this->userFactory->newFromUserIdentity( $userIdentity );
		$conflictingUser = $this->userFactory->newFromName( $newname );

		if ( $conflictingUser && $conflictingUser->isRegistered() && $conflictingUser->getId() !== $user->getId() ) {
			$oldname = $this->getName();
			$this->output( "Notice: User name $newname is already in use by ID {$conflictingUser->getId()}, keeping user name $oldname for $userid\n" );
			return $oldname;
		}

		# Update the user's name
		$user->setName( $newname );
		$user->saveSettings();

		return $newname;
	}

	/**
	 * Gets an actor id or creates one if it doesn't exist
	 *
	 * @param int $id User id, or 0
	 * @param string $name User name or IP address
	 */
	function getActorFromUser( $id, $name ) {
		$user = $this->getUserIdentity( $id, $name );
		return $this->actorStore->acquireActorId( $user, $this->dbw );
	}

	/**
	 * Adds tags for a given revision, log, etc.
	 * This method mimicks what ChangeTags::updateTags() does, and the code
	 * is largely copied from there, removing unnecessary bits.
	 * $rev_id and/or $log_id must be provided
	 *
	 * @param array $tagsToAdd Tags to add to the change
	 * @param int|null $rev_id The rev_id of the change to add the tags to.
	 * @param int|null $log_id The log_id of the change to add the tags to.
	 */
	function insertTags( $tagsToAdd, $rev_id = null, $log_id = null ) {
		if ( !$tagsToAdd || count( $tagsToAdd ) == 0 ) {
			return;
		}
		$changeTagDefStore = MediaWikiServices::getInstance()->getChangeTagDefStore();
		$changeTagMapping = [];
		$tagsRows = [];
		foreach ( $tagsToAdd as $tag ) {
			$changeTagMapping[$tag] = $changeTagDefStore->acquireId( $tag );
			$tagsRows[] = array_filter(
				[
					'ct_rc_id' => null,
					'ct_log_id' => $log_id,
					'ct_rev_id' => $rev_id,
					# TODO: Investigate if params are available somehow
					'ct_params' => null,
					'ct_tag_id' => $changeTagMapping[$tag] ?? null,
				]
			);
		}
		$this->dbw->insert( 'change_tag', $tagsRows, __METHOD__, [ 'IGNORE' ] );
		$this->dbw->update(
			'change_tag_def',
			[ 'ctd_count = ctd_count + 1' ],
			[ 'ctd_name' => $tagsToAdd ],
			__METHOD__
		);
	}
}
