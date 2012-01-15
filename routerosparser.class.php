<?

/**
  /section How it works
  # For each section: RouterOS::getall items from RouterOS
  # ignore all dynamic entries, remove all invalid entries
  # try to classify RouterOS item to either ignore or to pass list
  # try to match RouterOS item with local item using defined \a keys, if no match found remove, if match found update only what changed
  # reorder RouterOS item list
  # add not found items to RouterOS
 */
//! @brief Parser class to load configuration from file and perform differencing configuration update.
//! @author ayufan
//! @remarks GPL: http://www.gnu.org/licenses/gpl.html
class RouterOSParser {
    private $cmdList = array();
    private $sectionList = array();
    private $configList = array();
    private $ignoreList = array(); // takes precedence before passList
    private $passList = array();
    public $vars = array();
    public $logs = array();
    public $showIgnored = FALSE;
    public $currentContext = "--internal--";

    public function __get($name) {
        return $this->vars[$name];
    }

    public function __set($name, $value) {
        if($value === NULL)
            unset($this->vars[$name]);
        else
            $this->vars[$name] = $value;
    }

    public function __isset($name) {
        return isset($this->vars[$name]);
    }

    public function __unset($name) {
        unset($this->vars[$name]);
    }

    function error($message) {
        die($this->currentContext . " : $message\n");
    }

    function define($key, $value = NULL) {
        if($value !== NULL)
            $this->vars[$key] = $value;
        else
            unset($this->vars[$key]);
    }

    function variable($key) {
        return $this->vars[$key];
    }

    private static function splitLine($line, $count = FALSE) {
        return $count ? split("[ \t]+", $line, $count) : split("[ \t]+", $line);
    }

    function replace($string) {
        foreach($this->vars as $key => $value)
            $string = str_replace("%$key%", $value, $string);
        return stripcslashes($string);
    }

    private function explodeString($line, $defaults = array()) {
        if(!is_array($line)) {
            $args = $defaults;
            foreach(RouterOSParser::splitLine($line) as $arg) {
                list($key, $value) = explode('=', $arg, 2);
                $args[$key] = $value ? $this->replace($value) : $value;
            }
            return $args;
        }
        else {
            $args = $defaults;
            foreach(array_keys($line) as $key) {
                if(is_int($key)) {
                    list($key, $value) = explode('=', $arg, 2);
                    $args[$key] = $value ? $this->replace($value) : $value;
                }
                else {
                    $value = $line[$key];
                    $args[$key] = $value ? $this->replace($value) : $value;
                }
            }
            return $args;
        }
    }

    function config2($cmd) {
        $args = func_get_args();
        $cmd = array_shift($args);
        return config($cmd, $args);
    }

    function config2r($cmd) {
        $args = func_get_args();
        $cmd = array_shift($args);
        return config($cmd, $args, FALSE, TRUE);
    }

    function config($cmd, $line, $reverse = FALSE) {
        if(!isset($this->sectionList[$cmd]))
            $this->error("add : $cmd : section doesn't exist!");

        $config = &$this->configList[$cmd];

        $args = $this->explodeString($line, $this->sectionList[$cmd]['defaults']);

        if($this->sectionList[$cmd]['type'] == 'value') {
            foreach($args as $key => $value) {
                $config[$key] = $value;
            }
            return TRUE;
        }
        else if($keys = $this->sectionList[$cmd]['keys']) {
            $hash = FALSE;
            foreach($keys as $key => $value) {
                if(!isset($args[$key]))
                    $this->error("add : $cmd : undefined value for key : $key");
                if($hash)
                    $hash .= ' ';
                $hash .= $args[$key];
            }
            if($hash) {
                if($config && $reverse)
                    $config = array($hash => $args) + $config;
                else
                    $config[$hash] = $args;
                return TRUE;
            }
        }

        if($reverse)
            array_unshift($config, $args);
        else
            $config[] = $args;
        return TRUE;
    }

    function ignore2($cmd) {
        $args = func_get_args();
        $cmd = array_shift($args);
        return ignore($cmd, $args);
    }

    function ignore($cmd, $line) {
        if(!isset($this->sectionList[$cmd]))
            $this->error("ignore : $cmd : section doesn't exist");
        $ignore = &$this->ignoreList[$cmd];
        $ignore[] = $this->explodeString($line);
    }

