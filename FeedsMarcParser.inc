<?php

/**
 * @file
 * Contains the FeedsMarcParser class.
 */

/**
 * Parses a given file as a MARC file.
 */
class FeedsMarcParser extends FeedsParser {
   /**
   * Hexadecimal value for Subfield indicator
   */
  private $subfield_indicator = "\x1F";

  /**
   * Hexadecimal value for End of Field
   */
  private $field_end = "\x1E";

  /**
   * Hexadecimal value for End of Record
   */
  private $record_end = "\x1D";


  private $author_fields = array('700', '710', '100', '110', '111', '711', '720', '730', '740', '751', '752', '753', '754', '264');
  private $series_fields = array('440', '490', '810', '811', '830');
  private $title_fields = array('245', '130', '240', '246', '247', '440', '490', '500', '505', '700', '710', '711', '730', '740', '780', '800', '810', '811', '830', '840');
  private $subject_fields = array('600', '610', '611', '630', '648', '650', '651', '653', '654', '655', '656', '657', '658', '662', '690', '691', '692', '693', '694', '695', '696', '697', '698', '699');
  private $audience_fields = array('521','385');


  /**
   * Implements FeedsParser::parse().
   */
  public function parse(FeedsSource $source, FeedsFetcherResult $fetcher_result) {
    $state = $source->state(FEEDS_PARSE);
    $file_path = $fetcher_result->getFilePath();
    $marc_records = file_get_contents($file_path);
    $marc_records = explode($this->record_end, $marc_records);


    // Determine section to parse, parse.
    $rows = array();

    foreach($marc_records as $marc_record){
      $row = $this->parseItems($marc_record);
      if($row){
       $rows[] = $row;
      }
    }
   
    // Create a result object and return it.
    return new FeedsParserResult($rows, $source->feed_nid);
  }

  
  /**
   * Parse all of the items from the CSV.
   *
   * @param ParserCSV $parser
   * @param ParserCSVIterator $iterator
   * @param int $start
   *   The byte number from where to start parsing the file.
   * @param int $limit
   *   The number of lines to parse, 0 for all lines.
   *
   * @return array
   *   An array of rows of the CSV keyed by the column names previously set.
   */
  protected function parseItems($marc_record) {
    $marc_record = $this->getMarcFields($marc_record);
    if(empty($marc_record['leader'])){
      return null;
    }
    $record = $this->mapMarcFields($marc_record);
    /* watchdog(
      'custom',
      'Here is the printed node: <pre>@entity</pre>',
      array('@entity' => print_r( $record, TRUE)),
      WATCHDOG_INFO
    );  */
    
    return $record;
  }
  private function getMarcFields($marc_record){
    $marc_field_values = explode($this->field_end, $marc_record);
  
    // Now this is harder, we need to break the leader from the directory
    $start_length = strlen($marc_field_values[0]);
    $leader = substr($marc_field_values[0], 0, 23);
    if(!$leader || empty($leader)){
      return null;
    }
    $directory = substr($marc_field_values[0], 24, $start_length);
    $marc_field_values[0] = $leader;

    // Get the field numbers from the directory
    // $directoryfields contaings the fieldname, start position and length
    // that will get taken care of in a second
    $directory_fields = str_split($directory, 12);

    //Start building $record array
    $record = array();
    //First we set the leader and take the leader value out of the values
    $record['leader'] = $leader;
    array_splice($marc_field_values, 0, 1);

    $marc_field_count = array();
    //Then we loop through the directory fields and build array
    foreach ($directory_fields AS $key => $directory_field) {
      
      //The marc field number is just the first 3 characters
      $field_number = substr($directory_field, 0, 3);
      //The field value is the correpsonding value in the marcfieldvalues array.
      $field_value = $marc_field_values[$key];
      
      //We need to keep track of the field iterations
      if (!isset($marc_field_count[$field_number]) || !$marc_field_count[$field_number]){
        $marc_field_count[$field_number] = 0;
      }
      $field_count = $marc_field_count[$field_number];
      if (substr($directory_field, 0, 2) == '00') {
        // Populate Control fields
        // Technically control fields can be repeated, not that I ever see it.
        $record[$field_number][$field_count] = $field_value;
      }
      else {
        //Populate Indicators
        //$record[$field_number][$field_count]['field'] = $field_value;
        /* $record[$field_number][$field_count]['i1'] = substr($field_value, 0, 1);
        $record[$field_number][$field_count]['i2'] = substr($field_value, 1, 1); */
        
        //Start work on subfields
        // US (char) 31(dec) 1F 037 - character used to seperate subfields
        $subfields = explode($this->subfield_indicator, $marc_field_values[$key]);
        
        //Get rid of indicators
        array_splice($subfields, 0, 1);

        //$output .= $subfields[0]. '<br>';      

        //Insert subfields in database
        foreach ($subfields as $subfield) {
          $subfield_code = substr($subfield, 0, 1);
          $subfield_value = substr($subfield, 1, (strlen($subfield)-1));
          $record[$field_number][$field_count][$subfield_code] = $subfield_value;
        }
      }
      //We need to keep track of the field iterations
      $marc_field_count[$field_number]++;
    }
    return $record;
  }

