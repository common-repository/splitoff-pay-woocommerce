<?php
/**
* SplitOff Javascript initialisation code
*/
wp_enqueue_script( 'splitoff_js', plugins_url( 'js/splitoff.js', __FILE__ ), array('jquery') );
?> 
<script type="text/javascript">
	(function(token) {
		var splitoff_js_interval = setInterval(function() {
			if (typeof SplitOff != 'undefined') {
				clearInterval(splitoff_js_interval);

				var api_version = "<?php echo $this->settings['api-version']; ?>";

				if (typeof SplitOff.initialize === "function" && api_version == "v1" ) { 
				    // safe to use the function
				    SplitOff.initialize(<?php if (!is_null($init_object)): echo json_encode($init_object); endif; ?>);
				}
				else {
					SplitOff.init(<?php if (!is_null($init_object)): echo json_encode($init_object); endif; ?>);
				}
				
				SplitOff.<?php echo $lightbox_launch_method; ?>({
					token: token
				});
			}
		}, 200);
	})(<?php echo json_encode($token); ?>);
</script>