    function pass2($cmd) {
        $args = func_get_args();
        $cmd = array_shift($args);
        return pass($cmd, $args);
    }

    function pass($cmd, $line) {
        if(!isset($this->sectionList[$cmd]))
            $this->error("pass : $cmd : section doesn't exist");
        $pass = &$this->passList[$cmd];
        $pass[] = $this->explodeString($line);
    }

    function section($alias, $cmd, $type, $keys = FALSE, $defaults = FALSE) {
        if(!$alias)
            $this->error("section : undefined alias");
        if(isset($this->sectionList[$alias]))
            $this->error("section : $alias : section already exist");
        if(!$cmd || !$type)
            $this->error("section : $alias : undefined cmd and/or type");
	$args = explode(",", $type);
	$type = array_shift($args);
	$args = array_fill_keys($args, "");
        if(!in_array($type, array('value', 'set', 'addset', 'addset_order')))
            $this->error("section : $alias : invalid type");

        $section = array('cmd' => $cmd, 'type' => $type, 'args' => $args);
        if($keys && $keys != 'false')
            $section['keys'] = array_fill_keys(explode(',', $keys), TRUE);
        if($defaults)
            $section['defaults'] = $this->explodeString($defaults);
        $this->sectionList[$alias] = $section;
    }

    function cmd($alias, $cmd) {
        $this->cmdList[$alias] = $cmd;
    }

    private function compareValues($a, $op, $b) {
        $a = $this->replace($a);
        $b = $this->replace($b);
        switch($op) {
            case '=': return $a == $b;
            case '!=': return $a != $b;
            case '<': return doubleval($a) < doubleval($b);
            case '>': return doubleval($a) > doubleval($b);
            case '<=': return doubleval($a) <= doubleval($b);
            case '>=': return doubleval($a) >= doubleval($b);
            case '~=': return fnmatch($b, $a);
            case '!~=': return!fnmatch($b, $a);
            default:
                $this->error("if : invalid operator : $op");
                return FALSE;
        }
    }

