<?php
/**
 * Base class used for grabbers that connect to external wikis
 *
 * @file
 * @ingroup Maintenance
 * @author Jack Phoenix <jack@shoutwiki.com>
 * @author Calimonious the Estrange
 * @author Jesús Martínez <martineznovo@gmail.com>
 * @date 21 January 2024
 * @version 1.1
 */

use MediaWiki\MediaWikiServices;
use MediaWiki\User\ActorStore;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityValue;
use MediaWiki\User\UserNameUtils;
use Wikimedia\Rdbms\IDatabase;

require_once __DIR__ . '/../../maintenance/Maintenance.php';
require_once 'mediawikibot.class.php';

abstract class ExternalWikiGrabber extends Maintenance {

	protected const FANDOM_COMMENT_NAMESPACES = [
		500, // User blog
		501, // User blog comment
		502, // Blog
		503, // Blog talk
		1200, // Message Wall
		1201, // Thread
		1202, // Message Wall Greeting
		2000, // Board
		2001, // Board Thread
		2002, // Topic
	];

	/**
	 * Handle to the primary database connection
	 */
	protected IDatabase $dbw;

	protected MediaWikiBot $bot;

	protected ActorStore $actorStore;

	protected CommentStore $commentStore;

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
		$this->addOption( 'useragent', 'User agent to use on the target wiki', false, true );
		$this->addOption( 'fandom-auth', 'Use Fandom\'s authentication system', false, false );
		$this->addOption( 'fandom-app-id', 'App ID to use with Fandom\'s authentication system', false, true );
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
		$useragent = $this->getOption( 'useragent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/127.0.0.0 Safari/537.36' );
		$fandomAppId = $this->getOption( 'fandom-app-id', '1234' );

		# bot class and log in if requested
		if ( $user && $password ) {
			$this->bot = new MediaWikiBot(
				$url,
				'json',
				$user,
				$password,
				$useragent
			);
			if ( $this->getOption( 'fandom-auth' ) ) {
				$error = $this->bot->fandom_login( $fandomAppId );
			} else {
				$error = $this->bot->login();
			}
			if ( !$error ) {
				$this->output( "Logged in as $user...\n" );
			} else {
				$this->fatalError( sprintf( "Failed to log in as %s: %s",
					$user, $error['login']['reason'] ) );
			}
		} else {
			$this->bot = new MediaWikiBot(
				$url,
				'json',
				'',
				'',
				$useragent
			);
		}

		# Get a single DB_PRIMARY connection
		$this->dbw = $this->getDB( DB_PRIMARY, [], $this->getOption( 'db', $wgDBname ) );

		$services = MediaWikiServices::getInstance();
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
		$pageID = (int)$this->dbw->selectField(
			'page',
			'page_id',
			[
				'page_namespace' => $ns,
				'page_title' => $title,
			],
			__METHOD__
		);
		return $pageID;
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

		if ( !$id and !$this->userNameUtils->isIP( $name ) and !ExternalUserNames::isExternal( $name ) ) {
			# Everything that's not an IP or external, must be converted to external
			# Old imported revisions might be assigned to anon users.
			# We also need to prefix system users if they really have no user ID
			$name = "imported>$name";
		} elseif ( $id and !$this->userNameUtils->isValid( $name ) ) {
			# T353766: There's an edge case of apparently valid but not canonicalized usernames.
			# For example, usernames which start with lowercase characters.
			# Those users cause problems when this script detects a user name change,
			# because ActorStore does a normalization when inserting, but the external wiki
			# may have old users that don't follow current canonicalization rules.
			# Users with non-canonicalized names will be reported as invalid, and despite having
			# user id on the external wiki, they'll be inserted as imported to avoid further errors
			$name = "imported>$name";
			$id = 0;
		} elseif ( $id ) {
			$name = $this->userMappings[$id] ?? $name;
			$userIdentity = $this->actorStore->getUserIdentityByUserId( $id );
			if ( $userIdentity && $userIdentity->getName() !== $name ) {
				$oldname = $userIdentity->getName();
				# Cache the new user name for uncompleted user rename.
				$this->userMappings[$id] = $name = $this->getAndUpdateUserName( $userIdentity );
				if ( $oldname !== $name ) {
					$this->output( "Notice: We encountered a user rename on ID $id, $oldname => $name\n" );
				}
			} elseif ( $userIdentity ) {
				return $userIdentity;
			} else {
				// If a user already exists by this name, then append '@fandom' as a user can't create an account with the `@` character.
				$userIdentity = $this->actorStore->getUserIdentityByName( $name );
				if ( $userIdentity ) {
					$this->output( "Notice: The user name $name is already in use, using $name@fandom for ID $id instead.\n" );
					$this->userMappings[$id] = $name = $name . '@fandom';
				}
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
	private function getAndUpdateUserName( UserIdentity $user ) {
		$oldname = $user->getName();
		$userid = $user->getId();

		// Don't rename users who have already migrated.
		$usermigrated = $this->dbw->selectField(
			'user',
			'1',
			[
				'user_id' => $userid,
				"user_password != ''",
			],
			__METHOD__
		);
		if ( $usermigrated ) {
			$this->output( "Notice: User ID $userid already migrated, keeping user name as $oldname.\n" );
			return $oldname;
		}

		$params = [
			'list' => 'users',
			'ususerids' => $userid,
		];
		$result = $this->bot->query( $params );
		if ( !isset( $result['query']['users'][0]['name'] ) ) {
			$this->fatalError( "User ID $userid not found, is that a suppressed user now?" );
		}

		# Clear all related cache
		MediaWikiServices::getInstance()->getUserFactory()->newFromUserIdentity( $user )->invalidateCache();
		$this->actorStore->deleteUserIdentityFromCache( $user );

		$newname = $result['query']['users'][0]['name'];

		$conflictingid = (int)$this->dbw->selectField(
			'user',
			'user_id',
			[
				'user_name' => $newname,
			],
			__METHOD__
		);
		if ( $conflictingid && $conflictingid !== $userid ) {
			$this->output( "Notice: User name $newname is already in use by ID $conflictingid, keeping user name $oldname for $userid\n" );
			return $oldname;
		}
		# Adapt from RenameuserSQL::rename(), do we need other parts?
		$this->dbw->update(
			'user',
			[ 'user_name' => $newname, 'user_touched' => $this->dbw->timestamp() ],
			[ 'user_id' => $userid ],
			__METHOD__
		);
		$this->dbw->update(
			'actor',
			[ 'actor_name' => $newname ],
			[ 'actor_user' => $userid ],
			__METHOD__
		);

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
