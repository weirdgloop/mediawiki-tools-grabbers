<?php

/**
 *
 *  MediaWikiBot PHP Class
 *
 *  The MediaWikiBot PHP Class provides an easy to use interface for the
 *  MediaWiki api.  It dynamically builds functions based on what is available
 *  in the api.  This version supports Semantic MediaWiki.
 *
 *  You do a simple require_once( '/path/to/mediawikibot.class.php' ) in your
 *  own bot file and initiate a new MediaWikiBot() object.  This class
 *  supports all of the api calls that you can find on your wiki/api.php page.
 *
 *  You build the $params and then call the action.
 *
 *  For example,
 *  $params = array( 'text' => '==Heading 2==' );
 *  $bot->parse( $params );
 *
 *  @author 	Kaleb Heitzman
 *  @email  	jkheitzman@gmail.com
 *  @license 	The MIT License ( MIT )
 *  @date		2012-12-07 02:55 -0500
 *
 *  The MIT License ( MIT ) Copyright ( c ) 2011 Kaleb Heitzman
 *
 *  Permission is hereby granted, free of charge, to any person obtaining a
 *  copy of this software and associated documentation files ( the "Software" ),
 *  to deal in the Software without restriction, including without limitation
 *  the rights to use, copy, modify, merge, publish, distribute, sublicense,
 *  and/or sell copies of the Software, and to permit persons to whom the
 *  Software is furnished to do so, subject to the following conditions:
 *
 *  The above copyright notice and this permission notice shall be included in
 *  all copies or substantial portions of the Software.
 *
 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *  FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL
 *  THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 *  FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER
 *  DEALINGS IN THE SOFTWARE.
 */

#[\AllowDynamicProperties]
class MediaWikiBot {

	/** cURL handle for connection reuse.
	 */
	protected $ch;

	/** Methods set by the mediawiki api
	 */
	protected $apimethods = array(
		'block',
		'compare',
		'delete',
		'edit',
		'emailuser',
		'expandtemplates',
		'feedcontributions',
		'feedwatchlist',
		'filerevert',
		'help',
		'import',
		'login',
		'logout',
		'move',
		'opensearch',
		'paraminfo',
		'parse',
		'patrol',
		'protect',
		'purge',
		'query',
		'rollback',
		'rsd',
		'smwinfo',
		'unblock',
		'undelete',
		'upload',
		'userrights',
		'watch'
	);

	/** Methods that need an xml format
	 */
	protected $xmlmethods = array(
		'opensearch',
		'feedcontributions',
		'feedwatchlist',
		'rsd'
	);

	/** Methods that need multipart/form-date
	 */
	protected $multipart = array(
		'upload',
		'import'
	);

	/** Methods that do not need a param check
	 */
	protected $parampass = array(
		'login',
		'logout',
		'rsd'
	);

	/** Time in seconds to retry on failure before giving up
	 */
	protected $retryTimes = array( 10, 30, 60, 120 );

	protected $fandomAuth = false;
	protected $fandomAppId;

	/** Constructor
	 */
	public function __construct(
		$url = 'http://example.com/w/api.php',
		$format = 'php',
		$username = 'bot',
		$password = 'passwd',
		$useragent = 'WikimediaBot Framework by JKH',
		$fandomAuth = false,
		$cookies = 'cookies.tmp'
	) {
		/** Set some constants
		 */
		define( 'URL', $url );
		define( 'FORMAT', $format );
		define( 'USERNAME', $username );
		define( 'PASSWORD', $password );
		define( 'USERAGENT', $useragent );

		// Avoid reusing cookies when credentials have changed.
		$loginMethod = $fandomAuth ? 'fandom' : 'mediawiki';
		define( 'COOKIES', "$username-$loginMethod-$cookies" );

		// cURL handle for connection reuse.
		$this->ch = curl_init();
	}

	/** Dynamic method server
	 *
	 *  This builds dyamic api calls based on the protected apimethods var.
	 *  If the method exists in the array then it is a valid api call and
	 *  based on some php5 magic, the call is executed.
	 */
	public function __call( $method, $args ) {
		# get the params
		$params = $args[0];
		# check for multipart
		if ( isset( $args[1] ) ) {
			$multipart = $args[1];
		}
		# check for valid method
		if ( in_array( $method, $this->apimethods ) ) {
			# get multipart info
			if ( !isset( $multipart ) ) {
				$multipart = $this->multipart( $method );
			}
			# process the params
			return $this->standard_process( $method, $params, $multipart );
		} else {
			# not a valid method, kill the process
			die( "$method is not a valid method \r\n" );
		}
	}

