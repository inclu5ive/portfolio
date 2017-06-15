$('.list-item').click(function(){
	msg_id = $(this).attr('rel');
	document.location = '/messages.php?id=' + msg_id;
	msg_url = '/request.php?m=messages&f=message&listview=listview&id=' + msg_id;
	
	if( $('#compose') ){
		$('#compose').remove();
		$('#message-compose').css('display', 'none');
	}
	
	if( $(this).hasClass('list-item-new') ){
		$(this).removeClass('list-item-new');
	}
	
	$('.list-item').removeClass('list-item-selected');
	$(this).addClass('list-item-selected');
	
	$('.msg').css('opacity', 0.55).css('filter', 'alpha(opacity=55)');
	$('.ajax-loader-html').css('display', 'block');
	$('#message-container').load(msg_url, function(){
		if( $('#head-info.head-inbox') ){
			new_url = '/request.php?m=messages&f=head_info&listview=listview';
			$('#head-info.head-inbox').load(new_url);
		}
	});
});

$('#compose_send').click(function(){

	// check fields
	if($('#msg_type').val() == ''){ return; }
	if($('#rcpt-type-selector').val() == 'team' && $('.team-panel').length == 0){ alert('No recipient(s) selected. Use search box to add recipient'); return; }
	if($('#compose_subject').val() == ''){ alert('Subject is blank. Message must have a subject'); return; }
	if($('#compose_body').val() == ''){ alert('Message body is blank'); return; }
	
	// send msg
	id_arr = [];
	$.each($('.team-panel'), function(val, div) {
		id_arr.push( $(div).attr('rel') );
	});
	post_url = '/request.php?m=messages&f=send';
	post_data = { 
		'type' : $('#rcpt-type-selector').val(), 
		'rcpt' : id_arr, 
		'subject' : $('#compose_subject').val(), 
		'body' : $('#compose_body').val() 
	};
	$.post(post_url, post_data, function(data){
		if(data.error != 0){
			alert(data.error);
		} else {
			alert('Message Sent!');
			document.location = '/messages.php';
		}
	}, 'json');
});

function select_team(id, name){
	if($('#selected-rcpt .team-panel').length == 0){ $('#selected-rcpt').html(''); }
	$('#selected-rcpt').append('<div rel=\"' + id + '\" class=\"team-panel\"><span>' + name + '</span><span class=\"team-panel-close\" onclick=\"javascript:unselect_team(this);\"></span></div>');
}

var select_search_teams = [];
$('#compose_rcpt')
	.autocomplete({
		source: function(req, add) {
			$.getJSON('/request.php?m=schedule&f=search_teamname_data&query=' + req.term, function(data) {
				var select_team_names = [];
				var rcpt_ids = [];
				$.each($('.team-panel'), function(val, div) {
					rcpt_ids.push( $(div).attr('rel') );
				});
				$.each(data, function(key, value) {
					if($.inArray(key, rcpt_ids) < 0){
						select_team_names.push(value);
						select_search_teams[value] = key;
					}
				});
				add(select_team_names); 		     						   						
			});
		},
		select: function(event, ui) {
			search_team_id = select_search_teams[ui.item.value];
			select_team(search_team_id, ui.item.value);
		},
		close: function( event, ui ) {
			$('#compose_rcpt').val('');
		}
	});
	
$('#rcpt-type-selector').change(function(){
	$('#selected-rcpt').html('');
	$('#compose-rcpt-results > div').css('display', 'none');
	rcpt_type = $(this).val();
	$('#rcpt-type-' + rcpt_type).css('display', 'block');
});

// change from page selector
$('#page_selector').change(function() {
	page = $(this).val();
	if(page >= 1){
		url = '/request.php?m=messages&f=message_list&listview=listview&pg=' + page;
	}
	$('#message-list').load(url);
});

