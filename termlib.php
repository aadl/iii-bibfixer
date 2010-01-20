<?php
/**
 * A Libray enabling terminal commands to III servers
 * termlib.php
 * @author Eric J. Klooster
 * @package TermLib
 */

/**
 * TermLib Class allows terminal commands to the III server
 */
class TermLib {
  private $verbose;
  private $host;
  private $user;
  private $pass;
  private $init;
  private $init_pass;
  private $resource;
  private $stdio;

  /**
   * Constructor function sets the authentication variables
   */
  public function __construct($hostname, $username, $password, $initials, $initials_pass, $verbose = FALSE) {
    $this->host = $hostname;
    $this->user = $username;
    $this->pass = $password;
    $this->init = $initials;
    $this->init_pass = $initials_pass;
    if ($verbose) $this->verbose = TRUE; // any true value assigns as TRUE
    if ($this->verbose) echo "TermLib Constructor\n";
  }

  /*============================================================================
    PRIVATE FUNCTIONS
  ============================================================================*/
  private function login() {
    if (!($this->resource = @ssh2_connect($this->host))) {
      if ($this->verbose) echo "ssh2_connect(" . $this->host . ") FAIL\n";
      return 'ERR';
    } else {
      if (!@ssh2_auth_password($this->resource, $this->user, $this->pass)) {
        if ($this->verbose) echo "ssh2_auth_password(" . $this->user . "," . $this->pass . ") FAIL\n";
        return 'ERR';
      } else {
        if (!($this->stdio = @ssh2_shell($this->resource, "xterm"))) {
          if ($this->verbose) echo "ssh2_shell() FAIL\n";
          return 'ERR';
        }
      }
    }
    return 0;
  }

  private function transmit($command, $expect = NULL) {
    fwrite($this->stdio, $command);
    usleep(80000);

    while($line = fgets($this->stdio)) {
      $cmdresult .= trim($line);
    }

    if ($expect) {
      $i = 250000;
      while ($i <= 2000000 && (strpos($cmdresult, $expect) === FALSE)) {
        //echo "USLEEP: " . $i . "\n";
        usleep($i);
        while($line = fgets($this->stdio)) {
          $cmdresult .= trim($line);
        }
        $i*=2; // backoff wait time
      }
      if ($i > 2000000 && (strpos($cmdresult, $expect) === FALSE)) {
        $retarr['error'] = 1;
      } else {
        $retarr['error'] = 0;
      }
    } else {
      $retarr['error'] = 0;
    }
    $retarr['unfiltered'] = $cmdresult;
    $retarr['result'] = self::iii_filter($cmdresult);
    return $retarr;
  }

  private function transmit_loop($commands) {
    foreach ($commands as $cmd) {
      $trans = $this->transmit($cmd['input'], $cmd['expect']);
      if ($this->verbose) echo $cmd['input'] . ":" . $cmd['expect'] . "\n";
      if ($trans['error']) {
        if ($this->verbose) echo "TRANSMIT LOOP ERROR: " . $cmd['input'] . " EXPECTING " . $cmd['expect'] . "\n";
        return array('status' => "ERROR", 'info' => $cmd['input'] . " EXPECTING " . $cmd['expect']);
      }
    }
    return $trans;
  }
  
  private function disconnect() {
    fclose($this->stdio);
  }

  private function iii_filter($string) {
    $string = preg_replace('%\x1B\[(.*?);(.*?)H%s', "\n", $string);
    $string = preg_replace('%\x1B%s', '', $string);
    return $string;
  }
  
  /*============================================================================
    Bibliographic Record Functions
  ============================================================================*/
  
