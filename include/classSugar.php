<?php
/**
 * Author : James Alexander
 *
 * License: MIT (see http://opensource.org/licenses/MIT and LICENSE.txt which should be in the root folder with this file)
 *
 * Date of Creation: 2013-01-06
 *
 * Class for interacting with SugarCRM
 *
 */
require_once 'include/OAuth.php';

class sugar {

	/**
	 *
	 *
	 * @var OAuthConsumer $consumer
	 * New OAuth consumer object, created by constructor.
	 *
	 * From the OAuth.php libary containing the consumer key and secret.
	 */
	private $consumer;

	/**
	 *
	 *
	 * @var OAuthSignatureMethod_HMAC_SHA1 $signer
	 * New OAuth signature object, ceated by constructor.
	 *
	 * From the OAuth.php libary.
	 */
	private $signer;

	/**
	 *
	 *
	 * @var string $api_url
	 * Url to use when connecting to the SugarCRM API.
	 */
	private $api_url;

	/**
	 *
	 *
	 * @var OAuthToken $token
	 * New OAuth access token object, created by constructor.
	 *
	 * From the OAuth.php libary contains the user key and secret.
	 */
	private $token = null;

	/**
	 *
	 *
	 * @var string $oauth_callback
	 * Call back url to pass to sugar so that user will be redirected to our processing page.
	 */
	private $oauth_callback = null;

	/**
	 *
	 *
	 * @var string $request_token
	 * Temporary token recieved to all user to register with consumer/Sugar. Needs to bet set in session.
	 */
	private $setup_token= null;

	/**
	 *
	 *
	 * @var string $setup_secret
	 * Temporary token secret recieved to all user to register with consumer/Sugar. Needs to be set in session.
	 */
	private $setup_secret = null;

	/**
	 *
	 *
	 * @var string $setup_redirect_url
	 * URL to redirect user to when registering with consumer/Sugar. Must be passed to registration page.
	 */
	private $setup_redirect_url = null;

	/**
	 *
	 *
	 * @var string $setup_verifier
	 * Verification token recieved back from SugarCRM during an registration process. 
	 */
	private $setup_verifier = null;

	/**
	 *
	 *
	 * @var resource $ch
	 * cURL resource to be used for different api calls.
	 */
	private $ch = null;

	/**
	 *
	 *
	 * @var string $session
	 * Session id number recieved when logging into SugarCRM
	 */
	private $session = null;

	/**
	 *
	 *
	 * @var string $userid
	 * UserID number for logged in SugarCRM user.
	 */
	private $userid = null;


	/**
	 * Construction function called whent the class is created.
	 *
	 * Construction class requires, at the very least, the consumer key and secret.
	 * From here it will always create an OAuthConsumer object to become the basis of the request.
	 * If it recieves (optionally) a user key and secret it will also create an OAuthToken object to use when making a request.
	 * It will always create a signature object to be used signing the request.
	 * If only the consumer is made the addToken function can be used to add a token.
	 * Before that the object can only be used to register a new user and request their permenant OAuth information.
	 *
	 * @param string  $consumerKey    Public consumer key for sugar OAuth application. Set in sugar database and recorded in config file.
	 * @param string  $consumerSecret Consumer secret for sugar OAuth application. Set in sugar database and recorded in config file.
	 * @param string  $userKey        (optional) user specific permenant token for OAuth application. Recieved after registration process with user and sugar.
	 * @param string  $userSecret     (optional) user specific secret token for OAuth application. Recieved after registration process with user and sugar.
	 */
	function __construct( $consumerKey, $consumerSecret, $url, $userKey = null, $userSecret = null ) {
		$this->consumer = new OAuthConsumer( $consumerKey, $consumerSecret );
		$this->signer = new OAuthSignatureMethod_HMAC_SHA1();
		$this->api_url = $url;

		if ( $userKey && $userSecret ) {
			$this->token = new OAuthToken( $userKey, $userSecret );
		}
	}

	/*************************************************************************************

	Private internal functions to retrieve necessary variables with appropriate fallback

	**************************************************************************************/

	/**
	 * Get cURL object, create if doesn't exist.
	 *
	 * Should alway sbe used when cURL is going to be used so that we can use one cURL object and not open up too many threads.
	 * If $ch already exists function will immediatly return it, if not it will create one and then return it.
	 *
	 * @return cURL resource
	*/
	private function getcURL() {
		if ( $this->ch ) {
			return $this->ch;
		} else {
			$this->ch = curl_init();
			return $this->ch;
		}
	}