    function parseFile($file) {
        $levels = array();
        $skip = FALSE;

        $lines = is_array($file) ? $file : @file($file);
        if(!$lines)
            return FALSE;

        reset($lines);
        while(list($lineno, $line) = each($lines)) {
            $line = trim($line);
            if($line[0] == '#' || $line == '')
                continue;
            ++$lineno;
            $this->currentContext = "$file($lineno)";

            list($cmd, $line) = RouterOSParser::splitLine($line, 2);

            switch($cmd) {
                // if <variable> <operator> <value>
                case 'if':
                    if(isset($levels[0]) && $levels[0] != 'true') {
                        array_unshift($levels, 'skip');
                        continue;
                    }
                    list($var, $op, $value) = RouterOSParser::splitLine($line, 3);
                    array_unshift($levels, $this->compareValues($var, $op, $value) ? 'true' : 'false');
                    continue 2;

                // elseif <variable> <operator> <value>
                case 'elseif':
                    isset($levels[0]) or $this->error("elseif : unexpected");
                    if($levels[0] == 'skip')
                        continue;
                    list($var, $op, $value) = RouterOSParser::splitLine($line, 3);
                    $levels[0] = $levels[0] != 'false' ? 'skip' : $this->compareValues($var, $op, $value) ? 'true' : 'false';
                    continue 2;

                // else
                case 'else':
                    isset($levels[0]) or $this->error("else : unexpected");
                    if($line)
                        $this->error("else : invalid data after else");
                    $levels[0] = $levels[0] == 'skip' ? 'skip' : $levels[0] == 'true' ? 'false' : 'true';
                    continue 2;

                // endif
                case 'endif':
                    isset($levels[0]) or $this->error("endif : unexpected");
                    if($line)
                        $this->error("else : invalid data after else");
                    array_shift($levels);
                    continue 2;
            }

            if(isset($levels[0]) && $levels[0] != 'true')
                continue;

            switch($cmd) {
                // var <key> <value>
                case 'var':
                    list($key, $value) = RouterOSParser::splitLine($line, 2);
                    $this->define($key, $this->replace($value));
                    break;

                // set <alias> <values>
                case 'set':
                case 'add':
                    // extract cmd and values
                    list($cmd, $line) = RouterOSParser::splitLine($line, 2);
                    $this->config($cmd, $line);
                    break;

                case 'addr':
                    // extract cmd and values
                    list($cmd, $line) = RouterOSParser::splitLine($line, 2);
                    $this->config($cmd, $line, TRUE);
                    break;

                // function <function> <arg0> <arg1=value1> ...
                // ...
                // endfunction
                case 'function':
                    // extract cmd
                    list($cmds, $line) = RouterOSParser::splitLine($line, 2);

                    // extract args
                    $args = array("\$parser");
                    foreach($this->explodeString($line) as $key => $value)
                        $args[] = ($value === NULL) ? "$key" : "$key='$value'";
                    $args = join(",", $args);

                    // extract body
                    $body = array();
                    while(TRUE) {
                        if(list(, $line) = each($lines)) {
                            $line = trim($line);
                            if($line == '' || $line[0] == '#')
                                continue;
                            if($line == 'endfunction')
                                break;
                            $body[] = $line;
                        }
                        else {
                            $this->error("function : $cmds : unfinished function");
                        }
                    }
                    $body = join("\n", $body);

                    // add function
                    $func = create_function($args, $body) or $this->error("function : $cmds : function error");
                    foreach(explode(',', $cmds) as $cmd)
                        $this->cmd($cmd, $func);
                    break;

                // ignore <alias> <values>
                case 'ignore':
                    list($cmd, $line) = RouterOSParser::splitLine($line, 2);
                    $this->ignore($cmd, $line);
                    break;

                case 'pass':
                    list($cmd, $line) = RouterOSParser::splitLine($line, 2);
                    $this->pass($cmd, $line);
                    break;

                // flush <alias0> <alias1> ...
                case 'flush':
                    foreach(RouterOSParser::splitLine($line) as $cmd) {
                        if(!isset($this->sectionList[$cmd]))
                            $this->error("flush : $cmd : section doesn't exist");
                        $this->configList[$cmd] = array();
                    }
                    break;

                // section <alias> <cmd> <type> <keys> <defaults...>
                case 'section':
                    list($alias, $cmd, $type, $keys, $defaults) = RouterOSParser::splitLine($line, 5);
                    $this->section($alias, $cmd, $type, $keys, $defaults);
                    break;

                // disable <alias0> <alias1> ...
                case 'disable':
                    foreach(RouterOSParser::splitLine($line) as $cmd)
                        unset($this->sectionList[$cmd]);
                    break;

                // include <file>
                case 'include':
                    $line = $this->replace($line);
                    if(!$this->parseFile(dirname($file) . '/' . $line)
                        );
                    //$this->error("include : $line : failed to include");
                    break;

                // require <file>
                case 'require':
                    $line = $this->replace($line);
                    if(!$this->parseFile(dirname($file) . '/' . $line))
                        $this->error("require : $file : failed to require file");
                    break;

                // <function> <args>
                default:
                    $this->call($cmd, $line);
                    break;
            }
        }

        $this->currentContext = "internal";
        return TRUE;
    }

    function call($cmd, $args) {
        if(!isset($this->cmdList[$cmd]))
            $this->error("call : $cmd : undefined function");
        if(!is_array($args))
            $args = RouterOSParser::splitLine($args);
        foreach($args as &$arg)
            if($arg)
                $arg = $this->replace($arg);
        unset($arg);
        array_unshift($args, $this);
        return call_user_func_array($this->cmdList[$cmd], $args);
    }

    private function printValues($name, &$args, $newArgs = FALSE) {
        if($newArgs) {
            foreach($newArgs as $key => $value)
                $out .= "$key=(${args[$key]} => $value) ";
        }
        else {
            foreach($args as $key => $value)
                $out .= "$key=$value ";
        }
        if(isset($args['.id'])) {
            foreach(array('name', 'interface') as $key) {
                if(!isset($args[$key]))
                    continue;

                $this->logs[] = "\t$name : ${args['.id']} : ${args[$key]} : $out";
                return;
            }
            $this->logs[] = "\t$name : ${args['.id']} : $out";
            return;
        }
        $this->logs[] = "\t$name : $out";
    }

