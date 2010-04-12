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
		"p" => 1, "P" => 1, "v" => 0, "i" => 1,
		"r" => 0));
	
if(!$opts || $opts['v']) {
	echo "RosApi Exec ver:1.0 author: Kamil Trzcinski\n\n";
	echo "usage: ${argv[0]} [options]\n";
	echo " -h [hostname:port] (*)\n";
	echo " -l [login]\n";
	echo " -p [password]\n";
	echo " -P [password from file]\n";
	echo " -f [file] (*)\n";
	echo " -i [file.php] : include another file (has access to \$parser)\n";
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
$include = $opts['i'] ? $opts['i'] : FALSE;

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

if($include) {
	require_once($include);
}

// parse and update config
$parser->parseFile($file) or die("couldn't parse file\n");
$conn->readOnly = isset($opts['r']);
echo $parser->update($conn, true) . "\n";

?>