<?php

//=======================================
//  WP ALL IMPORT FUNCTIONS FOR OVERDRIVE
//  To retrieve records for import
//=======================================

/**
 * Provides file for WP All Import OverDrive API results.
 * 
 * Enter into the URL box for downloading import: [overdrive_library_download(9999)]
 * $new parameter default is false. If numeric, will download the "page" specified. Otherwise, if non-numeric &
 * non-false value is passed for $new, will attempt to calculate how many new items need to be imported based on
 * the total results from prior imports and the total items already imported. If that number is greater than the
 * $limit, an option will be saved in the database to preserve the offset for the next import.  
 * 
 * @param   int       $libraryId    the Overdrive Library ID to query
 * @param   int       $limit        the number of records to retrieve (max is 300)
 * @param   bool|int  $new          if numeric, the page to start import of new items ((page-1) * limit = offset)
 * 
 * @return  string    returns file location for result batch or null if all imported
 */
function overdrive_library_download( $libraryId, $limit = 200, $new = false ){
  if(empty($new) || $new == 'false'){
    $new = false;
  }
  if(empty($limit)){
    $limit = 200;
  }
  if($limit > 300){
    $limit = 300;
  }
  if($limit < 1){
    $limit = 10;
  }
  /**
   * Setup files for saving results. 
   */
  $uploads = wp_upload_dir();
  $filename = $uploads['basedir'] . '/wpallimport/files/' . strtok(basename($libraryId), "?") . '.json';

  if (file_exists($filename)){
    @unlink($filename);
  }

  /**
   * Try to retrieve results counter to continue from last import. 
   */

    if(false === ($results = get_option('overdrive_results_count_' . $libraryId)) || empty($results)){
      $results = array(
        'offset' => (int) 0,
        'total'  => (int) 0,
        'status'    => 'in progress'
      );
      $option = $results;
    } elseif (ceil($results['offset']/$limit) == ceil($results['total']/$limit) && $new === false){
      $results['status'] = 'completed';
      update_option('overdrive_results_count_' . $libraryId, $results, false);
      return null;
    } 

    $offset = $results['offset'];
    $total = $results['total'];
  /**
   * @var   array   $products   contains data for all products imported.
   * @var   array   $diff       contains ids for all existing products that have not yet been imported in this round.
   */
  $products = array();

  $overDriveApi = new Fontana_Overdrive_Api();
  
  /**
   * @var  array   $imported   contains ids for all products imported. 
   */
   $imported = array();
  

  //query for Overdrive products request.
  if(!$new){
    $query = array(
      'limit'     => $limit,
      'offset'    => $offset,
      'sort'      => 'dateadded:asc',
    );
  } elseif (is_numeric($new)){
    // if a numeric number is passed for $new, we'll page the results based on $limit
    $offset = ($new-1)*$limit;
    $query = array(
      'limit'     => $limit,
      'offset'    => $offset,
      'sort'      => 'dateadded:desc',
    );
  } else {
    //set default check to max
    $new_items_to_import = $limit;

    if(array_key_exists('items_imported', $results)){
      //if we have an item count from database, calculate the number of new items that need to be imported.
      $new_items_to_import = $total - $results['items_imported']; 
    }

    if(false === ($offset = get_option('new_overdrive_results_count_' . $libraryId))){
      $offset = 0;
    }
    
    if ($new_items_to_import <= $limit){
      delete_option('new_overdrive_results_count_' . $libraryId);
    } 
    
    $query = array(
      'limit'     => $limit,
      'offset'    => $offset,
      'sort'      => 'dateadded:desc',
    );
  }


  $response = $overDriveApi->getProducts($libraryId, $query);
  if(!isset($response['totalItems'])){
    return null;
  }


  $results['total'] = $response['totalItems'];
  update_option('overdrive_results_count_' . $libraryId, $results, false);


  foreach($response['products'] as $product){
    $imported[] = $product['id'];
  }

  $metadata = array();

  // Chunk imported ids into groups of 25, max amount for bulk meta requests.
  $chunkIds = array_chunk($imported, 25);
  foreach($chunkIds as $key => $val){
    $metaRecords = $overDriveApi->getBulkMeta($libraryId, $val);
    $metadata = array_merge($metadata, $metaRecords['metadata']);
  }

  //Merge records into a single record.
  $products = fbkMergeRecords($metadata, $response['products']);


  if(!empty($products) && is_array($products)){
    file_put_contents($filename, json_encode($products));
    return str_replace($uploads['basedir'], $uploads['baseurl'], $filename); 
  }
}
/**
 * Combines record arrays from 2 results together, merging based on record id.
 * 
 * @param   array   $metaRecords    results from OverDrive metarecord query
 * @param   array   $products       results from OverDrive product query
 * 
 * @return  array   $array          combined records array
 */

function fbkMergeRecords($metaRecords, $products){
  $combined = array();
  foreach($products as $key => $val){
    $combined[$val['id']] = $val; 
  }
  
  foreach($metaRecords as $key => $val){
    $combined[$val['id']] += $val;
  }

  $array = array();
  foreach($combined as $id => $record){
    $array[] = $record;
  }

  return $array;
 }

 
/**
 * Provides a serialized array of keywords for overdrive items.
 * 
 * Includes keys from record: subjects, interestLevel, gradeLevels, ATOS, lexileScore
 * 
 * @param   string    $subjects     a comma separated string of subject keywords
 * @param   string    $interest     a comma separated string of interest level indicators
 * @param   string    $keywords     a comma separated string of item keywords
 * @param   string    $lang         a comma separated string of language indicators
 * @param   string    $grade        a comma separated string of grade levels
 * @param   string    $atos         the ATOS score 
 * @param   string    $lexile       the LEXILE score
 * 
 * @return  string    serialized array of keywords to aid in matching genres and audiences, tagging for topics
 */
function fbkOdTermKeyArray($subjects = null, $interest = null, $keywords = null, $grade = null, $atos =null, $lexile=null) {
  $termArray = array(
    'genres'        => array(),
    'audience'      => explode(",", $interest),
    'topics'        => explode(",", $keywords),
    'genres_other'  => explode(",",$subjects), 
    'audience_other'=> explode(",", $grade)
  );

  //$audiences = fbkCleanArrayToCompare(explode(",", $grade));
  if($interest){
    $interests = explode(",", $interest);
    foreach($interests as $level){
      $termArray['audience_other'][] = "Interest Level: " . $level;
    }
  }
  if($lexile){
    $termArray['audience_other'][] = $lexile . "L Lexile ";
  }
  if($atos){
    $termArray['audience_other'][] = "ATOS: " . $atos;
  }
  
  foreach($termArray as $key => &$array) {
    //$array = fbkCleanArrayToCompare($array);
    foreach($array as $k => &$v){
      $v = trim($v, " \t\n\r\0\x0B-.,;:\/\\");
    }
    $array = array_map('strtolower', $array);
    $array = array_values(array_unique($array));
  }    

  $termArray = array_map('array_filter', $termArray);
  return serialize($termArray);
}