<?

/*
  Author: Kamil Trzcinski
  E-mail: ayufan(at)osk-net(dot)pl
  WWW: http://www.ayufan.eu
  SVN: https://svn.osk-net.pl:444/rosapi (login: guest)
  License: http://www.gnu.org/licenses/gpl.html
*/

class RouterOS
{
	private $sock;
	private $where;
  
  private $tags = array();
  private $tagIndex = 1;
  private $dispatcher = array();
	
	public $readOnly = FALSE;
	
	private function writeSock($cmd = '') {	
		//if(strlen($cmd) == 0)
		//	echo "<<< ---\n";
		//else
		//	echo "<<< $cmd\n";
		
		$l = strlen($cmd);
		if($l < 0x80) {
			fwrite($this->sock, chr($l));
		}
		else if($l < 0x4000) {
			$l |= 0x8000;
			fwrite($this->sock, chr(($l >> 8) & 0xFF) . chr($l & 0xFF));
		}	
		else if($l < 0x200000) {
			$l |= 0xC00000;
			fwrite($this->sock, chr(($l >> 16) & 0xFF) . chr(($l >> 8) & 0xFF) . chr($l & 0xFF));
		}
		else if($l < 0x10000000) {
			$l |= 0xE0000000;
			fwrite($this->sock, chr(($l >> 24) & 0xFF) . chr(($l >> 16) & 0xFF) . chr(($l >> 8) & 0xFF) . chr($l & 0xFF));
		}
		else {
			fwrite($this->sock, chr(0xF0) . chr(($l >> 24) & 0xFF) . chr(($l >> 16) & 0xFF) . chr(($l >> 8) & 0xFF) . chr($l & 0xFF));
		}
		
		fwrite($this->sock, $cmd);
	}
	
	private function readSock() {
		$c = ord(fread($this->sock, 1));
		if(($c & 0x80) == 0x00) {
		}
		else if(($c & 0xC0) == 0x80) {
			$c &= ~0xC0;
			$c = ($c << 8) +  ord(fread($this->sock, 1));
		}
		else if(($c & 0xE0) == 0xC0) {
			$c &= ~0xE0;
			$c = ($c << 8) +  ord(fread($this->sock, 1));
			$c = ($c << 8) +  ord(fread($this->sock, 1));
		}
		else if(($c & 0xF0) == 0xE0) {
			$c &= ~0xF0;
			$c = ($c << 8) +  ord(fread($this->sock, 1));
			$c = ($c << 8) +  ord(fread($this->sock, 1));
			$c = ($c << 8) +  ord(fread($this->sock, 1));
		}
		else {
			$c = ord(fread($this->sock));
			$c = ($c << 8) +  ord(fread($this->sock, 1));
			$c = ($c << 8) +  ord(fread($this->sock, 1));
			$c = ($c << 8) +  ord(fread($this->sock, 1));
		}
		
		if($c == 0) {
			//echo ">>> ---\n";
			return NULL;
		}
		
		$o = '';
		while(strlen($o) < $c)
			$o .= fread($this->sock, $c - strlen($o));
			
		//echo ">>> $o\n";	
		return $o;
	}
	
	private function trap($args, $hide = FALSE) {
		if($args['message'] && $args['message'] != 'interrupted')
			echo "trap[".$this->where."]: ${args['message']}\n";
		while($this->response() != '!done');
	}

	static function connect($host, $login, $password, $port = 8728, $timeout = 5) {
		$self = new RouterOS();
	
		// open socket
		if(($self->sock = @fsockopen($host, 8728, $errno, $errstr, $timeout)) === FALSE)
			return NULL;
		stream_set_timeout($self->sock, $timeout);
		
		// initiate login
		$self->send('', 'login');
		$type = $self->response(&$args);
		if($type != '!done' || !isset($args['ret']))
			return NULL;
					
		// try to login
		$self->send('', 'login', FALSE, array('name' => $login, 'response' => '00'.md5(chr(0).$password.pack('H*',$args['ret']))));
		$type = $self->response(&$args);
		if($type == '!done')
			return $self;
		else if($type == '!trap')
			$self->trap($args);
		unset($self);
		return NULL;
	}
	
	public function setTimeout($timeout = 5) {
		return stream_set_timeout($this->sock, $timeout);
	}

	private function send($cmd, $type, $proplist = FALSE, $args = FALSE, $tag = FALSE) {
    $result = TRUE;
    
		if(is_array($cmd))
			$cmd = '/' . join('/', $cmd);
		$cmd .= '/' . $type;
		
		$this->where = $cmd;
			
		// send command & args
		$this->writeSock($cmd);
		if($args) {
			foreach($args as $key=>$value)
				$this->writeSock("=$key=". $value);
		}
		if($proplist)
			$this->writeSock(".proplist=" . (is_array($proplist) ? join(',', $proplist) : $proplist));
		if($tag) {
      if(is_callable($tag)) {
        $result = $this->tagIndex++;
        $this->tags[$result] = $tag;
      }
      else {
        $result = $tag;
      }
      $this->writeSock(".tag=$result");
    }
		$this->writeSock();
    
    return $result;
	}
	