  /**
  * Retrieve MARC field information for a bib record
  */
  public function get_bib_info($bnum) {
    $bib_record = array();
    $bnum = ".b" . substr(preg_replace('/[^0-9]/', '', $bnum), 0, 7) . "a";
    if ($this->verbose) echo "Grabbing Bib Info for $bnum\n";
    
    if ($this->login()) {
      if ($this->verbose) echo "SSH LOGIN ERROR\n";
      return "SSH LOGIN ERROR";
    }
    
    $trans_arr = array(
      array('input' => '', 'expect' => 'MAIN MENU'),
      array('input' => 'd', 'expect' => 'CATALOG DATABASE'),
      array('input' => 'u', 'expect' => 'key your initials'),
      array('input' => $this->init . PHP_EOL, 'expect' => 'key your password'),
      array('input' => $this->init_pass . PHP_EOL, 'expect' => 'BIBLIOGRAPHIC'),
      array('input' => 'b', 'expect' => 'want to update'),
      array('input' => $bnum . PHP_EOL, 'expect' => 'Key its number'),
    );
    
    $trans = $this->transmit_loop($trans_arr);
  
    if ($trans['status'] != "ERROR") {
      // determine max code of editable fields
      preg_match("/Choose one \(1-([0-9]{1,3})/", $trans['unfiltered'], $max_code_match);
      $max_code = $max_code_match[1];
      while(!$last_code_found) {
        $lines = preg_split("[\n|\r]", $trans['result']);
        foreach($lines as $line) {
          if (preg_match('/^([0-9]{2}) (.{16})([0-9]{2}) (.{16})/', $line)) {
            // Mutiple entries on the same line
            preg_match_all('/([0-9]{2}) (.{16})/', $line, $multicodes);
            foreach ($multicodes[1] as $index => $code) {
              $split = strpos($multicodes[2][$index], ':');
              $field = substr($multicodes[2][$index], 0, $split);
              $value = trim(substr($multicodes[2][$index], $split+1));
              $bib_record[$code] = array('field' => $field, 'value' => $value);
              if ($code == $max_code) {
                $last_code_found = TRUE;
              }
            }
          } else if (preg_match('/([0-9]{2}) ([0-9 ]{3,7}) (.*)/', $line, $marcdata)) {
            // marc number fields
            $code = $marcdata[1];
            $marc = str_replace(' ', '', trim($marcdata[2]));
            $value = trim($marcdata[3]);
            $bib_record[$code] = array('field' => $marc, 'marc' => $marc, 'value' => $value);
            if ($code == $max_code) {
              $last_code_found = TRUE;
            }
          } else if (preg_match('/([0-9]{2}) ([A-Z ]{3,12}) (.*)/', $line, $fielddata)) {
            // other fields on a single line
            $code = $fielddata[1];
            $field = trim($fielddata[2]);
            $value = trim($fielddata[3]);
            $bib_record[$code] = array('field' => $field, 'value' => $value);
            if ($code == $max_code) {
              $last_code_found = TRUE;
            }
          } else if (preg_match('/[ ]{11}(.*)/', $line, $extra)) {
            // extra data that goes with the previous line
            $extra = trim($extra[1]);
            if ($code) {
              $bib_record[$code]['value'] .= ' ' . $extra;
            }
          }
        }
        $trans = $this->transmit('m');
      }
    }
    $this->disconnect();
    return $bib_record;
  }

  /**
   * add_bib_info inserts a new field into the Bib record as indicated
   * by the tag, code and value
   *
   * Example tags:
   * a AUTHOR        d SUBJECT       g GOV DOC #     k TOC DATA      o BIB UTIL #
   * b ADD AUTHOR    e EDITION       h LIB HAS       l LCCN          p PUB INFO
   * c CALL #        f VENDOR INF    i STANDARD #    n NOTE          r DESCRIPT
   *
   * s SERIES        w RELATED TO    z CONT'D BY
   * t TITLE         x CONTINUES
   * u ADD TITLE     y MISC
   */
  public function add_bib_info($bnum, $tag, $code, $value) {
    $status = "SUCCESS";
    $bnum = ".b" . substr(preg_replace('/[^0-9]/', '', $bnum), 0, 7) . "a";
    if ($this->verbose) echo "ADDING TO BIB $bnum\n";
    
    if ($this->login()) {
      if ($this->verbose) echo "SSH LOGIN ERROR\n";
      return "SSH LOGIN ERROR";
    }
    
    $trans_arr = array();
    $trans_arr[] = array('input' => '', 'expect' => 'MAIN MENU');
    $trans_arr[] = array('input' => 'd', 'expect' => 'CATALOG DATABASE');
    $trans_arr[] = array('input' => 'u', 'expect' => 'key your initials');
    $trans_arr[] = array('input' => $this->init . PHP_EOL, 'expect' => 'key your password');
    $trans_arr[] = array('input' => $this->init_pass . PHP_EOL, 'expect' => 'BIBLIOGRAPHIC');
    $trans_arr[] = array('input' => 'b', 'expect' => 'want to update');
    $trans_arr[] = array('input' => $bnum . PHP_EOL, 'expect' => 'Key its number');
    $trans_arr[] = array('input' => 'i', 'expect' => 'new field');
    $trans_arr[] = array('input' => $tag, 'expect' => 'MARC');
    $trans_arr[] = array('input' => $marc . PHP_EOL, 'expect' => 'Key new data');
    $trans_arr[] = array('input' => $value . PHP_EOL, 'expect' => 'field');
    $trans = $this->transmit_loop($trans_arr);

    $trans_arr = array();
    if (strpos($trans['result'], 'duplicate')) {
      $trans_arr[] = array('input' => 'n', 'expect' => 'field');
    }
    $trans_arr[] = array('input' => 'q', 'expect' => 'MAKE changes');
    $trans_arr[] = array('input' => 'm', 'expect' => 'BIBLIOGRAPHIC');
    
    $trans = $this->transmit_loop($trans_arr);
    $this->disconnect();
    
    return $trans;
  }

