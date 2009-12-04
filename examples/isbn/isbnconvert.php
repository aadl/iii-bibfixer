<?php

/*
This example converts an existing ISBN10 to ISBN13

This uses the ISBN class available at http://www.blyberg.net/files/
*/

require_once('termlib.php');
require_once('ISBN.php'); // from the above URL

$host = "iiiserver.yourlibrary.org"; // Your innovative server
$user = "iiiuser"; // User to login via SSH
$password = "iiipassword"; // Password to SSH user
$initials = "xxx"; // Initials of user with access to editor
$initpass = "xxx"; // Password of initials user

$start = 1147030; // First bib record in range (no b)
$end = 1147031; // Last bib record in range

// construct
$tl = new TermLib($host, $user, $password, $initials, $initpass);


$isbn_converter = new ISBN();

for($b = $start; $b < $end; $b++) {
  // Get the 020 fields for the current bib
  $fields = $tl->get_marc_field($b, "020");
  
  // If a 020 exists see if it has ISBN-10s or ISBN-13s
  if (is_array($fields)) {
    // Grab all existing ISBN-10s and ISBN-13s
    $isbn10s = array();
    $isbn13s = array();
    
    foreach($fields as $field) {
      if (preg_match('/[0-9]{12}[0-9Xx]/', $field['value'], $matches)) {
        $isbn13s[] = $matches[0];
      } else if (preg_match('/[0-9]{9}[0-9Xx]/', $field['value'], $matches)) {
        $isbn10s[] = $matches[0];
      }
    }
    echo "=== BIB $b ===\n";
    print_r($isbn10s);
    print_r($isbn13s);

    // If there is an ISBN-10, Convert it to ISBN-13
    if (is_array($isbn10s)) {
      foreach ($isbn10s as $isbn10) {
        $isbn13 = $isbn_converter->convert($isbn10);
        // check to see if the ISBN-13 version of this ISBN already exists in the record
        if (!in_array($isbn13, $isbn13s)) {
          // if not then add it to the bib
          $tl->add_bib_info($b, 'i', '020', $isbn13);
        }
      }
    }
  }
}

?>
