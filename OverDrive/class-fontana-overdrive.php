<?php
/**
 * Provides interface to fetch data from the Overdrive API.
 * 
 * API key and client name are required.
 * 
 * @see class-fontana-response.php
 */

class Fontana_Overdrive_Api extends Fontana_Response {
  /**
   * Variables from the Parent Class.
   * 
   * @access  public
   * @var   array         $args         arguments passed for wp_remote_get
   * @var   array|object  $data         an array or object representing the API results to our query
   * @var   object        $response     the response to wp_remote_get
   * @var   int           $responseCode the response code from our wp_remote_get
   * @var   string        $url          the url to fetch data from
   * 
   * @see class-fontana-response.php
   */

   /**
   * The private variables used in fetching or passing item data.
   * 
   * @access  private
   * @var   string  $client     The client name provided by overdrive for API access       
   * @var   string  $clientKey  The secret key provided for overdrive API access         
   * @var   string  $auth       The access token provided via API
   * @var   string  $expires    The expiration
   * 
   */
  private $client;
  private $clientKey;
  private $auth;
  
  private $expires;

  public $libraries;

  /**
	 * Initialize the class and set its properties.
	 *
   * Checks that the authentication token is not expired. If the token is expired, requests a new token
	 */
  public function __construct($url = null, $args = null){
    $this->client = esc_attr( get_option( 'fontana_api_settings' )['overdrive_client'] );
    $key = $this->client . ":" . esc_attr( get_option( 'fontana_api_settings' )['overdrive'] );
    
    $this->clientKey = base64_encode($key);
    $this->auth = get_transient('overdrive_access_token');
    $this->libraries =  get_option( 'fontana_overdrive_settings' );
    $this->expires = get_option('_transient_timeout_overdrive_access_token');
    
    if($this->auth === false){
      $this->getToken();
    } else{
      $this->checkToken();
    }

    $this->args = array(
      'headers' => array(
         'User-Agent'    => $this->client,
         'Authorization' => 'Bearer ' . $this->auth,
         'Host'          => 'fontanalib.org',
      ),
     );
  }

/**
 * Retrieves an access token for OverDrive API.
 * 
 * @link https://oauth.overdrive.com/token
 */
  public function getToken(){
    $url = "https://oauth.overdrive.com/token";

    $headers = array(
      'Authorization' => 'Basic ' . $this->clientKey,
      'Content-Type'  => 'application/x-www-form-urlencoded;charset=UTF-8',
    );



    $response = wp_remote_post($url, array(
      'body' => 'grant_type=client_credentials',
      'headers' => $headers,
    ));
    $responseCode = wp_remote_retrieve_response_code( $response );
    if(is_wp_error($response)){
      $this->responseCode = 800;
      return;
    }
    $data = json_decode($response['body'], true);

    if (isset($data["access_token"])){
      $this->auth = $data["access_token"];
      $this->expires = $data["expires_in"];

      set_transient('overdrive_access_token', $data["access_token"], $this->expires);
      $this->token_type = $data["token_type"];
    }
    
    return;
  }


/**
 * Gets the Library Account API Response from OverDrive. 
 * 
 * @param   int   $libId    The ID of the OverDrive Library
 * 
 * @link https://developer.overdrive.com/apis/library-account
 * @link https://api.overdrive.com/v1/libraries/{Library ID}
 */

 public function overdriveLibraryAccount($libId){
     $this->url = "https://api.overdrive.com/v1/libraries/" . $libId;
     $this->response = $this->fetch();
     $body = $this->getData('json');
     
     if (isset($body["id"])){
        $success = array(
          'products'          => $body["links"]["products"]["href"],
          'weblink'           => $body["links"]["dlrHomepage"]["href"],
          'collection_token'  => $body["collectionToken"]
        );
      } else{ $success = false; }
      
      return $success;
 }

 /**
  * Gets the Meta Record for a single overdrive record.
  *
  * @param    int     $libId      The OverDrive Library Id
  * @param    string  $recordId   The overdrive id for the record.
  *
  * @return   array   array of json data / results from the api request 
  */

 public function getMetaData($libId, $recordId){
  $this->url = "https://api.overdrive.com/v1/collections/" . $this->libraries[$libId]['collection_token'] . "/products/" . $recordId ."/metadata";
  $this->response = $this->fetch();

  return $this->getData('json');
 }
 
/**
 * 
 * @return   array   array of json data / results from the api request or null if no results
 */
  public function getBulkMeta($libId, $recordIds){
    if(is_array($recordIds)){
      $recordIds = implode(",", $recordIds);
    }

    $this->url = "https://api.overdrive.com/v1/collections/" . $this->libraries[$libId]['collection_token'] . "/bulkmetadata?reserveIds=" . $recordIds;
    $this->response = $this->fetch();

    return $this->getData('json');
  }


/**
 * Uses OverDrive Search API to return a complete digital collection.
 * 
 * Example: https://api.overdrive.com/v1/collections/L2B2gAAAKoBAAA1B/products
 * @link https://developer.overdrive.com/apis/search
 * 
 * @param   int     $libId    The overdrive library id
 * @param   array   $query    associative array of query parameters as key=>value
 *
 * @return   array   array of json data / results from the api request 
 */

  public function getProducts($libId, $query){
    $parameters = '';

    if(!empty($query)) {
      $parameters = $this->stringify_url_params($query);
    }

    $this->url = $this->libraries[$libId]['products'] . "?" . ltrim($parameters,"?");

    $this->response = $this->fetch();
  
    return $this->getData('json');
  }
/**
 * Getting Advantage Products Link from Overdrive.
 * 
 * @link https://api.overdrive.com/v1/libraries/1225/advantageAccounts
 * @link https://developer.overdrive.com/docs/advantage-link
 * 
 * @param   int     $libId    The overdrive library id
 * 
 * @return   array   array of json data / results from the api request 
 */

  public function getAdvantageProducts($libId){
    $this->url = "https://api.overdrive.com/v1/libraries/". $libId. "/advantageAccounts";
    $this->response = $this->fetch();
    
    return $this->getData('json');
  }

  /**
   * Verifies the Overdrive Authentication Token.
   * 
   * If the auth token is expired, requests a new authentication token
   * 
   */
  public function checkToken(){
    $expiration = $this->expires - 180;

    if( !$this->auth || (time() > $expiration)){
      $tokenCheck = $this->getToken();
      return;
    }
    return;
  }
}