	/**
	 * Get sessionID
	 *
	 * Shared function to retrieve the stored session ID and, if no ID stored, login to retrieve a new one.
	 *
	 * @return string SugarCRM session ID.
	 */
	private function getSession() {
		if ( $this->session ) {
			return $this->session;
		} else {
			$this->session = $this->login();
			return $this->session;
		}
	}

	/**********************************************************************************************

	Public functions to retrieve or set private variables with appropriate fallback or processing. 

	*************************************************************************************************/

	/**
	 * Set OAuth callback url.
	 *
	 * @param string  $url url to set the callback to.
	 */
	public function setCallback( $url ) {
		$this->oauth_callback = $url;
	}


	/**
	 * Set or change OAuth user token for SugarCRM
	 *
	 * @param string  $userKey        User specific permenant token for OAuth application. Recieved after registration process with user and sugar.
	 * @param string  $userSecret     Uer specific secret token for OAuth application. Recieved after registration process with user and sugar.
	 */
	public function settoken( $userKey, $userSecret ) {
		$this->token = new OAuthToken( $userKey, $userSecret );
	}


	/**
	 * Get temporary token to set in session.
	 *
	 * @return string with temporary request token.
	 */
	public function gettemptoken() {
		return $this->setup_token;
	}


	/**
	 * Get temporary secret to set in session.
	 *
	 * @return string string with temporary request secret.
	 */
	public function gettempsecret() {
		return $this->setup_secret;
	}


	/**
	 * Get stored Token Object
	 *
	 * Public function to fetch the stored OAuthToken token object. 
	 * Stored token can then be used to fetch the stored user key or secret.
	 * Example: $key = $sugar->getToken()->key and $secret = $sugar->getToken()->secret
	 *
	 * @return OAuthToken object with stored user key and secret.
	 */
	public function getToken() {
		return $this->token;
	}

	/*************************************************************************

	Shared, private, internal functions to make requests to the sugarCRM API

	**************************************************************************/

