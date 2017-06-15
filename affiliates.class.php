<?php
// users.class.php : users class

class affiliates
{
	// members
	var $db;
	var $affiliate_id;
	var $affiliates_domain = 'affiliates.franchiseball.com';
	var $processing_monthday = '5'; // default : payments transfered/sent on 5th of the month
	var $default_commission_pct = 20;
	var $default_commission_scope = 'subscriptions';
	var $default_commission_duration = '2-years';
	var $default_payment_pref = 'check_manual_mail';
	var $default_payment_freq = 'monthly-10'; // monthly with min of 10$ earned
	var $default_approved = 'yes';
	var $default_active = 'on';
	
	var $payment_freq_val_hash = array(
		'monthly-10' => 10,
		'monthly-100' => 100
	);
	
	var $commission_duration_hash = array(
		'1-year' => 31536000,
		'2-years' => 63072000,
		'5-years' => 157680000,
		'10-years' => 315360000
	);
	
	// constructor
	function affiliates()
	{
		// globals
		global $db;
		
		// connections
		$this->db = $db; 
		
	} // end constructor
	
	function do_login($affiliate_row = array())
	{
		if( !empty($affiliate_row) ){
			if( isset($affiliate_row['affiliate_id']) && isset($affiliate_row['affiliate_email']) ){
				$_SESSION['affiliate_login'] = $affiliate_row['affiliate_id'];
				$_SESSION['affiliate_email'] = $affiliate_row['affiliate_email'];			
			}
		}
	}

	function do_logout()
	{
		if( isset($_SESSION['affiliate_login']) ){
			unset($_SESSION['affiliate_login']);
		}
		if( isset($_SESSION['affiliate_email']) ){
			unset($_SESSION['affiliate_email']);
		}
	}	
	
	function get_affiliate_data($affiliate_id = 0)
	{
		if($affiliate_id == 0){
			if( isset($_SESSION['affiliate_login']) ){
				$affiliate_id = $_SESSION['affiliate_login'];
			}
		}
		
		$sql = "SELECT * FROM affiliates WHERE affiliate_id = {$affiliate_id}";
		$data = $this->db->get_arr($sql);
		return $data[0];
	}
	
	function trigger_commission($affiliate_id = 0, $product_charge = 0, $payment_id = 0, $type = 'purchase')
	{
		if($affiliate_id == 0){
			if( isset($_SESSION['affiliate_login']) ){
				$affiliate_id = $_SESSION['affiliate_login'];
			}
		}		
		
		if(($payment_id > 0) && ($product_charge > 0)){
			$affiliate_data = $this->get_affiliate_data($affiliate_id);
			
			if( isset($affiliate_data['affiliate_commission_pct']) && $affiliate_data['affiliate_commission_pct'] > 0 ){
				$commission_amt = $product_charge * ($affiliate_data['affiliate_commission_pct'] / 100);
				$commission_amt = number_format($commission_amt, 2);
				
				$insert_sql = "INSERT INTO affiliates_commissions SET 
					affiliate_id = {$affiliate_id},
					payment_id = {$payment_id},
					commission_amount = {$commission_amt},
					commission_status = 'unpaid',
					commission_type = '{$type}'
				";
				$this->db->query($insert_sql);
			}
		}
	}
	
	function get_duration_times($duration_slug = 'all', $time = 0)
	{
		// all time : default
		$start_time = 0;
		$end_time = 0;
		
		// this month
		if($duration_slug == 'this-month'){
			$date_str = date('F Y', $time);
			$start_time = strtotime($date_str);
			$end_time = $time;
				
		// last month
		} elseif($duration_slug == 'last-month') {
			$date_str = date('F Y', $time);
			$end_time = strtotime($date_str) - 1;
			$start_time = strtotime($date_str . ' - 1 month');
				
		// this year
		} elseif($duration_slug == 'this-year') {
			$date_str = 'January 1st ' . date('Y', $time);
			$start_time = strtotime($date_str);
			$end_time = $time;
				
		// last year
		} elseif($duration_slug == 'last-year') {
			$date_str = 'January 1st ' . date('Y', $time);
			$end_time = strtotime($date_str) - 1;
			$start_time = strtotime($date_str . ' - 1 year');
		}
		
		return array('start' => $start_time, 'end' => $end_time);
		
	}
	
