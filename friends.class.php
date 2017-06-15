<?php
// friends.class.php : teams friends management and operations class

class friends  
{
	var $logged_in_team = 0;
	
	// constructor
	function __construct()
	{
		global $db, $team;
		if( !isset($team) ){ $team = new team(); }
		$this->db = $db;
		
		$this->logged_in_team = $team->get_primary_team();
	}

	// is_friend : team_id optional, =0 default to logged in team
	function is_friend($friend_team_id, $team_id = 0)
	{ 
		if($team_id == 0){
			$team_id = $this->logged_in_team;
		}
		
		// team = friends with self
		if($friend_team_id == $team_id){
			return true;
		}
		
		$sql = "SELECT 1 FROM friendships 
				WHERE (team_id = {$team_id} OR friendship_team_id = {$team_id}) 
				  AND (team_id = {$friend_team_id} OR friendship_team_id = {$friend_team_id}) 
				  AND friendship_accept_date > 0 AND blocked_by = 0";
		$result = $this->db->get_arr($sql);
		
		if(count($result) > 0){
			return true;
		} else {
			return false;
		}
	}
	
	// get_friends : gets array of all friends for a given user, =0 default to logged in team
	function get_friends($team_id = 0, $all_data = false)
	{
		$results = array();
		if($team_id == 0){ $team_id = $this->logged_in_team; }
		
		$myteam_select_sql = 'team_id';
		$team_select_sql = 'friendship_team_id';
		$myteam_join_sql = '';
		$team_join_sql = '';
		$order_sql = '';
		
		if($all_data){
			$myteam_select_sql = '*';
			$team_select_sql = '*';
			$myteam_join_sql = 'LEFT JOIN teams ON teams.team_id = friendships.team_id LEFT JOIN locales ON locales.locale_id = teams.locale_id';
			$team_join_sql = 'LEFT JOIN teams ON teams.team_id = friendships.friendship_team_id LEFT JOIN locales ON locales.locale_id = teams.locale_id';
			$order_sql = 'ORDER BY locale_name, team_name';
		}
		
		$sql = "SELECT {$myteam_select_sql} FROM friendships
				{$myteam_join_sql}
				WHERE friendships.friendship_team_id = {$team_id} AND friendship_accept_date > 0 AND blocked_by = 0
				UNION 
				SELECT {$team_select_sql} FROM friendships
				{$team_join_sql}
				WHERE friendships.team_id = {$team_id} AND friendship_accept_date > 0 AND blocked_by = 0
				{$order_sql}";
		$res_arr = $this->db->get_arr($sql);
		foreach($res_arr as $friendship){
			if($all_data){
				$results[] = $friendship;
			} else {
				$results[] = $friendship['team_id'];
			}
		}
				
		return $results;
	}
	
	// add friend request : team_id optional, =0 default to logged in team, confirm_accept:true = bypass 'request' & make friend
	function add_friend($add_team_id, $team_id = 0, $do_accept = false, $set_alert = true)
	{
		global $team, $schedule, $alerts;
		if( !isset($team) ){ $team = new team(); }
		if( !isset($schedule) ){ $schedule = new schedule(); }
		if( !isset($alerts) ){ $alerts = new alerts(); }
		$time = $schedule->time();
		
		if($team_id == 0){
			$team_id = $this->logged_in_team;
		}
		
		$add_sql = "INSERT INTO friendships (team_id, friendship_team_id, friendship_request_date) VALUE ({$team_id}, {$add_team_id}, {$time})";
		if( !$this->_pending_friend($add_team_id, $team_id) && !$this->is_friend($add_team_id, $team_id) ){
			$this->db->query($add_sql);
			
			if( !$do_accept ){
				
				// set alert (friend request alert)
				if($set_alert){
					$team_name = $team->get_team_full_name($team_id);
					$alert_msg = "New friend request from {$team_name}";
					$alert_link = "/friend_request.php?id={$team_id}";
					$alerts->set_alert($add_team_id, 'friend_request', $team_id, $alert_msg, $alert_link);
				}
				
			} else {
				$this->accept_friend($add_team_id, $team_id, $set_alert);
			}
		}
	}
	
	function accept_friend($add_team_id, $team_id = 0, $set_alert = true)
	{
		global $schedule, $alerts, $team;
		if( !isset($schedule) ){ $schedule = new schedule(); }
		if( !isset($alerts) ){ $alerts = new alerts(); }
		if( !isset($team) ){ $team = new team(); }
		$time = $schedule->time();
		
		if($team_id == 0){
			$team_id = $this->logged_in_team;
		}
		
		$res_arr = $this->db->get_arr("SELECT * FROM friendships WHERE (team_id = {$add_team_id} AND friendship_team_id = {$team_id}) OR (team_id = {$team_id} AND friendship_team_id = {$add_team_id})");
		if( !empty($res_arr) ){
			if($res_arr[0]['team_id'] == $team_id){
				$update_sql = "UPDATE friendships SET friendship_accept_date = {$time} WHERE team_id = {$team_id} AND friendship_team_id = {$add_team_id}";
			} else {
				$update_sql = "UPDATE friendships SET friendship_accept_date = {$time} WHERE team_id = {$add_team_id} AND friendship_team_id = {$team_id}";
			}
			
			if( $this->db->query($update_sql) ){
				
				// set alert
				if($set_alert){
					$team_name = $team->get_team_full_name($team_id);
					$alerts->set_alert($add_team_id, 'friend_accept', $team_id, "Friend request accepted from {$team_name}", "/team.php?id={$team_id}");
				}
				
			}
		}
	} 
	
	function remove_friend($remove_team_id, $team_id = 0)
	{
		if($team_id == 0){
			$team_id = $this->logged_in_team;
		}
		
		$sql = "DELETE FROM friendships WHERE 
				(team_id = {$team_id} AND friendship_team_id = {$remove_team_id}) 
				OR (team_id = {$remove_team_id} AND friendship_team_id = {$team_id})";
		$this->db->query($sql);
	}
	
	function decline_friend($decline_team_id, $team_id = 0)
	{
		$this->remove_friend($decline_team_id, $team_id);
	}
	
	function block_friend($block_team_id, $team_id = 0)
	{	
		if($team_id == 0){
			$team_id = $this->logged_in_team;
		}

		$sql = "UPDATE friendships SET blocked_by = {$team_id} 
				WHERE (team_id = {$team_id} OR friendship_team_id = {$team_id}) 
				  AND (team_id = {$block_team_id} OR friendship_team_id = {$block_team_id}) AND blocked_by = 0";
		$this->db->query($sql);
	}
	
	// returns true if team has 
	function _pending_friend($pending_team_id, $team_id = 0)
	{
		if($team_id == 0){
			$team_id = $this->logged_in_team;
		}
		
		$sql = "SELECT 1 FROM friendships 
				WHERE (team_id = {$team_id} OR friendship_team_id = {$team_id}) 
				  AND (team_id = {$pending_team_id} OR friendship_team_id = {$pending_team_id}) 
				  AND friendship_accept_date = 0 AND blocked_by = 0";
	
		$result = $this->db->get_arr($sql);
		
		if(count($result) > 0){
			return true;
		} else {
			return false;
		}
	}
	
} // end class friends


?>