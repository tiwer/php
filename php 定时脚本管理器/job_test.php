<?php

error_reporting(E_ALL);
ini_set('display_errors', true); 
date_default_timezone_set("Asia/Shanghai"); 
$p = mysql_connect('127.0.0.1', 'root', 'root');
mysql_select_db('test');
mysql_query('set names utf8');

while(true){
	$time = time();
	$sql = "insert into user(`time`) values('{$time}')";
	$back = mysql_query($sql);
	if($back){
		echo date('Y-m-d H:i:s') . '：插入成功' . "\n";
	}else{
		echo date('Y-m-d H:i:s') . '：插入失败' . "\n";
	}

	sleep(10);
}