	public function __destruct() {
		# close the connection
		curl_close( $this->ch );
	}
	/** Log in and get the authentication tokens
	 *
	 *  MediaWiki requires a dual login method to confirm authenticity. This
	 *  entire method takes that into account.
	 *
	 *  It returns null if success, or an array on failure
	 */
	public function login( $init = true ) {
		// Skip login if session cookies are already set.
		if ( file_exists( COOKIES ) && $init ) {
			return null;
		}
		# build the url
		$url = $this->api_url( __FUNCTION__ );
		# build the params
		$params = array(
			'lgname' => USERNAME,
			'lgpassword' => PASSWORD,
			'format' => 'php' # do not change this from php
		 );
		# get initial login info
		if ( $init ) {
			$results = $this->login( false );
			if ( ! isset( $results['login']['token'] ) ) {
				return $results;
			}
			$results = ( array ) $results;
		} else {
			$results = null;
		}
		# pass token if not null
		if ( $results != null ) {
			$params['lgtoken'] = $results['login']['token'];
		}
		# get the data
		$data = $this->curl_post( $url, $params );
		# return or set data
		if ( ! is_array( $data ) || $data['login']['result'] != "Success" ) {
			# Stupid, stupid, stupid PHP!!! This returns 1 (converts the expression
			# to boolean) instead of an array!!!!
			#return $data || [ 'Unknown error' ];
			return $data ? $data : [ 'Unknown error' ];
		}
	}

	/** Log in and get the authentication tokens using Fandom's authentication system
	 *
	 *  It returns null if success, or an array on failure
	 */
	public function fandom_login( $fandomAppId ) {
		$this->fandomAuth = true;
		$this->fandomAppId = $fandomAppId;

		// Skip login if session cookies are already set.
		if ( file_exists( COOKIES ) ) {
			return null;
		}

		// Check if already logged-in to avoid hitting limits if running multiple times in quick succession.
		if ( !is_array( $this->fandom_whoami() ) ) {
			return null;
		}

		// Perform Fandom login
		$data = $this->curl_post( 'https://services.fandom.com/mobile-fandom-app/fandom-auth/login', [
			'username' => USERNAME,
			'password' => PASSWORD,
		] );
		$responseCode = curl_getinfo( $this->ch, CURLINFO_RESPONSE_CODE );
		if ( $responseCode !== 200 ) {
			return [
				'login' => [
					'reason' => "Response code $responseCode returned while logging in",
				],
			];
		} elseif ( !is_array( $data ) ) {
			$errno = curl_errno( $this->ch );
			return [
				'login' => [
					'reason' => "Curl errno $errno returned while logging in",
				],
			];
		}
		// Verify login was successful.
		return $this->fandom_whoami();
	}

	/** Check if logged in using Fandom's authentication system
	 *
	 *  It returns null if success, or an array on failure
	 */
	public function fandom_whoami() {
		// Verify login was successful.
		$data = $this->curl_get( 'https://services.fandom.com/whoami' );
		$responseCode = curl_getinfo( $this->ch, CURLINFO_RESPONSE_CODE );
		if ( $responseCode !== 200 ) {
			return [
				'login' => [
					'reason' => "Response code $responseCode returned while verifying login",
				],
			];
		} elseif ( !is_array( $data ) ) {
			$errno = curl_errno( $this->ch );
			return [
				'login' => [
					'reason' => "Curl errno $errno returned while verifying login",
				],
			];
		}

		// Success
		return null;
	}

	/** Standard processesing method
	 *
	 *  The standard process methods calls the correct api url with params
	 *  and executes a curl post request.  It then returns processed data
	 *  based on what format has been set (default=php).
	 */
	private function standard_process( $method, $params = null, $multipart = false ) {
		# check for null params
		if (  ! in_array( $method, $this->parampass ) ) {
			$this->check_params( $params );
		}
		# specify xml format if needed
		if ( in_array( $method, $this->xmlmethods ) ) {
			$params['format'] = 'xml';
		}
		# build the url
		$url = $this->api_url( $method );
		# get the data
		$data = $this->curl_post( $url, $params, $multipart );
		# check data for grabbers; shut up loops are confusing it's too early.
		# Note: $data can be an empty array, resulting from api generators returning zero results
		if ( $data === false ) {
			for ( $errors = 0; $errors < count( $this->retryTimes ); $errors++) {
				$seconds = $this->retryTimes[$errors];
				echo "API error: no results; retrying in {$seconds}s\n";
				sleep( $seconds );
				$data = $this->curl_post( $url, $params, $multipart );
				if ( $data !== false ) {
					break;
				}
			}
		}
		# set smwinfo
		$this->$method = $data;
		# return the data
		return $data;
	}