	function get_activity_data($limit = 10, $page = 0, $affiliate_id = 0, $start_time = 0, $end_time = 0, $counts = false)
	{
		if( !isset($schedule) ){ $schedule = new schedule(); }
		$time = $schedule->time();
		
		if($affiliate_id == 0){
			if( isset($_SESSION['affiliate_login']) ){
				$affiliate_id = $_SESSION['affiliate_login'];
			}
		}
		
		if($end_time == 0){
			$end_time = $time;
		}
		$time_clause = "payments.payment_time <= {$end_time}";
		
		if($start_time != 0){
			$time_clause .= " AND payments.payment_time >= {$start_time}";
		}
		
		$start = $page * $limit;
		$limit_clause = " LIMIT {$start}, {$limit}";
		if($limit == 0){
			$limit_clause = '';
		}
		
		$select_clause = "*";
		if($counts){
			$select_clause = "SUM(commission_amount) AS earnings, COUNT(*) AS transactions";
		}
		
		$sql = "SELECT {$select_clause} FROM affiliates_commissions
				LEFT JOIN payments ON payments.payment_id = affiliates_commissions.payment_id
				WHERE affiliate_id = {$affiliate_id} AND {$time_clause}
				ORDER BY payments.payment_time DESC
				{$limit_clause}";
		$data = $this->db->get_arr($sql);
		
		return $data;
	}
	
	function get_duration_activity_data($duration_slug = '', $affiliate_id = 0, $counts = false, $limit = 0, $page = 0)
	{
		if( !isset($schedule) ){ $schedule = new schedule(); }
		$time = $schedule->time();
	
		if($affiliate_id == 0){
			if( isset($_SESSION['affiliate_login']) ){
				$affiliate_id = $_SESSION['affiliate_login'];
			}
		}
	
		$times_arr = $this->get_duration_times($duration_slug, $time);
		$start_time = $times_arr['start'];
		$end_time = $times_arr['end'];
		
		$data = $this->get_activity_data($limit, $page, $affiliate_id, $start_time, $end_time, $counts);
		return $data;
	}	
	
	function get_duration_activity_total($duration_slug = '', $affiliate_id = 0)
	{
		if($affiliate_id == 0){
			if( isset($_SESSION['affiliate_login']) ){
				$affiliate_id = $_SESSION['affiliate_login'];
			}
		}		
		
		$count_data = $this->get_duration_activity_data($duration_slug, $affiliate_id, true);
		$count_data[0]['earnings'] = ($count_data[0]['earnings'] == '') ? 0 : $count_data[0]['earnings'];
		return $count_data[0];		
	}
	
	function ordinal($cdnl){
		$test_c = abs($cdnl) % 10;
		$ext = ((abs($cdnl) %100 < 21 && abs($cdnl) %100 > 4) ? 'th'
				: (($test_c < 4) ? ($test_c < 3) ? ($test_c < 2) ? ($test_c < 1)
						? 'th' : 'st' : 'nd' : 'rd' : 'th'));
		return $cdnl.$ext;
	}	
	
	function get_balance($affiliate_id = 0)
	{
		if($affiliate_id == 0){
			if( isset($_SESSION['affiliate_login']) ){
				$affiliate_id = $_SESSION['affiliate_login'];
			}
		}
		
		$balance = 0;
		$sql = "SELECT SUM(commission_amount) AS balance
				FROM affiliates_commissions 
				WHERE affiliate_id = {$affiliate_id} AND commission_status = 'unpaid'";
		$balance_arr = $this->db->get_arr($sql);
		if( !empty($balance_arr) ){
			$balance = $balance_arr[0]['balance'];
		}
		
		return $balance;
	}
	
