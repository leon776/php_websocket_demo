<?php
class Ws{
	private $host = '127.0.0.1';
	private $port = 8080;
	private $maxuser = 10;
	public  $accept = array(); //连接的客户端
	private $cycle = array(); //循环连接池
	private $isHand = array(); 
	/*
		接受三个回调函数，分别在新用户连接、有消息到达、用户断开时触发
		function add、function send、function close
	*/
	public $function = array();
	//Constructor
	function __construct($host, $port, $max) {
		$this->host = $host;
		$this->port = $port;
		$this->maxuser = $max;
	}
	//挂起socket
	public function start_server() {
		$this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		//允许使用本地地址
		socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, TRUE); 
		socket_bind($this->socket, $this->host, $this->port);
		//最多10个人连接，超过的客户端连接会返回WSAECONNREFUSED错误
		socket_listen($this->socket, $this->maxuser); 
		while(TRUE) {
			$this->cycle = $this->accept;
			$this->cycle[] = $this->socket;
			//阻塞用，有新连接时才会结束
			socket_select($this->cycle, $write, $except, null);
			foreach ($this->cycle as $k => $v) {
				if($v === $this->socket) {
					if (($accept = socket_accept($v)) < 0) {
						continue;
					}
					//如果请求来自监听端口那个套接字，则创建一个新的套接字用于通信
					$this->add_accept($accept);
					continue;
				}
				$index = array_search($v, $this->accept);
				if ($index === NULL) {
					continue;
				}
				if (!@socket_recv($v, $data, 1024, 0) || !$data) {//没消息的socket就跳过
					$this->close($v);
					continue;
				}
				if (!$this->isHand[$index]) {
					$this->upgrade($v, $data, $index);
					if(!empty($this->function['add'])) {
						call_user_func_array($this->function['add'], array($this));
					}
					continue;
				}
				$data = $this->decode($data);
				if(!empty($this->function['send'])) {
					call_user_func_array($this->function['send'], array($data, $index, $this));
				}
			}
			sleep(1);
		}
	}
	//增加一个初次连接的用户
	private function add_accept($accept) {
		$this->accept[] = $accept;
		$index = array_keys($this->accept);
		$index = end($index);
		$this->isHand[$index] = FALSE;
	}
	//关闭一个连接
	private function close($accept) {
		$index = array_search($accept, $this->accept);
		socket_close($accept);
		unset($this->accept[$index]);
		unset($this->isHand[$index]);
		if(!empty($this->function['close'])) {
			call_user_func_array($this->function['close'], array($this));
		}
	}
	//响应升级协议
	private function upgrade($accept, $data, $index) {
		if (preg_match("/Sec-WebSocket-Key: (.*)\r\n/",$data,$match)) {
			$key = base64_encode(sha1($match[1] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
			$upgrade  = "HTTP/1.1 101 Switching Protocol\r\n" .
					"Upgrade: websocket\r\n" .
					"Connection: Upgrade\r\n" .
					"Sec-WebSocket-Accept: " . $key . "\r\n\r\n";  //必须以两个回车结尾
			socket_write($accept, $upgrade, strlen($upgrade));
			$this->isHand[$index] = TRUE;
		}
	}
	//体力活
	public function frame($s){
		$a = str_split($s, 125);
		if (count($a) == 1){
			return "\x81" . chr(strlen($a[0])) . $a[0];
		}
		$ns = "";
		foreach ($a as $o){
			$ns .= "\x81" . chr(strlen($o)) . $o;
		}
		return $ns;
	}
	//体力活
	public function decode($buffer) {
		$len = $masks = $data = $decoded = null;
		$len = ord($buffer[1]) & 127;
		if ($len === 126) {
			$masks = substr($buffer, 4, 4);
			$data = substr($buffer, 8);
		} 
		else if ($len === 127) {
			$masks = substr($buffer, 10, 4);
			$data = substr($buffer, 14);
		} 
		else {
			$masks = substr($buffer, 2, 4);
			$data = substr($buffer, 6);
		}
		for ($index = 0; $index < strlen($data); $index++) {
			$decoded .= $data[$index] ^ $masks[$index % 4];
		}
		return $decoded;
	}
}
// END ws class

/* End of file class_ws.php */
/* Location: ./server/class_ws.php */