    private function diffCompareKey($a, $b) {
        if($a === $b || $a == str_replace(';', ',', $b))
            return 0;
        if(($a == 'false' || $a == 'no') && $b == '')
            return 0;
        if(($b == 'false' || $b == 'no') && $a == '')
            return 0;
        return ($a > $b) ? 1 : -1;
    }

    private function findChangeList($oldArgs, $newArgs) {
        if(!$newArgs)
            return array();
        foreach($newArgs as $key => $value) {
            if($value != 'false' && $value != '')
                continue;
            if(!isset($oldArgs[$key]))
                $oldArgs[$key] = $value;
        }
        return array_udiff_assoc($newArgs, array_intersect_key($oldArgs, $newArgs), array($this, 'diffCompareKey'));
    }

    private function updateSectionValues($conn, $section, $newList, $ignoreList) {
        // merge with defaults
        if($defaults = $section['defaults'])
            $newList = array_merge($defaults, $newList);

        // get current config
        $oldList = $conn->getall($section['cmd'], FALSE, $section['args']);
        if(!$oldList)
            return TRUE;

        // set differences
        $diffList = $this->findChangeList($oldList, $newList);
        if(count($diffList)) {
            $this->printValues("set", &$oldList, &$diffList);
            return $conn->set($section['cmd'], $diffList);
        }
        return TRUE;
    }

    private function mergeListWithArray($list, $array = FALSE) {
        if(!$array)
            return $list;
        foreach($list as &$line)
            $line = array_merge($array, $line);
        unset($line);
        return $list;
    }

    private function matchIgnoreList($args, $ignoreList) {
        foreach($ignoreList as $ignore) {
            if($this->findChangeList($args, $ignore))
                continue;
            return TRUE;
        }
        return FALSE;
    }

    private function matchConfigList($conn, $section, $args, &$configList, $dynamic) {
        // find config keys
        $keys = $section['keys'] ? array_intersect_key($args, $section['keys']) : FALSE;

        // find item in config and update
        foreach($configList as &$config) {
            if(isset($config['.id']))
                continue;

            // check if it matches all defined keys
            if($keys && count(array_intersect_assoc($keys, $config)) != count($keys))
                continue;

            // find differences
            $diff = $this->findChangeList($args, $config);
            if(count($diff)) {
                // no predefined keys, don't set value
                if(!$keys)
                    continue;

                // match dynamic entry?
                if($dynamic)
                    return 'dynamic';

                // update config
                $this->printValues("set", &$args, &$diff);
                $diff['.id'] = $args['.id'];
                $conn->set($section['cmd'], $diff);
            }
            else if($dynamic) {
                return 'dynamic';
            }
            return $config['.id'] = $args['.id'];
        }
        return FALSE;
    }

    private function synchronizeCurrentItemList($conn, $section, &$newList, $ignoreList, $passList) {
        $reorderList = array();

        // usunmy te wartosci, ktorych nie ma najpierw!
        // wyszukujemy na podstawie klucza
        // dodajac nowe wartosci i zamieniajac stare
        // oraz je przesuwajac w odpowiednie miejsce
        // aby zachowac okreslona kolejnosc

        $results = $conn->getall($section['cmd'], FALSE, $section['args']);
        if(!$results)
            return FALSE;

        $removeList = array();

        foreach($results as $index => $args) {
            if(!isset($args['.id']))
                continue;

            $dynamic = isset($args['dynamic']) && $args['dynamic'] == 'true';
            $disabled = isset($args['disabled']) && $args['disabled'] == 'true';
            $default = isset($args['default']) && $args['default'] == 'true';
            $invalid = isset($args['invalid']) && $args['invalid'] == 'true';

            // check if item is valid
            if($ignoreList && $this->matchIgnoreList($args, $ignoreList)) {
                // leave it alone (dynamic entry)
                if($dynamic)
                    continue;

                if($this->showIgnored)
                    $this->printValues("ignore", &$args);
                continue;
            }
            if($passList && !$this->matchIgnoreList($args, $passList)) {
                // leave it alone (dynamic entry)
                if($dynamic)
                    continue;

                if($this->showIgnored)
                    $this->printValues("nopass", &$args);
                continue;
            }

            // find configList in item
            $id = $this->matchConfigList($conn, $section, $args, $newList, $dynamic);
            if($id == 'dynamic') {
                $this->printValues("remove_dynamic", $args);
                $removeList[] = $args['.id'];
                //$conn->remove($section['cmd'], $args['.id']);
            }
            // found add to reorder list
            else if($id) {
                $reorderList[] = $id;
            }
            // remove item from list
            else if($section['type'] == 'addset' || $section['type'] == 'addset_order') {
                if($default || $dynamic)
                    continue;

                $this->printValues("remove", $args);
                $removeList[] = $args['.id'];
                //$conn->remove($section['cmd'], $args['.id']);
            }
        }

        if($removeList) {
            $conn->remove($section['cmd'], $removeList);
        }

        return $reorderList;
    }