	private function response($args = FALSE, $dispatcher = FALSE) {
    if($dispatcher && count($this->dispatcher)) {
      $res = array_shift($this->dispatcher);
      $args = $res["args"];
      return $res["type"];
    }
    
    while(true) {
      $args = array();
      $type = FALSE;
      
      // read response type
      if($type = $this->readSock()) {
        if($type[0] != '!') {
          while($this->readSock());
          return FALSE;
        }
      }
      
      // read response parameters
      while($line = $this->readSock()) {
        if($line[0] == '=') {
          $line = explode('=', $line, 3);
          $args[$line[1]] = count($line) == 3 ? $line[2] : TRUE;
          continue;
        }
        else {
          $line = explode('=', $line, 2);
          $args[$line[0]] = isset($line[1]) ? $line[1] : '';
        }
      }
      unset($args['debug-info']);
      
      if(isset($args[".tag"])) {
        if($dispatcher)
          return $type;
        $this->dispatcher[] = array("tag" => $args[".tag"], "type" => $type, "args" => $args);
      }
      else {
        return $type;
      }
    }
    return FALSE;
	}
	
	function getall($cmd, $proplist = FALSE, $args = array(), $assoc = FALSE, $callback = FALSE) {    
		$res = $this->send($cmd, 'getall', $proplist, $args, $callback);

		if($proplist) {
			if(!is_array($proplist))
				$proplist = explode(',', $proplist);
			$proplist = array_fill_keys($proplist, TRUE);
		}
    
    if($callback) {
      return $res;
    }
		
		$ids = array();
		
		// wait for response
		while(true) {
			$ret = array();
			switch($type = $this->response(&$ret)) {
				case '!re':
					if($proplist)
						$ret = array_intersect_key($ret, $proplist);
					if(isset($ret['.id']))
						if($assoc)
							$ids[$ret[$assoc]] = $ret;
						else
							$ids[] = $ret;
					else
						foreach($ret as $key => $value)
							$ids[$key] = $value;
					break;
					
				case '!trap':
					$this->trap($ret);
					return FALSE;
					
				case '!done':
					break 2;
					
				default:
					die("getall: undefined type=$type\n");
			}
		}
		
		return $ids;
	}
	
	function set($cmd, $args = array(), $callback = FALSE) {
		if($this->readOnly)
			return TRUE;
			
		$res = $this->send($cmd, 'set', FALSE, $args, $callback);
    
    if($callback) {
      return $res;
    }
		
		switch($type = $this->response(&$ret)) {
			case '!done':
				return TRUE;
				
			case '!trap':
				$this->trap($ret);
				return FALSE;
				
			default:
				die("set: undefined type\n");
		}
	}

	function reboot() {
		$this->send('/system', 'reboot', FALSE, FALSE);

		echo "!! rebooting...\n";
		sleep(5);

		switch($type = $this->response(&$ret)) {
			case '!done':
				return TRUE;

			case '!trap':
				$this->trap($ret);
				return FALSE;

			default:
				return TRUE;
		}
	}
	
	function cancel($tag = FALSE, $callback = FALSE) {	
    if(is_callable($tag)) {
      $tag = array_search($tag, $this->tags);
      if($tag === FALSE) {
        echo "cancel: undefined tag\n";
        return FALSE;
      }
    }
    
		$res = $this->send('', 'cancel', FALSE, array(".tag" => $tag), FALSE, $callback);
    
    if($callback) {
      return $res;
    }
		
		switch($type = $this->response(&$ret)) {
			case '!done':
				return TRUE;
				
			case '!trap':
				$this->trap($ret);
				return FALSE;
				
			default:
				die("set: undefined type\n");
		}
	}
  
	function fetchurl($url, $callback = FALSE) {
		$finished = FALSE;
		
		echo ".. downloading $url\n";

		$res = $this->send('/tool', 'fetch', FALSE, array('url' => $url), $callback);
    
    if($callback) {
      return $res;
    }
		
		while(true) {
			switch($type = $this->response(&$ret)) {
				case '!done':
					return TRUE;
					
				case '!trap':
					$this->trap($ret);
					return FALSE;
					
				case '!re':
					switch($ret['status']) {
						case 'connecting':
						case 'requesting':
							break;
							
						case 'downloading':
							if(!$ret['total'])
								break;
							$progress = round(intval($ret['downloaded'])*100 / intval($ret['total']), 1);
							echo ".. ${ret['downloaded']} of ${ret['total']} ($progress%) within ${ret['duration']}\n";
							break;
							
						case 'finished':
							echo ".. downloaded!\n";
							$this->cancel();
							$finished = TRUE;
							break;
							
						case 'failed':
							echo "!! failed!\n";
							$this->cancel();
							break;
							
						default:
							die("fetch: undefined response (${ret['status']})\n");
					}
					break;
					
				default:
					die("fetch: undefined type\n");
			} 
			flush();
		}
		return $finished;
	}
	
