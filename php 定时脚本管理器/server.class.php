<?php
if (php_sapi_name() != 'cli') die('Server must run under cli mode!');
/**********************************
 * 后端进程管理器服务器类
 *
 * author: xwg
 * version: 1.0
 **********************************/

/**
 *  服务器类
 *
 */
class socket_server{
	private $server_ip   = null;
	private $server_port = null;
	private $job_path    = null;
	private $sock        = null;
	private $connect     = null;
	private $processes 	 = null;
	private $child_pids  = null;
	private $pipes       = null;
	private $log_file    = null;
	private $share_mem_file = null;
	private $share_mem   = null;
	private $server_output_buffer = array();

	public function __construct($server_ip = '192.168.1.23', $server_port = '13469', $job_path = '', $log_file = "./error.log", $share_mem_file = ''){
		$this->server_ip   = $server_ip;
		$this->server_port = $server_port;
		$this->job_path    = $job_path;
		$this->pipes       = array();
		$this->processes   = array();
		$this->log_file    = $log_file;
		$this->share_mem_file = $share_mem_file;
	}

	//初始化socket
	public function init(){
		// 开始监听
		$sock = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		if (!$sock){
			echo "socket_create() failed.\n";
			exit;
		}

		if (!socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1)){
			echo "socket_set_option() failed.\n";
			exit;
		}

		//绑定端口和ip
		if (!($ret = @socket_bind($sock, $this->server_ip, $this->server_port))){
			echo "socket_bind() failed.\n";
			exit;
		}

		//等待监听
		if (!($ret = @socket_listen($sock, 5))){
			echo "socket_listen() failed.\n";
			exit;
		}

		//设置
		$this->sock = $sock;

		//设置共享内存
		if(!file_exists($this->share_mem_file)){
			echo "share memory file not exists.\n";
			exit;
		}else{
			include_once($this->share_mem_file);
		}

