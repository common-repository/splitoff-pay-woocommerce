<?php
/**
 * SplitOff Checkout Instalments Display
 * @var WC_Gateway_SplitOff $this
 */

# Process Region-based Assets
include('assets.php');
$currency               =   get_option('woocommerce_currency');
//print_r(get_option('woocommerce_currency'));
if (!empty($assets[strtolower($currency)])) {
    $region_assets              =   $assets[strtolower($currency)];
    $checkout_page_cta          =   $region_assets['checkout_page_cta'];
    $checkout_page_first_step   =   $region_assets['checkout_page_first_step'];
    $checkout_page_footer       =   $region_assets['checkout_page_footer'];
}
else {
    $checkout_page_cta          =   $assets['aud']['checkout_page_cta'];
    $checkout_page_first_step   =   $assets['aud']['checkout_page_first_step'];
    $checkout_page_footer       =   $assets['aud']['checkout_page_footer'];
}

/*
if ($this->settings['testmode'] != 'production') {
    ?><p class="splitoff-test-mode-warning-text"><?php _e( 'TEST MODE ENABLED', 'woo_splitoff' ); ?></p><?php
}
*/

$order_button_text = !empty($this->order_button_text) ? $this->order_button_text : 'Place order';
?>
<ul class="form-list">
    <li class="form-alt">
        <div class="instalment-info-container" id="splitoff-checkout-instalment-info-container">
            <div class="wrap__popup">
              <div class="tgp__description--popup" style="font-weight: 600; text-align: left;  padding-bottom: 10px;">
        	       Split payment with friends.<br>No fees. No fuss.
	            </div>
              <div class="tgp__list---popup-icons" style="display: flex; flex-direction: column;">
                <div class="tgp__list--popup-item" style="text-align: left; display: flex; ">
                  <div class="tpg__list--popup-icon">
                    <svg width="60px" height="60px" viewBox="0 0 157 157" fill="blue" xmlns="http://www.w3.org/2000/svg">
                      <circle cx="78.0366" cy="78.0366" r="78.0366" fill="white" fill-opacity="0.1"></circle>
                      <path fill-rule="evenodd" clip-rule="evenodd" d="M59.6014 36.3335H96.288C110.56 36.3335 120.028 46.4029 120.028 60.8884V95.112C120.028 109.597 110.56 119.667 96.288 119.667H59.6014C45.3297 119.667 35.8613 109.597 35.8613 95.112V60.8884C35.8613 46.4164 45.3547 36.3335 59.6014 36.3335ZM96.288 42.1474H59.6014C48.6935 42.1474 41.7334 49.5397 41.7334 60.8883V95.1119C41.7334 106.476 48.6703 113.853 59.6014 113.853H96.288C107.219 113.853 114.156 106.476 114.156 95.1119V60.8883C114.156 49.5246 107.219 42.1474 96.288 42.1474Z" fill="#596FA8"></path>
                      <path d="M72.4762 92.7058C71.6733 92.7025 70.9045 92.3876 70.3385 91.8302L61.9999 83.6674C61.431 83.1104 61.1113 82.355 61.1113 81.5673C61.1113 80.7796 61.431 80.0242 61.9999 79.4672C62.5689 78.9103 63.3406 78.5974 64.1452 78.5974C64.9498 78.5974 65.7215 78.9103 66.2905 79.4672L72.4913 85.5374L92.3219 66.1248C92.8908 65.5678 93.6625 65.2549 94.4672 65.2549C95.2718 65.2549 96.0435 65.5678 96.6124 66.1248C97.1814 66.6817 97.501 67.4371 97.501 68.2248C97.501 69.0125 97.1814 69.7679 96.6124 70.3249L74.629 91.845C74.3457 92.1201 74.0097 92.3378 73.6402 92.4855C73.2708 92.6332 72.8752 92.7081 72.4762 92.7058Z" fill="#596FA8"></path>
                    </svg>
                  </div>
                  <div class="tgp__list--popup-item__inner-wrap" style="padding-top: 7px;">
                    <div class="tgp__list--popup-icon-text" style="flex-direction: column; display: flex;">
                      <span style="font-weight: 600;"> Select TogetherPay</span>
                      <span class="spl__list--popup-icon-text-small" style="font-size: small;" >Choose TogetherPay to split payments with friends</span>
                    </div>
                  </div>
                </div>
                <div class="tgp__list--popup-item" style="text-align: left; display: flex; padding-bottom: 10px;">
                  <div class="tpg__list--popup-icon">
                    <svg width="60px" height="60px" viewBox="0 0 157 157" fill="blue" xmlns="http://www.w3.org/2000/svg">
                      <circle cx="78.0366" cy="78.0366" r="78.0366" fill="white" fill-opacity="0.1"></circle>
                      <path fill-rule="evenodd" clip-rule="evenodd" d="M88.4103 35.8433C76.0629 35.8433 66.0534 45.7959 66.0534 58.0732C66.0534 70.3504 76.0629 80.303 88.4103 80.303C100.758 80.303 110.767 70.3504 110.767 58.0732C110.767 45.7959 100.758 35.8433 88.4103 35.8433ZM88.4103 41.8752C97.4072 41.8752 104.701 49.1273 104.701 58.0732C104.701 67.019 97.4072 74.2711 88.4103 74.2711C79.4133 74.2711 72.1198 67.019 72.1198 58.0732C72.1198 49.1273 79.4133 41.8752 88.4103 41.8752ZM79.2604 89.2636C75.9875 89.4886 72.6792 89.9511 69.416 90.6435C63.1177 91.9281 58.079 94.4945 55.9344 98.7594C55.1255 100.427 54.7188 102.213 54.7257 104.021C54.7232 105.816 55.1266 107.604 55.9065 109.24C57.9658 113.456 62.4176 115.842 68.4289 117.164L69.5058 117.387C72.6804 118.096 75.9896 118.573 79.321 118.798C79.6048 118.88 80.281 118.956 81.0191 118.994L81.6262 119.016C81.9383 119.024 82.2926 119.025 82.8204 119.025C87.6089 119.286 92.5729 119.21 97.5139 118.794C100.147 118.615 102.798 118.274 105.426 117.775L107.393 117.371C113.884 116.103 118.745 113.692 120.875 109.245C122.453 105.944 122.453 102.113 120.875 98.813C118.751 94.3777 113.952 91.9865 107.344 90.6386C104.751 90.0906 102.116 89.6851 99.463 89.4261L97.5231 89.2636C91.4472 88.7329 85.3363 88.7329 79.2604 89.2636ZM96.9923 95.2724L97.0456 95.2766C100.091 95.4888 103.118 95.912 106.104 96.5431C111.012 97.5444 114.344 99.2043 115.398 101.405C116.191 103.064 116.191 104.992 115.397 106.653C114.412 108.709 111.42 110.298 107.096 111.269L106.149 111.467C103.105 112.14 100.088 112.573 97.0511 112.779C92.3394 113.176 87.6556 113.248 82.9836 112.998L81.3272 112.97C80.8673 112.946 80.4798 112.902 80.1207 112.831C77.2769 112.619 74.718 112.282 72.2334 111.795L70.7499 111.484C65.826 110.528 62.4654 108.861 61.3758 106.631C60.9945 105.831 60.7909 104.928 60.7921 104.014C60.7887 103.105 60.9896 102.222 61.3798 101.418C62.4392 99.3116 66.0005 97.4976 70.6586 96.5475C73.6662 95.9095 76.6915 95.4864 79.7355 95.2767C85.5139 94.7726 91.2696 94.7726 96.9923 95.2724Z" fill="#596FA8"></path>
                      <path d="M54.1727 76.3066C55.5718 76.3066 56.7059 77.5037 56.7059 78.9804C56.7059 80.3341 55.7529 81.4528 54.5165 81.6298L54.1727 81.6542H29.5331C28.1341 81.6542 27 80.4571 27 78.9804C27 77.6268 27.953 76.5081 29.1894 76.331L29.5331 76.3066H54.1727Z" fill="#596FA8"></path>
                      <path d="M39.1523 66.7823C39.1523 65.3972 40.3614 64.2743 41.8529 64.2743C43.2201 64.2743 44.3499 65.2178 44.5288 66.442L44.5534 66.7823V91.178C44.5534 92.5631 43.3443 93.686 41.8529 93.686C40.4857 93.686 39.3558 92.7425 39.177 91.5183L39.1523 91.178V66.7823Z" fill="#596FA8"></path>
                    </svg>
                  </div>
                  <div class="tgp__list--popup-item__inner-wrap" style="padding-top: 7px;">
                    <div class="tgp__list--popup-icon-text" style="flex-direction: column; display: flex;">
                      <span style="font-weight: 600;">Share the link</span>
          	          <span class="spl__list--popup-icon-text-small" style="font-size: small;">Invite friends to pay their share</span>
                    </div>
                  </div>
                </div>
                <div class="tgp__list--popup-item" style="text-align: left; display: flex; padding-bottom: 10px;">
                  <div class="tpg__list--popup-icon">
                    <svg width="60px" height="60px" viewBox="0 0 157 157" fill="none" xmlns="http://www.w3.org/2000/svg">
                      <circle cx="78.1831" cy="78.0366" r="78.0366" fill="white" fill-opacity="0.1"></circle>
                      <path fill-rule="evenodd" clip-rule="evenodd" d="M83.9333 36.3379C82.3525 36.2777 80.7809 36.84 79.5966 37.9019C78.6536 38.7476 78.0132 39.8593 77.7638 41.0761L77.6709 41.7625L77.6497 42.3716L79.5192 69.5563C79.7523 73.0214 82.8328 75.6592 86.4176 75.4587L114.384 73.6532C116.021 73.5361 117.521 72.8019 118.574 71.6117C119.413 70.664 119.917 69.4844 120.019 68.2441L120.028 67.4031L119.988 66.9203C117.094 49.6736 101.968 36.843 83.9333 36.3379ZM83.8839 42.3711L84.7991 42.4141C99.0367 43.2687 110.903 53.3344 113.654 66.8899L113.792 67.6399L86.0308 69.4378C85.8903 69.4455 85.7458 69.3218 85.7349 69.1591L83.8839 42.3711ZM64.3997 52.3557C67.4322 51.9452 70.4158 53.5191 71.6227 56.1693C71.9918 56.8954 72.2136 57.6832 72.2774 58.5044L73.9125 81.3732C73.9344 81.6865 74.0843 81.9785 74.3291 82.1844C74.5127 82.3389 74.7383 82.4355 74.9905 82.4636L75.251 82.4684L98.7089 81.0693C100.614 80.9584 102.481 81.6148 103.87 82.883C105.258 84.1513 106.043 85.9187 106.033 87.9634C104.99 103.015 93.8229 115.599 78.6139 118.86C63.405 122.122 47.7966 115.281 40.313 102.104C38.3118 98.7066 36.963 94.9909 36.3402 91.2288L36.1409 89.8164C35.9678 88.7587 35.8748 87.6907 35.8613 86.6649L35.8744 85.6551C35.9177 69.9396 47.0621 56.3503 62.7445 52.6849L63.8208 52.4505L64.3997 52.3557ZM65.389 58.3257L65.0344 58.3657L64.0729 58.5807C51.5807 61.5618 42.6209 72.2098 42.1233 84.7544L42.1016 85.7756C42.0717 86.5525 42.1004 87.3301 42.195 88.1575L42.3119 89.0034C42.73 92.5628 43.8908 96.0028 45.7475 99.1553C51.9219 110.027 64.7588 115.653 77.2673 112.971C89.7758 110.288 98.9597 99.9389 99.8106 87.756C99.8109 87.5725 99.733 87.3971 99.5952 87.2711C99.5033 87.1872 99.3903 87.1303 99.2704 87.1048L99.0874 87.0908L75.6683 88.4875C73.6918 88.6265 71.7396 87.997 70.2437 86.7383C68.7478 85.4796 67.8315 83.6954 67.6985 81.7918L66.0648 58.9426C66.0619 58.9047 66.0515 58.8678 65.9798 58.721C65.8732 58.4875 65.6417 58.339 65.389 58.3257Z" fill="#596FA8"></path>
                    </svg>
                  </div>
                  <div class="tgp__list--popup-item__inner-wrap" style="padding-top: 7px;">
                    <div class="tgp__list--popup-icon-text" style="flex-direction: column; display: flex;">
                      <span style="font-weight: 600;">Confirm your share</span>
         	            <span class="spl__list--popup-icon-text-small" style="font-size: small;">Once everyone confirms payment, your order is placed</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <div class="what-is-splitoff-container">
                <span class="splitoff-popup" style="cursor: pointer; text-decoration: underline; " >More info!</span>
            </div>
          </div>
      </li>
</ul>