	function move($cmd, $id, $before, $callback = FALSE) {
		if($this->readOnly)
			return TRUE;
			
		$res = $this->send($cmd, 'move', FALSE, array('numbers' => $id, 'destination' => $before), $callback);
    
    if($callback) {
      return $res;
    }
		
		switch($type = $this->response(&$ret)) {
			case '!done':
				return TRUE;
				
			case '!trap':
				$this->trap($ret);
				return FALSE;
				
			default:
				die("set: undefined type\n");
		}
	}
  
	function add($cmd, $args = array(), $callback = FALSE) {
		if($this->readOnly)
			return TRUE;
			
		$res = $this->send($cmd, 'add', FALSE, $args, $callback);
    
    if($callback) {
      return $res;
    }
		
		switch($type = $this->response(&$ret)) {
			case '!done':
				if(isset($ret['ret']))
					return $ret['ret'];
				return TRUE;
				
			case '!trap':
				$this->trap($ret);
				return FALSE;
				
			default:
				die("set: undefined type\n");
		}
	}
	
	function remove($cmd, $id, $callback = FALSE) {
		if($this->readOnly)
			return TRUE;
			
		$res = $this->send($cmd, 'remove', FALSE, array('.id' => is_array($id) ? join(',', $id) : $id), $callback);
    
    if($callback) {
      return $res;
    }
		
		switch($type = $this->response(&$ret)) {
			case '!done':
				return TRUE;
				
			case '!trap':
				$this->trap($ret);
				return FALSE;
				
			default:
				die("remove: undefined type\n");
		}
	}
  
	function unsett($cmd, $id, $value, $callback = FALSE) {
		if($this->readOnly)
			return TRUE;
				
		$res = $this->send($cmd, 'unset', FALSE, array('numbers' => $id, 'value-name' => $value), $callback);
    
    if($callback) {
      return $res;
    }
		
		switch($type = $this->response(&$ret)) {
			case '!done':
				return TRUE;
				
			case '!trap':
				$this->trap($ret);
				return FALSE;
				
			default:
				die("unset: undefined type\n");
		}
	}
  
	function scan($id, $duration="00:02:00", $callback = FALSE) {
		$res = $this->send('/interface/wireless', 'scan', FALSE, array('.id' => $id, 'duration' => $duration), $callback);
    
    if($callback) {
      return $res;
    }

		$results = array();
		
		while(true) {
			$ret = array();
			switch($type = $this->response(&$ret)) {
				case '!done':
					return $results;
					
				case '!re':
					$results[$ret['address']] = $ret;
					break;
					
				case '!trap':
					$this->trap($ret);
					return FALSE;
					
				default:
					die("scan: undefined type: $type\n");
			}
		}
	}
  
  function btest($address, $speed = "1M", $protocol = "tcp", $callback = FALSE) {
    echo ".. running btest to $address ($speed/$protocol)...\n";
    
    $res = $this->send('/tool', 'bandwidth-test', FALSE, 
      array(
        "address" => $address,
        "direction" => "transmit",
        "local-tx-speed" => $speed, 
        "protocol" => ($protocol == "tcp" ? "tcp" : "udp"),
        "local-udp-tx-size" => ($protocol == "tcp" ? 1500 : min(max(intval($protocol), 30), 1500))), $callback);
        
    if($callback) {
      return $res;
    }
        
    while(true) {
      $ret = array();
			switch($type = $this->response(&$ret)) {
				case '!done':
					return TRUE;
					
				case '!re':
					print_r($ret);
					break;
					
				case '!trap':
					$this->trap($ret);
					return FALSE;
					
				default:
					die("btest: undefined type: $type\n");
			}
    }
  }
  
  function dispatch(&$continue) {
    while($continue || count($this->tags)) {
      switch($type = $this->response(&$ret, TRUE)) {
        case '!re':
          if(isset($ret['.tag'])) {
            $callback = $this->tags[$ret['.tag']];
            if(is_callable($callback))
              $callback($this, TRUE, $ret);
          }
          break;
          
        case '!done':
          if(isset($ret['.tag'])) {
            $callback = $this->tags[$ret['.tag']];
            if(is_callable($callback))
              call_user_func($callback, $this, TRUE, NULL);
            unset($this->tags[$ret['.tag']]);
          }
          return TRUE;
          
        case '!trap':
          if(isset($ret['.tag'])) {
            $callback = $this->tags[$ret['.tag']];
            if(is_callable($callback))
              call_user_func($callback, $this, FALSE, $ret);
            unset($this->tags[$ret['.tag']]);
          }
          return FALSE;
          
        default:
          die("dispatch: undefined type\n");
      }
    }
  }
};

?>