	/** Like curl_post, but for dumb hacks (grabDeletedFiles screenscraping, specifically)
	 */
	public function curl_get( $url ) {
		curl_reset( $this->ch );
		# set the url, stuff
		curl_setopt( $this->ch, CURLOPT_URL, $url );
		curl_setopt( $this->ch, CURLOPT_USERAGENT, USERAGENT );
		curl_setopt( $this->ch, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt( $this->ch, CURLOPT_ENCODING, '' );
		curl_setopt( $this->ch, CURLOPT_FAILONERROR, 1 );
		curl_setopt( $this->ch, CURLOPT_TIMEOUT, 30 );
		curl_setopt( $this->ch, CURLOPT_FOLLOWLOCATION, TRUE );
		curl_setopt( $this->ch, CURLOPT_MAXREDIRS, 5 );
		curl_setopt( $this->ch, CURLOPT_COOKIEFILE, COOKIES );
		curl_setopt( $this->ch, CURLOPT_COOKIEJAR, COOKIES );
		# support Fandom auth
		if ( $this->fandomAuth ) {
			curl_setopt( $this->ch, CURLOPT_HTTPHEADER, [
				'X-Fandom-Auth' => '1',
				'X-Wikia-WikiaAppsID' => $this->fandomAppId,
			] );
		}

		# execute the get
		$results = curl_exec( $this->ch );
		$error = curl_errno( $this->ch );
		if ( $error !== 0 ) {
			$results = [ false, sprintf( "%s", curl_error( $this->ch ) ) ];
		} else {
			$results = [ true, $results ];
		}
		# return the unserialized results
		return $results;
	}

	/** Execute curl post
	 */
	private function curl_post( $url, $params = '', $multipart = false ) {
		# set the format if not specified
		if ( empty( $params['format'] ) ) {
			$params['format'] = FORMAT;
		}
		curl_reset( $this->ch );
		# set the url, number of POST vars, POST data
		curl_setopt( $this->ch, CURLOPT_URL, $url );
		curl_setopt( $this->ch, CURLOPT_USERAGENT, USERAGENT );
		curl_setopt( $this->ch, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt( $this->ch, CURLOPT_ENCODING, '' );
		curl_setopt( $this->ch, CURLOPT_FAILONERROR, 1 );
		curl_setopt( $this->ch, CURLOPT_TIMEOUT, 30 );
		curl_setopt( $this->ch, CURLOPT_COOKIEFILE, COOKIES );
		curl_setopt( $this->ch, CURLOPT_COOKIEJAR, COOKIES );
		# support Fandom auth
		if ( $this->fandomAuth ) {
			curl_setopt( $this->ch, CURLOPT_HTTPHEADER, [
				'X-Fandom-Auth' => '1',
				'X-Wikia-WikiaAppsID' => $this->fandomAppId,
			] );
		}
		curl_setopt( $this->ch, CURLOPT_POST, count( $params ) );
		# choose multipart if necessary
		if ( $multipart ) {
			# submit as multipart
			curl_setopt( $this->ch, CURLOPT_POSTFIELDS, $params );
		} else {
			# submit as normal
			curl_setopt( $this->ch, CURLOPT_POSTFIELDS, $this->urlize_params( $params ) );
		}
		# execute the post
		$results = curl_exec( $this->ch );
		$error = curl_errno( $this->ch );
		if ( $error !== 0 ) {
			echo sprintf( "CURL ERROR: %s\n", curl_error( $this->ch ) );
		}
		# return the unserialized results
		return $error !== 0 ? false : $this->format_results( $results, $params['format'] );
	}

	/** Check for multipart method
	 */
	private function multipart( $method ) {
		# get multipart true/false
		$multipart = in_array( $method, $this->multipart );
		# check to see if multipart method exists and return true/false
		return $multipart;
	}

	/** Format results based on format (default=php)
	 */
	private function format_results( $results, $format ) {
		switch( $format ) {
			case 'json':
				return json_decode( $results, true );
			case 'php':
				return unserialize( $results );
			case 'wddx':
				return wddx_deserialize( $results );
			case 'xml':
				return simplexml_load_string( $results );
			case 'yaml':
				return $results;
			case 'txt':
				return $results;
			case 'dbg':
				return $results;
			case 'dump':
				return $results;
		}
	}

	/** Check for null params
	 *
	 *  If needed params are not passed then kill the script.
	 */
	private function check_params( $params ) {
		# check for null
		if ( $params == null ) {
			die( "You didn't pass any params. \r\n" );
		}
	}

	/** Build a url string out of params
	 */
	private function urlize_params( $params ) {
		# url-ify the data for POST
		$urlstring = '';
		foreach ( $params as $key => $value ) {
			$urlstring .= $key . '=' . urlencode( $value ) . '&';
		}
		# pull the & off the end
		rtrim( $urlstring, '&' );
		# return the string
		return $urlstring;
	}

	/** Build the needed api url
	 */
	private function api_url( $function ) {
		# return the url
		return URL."?action={$function}&";
	}

}
