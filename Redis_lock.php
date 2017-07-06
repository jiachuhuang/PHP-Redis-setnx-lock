<?php
class Redis_lock {

	private $con;

	public function __construct(){
		$this->conn = new Redis ();
		try{
			$this->conn->pconnect('127.0.0.1', '6379');
		}catch(Exception $e) {

		}
	}

	/**
	 * 阻塞锁
	 * @param $lock_key
	 * @param $lock_timeout 过期时间戳，毫秒
	 */
	public function block($lock_key,&$lock_timeout)
	{
		$try_num = 45;
		$s_ms = 5;

		while($try_num--){
			if($this->try_lock($lock_key, $lock_timeout)){
				return true;
			}else{
				usleep(1000*$s_ms);   // $s_ms 毫秒
				$lock_timeout += $s_ms;
				$s_ms = ($s_ms >= 160? 160: $s_ms*2); // 指数避让
			}
		}
		return false;
	}

	/**
	 * redis 模拟锁
	 * @param $lock_key
	 * @param float $lock_timeout 过期时间，单位毫秒
	 * @return bool
	 */
	public function try_lock($lock_key,$lock_timeout)
	{
		$lock_key = strval($lock_key);
		$lock_timeout = (float)$lock_timeout;

		if(!$lock_key || $lock_timeout <= 0)
		{
			return false;
		}

		$now = $this->get_millisecond();

		$lock = $this->conn->setnx($lock_key,$lock_timeout);
		if($lock || (($now > (float)$this->conn->get($lock_key)) && $now > (float)$this->conn->get_set($lock_key,$lock_timeout))) {
			return true;
		}else{
			return false;
		}
	}

	/**
	 * 释放锁
	 * @param $lock_key
	 * @param float $lock_timeout try_lock设置的过期时间
	 * @return bool
	 */
	public function release_lock($lock_key,$lock_timeout)
	{
		$lock_key = strval($lock_key);
		$lock_timeout = (float)$lock_timeout;

		if(!$lock_key || $lock_timeout <= 0)
		{
			return false;
		}

		$now = $this->get_millisecond();

		if($now < $lock_timeout){
			$this->conn->delete($lock_key);
		}
	}

	/**
	* 获取毫秒时间戳
	*/
	public function get_millisecond() 
	{
    		list ( $t1, $t2 ) = explode ( ' ', microtime () );
    		return ( float ) sprintf ( '%.0f', (floatval ( $t1 ) + floatval ( $t2 )) * 1000 );
	}
}
