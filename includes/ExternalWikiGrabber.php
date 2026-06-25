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

use MediaWiki\CommentStore\CommentStore;
use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IMaintainableDatabase;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\User\ActorStore;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityValue;
use MediaWiki\User\UserNameUtils;

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
	protected IMaintainableDatabase $dbw;

	protected MediaWikiBot $bot;

	protected ActorStore $actorStore;

	protected CommentStore $commentStore;

	protected UserNameUtils $userNameUtils;

	protected bool $isFandom = false;

	/**
	 * Array of [ userid => newname ] pairs.
	 *
	 * Cache the new user name for uncompleted user rename (inconsistent database)
	 * on old sites without the actor system, where each revision has a rev_user_text
	 * field to be updated and the process fails.
	 */
	protected array $userMappings = [];

	private string $platform = '';

	public function __construct() {
		parent::__construct();
		$this->addOption( 'url', 'URL to the target wiki\'s api.php', true /* required? */, true /* withArg */, 'u' );
		$this->addOption( 'username', 'Username to log into the target wiki', false, true, 'n' );
		$this->addOption( 'password', 'Password on the target wiki', false, true, 'p' );
		$this->addOption( 'db', 'Database name, if we don\'t want to write to $wgDBname', false, true );

		// WGL added options
		$this->addOption( 'platform', 'External platform name to use for GUM if not autodetected, e.g. "fandom" or "wikigg"', false, true );
		$this->addOption( 'useragent', 'User agent to use on the target wiki', false, true );
		$this->addOption( 'actor-conflict-suffix', 'Suffix to use when a user\'s name conflicts, such as "fandom"', false, true );
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
		$this->isFandom = (bool)preg_match( '/\.(fandom|wikia|gamepedia)\.com/',  $this->getOption( 'url', '' ) );

		# bot class and log in if requested
		if ( $user && $password ) {
			$this->bot = new MediaWikiBot(
				$url,
				'json',
				$user,
				$password,
				$useragent,
				$this->isFandom
			);
			if ( $this->isFandom ) {
				$error = $this->bot->fandom_login();
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
				$useragent,
				$this->isFandom
			);
		}

		# Get a single DB_PRIMARY connection
		$this->dbw = $this->getDB( DB_PRIMARY, [], $this->getOption( 'db', $wgDBname ) );

		$services = MediaWikiServices::getInstance();
		$this->actorStore = $services->getActorStoreFactory()->getActorStoreForImport();
		$this->commentStore = $services->getCommentStore();
		$this->userFactory = $services->getUserFactory();
		$this->userNameUtils = $services->getUserNameUtils();

		// WGL - GUM support
		if ( ExtensionRegistry::getInstance()->isLoaded( 'GUM' ) ) {
			if ( !empty( $this->getOption( 'platform' ) ) ) {
				$this->platform = $this->getOption( 'platform' );
				$this->output( "Provided external platform is '$platform'.\n" );
			} elseif ( $this->isFandom ) {
				$this->platform = 'fandom';
				$this->output( "Autodetected external platform is 'fandom'.\n" );
			} elseif ( str_contains( $url, '.wiki.gg' ) ) {
				$this->platform = 'wikigg';
				$this->output( "Autodetected external platform is 'wikigg'.\n" );
			} else {
				$this->error( 'The "GUM" extension is loaded and the external platform could not be autodetected. Please pass "--platform=<name>" to continue.', 1 );
			}
			if ( !in_array( $this->platform, $this->getConfig()->get( 'GumRemotePlatformsConfig' ) ) ) {
				$this->error( "The 'GUM' extension is loaded and the passed external platform '{$this->platform}' is unknown.", 1 );
			}
		}
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
	 * @param int $remoteId User id, or 0
	 * @param string $name User name or IP address
	 */
	function getUserIdentity( $remoteId, $name ) {
		if ( empty( $name ) ) {
			return $this->actorStore->getUnknownActor();
		}

		if ( !$remoteId and !$this->userNameUtils->isIP( $name ) and !ExternalUserNames::isExternal( $name ) ) {
			# Everything that's not an IP or external, must be converted to external
			# Old imported revisions might be assigned to anon users.
			# We also need to prefix system users if they really have no user ID
			$name = "imported>$name";
		} elseif ( $remoteId and !$this->userNameUtils->isValid( $name ) ) {
			# T353766: There's an edge case of apparently valid but not canonicalized usernames.
			# For example, usernames which start with lowercase characters.
			# Those users cause problems when this script detects a user name change,
			# because ActorStore does a normalization when inserting, but the external wiki
			# may have old users that don't follow current canonicalization rules.
			# Users with non-canonicalized names will be reported as invalid, and despite having
			# user id on the external wiki, they'll be inserted as imported to avoid further errors
			$name = "imported>$name";
			$remoteId = 0;
		}

		// User creation and lookup only applies to remote registered users.
		if ( $remoteId ) {
			if ( $this->platform ) {
				// Use the invalid user id 0 for missing user entries.
				$id = (int)$this->dbw->selectField(
					'gum_user_platforms',
					'gup_user',
					[
						'gup_platform' => $this->platform,
						'gup_remote_id' => $remoteId,
					],
					__METHOD__
				);
			} else {
				$id = $remoteId;
			}

			// If a user (mapping) exists, check if renaming is needed.
			if ( $id !== 0 ) {
				$name = $this->userMappings[$id] ?? $name;
				$userIdentity = $this->actorStore->getUserIdentityByUserId( $id );
				if ( $userIdentity ) {
					if ( $userIdentity->getName() !== $name ) {
						$oldname = $userIdentity->getName();
						# Cache the new user name for uncompleted user rename.
						$this->userMappings[$id] = $name = $this->getAndUpdateUserName( $userIdentity );
						if ( $oldname !== $name ) {
							$this->output( "Notice: We encountered a user rename on ID $id, $oldname => $name\n" );
						}
					}
					return $userIdentity;
				} elseif ( $this->platform ) {
					// TODO: How to handle? Should we create the user table entry like populateUserTable?
					$this->error( "Desync: User '$name' ($id) found in GUM, but not user table." );
				}
			}

			$user = $this->userFactory->newFromName( $name );
			// If user creation failed, then the name is invalid, so treat the result as an imported user.
			if ( !$user ) {
				$name = "imported>$name";
				$remoteId = 0;
			} else {
				// If user exists, but GUM doesn't know about it, then we have a name conflict.
				if ( $user->isRegistered() ) {
					$suffix = '@' . $this->getOption( 'actor-conflict-suffix', $this->platform ?: 'conflict' );
					$this->output( "Notice: The user name $name is already in use, using $name$suffix for ID $id instead.\n" );
					$name = $name . $suffix;
					$user = $this->userFactory->newFromName( $name );
					// Needed here so that the in-process user mapping has the correct user ID.
					$user->addToDatabase();
					$id = $user->getId();
					if ( $id !== 0 ) {
						$this->userMappings[$id] = $name;
					}
				} else {
					$user->addToDatabase();
				}

				$id = $user->getId();
				if ( $this->platform && $id !== 0 ) {
					$this->dbw->insert(
						'gum_user_platforms',
						[
							'gup_user' => $id,
							'gup_platform' => $this->platform,
							'gup_remote_id' => $remoteId,
						],
						__METHOD__
					);
				}
				return $user;
			}
		}

		$userIdentity = new UserIdentityValue( $remoteId, $name );
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