	/**
	 * Shared function to do a api request to sugar using an http GET.
	 *
	 * Requires a OAuthRequest object which will then be used to both create the url and the header.
	 *
	 * @param OAuthRequest $request request to be sent to sugarAPI.
	 * 
	 * @return raw response from sugar (usually json) to be handled by calling function.
	 */
	private function sugarGetRequest( OAuthRequest $request ) {

		// set up request
		$ch = $this->getcURL();
		curl_setopt( $ch, CURLOPT_URL, $request->to_url() ); // request url with info
		curl_setopt( $ch, CURLOPT_HTTPGET, true );
		curl_setopt( $ch, CURLOPT_HEADER, false );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array( $request->to_header() ) ); // sign request (needed?)
		curl_setopt( $ch, CURLOPT_VERBOSE, true );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );

		$rawresponse = curl_exec( $ch ); // do request

		if ( !$rawresponse ) {
			die( 'Curl error: ' . curl_error( $ch ) );
		}

		return $rawresponse;

	}

	/**
	 * Shared function to do a api request to sugar using an http POST.
	 *
	 * Requires a OAuthRequest object which will then be used to both create the post fields and the header.
	 *
	 * @param OAuthRequest $request request to be sent to sugarAPI.
	 * 
	 * @return raw response from sugar (usually json) to be handled by calling function.
	 */
	private function sugarPostRequest( OAuthRequest $request ) {

		// set up request
		$ch = $this->getcURL();
		
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_URL, $this->api_url );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $request->get_parameters() );
		curl_setopt( $ch, CURLOPT_HEADER, false );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array( $request->to_header() ) ); // sign request
		curl_setopt( $ch, CURLOPT_VERBOSE, true );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );

		$rawresponse = curl_exec( $ch ); // do request

		if ( !$rawresponse ) {
			die( 'Curl error: ' . curl_error( $ch ) );
		}

		return $rawresponse;

	}


	/**
	 * Shared function to make a POST to the sugarCRM rest api.
	 *
	 * Designed so that it can be called from most normal api functions.
	 * The function sets a request up (given a method and request body) and does the request through the sugarPostRequest funtion.
	 *
	 * @param string $method api method to use in this request
	 * @param array $mainrequest Array of commands and options for the API to be JSON encoded. Optional in case method requires no further commands.
	 * 
	 * @return raw response from sugar (JSON) to be handled by calling function.
	 */
	private function rest_post( $method, array $mainrequest = null ) {
		$params['method'] = $method;
		$params['input_type'] = 'JSON';
		$params['response_type'] = 'JSON';
		if ( $mainrequest ) {
			$params['rest_data'] = json_encode( $mainrequest );
		}

		$request = OAuthRequest::from_consumer_and_token( $this->consumer, $this->token, "POST", $this->api_url, $params );
		$request->sign_request ( $this->signer, $this->consumer, $this->token );

		$rawresponse = $this->sugarPostRequest( $request );

		return $rawresponse;


	}


	/**************************************************************************

	Public functions to make requests to the SugarCRM API. 
	Generall relatively targeted in nature and in rough order of initial use.

	****************************************************************************/


	/**
	 * Request temporary token from sugar.
	 *
	 * Request a temporary token from sugar to register a new user with the consumer created in the construction.
	 *
	 * @return array with request token, token secret and full url to redirect the user too to authorize the consumer.
	 */
	public function getRequestToken() {
		$params['method'] = 'oauth_request_token';
		$params['oauth_callback'] = $this->oauth_callback;

		$request = OAuthRequest::from_consumer_and_token( $this->consumer, NULL, "GET", $this->api_url, $params );
		$request->sign_request ( $this->signer, $this->consumer, NULL );

		$rawresponse = $this->sugarGetRequest( $request );

		$response = array();
		parse_str( $rawresponse, $response );
		$redirectparams['oauth_token'] = $response['oauth_token'];
		$redirectparams['oauth_token_secret'] = $response['oauth_token_secret'];

		$this->setup_redirect_url = $response['authorize_url'].'&'.http_build_query( $redirectparams );

		$this->setup_token= $redirectparams['oauth_token'];
		$this->setup_secret = $redirectparams['oauth_token_secret'];

		return $this->setup_redirect_url;

	}


	/**
	 * Request a permanent access token after a successful authorization
	 *
	 * Called after a user has successfully authorized access to their SugarCRM account and been given a verification token.
	 * Function requests permanent token and then sets it within the object available either for grabbing or for use in an api call.
	 *
	 * @param string $verifier Verification string/token passed from Sugar after a successful authentication with a temporary request token.
	 * @param string $temptoken Temporary request token that user used to authorize their account on Sugar.
	 * @param string $tempsecret Secret key recieved when temporary request token requested earlier, usually passed from user session.
	 * 
	 * @return booleon result of token request.
	 */
	public function getpermtoken( $verifier, $temptoken, $tempsecret ) {
		$this->setup_verifier = $verifier;
		$this->setup_token= $temptoken;
		$this->setup_secret = $tempsecret;
		$this->settoken( $this->setup_token, $this->setup_secret );

		$params['method'] = 'oauth_access_token';
		$params['oauth_verifier'] = $this->setup_verifier;

		$request = OAuthRequest::from_consumer_and_token( $this->consumer, $this->token, "POST", $this->api_url, $params );
		$request->sign_request( $this->signer, $this->consumer, $this->token );

		$rawresponse = $this->sugarPostRequest( $request );

		$response = array();
		parse_str( $rawresponse, $response );
		$tokeninfo['oauth_token'] = $response['oauth_token'];
		$tokeninfo['oauth_token_secret'] = $response['oauth_token_secret'];
		$this->settoken( $tokeninfo['oauth_token'], $tokeninfo['oauth_token_secret'] );

		if ( isset( $tokeninfo['oauth_token'] ) && isset( $tokeninfo['oauth_token_secret'] ) ) {
			return true;
		} else {
			return false;
		}

	}


	/**
	 * Login to SugarCRM.
	 *
	 * Login to SugarCRM with stored OAuth credentials and store the session ID recieved.
	 *
	 * @return boolean response on success/failure of login request.
	 */
	public function login() {
		$method = 'oauth_access';
		
		$rawresponse = $this->rest_post( $method );

		$result = json_decode( $rawresponse, true );

		if ( isset($result['id'] ) ) {
			$this->session = $result['id'];
			return true;
		} else {
			return false;
		}
	}


	/**
	 * Get available SugarCRM Modules
	 *
	 * Public function to fetch modules available for the logged in user to access.
	 * Also includes a list of what basic rights the user has on each module.
	 *
	 * @return array of available modules.
	 */
	public function getModules() {
		$method = 'get_available_modules';
		$params['session'] = $this->getSession();

		$rawresponse = $this->rest_post( $method, $params );

		$result = json_decode( $rawresponse, true );

		return $result;
	}


	/**
	 * Get SugarCRM userID of logged in user.
	 *
	 * Public function to get the user ID number of the logged in user.
	 * Stores userID in object and returns it as a string.
	 *
	 * @return string SugarCRM userID of logged in user
	 */
	public function getUserID() {
		$method = 'get_user_id';
		$params['session'] = $this->getSession();

		$rawresponse = $this->rest_post( $method, $params );

		$id = json_decode( $rawresponse, true );

		$this->userid = $result;

		return $id;

	}


}