  /**
   * Update a bib record with the given text
   * You MUST enter the field code number as returned by get_bib_info()
   */
  public function edit_bib_info($bnum, $code, $marc, $value) {
    $status = "SUCCESS";
    $bnum = ".b" . substr(preg_replace('/[^0-9]/', '', $bnum), 0, 7) . "a";
    if ($this->verbose) echo "UPDATING BIB $bnum\n";
    
    if ($this->login()) {
      if ($this->verbose) echo "SSH LOGIN ERROR\n";
      return "SSH LOGIN ERROR";
    }
    
    $trans_arr = array();
    $trans_arr[] = array('input' => '', 'expect' => 'MAIN MENU');
    $trans_arr[] = array('input' => 'd', 'expect' => 'CATALOG DATABASE');
    $trans_arr[] = array('input' => 'u', 'expect' => 'key your initials');
    $trans_arr[] = array('input' => $this->init . PHP_EOL, 'expect' => 'key your password');
    $trans_arr[] = array('input' => $this->init_pass . PHP_EOL, 'expect' => 'BIBLIOGRAPHIC');
    $trans_arr[] = array('input' => 'b', 'expect' => 'want to update');
    $trans_arr[] = array('input' => $bnum . PHP_EOL, 'expect' => 'Key its number');
    $trans_arr[] = array('input' => $code, 'expect' => 'MARC');
    $trans_arr[] = array('input' => $marc . PHP_EOL, 'expect' => 'Key new data');
    $trans_arr[] = array('input' => $value . PHP_EOL, 'expect' => 'field');
    $trans = $this->transmit_loop($trans_arr);

    $trans_arr = array();
    if (strpos($trans['result'], 'delete this field')) {
      $trans_arr[] = array('input' => 'y', 'expect' => 'field');
    } else if (strpos($trans['result'], 'duplicate')) {
      $trans_arr[] = array('input' => 'n', 'expect' => 'field');
    }
    $trans_arr[] = array('input' => 'q', 'expect' => 'MAKE changes');
    $trans_arr[] = array('input' => 'm', 'expect' => 'BIBLIOGRAPHIC');
    $trans = $this->transmit_loop($trans_arr);
    
    $this->disconnect();
    
    return $trans;
  }
  
  /**
   * delete_bib_info deletes the field from the Bib record
   * as indicated by its corresponding code number
   */
  public function delete_bib_info($bnum, $code) {
    $trans = $this->edit_bib_info($bnum, $code, '', '');
    if ($this->verbose && $trans['status'] == "ERROR") {
      echo "DELETE BIB INFO ERROR\n";
    }
    return $trans;
  }
  
  /**
   * replace_bib_text searches the Bib record for a field matching old field text exactly
   * If found, it replaces it with new text
   * 
   * returns a Error if old text is not found in the Bib record
   */
  public function replace_bib_text($bnum, $old_text, $new_text) {
    $bib = $this->get_bib_info($bnum);
    foreach ($bib as $code => $field) {
      if ($field['value'] == $old_text) {
        // found the field to update
        $this->edit_bib_info($bnum, $code, $field['marc'], $new_text);
        if ($this->verbose) {
          echo "UPDATED Bib:" . $bnum . " field code:" . $code . " marc:" . $field['marc'] . " with:" . $new_text . "\n";
        }
        $found = TRUE;
        break;
      }
    }
    if (!$found) {
      if ($this->verbose) echo "FIELD TEXT NOT FOUND:" . $old_text . " in Bib: " . $bnum . "\n";
      return "ERROR: FIELD TEXT NOT FOUND";
    }
  }
  
  public function get_bib_marc_field($bnum, $marc) {
    $matches = array();
    foreach(self::get_bib_info($bnum) as $code => $field) {
      if (substr($field['marc'], 0, 3) == $marc) {
        $matches[$code] = $field;
      }
    }
    return $matches;
  }
  
  /*============================================================================
    Item Record Functions
  ============================================================================*/
  