  private function mapMarcFields($marc_record){
    $record = array(
      'title' => '', //245[0][a]
      'active_date'=> '', // 005[0] needs trimming
      'author' => '', //264[0][b] trim comma or concat with [c] pub date t('Author (Personal Name -700)'), // 700[a]
      'abstract' => array(), // 520[0][a]
      'topics' => array(), // 650
      'genre' => array(), // 655
      'url' => '', // 856[0][u]
      'cover' => '',
      'alt_cover'=>'',
      'id' => '',
      'audience' => array()
    );
    //'form' => t('Form Description'), //300 concat [0][a] [0][b]
    if(isset($marc_record['001'])){
      $record['id'] = $marc_record['001'][0];
    }

    if(isset($marc_record['005'])){
      $record['active_date'] = substr($marc_record['005'][0], 0, 8);
    }

    // GET THE AUTHOR
    if(isset($marc_record['264']) && isset($marc_record['264'][0]['b'])){
      $record['author'] = strpos(strtolower($marc_record['264'][0]['b']), 'great courses') !== FALSE ? "The Great Courses":'';
    }
    if(empty($record['author'])){
      foreach($this->author_fields as $author_field){
        if(isset($marc_record[$author_field])){
          $author = $this->getStringField($marc_record[$author_field][0], $author_field);
          if(!empty($author)){
            $author = preg_replace('/\(.*\)|\bdst\b/','',$author);
            $record['author'] = $author;
            break;
          }
        } 
      }
    }

    // GET TITLE
    foreach($this->title_fields as $title_field){
      if(isset($marc_record[$title_field])){
        $title = $this->getStringField($marc_record[$title_field][0], $title_field);
        if(!empty($title)){
          $record['title'] = $title;
          break;
        }
      } 
    }

    // GET AUDIENCE
    foreach($this->audience_fields as $audience_field){
      if(isset($marc_record[$audience_field])){
        $s = $this->getFieldArray($marc_record[$audience_field], null, true);
        if(!empty($s)){
          $record['audience'] = array_merge($record['audience'], $s);
        }
      }
    }

    // GET THE SUBJECTS
    foreach($this->subject_fields as $subject_field){
      if(isset($marc_record[$subject_field])){
        $s = $this->getFieldArray($marc_record[$subject_field], null, true);
        if(!empty($s)){
          $record['topics'] = array_merge($record['topics'], $s);
          $record['audience'] = array_merge($record['audience'], $s);
          if($subject_field == 655){
            $record['genre'] = $s;
          }
        }
      }
    }
    if(isset($marc_record['008']) && !empty($marc_record['008'])){
      $language = substr($marc_record['008'][0], 30);
      $language = trim($language);
      
      if(substr($language,2,3) !== 'eng' && substr($language,2,3) !== 'und' && substr($language,2,3) !== 'zxx'){        
        $record['genre'][] = 'foreign language';
      }
    }
    if(isset($marc_record['028']) && strtolower($marc_record['028'][0]['b']) == 'kanopy'){
      $record['cover'] = 'https://www.kanopy.com/sites/default/files/imagecache/vp_poster_small/video-assets/' . $marc_record['028'][0]['a'] . '_poster.jpg';
    }

    // GET THE URL INFO
    if(isset($marc_record[856])){
      foreach($marc_record[856] as $url){
        
        if(empty($record['cover']) && strpos($url['u'], '/external-image') !== FALSE){
          $record['cover'] = $url['u'];
        } elseif (strpos($url['u'], '/external-image') == FALSE){
          $record['url'] = $url['u'];
        }
        if(strpos($url['u'], '/external-image') !== FALSE){
          $record['alt_cover'] = $url['u'];
        } 
        
        if(!empty($record['url']) && !empty($record['cover']) && !empty($record['alt_cover'])){
          break;
        }
      } 
    }
    if(isset($marc_record[546]) && isset($marc_record[546][0]['a']) ){
      $marc_record[546][0]['a'] = implode(", ", explode(",",$marc_record[546][0]['a']));
    }
    //GET DESCRIPTION VALS
    if(isset($marc_record[520])){
      $data = $this->getFieldArray($marc_record[520]);
      $record['abstract'] = array_merge($record['abstract'], $data);
    }

    foreach($marc_record as $key=> $field){
      $k = ltrim($key, '0');
      if($k >= 100 && $k !== 'leader' && ($k < 300 || $k >= 400) && $k != 520 && $k != 856 && !in_array($k, $this->subject_fields)){
        $data = $this->getFieldArray($field, " ");
        $data = implode("; ", $data);
        $record['abstract'][] =  $data;
      }
    }
    return $record;
  }
  private function getStringField($array, $field_number, $separator = " "){
    $field_number = strval($field_number);
    $field = array_merge(array(), $array);
    if(empty($field)){
      return null;
    }

    if(isset($field->i1)){
      unset($field->i1);
    }
    if(isset($field->i2)){
      unset($field->i2);
    }
    
    $value='';
   
    /* switch($field_number){
      case '700': $value = isset($field->a);
      case '710': $value = isset($field->a) ? $field->a : implode($separator, $field);
      case '264': $value = isset($field->b) ? $field->b : implode($separator, $field);
      case '245': $value = isset($field->a) ? $field->a : implode($separator, $field);
      default: $value = implode($separator, $field);
    }
 */
    if($field_number == 700){
      $value = $field['a'];
    }elseif($field_number == 710){
      $value = isset($field['a']) ? $field['a'] : implode($separator, $field);
    }elseif($field_number == 264){
      $value = isset($field['b']) ? $field['b']: implode($separator, $field);
    }elseif($field_number == 245){
      $value = isset($field['a']) ? $field['a'] : implode($separator, $field);
    }else{
      $value = implode($separator, $field);
    }
    $value = trim($value);
    return rtrim($value, ",;.:");
  }

  private function getFieldArray($array, $separator = null, $strip_numeric = false){
    $field = array_merge(array(), $array);
    $value = array();

    if(isset($field->i1)){
      unset($field->i1);
    }
    if(isset($field->i2)){
      unset($field->i2);
    }

    foreach($field as $f){
      if(is_array($f)){
        if(isset($field->i1)){
          unset($field->i1);
        }
        if(isset($field->i2)){
          unset($field->i2);
        }
        if($separator){
          $val = implode($separator, $f);
          $value[]=$val;
        } else if(!$strip_numeric){
          $value = array_merge($value, $f);
        } else {
          foreach($f as $k=>$v){
            if(!is_numeric($k)){
              $value[]=$v;
            }
          }
        }
      } else {
        $value[] = $f;
      }
    }
    return $value;
  }

}