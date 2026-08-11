<?php

use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\MediaWikiServices;

class ExternalWikiService {
	protected HttpRequestFactory $httpRequestFactory;

	private CookieJar $cookieJar;

	protected array $commonOpts = [];

	private bool $isFandomAuth = false;

	public function __construct( public string $apiUrl, $userAgent = null ) {
		$this->httpRequestFactory = MediaWikiServices::getInstance()->getHttpRequestFactory();
		$this->cookieJar = new CookieJar();
		$this->commonOpts = [
			'userAgent' => $userAgent ?? $this->httpRequestFactory->getUserAgent()
		];
	}

	/**
	 * Get a CSRF token from the remote wiki
	 * @param string $type see https://www.mediawiki.org/wiki/Special:MyLanguage/API:Tokens
	 * @return StatusValue - containing token or null if there was a problem
	 */
	public function getCsrfToken( string $type ) {
		$status = \MediaWiki\Status\Status::newGood();
		$req = $this->httpRequestFactory->create( "$this->apiUrl?" . http_build_query( [
			'action' => 'query',
			'meta' => 'tokens',
			'type' => $type,
			'format' => 'json'
		] ), $this->commonOpts );
		$req->setCookieJar( $this->cookieJar );
		if ( !$req->execute()->isOK() || $req->getStatus() !== 200 ) {
			return $status->fatal( "Could not get CSRF token, status code {$req->getStatus()}" );
		}
		$json = json_decode( $req->getContent(), true );
		if ( !isset( $json['query']['tokens']['logintoken'] ) ) {
			return $status->fatal( "No CSRF token found in response: {$json}" );
		}
		$status->setResult( true,$json['query']['tokens']['logintoken'] );
		return $status;
	}

	/**
	 * Login to a remote MediaWiki instance and save the cookies to the jar
	 * @return StatusValue
	 */
	public function login( string $username, string $password ) {
		$token = $this->getCsrfToken( 'login' );
		if ( !$token->isOK() ) {
			return $token;
		}

		$status = \MediaWiki\Status\Status::newGood();
		$req = $this->httpRequestFactory->create( $this->apiUrl, array_merge( $this->commonOpts, [
			'method' => 'POST',
			'postData' => [
				'action' => 'login',
				'lgname' => $username,
				'lgpassword' => $password,
				'lgtoken' => $token->getValue(),
				'format' => 'json',
			]
		] ) );
		$req->setCookieJar( $this->cookieJar );
		if ( !$req->execute()->isOK() || $req->getStatus() !== 200 ) {
			return $status->fatal( "Could not login, status code {$req->getStatus()}" );
		}
		$json = json_decode( $req->getContent(), true );
		if ( !isset( $json['login']['result'] ) ) {
			return $status->fatal( 'Invalid response while logging in' );
		}
		if ( $json['login']['result'] !== 'Success' ) {
			return $status->fatal( 'Login failed' );
		}
		return $status;
	}

	/**
	 * Login to Fandom. They do not use the traditional MediaWiki login system, so we have to do something different.
	 * @return StatusValue
	 */
	public function loginToFandom( string $username, string $password ) {
		$status = \MediaWiki\Status\Status::newGood();
		$this->isFandomAuth = true;
		if ( $this->fandomWhoAmI() ) {
			// Already logged in, no need to do anything
			return $status;
		}

		$req = $this->httpRequestFactory->create( 'https://services.fandom.com/mobile-fandom-app/fandom-auth/login',
			array_merge( $this->commonOpts, [
				'method' => 'POST',
				'postData' => [
					'username' => $username,
					'password' => $password
				]
			] ) );
		$req->setCookieJar( $this->cookieJar );
		if ( !$req->execute()->isOK() || $req->getStatus() !== 200 ) {
			return $status->fatal( "Could not login, status code {$req->getStatus()}" );
		}

		// This is extremely dumb but includes/libs/Cookie.php expects cookies that are shared on a domain to start
		// with a period (RFC 2109), and Fandom returns the cookie on "fandom.com" instead of ".fandom.com" (RFC 6265).
		// So we'll hack around it by replacing the domain in the cookie string and parsing the cookies again...
		$cookies = $req->getResponseHeaders()['set-cookie'];
		if ( isset( $cookies ) ) {
			foreach ( $cookies as $cookie ) {
				$cookie = str_replace( 'Domain=fandom.com', 'Domain=.fandom.com', $cookie );
				$this->cookieJar->parseCookieResponseHeader( $cookie, 'services.fandom.com' );
			}
		}

		return $status;
	}

	/**
	 * Check whether we are logged in to Fandom.
	 * @return bool
	 */
	private function fandomWhoAmI() {
		$req = $this->httpRequestFactory->create( 'https://services.fandom.com/whoami', $this->commonOpts );
		$req->setCookieJar( $this->cookieJar );
		if ( !$req->execute()->isOK() || $req->getStatus() !== 200 ) {
			return false;
		}
		return true;
	}

	/**
	 * Constructs a raw GET request using the cookie jar and common opts, but does not execute it or parse it.
	 * @param string $url
	 * @return MWHttpRequest
	 */
	public function rawGet( string $url ) {
		$req = $this->httpRequestFactory->create( $url, array_merge( $this->commonOpts, [
			'method' => 'GET'
		] ) );
		if ( $this->isFandomAuth ) {
			$req->setHeader( 'X-Requested-With', 'com.android.chrome' );
		}
		$req->setCookieJar( $this->cookieJar );
		return $req;
	}

	/**
	 * Make a GET request to the remote wiki's API
	 * @param array $apiParams parameters to api.php
	 * @param bool $wasRetry whether this is a retry after an error. should not be set manually
	 * @return StatusValue
	 */
	public function fetch( array $apiParams, bool $wasRetry = false ) {
		$status = \MediaWiki\Status\Status::newGood();
		$attempts = 1;
		$req = $this->rawGet( "{$this->apiUrl}?" . http_build_query( $apiParams + [
			'format' => 'json'
		] ) );

		while ( true ) {
			if ( !$req->execute()->isOK() || $req->getStatus() !== 200 ) {
				if ( $req->getStatus() === 429 ) {
					// Rate limited, wait as long as we're required then try again
					$retryAfter = intval( $req->getResponseHeader( 'Retry-After' ) );
					if ( !$retryAfter ) {
						return $status->fatal( "Rate limited but no Retry-After provided" );
					}
					if ( $wasRetry ) {
						return $status->fatal( "Rate limited but we already waited and retried once" );
					}
					sleep( $retryAfter );
					return $this->fetch( $apiParams, true );
				}
				if ( $attempts >= 3 ) {
					// If this was our third attempt, give up
					return $status->fatal(
						"Error: status code {$req->getStatus()}: \"{$req->getContent()}\", gave up retrying" );
				}
				// Else, try again with a small exponential backoff
				$attempts++;
				$retry = 5 ** ( $attempts - 1 );
				echo "Error: status code {$req->getStatus()}: \"{$req->getContent()}\", retrying after {$retry}s...\n";
				sleep( $retry );
			} else {
				return $status->setResult( true, json_decode( $req->getContent(), true ) );
			}
		}
	}
}
