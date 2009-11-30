<?

require_once("routeros.class.php");

define(MAX_LENGTH, 10);

if($argc < 6) {
  die("usage: ${argv[0]} <host> <login> <password> <speed> <protocol> <destination1>...\n");
}

list($cmd, $host, $login, $password, $speed, $protocol) = $argv;

$conn = RouterOS::connect($host, $login, $password) or die("couldn't connect to $login@$host\n");
$conn->setTimeout(60);

$dests = array();
$header = array();
$status = array();

// add time
$header["time"] = str_pad("time", MAX_LENGTH);
$status["time"] = 0;

// start btest
for($i = 6; $i < $argc; ++$i) {
  $dest = $argv[$i];
  $tag = $conn->btest($dest, $speed, $protocol, btestCallback);
  if($tag === FALSE)
    continue;
  $dests[$dest] = $tag;
  $header[$dest] = str_pad($dest, MAX_LENGTH);
  $status[$dest] = str_pad($dest, MAX_LENGTH);
}

// print header
echo "-- ".join(" -- ", $header)." --\n";
printStatus();

// dispatch messages
$continue = TRUE;
$conn->dispatch($continue);

exit;

function btestCallback($conn, $state, $results) {
  global $dests, $header, $status, $speed, $protocol;

  // done message
  if($state == TRUE && !$results)
    return;
  
  // find destination
  $dest = array_search($results[".tag"], $dests);
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
      $dests[$dest] = $conn->btest($dest, $speed, $protocol, btestCallback);
    }
    return;
  }
 
  // running get results
  $status[$dest] = bytesToString($results["tx-10-second-average"], 1000)."bit/s";
  printStatus();
}

function bytesToString($data, $multi = 1024) {
  $data = intval($data);

  if($data < $multi) {
          return round($data, 0) . "B";
  }
  if($data < $multi*$multi) {
          return round($data/$multi, 1) . "kB";
  }
  if($data < $multi*$multi*$multi) {
          return round($data/$multi/$multi, 1) . "MB";
  }
  return round($dat /$multi/$multi/$multi, 1) . "GB";
}

function printStatus() {
  global $dests, $header, $status;
  
  // update time
  static $startTime;
  if(!$startTime)
    $startTime = microtime(TRUE);
  $status["time"] = round(microtime(TRUE) - $startTime, 1);
  
  // print status line
  $out = "-- ";
  foreach($status as $dest=>$stat)
    $out .= str_pad($stat, max(strlen($header[$dest]), MAX_LENGTH)) . " -- ";
  echo "$out\r";
  flush();
}

?>