  /**
  * Retrieve MARC field information for a item record
  */
  public function get_item_info($inum) {
    $item_record = array();
    $inum = ".i" . substr(preg_replace('/[^0-9]/', '', $inum), 0, 7) . "a";
    if ($this->verbose) echo "Grabbing Item Info for $inum\n";
    
    if ($this->login()) {
      if ($this->verbose) echo "SSH LOGIN ERROR\n";
      return "SSH LOGIN ERROR";
    }
    
    $trans_arr = array(
      array('input' => '', 'expect' => 'MAIN MENU'),
      array('input' => 'd', 'expect' => 'CATALOG DATABASE'),
      array('input' => 'u', 'expect' => 'key your initials'),
      array('input' => $this->init . PHP_EOL, 'expect' => 'key your password'),
      array('input' => $this->init_pass . PHP_EOL, 'expect' => 'ITEM'),
      array('input' => 'i', 'expect' => 'want to update'),
      array('input' => $inum . PHP_EOL, 'expect' => 'Key its number'),
    );
    
    $trans = $this->transmit_loop($trans_arr);

    if ($trans['status'] != "ERROR") {
      // determine max code of editable fields
      preg_match("/Choose one \(1-([0-9]{1,3})/", $trans['unfiltered'], $max_code_match);
      $max_code = $max_code_match[1];
      while(!$last_code_found) {
        $lines = preg_split("[\n|\r]", $trans['result']);
        foreach($lines as $line) {
          if (preg_match('/^([0-9]{2}) (.{16})([0-9]{2}) (.{16})/', $line)) {
            // Mutiple entries on the same line
            preg_match_all('/([0-9]{2}) (.{16})/', $line, $multicodes);
            foreach ($multicodes[1] as $index => $code) {
              $split = strpos($multicodes[2][$index], ':');
              $field = substr($multicodes[2][$index], 0, $split);
              $value = trim(substr($multicodes[2][$index], $split+1));
              $item_record[$code] = array('field' => $field, 'value' => $value);
              if ($code == $max_code) {
                $last_code_found = TRUE;
              }
            }
          } else if (preg_match('/([0-9]{2}) ([0-9 ]{3,7}) (.*)/', $line, $marcdata)) {
            // marc number fields
            $code = $marcdata[1];
            $marc = str_replace(' ', '', trim($marcdata[2]));
            $value = trim($marcdata[3]);
            $item_record[$code] = array('field' => $marc, 'marc' => $marc, 'value' => $value);
            if ($code == $max_code) {
              $last_code_found = TRUE;
            }
          } else if (preg_match('/([0-9]{2}) ([A-Z ]{3,12}) (.*)/', $line, $fielddata)) {
            // other fields on a single line
            $code = $fielddata[1];
            $field = trim($fielddata[2]);
            $value = trim($fielddata[3]);
            $item_record[$code] = array('field' => $field, 'value' => $value);
            if ($code == $max_code) {
              $last_code_found = TRUE;
            }
          } else if (preg_match('/[ ]{11}(.*)/', $line, $extra)) {
            // extra data that goes with the previous line
            $extra = trim($extra[1]);
            if ($code) {
              $item_record[$code]['value'] .= ' ' . $extra;
            }
          }
        }
        $trans = $this->transmit('m');
      }
    }
    $this->disconnect();
    ksort($item_record);
    return $item_record;
  }

  /**
   * add_item_info inserts a new field into the Item record as indicated
   * by the tag, code and value
   *
   * Example tags:
   * a ITEM FIELD    d INV DATE      l OWN LOC       r RESER NOTE    y URL
   * b BARCODE       e DRA CNUM      m MESSAGE       v VOLUME
   * c CALL #        f DRA LASTTR    o DRA OWNING    x INT NOTE
   */
  public function add_item_info($inum, $tag, $code, $value) {
    $status = "SUCCESS";
    $inum = ".i" . substr(preg_replace('/[^0-9]/', '', $inum), 0, 7) . "a";
    if ($this->verbose) echo "ADDING TO ITEM $inum\n";
    
    if ($this->login()) {
      if ($this->verbose) echo "SSH LOGIN ERROR\n";
      return "SSH LOGIN ERROR";
    }
    
    $trans_arr = array();
    $trans_arr[] = array('input' => '', 'expect' => 'MAIN MENU');
    $trans_arr[] = array('input' => 'd', 'expect' => 'CATALOG DATABASE');
    $trans_arr[] = array('input' => 'u', 'expect' => 'key your initials');
    $trans_arr[] = array('input' => $this->init . PHP_EOL, 'expect' => 'key your password');
    $trans_arr[] = array('input' => $this->init_pass . PHP_EOL, 'expect' => 'BIBLIOGRAPHIC');
    $trans_arr[] = array('input' => 'i', 'expect' => 'want to update');
    $trans_arr[] = array('input' => $inum . PHP_EOL, 'expect' => 'Key its number');
    $trans_arr[] = array('input' => 'i', 'expect' => 'new field');
    $trans_arr[] = array('input' => $tag, 'expect' => 'MARC');
    $trans_arr[] = array('input' => $marc . PHP_EOL, 'expect' => 'Key new data');
    $trans_arr[] = array('input' => $value . PHP_EOL, 'expect' => 'field');
    $trans = $this->transmit_loop($trans_arr);

    $trans_arr = array();
    if (strpos($trans['result'], 'duplicate')) {
      $trans_arr[] = array('input' => 'n', 'expect' => 'field');
    }
    $trans_arr[] = array('input' => 'q', 'expect' => 'MAKE changes');
    $trans_arr[] = array('input' => 'm', 'expect' => 'ITEM');
    
    $trans = $this->transmit_loop($trans_arr);
    $this->disconnect();
    
    return $trans;
  }
  
