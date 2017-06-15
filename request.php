<?php 
// request.php : ajax request content loader

// includes
include_once('../includes/core.php');

// classes
$db = new db();

// vars
$module = '';
$class = '';
$function = '';
$error_msg = 'error ';

// load module if defined
if(isset($_REQUEST['m']) && $_REQUEST['m'] != ''){
	$module = $_REQUEST['m'];
	$system->load_module($module);
	
	if(isset($_REQUEST['f']) && $_REQUEST['f'] != ''){
		$function = "request_{$_REQUEST['f']}";
		eval("\$ui = new ".$system->get_class('ui')."();");
		
		if( method_exists($ui, $function) ){
			$ui->$function();
		} else {
			echo $error_msg.'1.1';
		}		
	} else {
		echo $error_msg.'1.2';
	}
	
// else : use manually defined loader function	
} else {
	
	// instantiate specified class method
	if(isset($_REQUEST['c']) && $_REQUEST['c'] != ''){		
		$class = $_REQUEST['c'];
				
		if( class_exists($class) ){
			if(isset($_REQUEST['f']) && $_REQUEST['f'] != ''){
				$obj = new $class();
				$function = "request_{$_REQUEST['f']}";
				if( !method_exists($obj, $function) ){
					$function = $_REQUEST['f'];
				}
				
				if( method_exists($obj, $function) ){
					$obj->$function();
				} else {
					echo $error_msg.'2.1';
				}			
			} else {
				echo $error_msg.'2.2';
			}			
		} else {
			
			// try loading class manually
			global $class_dir;
			$formatted_class = str_replace('_', '.', $class);
			$class_path = "{$class_dir}{$formatted_class}.class.php";
			@include_once($class_path);

			if(isset($_REQUEST['f']) && $_REQUEST['f'] != ''){
				$function = $_REQUEST['f'];
				
				if( class_exists($class) ){
					$obj = new $class();
					if( method_exists($obj, $function) ){
						$obj->$function();
					} else {
						echo $error_msg.'2.3';
					}
				} else {
					echo $error_msg.'2.4';
				}
				
			} else {
				echo $error_msg.'2.5';
			}
			
		}		
	} else {
		echo $error_msg.'2.6';
	}
}

// if hook dialog ?
if( isset($_SESSION['dialog']) ){
	unset($_SESSION['dialog']);
}

?>