    private function reorderItemList($conn, $section, &$newList, &$reorderList) {
        foreach($newList as $config) {
            // not added yet
            if(!isset($config['.id']))
                continue;

            // add to order
            $orderList[] = $config['.id'];

            // error!
            if(!count($reorderList))
                break;

            // next item
            if($reorderList[0] == $config['.id']) {
                array_shift($reorderList);
                continue;
            }

            // move before that item!
            $this->logs[] = "\tmove : ${config['.id']} : ${reorderList[0]}\n";
            $conn->move($section['cmd'], $config['.id'], $reorderList[0]);

            // remove item with that .id
            foreach($reorderList as $list_index => $list_item) {
                if($list_item != $config['.id'])
                    continue;
                array_slice($reorderList, $list_index, 1);
                break;
            }
        }
        return $orderList;
    }

    private function addLeftItems($conn, $section, &$newList, &$orderList) {
        foreach($newList as $index => &$config) {
            // already added
            if(isset($config['.id'])) {
                if($orderList && $orderList[0] == $config['.id'])
                    array_shift($orderList);
                continue;
            }

            // place in order
            if(count($orderList)) {
                $config['place-before'] = $orderList[0];
            }

            // add config
            $this->printValues("add", $config);
            $config['.id'] = $conn->add($section['cmd'], $config);
        }
    }

    private function updateSectionList($conn, $section, $newList, $ignoreList, $passList) {
        if($defaults = $section['defaults'])
            $newList = $this->mergeListWithArray($newList, $defaults);

        $reorderList = $this->synchronizeCurrentItemList($conn, $section, &$newList, $ignoreList, $passList);

        $type = $section['type'];

        if($type == 'addset_order') {
            $newOrderList = $this->reorderItemList($conn, $section, &$newList, $reorderList);
        }
        else {
            $newOrderList = array();
        }

        if($type == 'addset' || $type == 'addset_order') {
            $this->addLeftItems($conn, $section, $newList, $newOrderList);
        }
    }

    function updateSection($conn, $alias) {
        if(!isset($this->sectionList[$alias]))
            return FALSE;

        $section = $this->sectionList[$alias];
        $configList = $this->configList[$alias];
        $ignoreList = $this->ignoreList[$alias];
        $passList = $this->passList[$alias];

        // check type
        switch($type = $section['type']) {
            case 'value':
                return $this->updateSectionValues($conn, $section, $configList, $ignoreList);

            case 'set':
                if(!$configList)
                    return TRUE;
            case 'addset':
            case 'addset_order':
                if(!$configList)
                    $configList = array();
                return $this->updateSectionList($conn, $section, $configList, $ignoreList, $passList);

            default:
                die("updateSection(${sectionList['cmd']}): invalid type: $type");
        }
    }

    function update($conn, $ret = FALSE) {
        $logs = FALSE;

        $start = microtime(TRUE);
        foreach($this->sectionList as $alias => $section) {
            if(!$ret)
                echo ".";

            $this->logs = array();

            $this->updateSection($conn, $alias);

            if(count($this->logs)) {
                $logs[] = "$alias --";
                foreach($this->logs as $log)
                    $logs[] = $log;
            }

            flush();
        }
        $end = microtime(TRUE);
        $this->logs = array();

        if($logs) {
            $logs[] = "-- update took " . round($end - $start, 2) . " second(s)";
            if($ret)
                return join("\n", $logs);
            else
                echo "\n" . join("\n", $logs) . "\n";
        }
        else {
            if($ret)
                return FALSE;
            else
                echo "\n";
        }
    }
};
?>
