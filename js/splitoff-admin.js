jQuery(function($) {
	$('select#woocommerce_splitoff_testmode')
		.on('change', function(event) {
			if ($(this).val() != 'production') {
				$('input#woocommerce_splitoff_prod-id').closest('tr').hide();
				$('input#woocommerce_splitoff_prod-secret-key').closest('tr').hide();
				$('input#woocommerce_splitoff_test-id').closest('tr').show();
				$('input#woocommerce_splitoff_test-secret-key').closest('tr').show();
			} else {
				$('input#woocommerce_splitoff_prod-id').closest('tr').show();
				$('input#woocommerce_splitoff_prod-secret-key').closest('tr').show();
				$('input#woocommerce_splitoff_test-id').closest('tr').hide();
				$('input#woocommerce_splitoff_test-secret-key').closest('tr').hide();
			}
		})
		.trigger('change');

	$('input#woocommerce_splitoff_show-info-on-category-pages')
		.on('change', function(event) {
			if ($(this).is(':checked')) {
				$('input#woocommerce_splitoff_category-pages-info-text').closest('tr').show();
			} else {
				$('input#woocommerce_splitoff_category-pages-info-text').closest('tr').hide();
			}
		})
		.trigger('change');

	$('input#woocommerce_splitoff_show-info-on-product-pages')
		.on('change', function(event) {
			if ($(this).is(':checked')) {
				$('input#woocommerce_splitoff_product-pages-info-text').closest('tr').show();
			} else {
				$('input#woocommerce_splitoff_product-pages-info-text').closest('tr').hide();
			}
		})
		.trigger('change');
	
	$('a#reset-to-default-link').on('click',function(event){
		$.ajax(
		{
			type: "post",
			url: splitoff_ajax_object.ajax_url,
			data: {'action': 'splitoff_action'},
			success: function(response){
				$.each(response,function(index, element){
					var attr_type=$("#woocommerce_splitoff_"+index).attr("type");
					
					if(attr_type == 'text'  || attr_type=='textarea' || attr_type=='number'){
						$("#woocommerce_splitoff_"+index).val(element);
					}
					else if(attr_type == "checkbox"){
						if(element == 'yes'){
							$("#woocommerce_splitoff_"+index).prop('checked', true);
						}
						else{
							$("#woocommerce_splitoff_"+index).prop('checked', false);
						}
					}
					else{
						tinymce.get(index.replace(/-/g, "")).setContent(element);
					}
				});
				alert('Customisations have now been reset to defaults. Please review and click "Save Changes" to accept the new values.');
			}
		});
	});
	
	$('a.splitoff-notice-dismiss').on('click',function(event){
		var review_link;
		if(this.hasAttribute('href')){
			review_link = $(this).attr("href");
			event.preventDefault();
		}
		var noticeClass = $(this).attr("class");
		$.ajax(
		{
			type: "post",
			url: splitoff_ajax_object.ajax_url,
			data: {'action': 'splitoff_notice_dismiss_action'},
			success: function(response){
				if(response){
					if(noticeClass.includes("splitoff_rate_redirect")){
						$(".splitoff-rating-notice").hide();
						window.open(review_link,"_blank");
					}
					else{
						location.reload();
					}
				}
			}
		});
	});
});
