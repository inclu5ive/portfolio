<?php 
// comment.class.php : handles commenting display permissions & functionality

class comment
{
	// members
	var $db;
	var $team_id = 0;
	var $type = '';
	var $type_id = 0;
	var $offset = 0;
	var $num = 10;
	var $total = '';
	var $page = 1;
	var $spam_strike_limit = 3;
	var $alert_types = array('division', 'game', 'team');
	var $ban_date = 0;
	
	var $overpost_post_limit = 3;
	var $overpost_sec_limit = 60;

	// constructor
	function __construct($type = '', $type_id = 0, $offset = 0, $num = 0, $team_id = 0)
	{
		// globals
		global $db, $team, $schedule;
		if( !isset($team) ){ $team = new team(); }
		if( !isset($schedule) ){ $schedule = new schedule(); }
		
		// connections
		$this->db = $db;
		
		$time = $schedule->time();
		$this->team_id = $team_id == 0 ? $team->get_primary_team() : $team_id;
		$this->type = $type == '' ? 'none' : $type;
		$this->type_id = $type_id;
		$this->offset = $offset;
		$this->num = ($num == 0) ? $this->num : $num;
		
		// is host allowed ?
		$allowed_arr = $this->db->get_arr("SELECT * FROM hosts WHERE (host_ip = '{$_SERVER['REMOTE_ADDR']}' OR team_id = {$this->team_id}) AND host_bantype = 'comments' AND {$time} < host_allowdate");
		if( !empty($allowed_arr) && isset($allowed_arr[0]['host_allowdate']) ) {
			$this->ban_date = intval($allowed_arr[0]['host_allowdate']);
		}
	}
	
	function request_post()
	{
		global $schedule;
		if(!isset($schedule)){ $schedule = new schedule(); }
		$json = array('error' => 'Unknown Error');

		$post_data_exists = (isset($_POST['content']) && isset($_POST['id']) && isset($_POST['id']));
		if($post_data_exists && ($_POST['content'] != '') && ($_POST['type'] != '') && ($_POST['id'] != '') && is_numeric($_POST['id']) ){
			
			$time = $schedule->time();
			$is_allowed = !isset($_POST['ban_date']);
			$this->type = $_POST['type'];
			$this->type_id = $_POST['id'];
			
			if($is_allowed) {
			
				// check for team/user over-frequent commenting
				// (default : 3 in last 60 seconds per team/user : will not post)
				$overpost_time = $time - $this->overpost_sec_limit;
				$sql = "SELECT * FROM comments 
						WHERE team_id = {$this->team_id}
						AND comment_type = '{$this->type}'
						AND comment_type_id = {$this->type_id}
						AND comment_date >= {$overpost_time}
						ORDER BY comment_date DESC";
				$recent_results = $this->db->get_arr($sql);
				
				// check if within overpost limit ?
				if(count($recent_results) < $this->overpost_post_limit){
					
					// post comment
					$content = trim($_POST['content']);
					$content = filter_var($content, FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH);
					$content = mysql_real_escape_string($content);
					
					// ignore if repeat post
					if( isset($recent_results[0]['comment_content']) && ($recent_results[0]['comment_content'] == $content) ) {
						$json['error'] = 0; // ignore
					} else {
						$sql = "INSERT INTO comments SET
								team_id = {$this->team_id},
								comment_type = '{$this->type}',
								comment_type_id = {$this->type_id},
								comment_content = '{$content}',
								comment_date = {$time}";
						if( $this->db->query($sql) ){
							$json['error'] = 0;
							
							$comment_id = mysql_insert_id();
							if( in_array($this->type, $this->alert_types) ){
								$this->trigger_alert();
							}
						}
					}
					
				} else {
					$json['error'] = 'Too many recent posts.  Please slow down and try again later.';
				}
			
			} else {
				$allow_date = (isset($_POST['ban_date']) && $_POST['ban_date'] != '') ? date('r', $_POST['ban_date']) : '';
				$json['error'] = ($allow_date != '') ? "Host is temporarily banned from commenting until {$allow_date}" : 'Host temporarily banned from commenting';
			}
			
		} else {
			$json['error'] = 'Post Failed : Missing Information';
		}
		
		echo json_encode($json);
	}

	function get_total()
	{
		if($this->total != ''){
			return $this->total;
		}
		
		$sql = "SELECT COUNT(*) AS total FROM comments 
				LEFT JOIN teams ON teams.team_id = comments.team_id
				WHERE comment_type = '{$this->type}' AND comment_type_id = {$this->type_id}
				ORDER BY comment_date DESC";
		$res = $this->db->query($sql);
		if($res && mysql_num_rows($res) > 0){
			$this->total = mysql_result($res, 0, 'total');
		}
		
		return $this->total;
	}
	
