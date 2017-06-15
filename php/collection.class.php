<?php
// collection.class.php : handles data collection / api request services

class collection
{
	// members
	var $db;
	var $collections_db = 'collections';
	var $email_domains = array('@gmail.com', '@yahoo.com', '@hotmail.com');
	
	// miner vars
	var $per_mine_query_max = 200; // mine X term records max per run
	var $per_mine_max = 20; // mine X term records max per run
	var $per_account_max = 400; // send max of X emails per account per run (default 1 run per day)
	var $repeat_email_duration = 15768000; // half a year (approx 6 months)
	var $max_email_accounts = 5;
	
	// api
	var $box_client_id = 'oowe1p7n6jo4r5ayrxw6gml6wb0rqodk';
	var $box_client_secret = 'Wbic2HoInnwH4tmPM8wTQyoUUpIwjXIF';
	
	// constructor
	function __construct()
	{	
		// globals
		global $db;
		
		// connections
		$this->db = $db;	
	}
	
	function get_data($url, $headers = array(), $proxy = '')
	{
		$ch = curl_init();
	
		if($proxy != ''){
			curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, 0);
			curl_setopt($ch, CURLOPT_PROXY, $proxy);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		}
	
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.3) Gecko/20070309 Firefox/2.0.0.3");
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	
		// data
		if( !empty($headers) ){
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		}		
		
		// try
		$output = curl_exec($ch);
		if($output != ''){
			return $output;
		} else {
			$curl_info = curl_getinfo($ch);
			return $curl_info;
		}
	
		curl_close($ch);
	}
	
	function request_data($url, $headers = array(), $fields = array(), $type = 'POST', $proxy = '')
	{
		$ch = curl_init();
		$output = '';
		
		// proxy ?
		if($proxy != ''){
			curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, 0);
			curl_setopt($ch, CURLOPT_PROXY, $proxy);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		}
		
		// config
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.3) Gecko/20070309 Firefox/2.0.0.3");
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $type);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		
		// data
		if( !empty($headers) ){
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		}
		if( !empty($fields) ){
			curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
		}
		
		// request
		try {
			$result = curl_exec($ch);
			if($result != ''){
				$output = $result;
			} else {
				$curl_info = curl_getinfo($ch);
				$output = $curl_info;
			}
			curl_close($ch);
		} catch(Exception $e) {
            $output = $e->getMessage();
		}
		
		return $output;
	}
	
	function get_access_token()
	{
		// set collections db
		$this->db->set_db($this->collections_db);
		
		// vars
		$oauth_endpoint = "https://api.box.com/oauth2/token";
		$access_token = '';
		
		// get last refresh token
		$auth_data = $this->db->get_arr("SELECT * FROM box_auth");
		if( isset($auth_data[0]['refresh_token']) ){
			
			$refresh_token = $auth_data[0]['refresh_token'];
			$headers = array("Content-Type: application/x-www-form-urlencoded");
			$fields_arr = array(
				'grant_type' => 'refresh_token',
				'refresh_token' => $refresh_token,
				'client_id' => $this->box_client_id,
				'client_secret' => $this->box_client_secret
			);
			$fields = http_build_query($fields_arr);
			
			$result = $this->request_data($oauth_endpoint, $headers, $fields);
			$result_arr = json_decode($result, true);
			if( isset($result_arr['access_token']) ){
				$access_token = $result_arr['access_token'];
				
				// store new tokens
				if( isset($result_arr['refresh_token']) ){
					$sql = "UPDATE box_auth SET access_token = '{$access_token}', refresh_token = '{$result_arr['refresh_token']}'";
					$this->db->query($sql);
				}
			} elseif( isset($result_arr['error']) ){
				print_r($result_arr);
			}
			
		}
		
		return $access_token;
	}
	
	// remote_drive_store : store file on remote drive
	function remote_drive_store($local_file, $remote_filename, $dir_id = 0)
	{
		global $base_dir;
		
		// box api endpoints
		$upload_endpoint = "https://upload.box.com/api/2.0/files/content";
		$list_endpoint = "https://api.box.com/2.0/folders/{$dir_id}/items";
		
		// retrieve new box api access token
		$access_token = $this->get_access_token();
		if($access_token == ''){
			echo "[error : failed to acquire access token]";
			exit;
		}
		
		// set data
		$headers = array("Authorization: Bearer {$access_token}");
		$attributes = array('name' => $remote_filename, 'parent' => array('id' => $dir_id));
		$file = class_exists('CURLFile') ? new CURLFile($local_file) : "@{$local_file}";
		$fields = array('attributes' => json_encode($attributes), 'file' => $file);
		
		// remove file if already exists ?
		$result = $this->get_data($list_endpoint, $headers);
		$list_data = json_decode($result, true);
		$overwrite_file_id = 0;
		if( isset($list_data['entries']) && isset($list_data['total_count']) && $list_data['total_count'] > 0 ){
			foreach($list_data['entries'] as $entry){
				if($entry['name'] == $remote_filename){
					$overwrite_file_id = $entry['id'];
					break;
				}
			}
		}
		if($overwrite_file_id > 0){
			$delete_endpoint = "https://api.box.com/2.0/files/{$overwrite_file_id}";
			$this->request_data($delete_endpoint, $headers, array(), 'DELETE');
		}
		
		// transfer file
		$result = $this->request_data($upload_endpoint, $headers, $fields);
		//print_r($result);
	}
	
	function store_emails($emails_set = array(), $offer_id = 0, $terms_id = 0, $email_tag = '')
	{
		// vars
		$time = time();
		
		if( !is_array($emails_set) ){
			$this_email = $emails_set;
			$emails_set = array($this_email);
		}
		
		if( !empty($emails_set) ){
			$this->db->set_db($this->collections_db);
	
			// insert new emails
			foreach($emails_set as $email){
				
				$exists_arr = $this->db->get_arr("SELECT * FROM emails WHERE email_address = '{$email}' AND offer_id = {$offer_id}");
				if( empty($exists_arr) ){
					$sql = "INSERT INTO emails SET
							email_address = '{$email}',
							email_createdate = {$time}";
					$sql .= ($offer_id > 0) ? ", offer_id = {$offer_id}" : '';
					$sql .= ($terms_id > 0) ? ", terms_id = {$terms_id}" : '';
					$sql .= ($email_tag > 0) ? ", email_tags = ':{$email_tag}:'" : '';
					
					$this->db->query($sql);
				}
					
			}
		}
	}	
	
	function mine_emails($search_term, $email_domain = '@gmail.com', $cycles = 32)
	{		
		// vars
		$search_param = urlencode("{$search_term} {$email_domain}");
		$set_max = 18 * $cycles;
		$interval = 18;
		$set_init = 0;
		$streak_timeout = 5; // if X blank result cycles, return
		$emails_set = array();
		
		echo "\n\n";
		$empty_streak = 0;
		for($qsi = $set_init; $qsi < $set_max; $qsi += $interval){
			$this_emails_set = array();
			
			echo $url = "http://www.webcrawler.com/search/web?qsi={$qsi}&fcoid=417&fcop=topnav&fpid=2&q={$search_param}&ql=";
			echo " : \n";
			$html = $this->get_data($url);
		
			preg_match_all("/[A-Z0-9._%+-]+{$email_domain}/iU", $html, $matches);
			foreach($matches[0] as $email_match){
				if( !in_array($email_match, $emails_set) ){
					$result_email = trim(strtolower($email_match));
					
					$emails_set[] = $result_email;
					$this_emails_set[] = $result_email;
					echo "\n\t{$email_match}";
				}
			}
			
			if( empty($this_emails_set) ){
				$empty_streak++;
				if($empty_streak >= $streak_timeout){
					return $emails_set;
				}
			} else {
				$empty_streak = 0;
			}
			
			sleep(rand(2,5));
			echo "\n\n";
		}
		
		return $emails_set;
		
	}
	
	// run_miner : run regular data collection queue miner
	function run_miner()
	{
		global $schedule;
		if( !isset($schedule) ){ $schedule = new schedule(); }
		
		// vars
		$time = $schedule->time();
		
		// set db
		$this->db->set_db($this->collections_db);
		
		// TODO - reset solution : (set this up later if needed) reset terms_minedate & terms_mineresults if older than terms_minecycle
		// current reset solution : reset all terms if everything has been mined
		$count_sql = "SELECT COUNT(*) AS count FROM offers_terms WHERE terms_minedate = 0";
		$count_arr = $this->db->get_arr($count_sql);
		if($count_arr[0]['count'] == 0){
			$this->db->query("UPDATE offers_terms SET terms_mineresults = 0, terms_minedate = 0");
		}
		
		// get mineable terms
		$sql = "SELECT * FROM offers_terms WHERE terms_minedate = 0 
				ORDER BY RAND()
				LIMIT 0, {$this->per_mine_query_max}";
		$arr = $this->db->get_arr($sql);
		$terms_count = 0;
		foreach($arr as $row){
			
			$terms_id = $row['terms_id'];
			$search_term = $row['terms_text'];
			$offer_id = $row['offer_id'];
			
			foreach($this->email_domains as $email_domain){
				echo "[{$search_term} / {$email_domain}]\n";
				
				$time = $schedule->time();
				$num_results = 0;
				
				$emails_set = $this->mine_emails($search_term, $email_domain);
				if( !empty($emails_set) ){
					$num_results = count($emails_set);
					$this->store_emails($emails_set, $offer_id, $terms_id);
				}
				$update_sql = "UPDATE offers_terms 
							   SET terms_minedate = {$time}, terms_mineresults = {$num_results}
							   WHERE terms_id = {$terms_id}";
				$this->db->query($update_sql);
				
				if($num_results > 0){
					$terms_count++;
				}
			}
			
			if($terms_count >= $this->per_mine_max){
				break;
			}
		}
		
		
	}
	
	// send_offers : sends email offers 
	function send_offers()
	{
		global $schedule, $email;
		if( !isset($schedule) ){ $schedule = new schedule(); }
		if( !isset($email) ){ $email = new email(); }
		
		// vars
		$time = $schedule->time();
		
		// set db
		$this->db->set_db($this->collections_db);
		
		// get active offers clause
		$active_offers_arr = $this->db->get_arr("SELECT offer_id FROM offers WHERE offer_active = 'on'");
		$active_offers_clause = '';
		$comma = '';
		foreach($active_offers_arr as $datum){
			$active_offers_clause .= "{$comma}{$datum['offer_id']}";
			$comma = ',';
		}
		if( empty($active_offers_arr) ){ 
			echo 'error : no active offers';
			return;
		}
		
		// reset emails_sentdate if older than repeat_email_duration
		$reset_sql = "UPDATE emails SET email_sentdate = 0 WHERE email_sentdate > 0 AND ({$time} - email_sentdate) > {$this->repeat_email_duration}";
		$this->db->query($reset_sql);
		
		// iterate over available sender email accounts
		$account_arr = $this->db->get_arr("SELECT * FROM emails_accounts WHERE account_active = 'on' LIMIT 0, {$this->max_email_accounts}");
		foreach($account_arr as $acct){
			$account_id = $acct['account_id'];
			$this_active_offers_clause = $active_offers_clause;
			if($acct['offer_id'] > 0){
				$this_active_offers_clause = $acct['offer_id'];
			}
			
			$sql = "SELECT * FROM emails 
					LEFT JOIN offers ON offers.offer_id = emails.offer_id
					LEFT JOIN emails_templates ON emails_templates.template_id = offers.template_id
					WHERE emails.email_sentdate = 0 
					AND emails.offer_id IN ({$this_active_offers_clause})
					ORDER BY RAND()
					LIMIT 0, {$this->per_account_max}";
			$emails_arr = $this->db->get_arr($sql);
			foreach($emails_arr as $row){
				
				$time = $schedule->time();
				
				$email->to = $row['email_address'];
				$email->subject = $row['template_subject'];
				$email->body = $row['template_content'];
				
				if( $email->account_send($account_id) ){
					echo "[email sent to : {$email->to}]\n";
					$this->db->query("UPDATE emails SET email_sentdate = {$time} WHERE email_id = {$row['email_id']}");
				}
				
			}
		
		}
		
	}
		
} // end class collection