  /**
   * Update an item record with the given text
   * You MUST enter the field code number as returned by get_item_info()
   */
  public function edit_item_info($inum, $code, $marc, $value) {
    $status = "SUCCESS";
    $inum = ".i" . substr(preg_replace('/[^0-9]/', '', $inum), 0, 7) . "a";
    if ($this->verbose) echo "UPDATING ITEM $inum\n";
    
    if ($this->login()) {
      if ($this->verbose) echo "SSH LOGIN ERROR\n";
      return "SSH LOGIN ERROR";
    }
    
    $trans_arr = array();
    $trans_arr[] = array('input' => '', 'expect' => 'MAIN MENU');
    $trans_arr[] = array('input' => 'd', 'expect' => 'CATALOG DATABASE');
    $trans_arr[] = array('input' => 'u', 'expect' => 'key your initials');
    $trans_arr[] = array('input' => $this->init . PHP_EOL, 'expect' => 'key your password');
    $trans_arr[] = array('input' => $this->init_pass . PHP_EOL, 'expect' => 'ITEM');
    $trans_arr[] = array('input' => 'i', 'expect' => 'want to update');
    $trans_arr[] = array('input' => $inum . PHP_EOL, 'expect' => 'Key its number');
    $trans_arr[] = array('input' => $code, 'expect' => 'MARC');
    $trans_arr[] = array('input' => $marc . PHP_EOL, 'expect' => 'Key new data');
    $trans_arr[] = array('input' => $value . PHP_EOL, 'expect' => 'field');
    $trans = $this->transmit_loop($trans_arr);

    $trans_arr = array();
    if (strpos($trans['result'], 'delete this field')) {
      $trans_arr[] = array('input' => 'y', 'expect' => 'field');
    } else if (strpos($trans['result'], 'duplicate')) {
      $trans_arr[] = array('input' => 'n', 'expect' => 'field');
    }
    $trans_arr[] = array('input' => 'q', 'expect' => 'MAKE changes');
    $trans_arr[] = array('input' => 'm', 'expect' => 'ITEM');
    
    $trans = $this->transmit_loop($trans_arr);
    $this->disconnect();
    
    return $trans;
  }

  /**
   * delete_item_info deletes the field from the Item record
   * as indicated by its corresponding code number
   */
  public function delete_item_info($inum, $code) {
    $trans = $this->edit_item_info($inum, $code, '', '');
    if ($this->verbose && $trans['status'] == "ERROR") {
      echo "DELETE ITEM INFO ERROR\n";
    }
    return $trans;
  }
  
  /**
   * replace_item_text searches the Item record for a field matching old field text exactly
   * If found, it replaces it with new text
   * 
   * returns a Error if old text is not found in the Bib record
   */
  public function replace_item_text($inum, $old_text, $new_text) {
    $item = $this->get_item_info($inum);
    foreach ($item as $code => $field) {
      if ($field['value'] == $old_text) {
        // found the field to update
        $this->edit_item_info($inum, $code, $field['marc'], $new_text);
        if ($this->verbose) {
          echo "UPDATED Item:" . $inum . " field code:" . $code . " marc:" . $field['marc'] . " with:" . $new_text . "\n";
        }
        $found = TRUE;
        break;
      }
    }
    if (!$found) {
      if ($this->verbose) echo "FIELD TEXT NOT FOUND:" . $old_text . " in Item: " . $inum . "\n";
      return "ERROR: FIELD TEXT NOT FOUND";
    }
  }

  public function get_item_marc_field($inum, $marc) {
    $matches = array();
    foreach(self::get_item_info($inum) as $code => $field) {
      if (substr($field['marc'], 0, 3) == $marc) {
        $matches[$code] = $field;
      }
    }
    return $matches;
  }
  
} // End of class TermLib

?>