	function display_input_box()
	{
		$init_coment_str = "New Comment";

		?>
		<div id="content-comments-input">
			<input id="comments-input-box" class="reset-mode" type="text" value="<?php echo $init_coment_str; ?>" maxlength="250" />
			<input id="comments-post-button" class="statbox-options-button" type="button" value="Post" style="width:52px; margin:6px 0; font-size:10px; padding:4px;" />
		</div>
		<script type="text/javascript">
			$('.reset-mode').click(function(){
				if( $(this).hasClass('reset-mode') ){
					$(this).val('').removeClass('reset-mode');
				}
			});
			
			$('#comments-post-button').click(function(){
				blank_err = 'Comment is blank';
				
				if( $('#comments-input-box').hasClass('reset-mode') ){ 
					alert(blank_err);
					return; 
				}
				if( $('#comments-input-box').val() != '' ){

					post_url = '/request.php?c=comment&f=post';
					post_data = { 'type':'<?php echo $this->type; ?>', 'id':'<?php echo $this->type_id; ?>', 'content':$('#comments-input-box').val() };
					<?php if($this->ban_date > 0) { ?>
						post_data.ban_date = <?php echo $this->ban_date; ?>;
					<?php } ?>
					
					$.post(post_url, post_data, function(data){
					 	if(data.error != 0){
					 		alert(data.error);
					 	} else {
					 		$('#comments-input-box').addClass('reset-mode').val('<?php echo $init_coment_str; ?>');
						 	feed_url = '/request.php?c=comment&f=feed&type=<?php echo $this->type; ?>&id=<?php echo $this->type_id; ?>';

						 	load_comments(feed_url, function(){
					 			if(($('#content-comments-pagination').css('display') == 'none') && ($('#content-comments-feed > div').length >= <?php echo $this->num; ?>)){
									$('#content-comments-pagination').css('display', 'block');
					 			}
					 		});
						}
					}, 'json');
					
				} else {
					alert(blank_err);
				}
			});

			function reload_feed(obj){
				page_str = '';
				page = 0;
				if(obj && obj.length > 0){ page = $(obj).val(); }
				if(page > 0){ page_str = ('&page=' + page); }

		 		feed_url = '/request.php?c=comment&f=feed&type=<?php echo $this->type; ?>&id=<?php echo $this->type_id; ?>' + page_str;
		 		load_comments(feed_url, null);				
			}

			function load_comments(feed_url, func){
				$('#content-comments-feed, #content-comments-pagination').css('opacity', 0.55).css('filter', 'alpha(opacity=55)');
				$('.ajax-loader-html').css('display', 'block');
				$('#content-comments-feed-container').load(feed_url, func);
			}

			function mark_spam(comment_id){
				if ( confirm('Flag this comment as spam or inappropriate?') ) {
					url = '/request.php?c=comment&f=flag&id=' + comment_id;
  					$.getJSON(url, function(data) {
					 	if(data.error == 0){
					 		alert('Comment flagged');
					 	}
					});
				}
			}

			function delete_comment(comment_id){
				if ( confirm('Remove comment?') ) {
					url = '/request.php?c=comment&f=delete&id=' + comment_id;
  					$.getJSON(url, function(data) {
					 	if(data.error == 0){
					 		alert('Comment Removed');
					 		reload_feed( $('#comments_selector') );
					 	}
					});
				}
			}
		</script>
		<?php
	}
	
	function get_comments($offset = 0, $num = 0)
	{
		global $schedule;
		if(!isset($schedule)){ $schedule = new schedule(); }
		
		$this->offset = ($offset == 0) ? $this->offset : $offset;
		$this->num = ($num == 0) ? $this->num : $num;
		$sql_extra = '';		
		
		if($this->type == 'game'){
			$time = $schedule->time();
			$season_id = $schedule->get_current_season();
			$start_time_arr = $this->db->get_arr("SELECT * FROM seasons WHERE season_id = {$season_id}");
			if( !empty($start_time_arr) ){
				$season_start_time = strtotime("{$start_time_arr[0]['season_month']}/1/{$start_time_arr[0]['season_year']}");
			} else {
				$date_str = date('n', $time)."/1/".date('Y', $time);
				$season_start_time = strtotime($date_str);
			}
			
			$sql_extra .= " AND comment_date > {$season_start_time}";
		}
		
		$sql = "SELECT * FROM comments 
				LEFT JOIN teams ON teams.team_id = comments.team_id
				WHERE comment_type = '{$this->type}' AND comment_type_id = {$this->type_id}{$sql_extra}
				ORDER BY comment_date DESC
				LIMIT {$this->offset}, {$this->num}";
		$results = $this->db->get_arr($sql);
		
		return $results;
	}
	
