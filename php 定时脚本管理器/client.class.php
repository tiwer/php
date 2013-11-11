<?php
/**********************************
 * 后端进程管理器客户端操作类
 *
 * author: xwg
 * version: 1.0
 **********************************/

Class Client
{
	// 初始化服务器信息
	public function __construct($server_ip = '127.0.0.1', $server_port = '13469'){
		$this->server_ip = $server_ip;
		$this->server_port = $server_port;
	}

	// 查询进程状态 返回：UP（正常）、DOWN（当机）
	public function status($jobname){
		return $this->_cmd("STATUS {$jobname}");
	}

	// 开启新进程 OK（成功）、FAILED（失败）
	public function start($jobname, $script_cmd){
		return $this->_cmd("START {$jobname} {$script_cmd}");
	}

	// 结束进程 返回：OK（成功）、FAILED（失败）
	public function stop($jobname, $graceful=FALSE){
		$p2 = $graceful ? 1 : 0;
		return $this->_cmd("STOP {$jobname} {$p2}");
	}

	// 重启进程 OK（成功）、FAILED（失败）
	public function restart($jobname, $graceful=FALSE){
		$p2 = $graceful ? 1 : 0;
		return $this->_cmd("RESTART {$jobname} {$p2}");
	}

	// 读取进程服务器的输出缓冲 返回：进程服务器使用的内存
	public function servermem(){
		return $this->_cmd("SERVERMEM");
	}

	// 读取进程输出缓冲
	// 返回：进程输出缓冲区内容
	public function read($jobname){
		return substr($this->_cmd("READ {$jobname}"), 0, -1);
	}

	// 读取进程服务器的输出缓冲
	// 返回：进程服务器输出缓冲区内容
	public function serverread(){
		return $this->_cmd("SERVERREAD");
	}


	// 执行命令并返回结果
	private function _cmd($primitive){
		if (!($sock = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP))){
			return FALSE;
		}

		if (!@socket_connect($sock, $this->server_ip, $this->server_port)){
			return FALSE;
		}

		socket_write($sock, $primitive);
		$rt = socket_read($sock, 1024);
		socket_close($sock);
		return $rt;
	}
}
