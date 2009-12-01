#!/usr/bin/php
<?

require_once(dirname(__FILE__)."/routeros.class.php");

define(MAX_LENGTH, 10);
define(MAX_TIME_LENGTH, 6);

if($argc < 3) {
  die("usage: ${argv[0]} <login>:<password>@<host> <destination1>@<speed>@<protocol>...\n");
}

// get args
list($login, $host) = explode('@', $argv[1], 2);
if($host) {
  list($login, $password) = explode(':', $login, 2);
}
else {
  $host = $login;
  $login = "admin";
  $password = "";
}

// connect to server
$conn = RouterOS::connect($host, $login, $password) or die("couldn't connect to $login@$host\n");
$conn->setTimeout(60);

// structures
$dests = array();
$status = array();
$tags = array();

// start btest
for($i = 2; $i < $argc; ++$i) {
  list($dest, $speed, $protocol) = explode("@", $argv[$i]);

  if(!$speed)
    $speed = 0;
  if(!$protocol)
    $protocol = "tcp";
    
  $names = gethostbynamel($dest);
  if($names === FALSE) 
      die("couldn't resolve $dest!\n");
  $dest = $names[0];
      
  if($dests[$dest])
    die("destination $dest already defined!\n");  
 
  $tag = $conn->btest($dest, $speed, $protocol, btestCallback);
  if($tag === FALSE)
    continue;
  
  $tags[$tag] = $dest;
  $dests[$dest] = array("dest" => $dest, "speed" => $speed, "protocol" => $protocol);
}

// print header
printHeader();
printStatus();

// dispatch messages
$continue = TRUE;
$conn->dispatch($continue);

exit;

function btestCallback($conn, $state, $results) {
  global $dests, $tags, $status;

  // done message
  if($state == TRUE && !$results)
    return;
  
  // find destination
  $dest = $tags[$results[".tag"]];
  if($dest === FALSE)
    return;
  
  // trap message
  if($state == FALSE) {
    if($results["message"] == "interrupted")
      return;
      
    // state changed
    if($status[$dest] != $results["message"]) {
      $status[$dest] = $results["message"];
      printStatus();
    }
    return;
  }
  
  // not running
  if($results["status"] != "running") {
    // state changed
    if($status[$dest] != $results["status"]) {
      $status[$dest] = $results["status"];
      printStatus();
    }
    
    // restart btest (in error state)
    if($results["status"] != "connecting") {
      $conn->cancel($results[".tag"]);
      $tag = $conn->btest($dest, $dests[$dest]["speed"], $dests[$dest]["protocol"], btestCallback);
      if($tag !== FALSE)
        $tags[$tag] = $dest;
    }
    return;
  }
 
  // running get results
  $status[$dest] = bytesToString($results["tx-10-second-average"], 1000, "bps");
  printStatus();
}

function bytesToString($data, $multi = 1024, $postfix = "B") {
  $data = intval($data);

  if($data < $multi) {
    return round($data, 0) . $postfix;
  }
  if($data < $multi*$multi) {
    return round($data/$multi, 1) . "k$postfix";
  }
  if($data < $multi*$multi*$multi) {
    return round($data/$multi/$multi, 1) . "M$postfix";
  }
  return round($dat /$multi/$multi/$multi, 1) . "G$postfix";
}

function printHeader() {
  global $dests;
  
  $out = "-- ". str_pad("time", MAX_TIME_LENGTH)." -- ";
  foreach($dests as $dest=>$desc) {
    $out .= str_pad($dest, MAX_LENGTH)." -- ";
  }
  echo "$out\n";
  flush();
}

function printStatus() {
  global $dests, $header, $status;
  
  // update time
  static $startTime;
  if(!$startTime)
    $startTime = microtime(TRUE);
  $time = round(microtime(TRUE) - $startTime, 1);
  
  // print status line
  $out = "-- ".str_pad($time, MAX_TIME_LENGTH)." -- ";
  foreach($status as $dest=>$stat)
    $out .= str_pad($stat, max(strlen($dest), MAX_LENGTH)) . " -- ";
  echo "$out\r";
  flush();
}

?>