	function get_balance_due_msg($affiliate_id = 0) 
	{
		if( !isset($schedule) ){ $schedule = new schedule(); }
		$time = $schedule->time();
		
		if($affiliate_id == 0){
			if( isset($_SESSION['affiliate_login']) ){
				$affiliate_id = $_SESSION['affiliate_login'];
			}
		}
		
		$pref = 'check_manual_mail';
		$freq = 10;
		$sql = "SELECT affiliate_payment_pref, affiliate_payment_freq FROM affiliates WHERE affiliate_id = {$affiliate_id}";
		$pref_arr = $this->db->get_arr($sql);
		if( !empty($pref_arr) ){
			$pref = $pref_arr[0]['affiliate_payment_pref'];
			if( isset($this->payment_freq_val_hash[$pref_arr[0]['affiliate_payment_freq']]) ){
				$freq = $this->payment_freq_val_hash[$pref_arr[0]['affiliate_payment_freq']];
			}
		}		
		
		$message = '';
		
		$balance = $this->get_balance($affiliate_id);
		if($balance < $freq){
			$freq_str = '$' . number_format($freq, 2);
			$message = "You must have a balance of {$freq_str} or more to recieve this month's earnings";
		} else {
			
			// set transfer string
			$today_dayofmonth = date('j', $time);
			$transfer_date_str = date('F', $time);
			if($today_dayofmonth > $this->processing_monthday){
				$transfer_date_str = date('F', strtotime('today + 1 month', $time));
			}
			$transfer_date_str .= ' ' . $this->ordinal($this->processing_monthday);
			
			$payment_amt = '$' . number_format($balance, 2);
			$message = "Next payment of {$payment_amt} will be processed and ";
			if($pref == 'check_manual_mail'){
				$message .= "mailed ";
			} elseif($pref == 'stripe_transfer') {
				$message .= "transferred to your bank account ";
			} elseif($pref == 'paypal_manual') {
				$message .= "transferred via paypal to you ";
			}
			$message .= $transfer_date_str;
			
			// duedate_month_day
		}
		
		return $message;
	}
	
	function generate_affiliate_keycode($affiliate_id) {
		
		$new_hash = $affiliate_id.'-';
		for($i = 0; $i < 8; $i++){
			$char = '0';
			if(rand(1,3) == 1){ 
				$char = chr( rand(48, 57) );
			} else {
				$char = chr( rand(97, 122) );
			}
			$new_hash .= $char;
		}
		
		return $new_hash;
	}
	
	function get_affiliate_ref_url($affiliate_id = 0)
	{
		global $domain;
		
		$url = '';
		
		if($affiliate_id == 0){
			if( isset($_SESSION['affiliate_login']) ){
				$affiliate_id = $_SESSION['affiliate_login'];
			}
		}
		
		if( isset($domain) ){
			$sql = "SELECT affiliate_keycode FROM affiliates WHERE affiliate_id = {$affiliate_id}";
			$arr = $this->db->get_arr($sql);
			if( !empty($arr) && isset($arr[0]['affiliate_keycode']) ){
				$keycode = $arr[0]['affiliate_keycode'];
				$url = "http://{$domain}?aff={$keycode}";
			}
		}
		
		return $url;
	}
	
	/*
	function send_welcome_email()
	{
		global $domain;
		$email = new email();
		
		//return; // TODO : remove (this was slowing down the user creation process, needs to be offloaded somewhere else)
		
		// send welcome email
		$email->from = "franchiseball@gmail.com";
		$email->to = $this->values['franchise_email'];
		$email->subject = "Welcome! The {$this->values['franchise_name']} franchise has been initialized";
		$email->body = "
Your new franchise has begun!
Time to set things up : http://{$domain}/start.php

- Franchise Ball
";
		
		// if mail transmission successful : goto getting started page
		$email->simple_send();
	}
	*/

} // end class affiliates