	function parse_comment($content)
	{
		// link urls ?
		$content = preg_replace(
			"~https?://[^<>[:space:]]+[[:alnum:]/]~",
			"<a href=\"\\0\" target=\"_blank\">\\0</a>",
			$content
		);
		
		// link hashtags ?
		// ..
		
		return $content;
	}
	
	function request_feed()
	{
		global $schedule;
		if( !isset($schedule) ){ $schedule = new schedule(); }
		
		if( isset($_REQUEST['type']) && isset($_REQUEST['id']) ){
			$this->type = $_REQUEST['type'];
			$this->type_id = $_REQUEST['id'];
		}
		
		if( isset($_REQUEST['page']) && is_numeric($_REQUEST['page']) ){
			$this->page = $_REQUEST['page'];
			$this->offset = ($this->page - 1) * $this->num;
		}
		
		$comments = $this->get_comments();
		$total_comments = $this->get_total();
		$total_pages = ceil($total_comments / $this->num);
		$my_team_page_comment = (($this->type == 'team') && ($this->type_id == $this->team_id));
		
		?>
		<div id="content-comments-feed">
			<?php
			if($total_comments > 0){
				if( !empty($comments) ){
					$count = 0;
					foreach($comments as $comment){
						
						$bin_order = ($count % 2 == 0) ? 'even' : 'odd';
						$time_str = $schedule->get_time_ago_str($comment['comment_date']);
						$time_date_str = date('F j, Y, g:i a', $comment['comment_date']);
						
						?>
						<div class="comment comment-<?php echo $comment['comment_id']; ?> comment-<?php echo $bin_order; ?>">
							<div class="comment-team"><a href="/team.php?id=<?php echo $comment['team_id']; ?>"><?php echo $comment['team_name']; ?></a>&nbsp:&nbsp;</div>
							<div class="comment-content"><?php echo $this->parse_comment($comment['comment_content']); ?></div>
							
							<div class="comment-options">
								<?php if(($comment['team_id'] == $this->team_id) || $my_team_page_comment || ($this->team_id == 1)){ ?>
									<div class="comment-delete" onclick="javascript:delete_comment(<?php echo $comment['comment_id']; ?>);" title="Remove this comment"></div>
								<?php } else { ?>
									<div class="comment-flag" onclick="javascript:mark_spam(<?php echo $comment['comment_id']; ?>);" title="Flag this comment"></div>
								<?php } ?>
								<div class="comment-time"><span title="<?php echo $time_date_str; ?>"><?php echo $time_str; ?></span></div>
							</div>
						</div>
						<?php
						$count++;
					}
					
				} else {
					?>
					<span class="no-comments">No comments in this set</span>
					<?php
				}
			} else {
				?>
				<span class="no-comments">No comments have been posted</span>
				<?php
			}
			?>
		</div>
		
		<?php if($total_pages > 1){ ?>
			<div id="content-comments-pagination">
				<div style="margin-right:10px;">
					<span style="display:block; float:left; margin:7px 2px 0 0;">Page</span>
					<?php echo $this->get_page_selector(); ?>
					<span style="display:block; float:left; margin:7px 0 0 2px;">of <?php echo $total_pages; ?></span>
				</div>
			</div>
		<?php } ?>
		<div class="ajax-loader-html"></div>
		<?php
	}
	
