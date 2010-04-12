<?

function getopt_($params) {
	$out = array();
	$args = $GLOBALS['argv'];
	
	$cmd = array_shift($args);
	
	while($name = array_shift($args)) {
		if($name[0] != '-')
			return FALSE;
		$name = substr($name, 1);
		
		if(isset($params[$name])) {
			$param = $params[$name];
			if($param) {
				if(!$args)
					return FALSE;
				$out[$name] = array_shift($args);
			}
			else {
				$out[$name] = TRUE;
			}
			continue;
		}
		return FALSE;
	}

	foreach($params as $key=>$value) {
		if($value >= 2 && !isset($out[$key]))
			return FALSE;
	}
	return $out;	
}

// parse args
$opts = getopt_(array("h" => 2, "f" => 2, "l" => 1,
		"p" => 1, "P" => 1, "v" => 0,
		"r" => 0));
	
if(!$opts || $opts['v']) {
	echo "RosApi Exec ver:1.0 author: Kamil Trzcinski\n\n";
	echo "usage: ${argv[0]} [options]\n";
	echo " -h [hostname:port] (*)\n";
	echo " -l [login]\n";
	echo " -p [password]\n";
	echo " -P [password from file]\n";
	echo " -f [file] (*)\n";
	echo " -r : read only\n";
	echo " -v : this help\n";
	exit(1);
}

// require classes
require_once("routeros.class.php");
require_once("routerosparser.class.php");

// explode args
list($host, $port) = explode(':', $opts['h'], 2);
$port = intval($port);
$file = $opts['f'];
$login = $opts['l'] ? $opts['l'] : "admin";
$password = $opts['P'] ? file_get_contents($opts['P']) : $opts['p'];

// connect to router
echo "-- $login@$host -- \n";
$ip = gethostbyname($host) or die("couldn't resolve hostname\n");;
$conn = RouterOS::connect($ip, $login, $password) or die("couldn't connect to router\n");
$resource = $conn->getall('/system/resource') or die("coudln't fetch system version\n");

// get major revision
$major = explode('.', $resource['version']);
$major = intval($major[0]);

// create parser
$parser = new RouterOSParser();
$parser->define('host', $host);
$parser->define('address', $ip);
$parser->define('version', $resource['version']);
$parser->define('major', $major);
$parser->define('arch', $resource['architecture-name']);
$parser->define('board', $resource['board-name']);

function convertToMac($mac) {	// tylko cyfry
	$mac = strtoupper($mac);
	if(!$mac)
		return $mac;
	if (preg_match("/^([0-9A-F]{2}:[0-9A-F]{2}:[0-9A-F]{2}:[0-9A-F]{2}:[0-9A-F]{2}:[0-9A-F]{2}+)$/", $mac))
		return $mac;
	if (preg_match("/^([0-9A-F]{12})$/", $mac))
		return join(':', str_split($mac, 2));
	else
		return false;
}

function acl($parser, $address) { 
	if(!$address)
		$parser->error("no address specified");
	$arr = func_get_args();
	$parser = array_shift($arr);
	$address = array_shift($arr);
	$address2 = convertToMac($address);
	$comment = join(' ', $arr);
	if(!$address2)
		$parser->error("invalid mac-address specified : $address");
	$parser->config('wireless-access-list', array('mac-address' => $address2, 'comment' => $comment, 'interface' => 'all'));
}
$parser->section('wireless-access-list', '/interface/wireless/access-list', 'addset', 'mac-address,interface');
$parser->cmd('acl', acl);

// parse and update config
$parser->parseFile($file) or die("couldn't parse file\n");
$conn->readOnly = isset($opts['r']);
echo $parser->update($conn, true) . "\n";

?>