		//初始化共享内存
		$this->share_mem = new share_memory('shm_key_of_server_'.$this->server_ip.'_'.$this->server_port);
		if(!$this->share_mem->attach()){
			echo "shm attach() failed.\n";
			exit;
		}
	}

	//开始监听
	public function start(){
		// 循环处理
		while (TRUE){
			// 等待连接
			$this->server_echo("Waiting for new command...");

			$this->connect = @socket_accept($this->sock);
			if (!$this->connect){
				$this->server_echo("socket_accept() failed.\n");
				socket_write($this->connect, "socket_accept() failed.\n");
				break;
			}

			// 读取输入
			if (!($input = @socket_read($this->connect, 1024))) {
				$this->server_echo("socket_read() failed.\n");
				socket_write($this->connect, "socket_read() failed.\n");
				break;
			}

			// 分析并执行命令
			$input_arr = explode(' ', trim($input));
			if (count($input_arr) > 1){
				list($cmd, $params) = explode(' ', trim($input), 2);
			}else{
				$cmd = $input;
				$params = '';
			}

			//输出服务器内容
			$this->server_echo(date('Y-m-d H:i:s e')."\n$cmd $params\n");

			//遍历功能
			switch ($cmd){
				case 'STATUS':	// 获取进程状态
					$jobname = $params;
					$res = $this->backend_status($jobname);
					socket_write($this->connect, $res['msg']);
					break;
				case 'START':	// 开启进程
					$params = explode(' ', $params);
					$params_len = count($params);
					if ($params_len == 1){
						// 没有输入程序路径
						socket_write($this->connect, 'PARAMS FAILED');
						break;
					}
					$jobname 	= array_shift($params);
					$job_file 	= array_shift($params);
					$script_cmd = $this->job_path . $job_file;
					$res = $this->backend_start($jobname, $script_cmd);
					socket_write($this->connect, $res['msg']);
					break;
				case 'STOP':	// 结束进程 STOP NAME 0
					list($jobname, $graceful) = explode(' ', $params);
					$res = $this->backend_stop($jobname, $graceful);
					socket_write($this->connect, $res['msg']);
					break;
				case 'SERVERMEM':	// 读取服务器内存占用情况
					$mem = $this->my_memory_get_usage();
					socket_write($this->connect, $mem);
					break;
				case 'READ':
					$jobname = $params;
					$res= $this->share_mem_read($jobname);
					socket_write($this->connect, $res['msg']);
					break;
				case 'SERVERREAD':
					socket_write($this->connect, implode('', $this->server_output_buffer));
					break;
			}
		}
	}

	// 获取运行当前脚本的PHP解析器路径
	private function get_php_path(){
		return readlink('/proc/'.getmypid().'/exe');
	}

	// 强制结束进程
	private function force_stop_process($jobname){
		$this->stop_process($jobname, FALSE);
	}

	// 优雅结束进程
	private function graceful_stop_process($jobname){
		$this->stop_process($jobname, TRUE);
	}

	// 结束进程，并释放相关资源
	private function stop_process($jobname, $graceful){
		if (!$graceful) {
			// 强制结束proc_open打开的进程
			$status = proc_get_status($this->processes[$jobname]);
			exec('kill -9 '.$status['pid'].' 2>/dev/null >&- >/dev/null');
		}

		proc_terminate($this->processes[$jobname]);
		proc_close($this->processes[$jobname]);
		unset($this->processes[$jobname]);
	}

	// 查看进程状态
	private function backend_status($jobname){
		if (!isset($this->processes[$jobname])){
			// 进程不存在
			$this->server_echo("DOWN. (process $jobname does not exist.)\n");
			return  array('status' => false, 'msg' => 'DOWN');
		}

		$status = proc_get_status($this->processes[$jobname]);
		if (!$status){
			$this->force_stop_process($jobname);
			$this->server_echo("DOWN. (proc_get_status failed.)\n");
			return  array('status' => false, 'msg' => 'DOWN');
		}

		if ($status['running']){
			$this->server_echo("UP\n");
			return  array('status' => true, 'msg' => 'UP');
		}else{
			$this->server_echo("DOWN\n");
			return  array('status' => false, 'msg' => 'DOWN');
		}
	}

	// 开启进程
	private function backend_start($jobname, $script_cmd){
		// 检查进程名是否已经存在
		if (isset($this->processes[$jobname])){
			// 取进程状态
			$status = proc_get_status($this->processes[$jobname]);
			if (!$status){
				$this->force_stop_process($jobname);
				$this->server_echo("FAILED. (proc_get_status failed.)\n");
				return  array('status' => false, 'msg' => "FAILED. (proc_get_status failed.)\n");
			}

			// 检查进程是否正在运行
			if ($status['running']){
				$this->server_echo("FAILED. (process $jobname has already exist.)\n");
				return  array('status' => false, 'msg' => "FAILED. (process $jobname has already exist.)\n");
			}else{
				// 停止
				$this->force_stop_process($jobname);
			}
		}

		if (!file_exists($script_cmd)){
			// 文件不存在
			$this->server_echo("FAILED. ($script_cmd does not exist.)\n");
			return  array('status' => false, 'msg' => "FAILED. ($script_cmd does not exist.)\n");
		}

		// 执行后台进程
		$descriptorspec = array(
			0 => array("pipe", "r"),
			1 => array("pipe", "w"),
			2 => array("file", $this->log_file, "a")
		);

		$php_path = $this->get_php_path();
		$this->processes[$jobname] = proc_open("{$php_path} {$script_cmd}", $descriptorspec, $this->pipes[$jobname], dirname($script_cmd));

		if (!is_resource($this->processes[$jobname])){
			$this->server_echo("FAILED. (proc_open failed.)\n");
			return  array('status' => false, 'msg' => 'FAILED. (proc_open failed.)');
		}

		// 非阻塞模式读取
		$output_pipe = $this->pipes[$jobname][1];
		stream_set_blocking($output_pipe, 0);
		
		// 记录缓冲区行数
		$extra_settings[$jobname] = array(
			'bufferlines' => 10
		);

		// 创建共享变量用于存储输出缓冲
		$output_buffer = array();
		if (!$this->share_mem->put_var($jobname, $output_buffer)){
			$this->server_echo("shm put_var() failed.\n");
			return  array('status' => false, 'msg' => "shm put_var() failed.\n");
		}
		fclose($this->pipes[$jobname][0]);

		//新建一个子进程用于读取进程输出
		$pid = pcntl_fork();
		if ($pid == -1){
			$this->server_echo("pcntl_fork() failed.\n");
			return  array('status' => false, 'msg' => "pcntl_fork() failed.\n");
		}else if ($pid){
			//父进程
			$child_pids[$jobname] = $pid;
			pcntl_waitpid($t_pid, $status);
			$this->server_echo("START OK\n");
			return  array('status' => true, 'msg' => "SUCESS");
		}else{
			// 新建一个孙子进程用于避免僵尸进程
			$t_pid = pcntl_fork();
			if ($t_pid == -1){
				$this->server_echo("pcntl_fork() failed.\n");
				return  array('status' => false, 'msg' => "pcntl_fork() failed.\n");
			}else if ($t_pid){
				// 父进程
				exit;
			}else{
				//取出共享内存中的输出缓冲
				$output_buffer = $this->share_mem->get_var($jobname);
				while (TRUE){
					$read   = array($output_pipe);
					$write  = NULL;
					$except = NULL;

					if (FALSE === ($num_changed_streams = stream_select($read, $write, $except, 3))){
						continue;
					}elseif ($num_changed_streams > 0){
						$output = stream_get_contents($output_pipe);

						// 缓存输出
						if ($output !== ''){
							$buffer_lines = $extra_settings[$jobname]['bufferlines'] + 1;
							$output_lines = explode("\n", $output);
							$old_len = count($output_buffer);
							if ($old_len > 0){
								$output_buffer[$old_len-1] .= array_shift($output_lines);
							}
							$output_buffer = array_merge($output_buffer, $output_lines);
							$output_buffer = array_slice($output_buffer, -$buffer_lines, $buffer_lines);

							// 更新共享变量
							if (!$this->share_mem->put_var($jobname, $output_buffer)){
								$this->server_echo("shm put_var() failed.\n");
							}
						}else{
							break;
						}
					}
				}
				exit;
			}
		}
	}

	// 结束进程
	// $is_restart 是否是重启进程，如果是，则SOCKET不输出
	function backend_stop($jobname, $graceful=FALSE){
		if (!isset($this->processes[$jobname])){
			$this->server_echo("FAILED. (process $jobname does not exist.)\n");
			return  array('status' => false, 'msg' => "FAILED. (process $jobname does not exist.)\n");
		}

		$status = proc_get_status($this->processes[$jobname]);
		if (!$status){
			$this->force_stop_process($jobname);
			$this->server_echo("FAILED. (proc_get_status failed.)\n");
			return  array('status' => false, 'msg' => "FAILED. (proc_get_status failed.)\n");
		}

		if ($graceful){
			$this->graceful_stop_process($jobname);
		}else{
			$this->force_stop_process($jobname);
		}

		$this->server_echo("OK\n");
		return  array('status' => true, 'msg' => 'SUCESS');
	}



	// 服务器输出
	private function server_echo($str){
		$this->server_output_buffer[] = $str;
		$this->server_output_buffer = array_slice($this->server_output_buffer, -20, 20);
		echo $str . "\n";
	}

	// 返回进程占用的实际内存值
	private function my_memory_get_usage(){
		$pid = getmypid();
		$status = file_get_contents("/proc/{$pid}/status");
		preg_match('/VmRSS\:\s+(\d+)\s+kB/', $status, $matches);
		$vmRSS = $matches[1];
		return $vmRSS*1024;
	}

	// 读取进程输出缓冲区
	private function share_mem_read($jobname){
		if (!isset($this->processes[$jobname])){
			// 进程不存在
			$this->server_echo("NULL. (process does not exist.)\n");
			return  array('status' => false, 'msg' => 'process does not exist');
		}

		$status = proc_get_status($this->processes[$jobname]);
		if (!$status){
			$this->force_stop_process($jobname);
			$this->server_echo("NULL. (proc_get_status failed.)\n");
			return  array('status' => false, 'msg' => 'proc_get_status failed');
		}

		// 取出共享内存中的输出缓冲
		$output_buffer = $this->share_mem->get_var($jobname);
		if ($output_buffer){
			$content = implode("\n", $output_buffer)."\n";
			return  array('status' => true, 'msg' => $content);
		}else{
			return  array('status' => false, 'msg' => 'there have not share mem!');
		}
	}
}