	function trigger_alert()
	{
		global $team;
		if( !isset($team) ){ $team = new team(); }
		$alerts = new alerts();
		$team_name = $team->get_team_full_name($this->team_id);
		
		// supported alert types :
		switch($this->type){
			case 'division' :
				
				$alert_type = 'division_comment';
				
				$sql = "SELECT * FROM standings WHERE division_id = {$this->type_id}";
				$res_arr = $this->db->get_arr($sql);
				foreach($res_arr as $row){
					$this_team_id = $row['team_id'];
					if($this_team_id == $this->team_id){ continue; }
					
					$alert_msg = "{$team_name} commented in division conversation";
					$alert_link = "/standings.php?filter=division&id={$this->type_id}";
					$alerts->set_alert($this_team_id, $alert_type, $this->type_id, $alert_msg, $alert_link);
				}
				
				break;
			case 'game' :

				$alert_type = 'game_comment';
				
				$res_arr = $this->db->get_arr("SELECT game_hometeam_id, game_awayteam_id FROM games WHERE game_id = {$this->type_id}");
				$teams_arr = array($res_arr[0]['game_hometeam_id'] => true, $res_arr[0]['game_awayteam_id'] => true);
				
				$comments_res_arr = $this->db->get_arr("SELECT * FROM comments WHERE comment_type = 'game' AND comment_type_id = {$this->type_id}");
				if( !empty($comments_res_arr) ){
					foreach($comments_res_arr as $row){
						$teams_arr[$row['team_id']] = true;
						
					}
				}
				unset($teams_arr[$this->team_id]);
				foreach($teams_arr as $this_team_id => $bool){
					$alert_msg = "{$team_name} commented in a game conversation";
					$alert_link = "/game.php?&id={$this->type_id}";
					$alerts->set_alert($this_team_id, $alert_type, $this->type_id, $alert_msg, $alert_link);
				}
					
				break;
			case 'team' :
				
				$alert_type = 'team_comment';
				
				$teams_arr = array($this->type_id => true);
				$comments_res_arr = $this->db->get_arr("SELECT * FROM comments WHERE comment_type = 'team' AND comment_type_id = {$this->type_id} ORDER BY comment_date DESC LIMIT 0, 10");
				if( !empty($comments_res_arr) ){
					foreach($comments_res_arr as $row){
						$teams_arr[$row['team_id']] = true;
					}
				}				
				unset($teams_arr[$this->team_id]);
				foreach($teams_arr as $this_team_id => $bool){
					$alert_msg = "{$team_name} commented in a team conversation";
					$alert_link = "/team.php?&id={$this->type_id}";
					$alerts->set_alert($this_team_id, $alert_type, $this->type_id, $alert_msg, $alert_link);
				}
				
				break;
		}
	}	
	
	function get_page_selector()
	{
		$result = '';
		$total_comments = $this->get_total();
		$total_pages = ceil($total_comments / $this->num);
		
		$result .= "<select id=\"comments_selector\" onchange=\"javascript:reload_feed(this);\" style=\"display:block; float:left; margin:6px; font-size:10px; min-width:32px;\">";
		for($i = 1; $i <= $total_pages; $i++){
			$selected_str = ($i == $this->page) ? " SELECTED" : '';
			$result .= "<option value=\"{$i}\"{$selected_str}>{$i}</option>";
		}
		$result .= "</select>";
		
		return $result;
	}
	
	function request_flag()
	{
		$json = array('error' => 'Unknown Error');
		
		if( isset($_REQUEST['id']) && $_REQUEST['id'] != '' && is_numeric($_REQUEST['id']) ){
			$result = $this->db->get_arr("SELECT * FROM comments WHERE comment_id = {$_REQUEST['id']}");
			
			if( !empty($result[0]) ){
				$comment = $result[0];
				$strikes_arr = json_decode($comment['comment_spam_strikes']);
				
				if( ($strikes_arr == '') || !in_array($this->team_id, $strikes_arr) ){
					$new_strikes_num = count($strikes_arr) + 1;
					
					// delete comment if strikes >= limit
					if($new_strikes_num >= $this->spam_strike_limit){
						
						$this->db->query("DELETE FROM comments WHERE comment_id = {$comment['comment_id']}");
						
					// add new strike to comment
					} else {
						
						$strikes_arr[] = $this->team_id;
						$new_strikes_val = json_encode($strikes_arr);
						$this->db->query("UPDATE comments SET comment_spam_strikes = '{$new_strikes_val}' WHERE comment_id = {$comment['comment_id']}");
					}
				}
				
				$json['error'] = 0;
				
			} else {
				$json['error'] = 'Comment does not exist';
			}
		}
		
		echo json_encode($json);
	}
	
	function request_delete()
	{
		global $team;
		if( !isset($team) ){ $team = new team(); }
		
		$json = array('error' => 'Unknown Error');
		$logged_team_id = $team->get_primary_team();
		
		if( isset($_REQUEST['id']) && $_REQUEST['id'] != '' && is_numeric($_REQUEST['id']) ){
			$result = $this->db->get_arr("SELECT * FROM comments WHERE comment_id = {$_REQUEST['id']}");
			
			if( !empty($result[0]) ){
				$comment = $result[0];
				$my_team_page_comment = (($comment['comment_type'] == 'team') && ($comment['comment_type_id'] == $this->team_id));
				
				if(($comment['team_id'] == $this->team_id) || $my_team_page_comment || ($logged_team_id == 1)){
					$this->db->query("DELETE FROM comments WHERE comment_id = {$comment['comment_id']}");
					$json['error'] = 0;
				} else {
					$json['error'] = 'You may only remove your own comments';
				}
			} else {
				$json['error'] = 'Comment does not exist';
			}
		}
		
		echo json_encode($json);
	}	
	
} // end class comments

?>