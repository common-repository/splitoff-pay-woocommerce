<?php

//These are the endpoints that will be appended to the api_url, which is selected on the gateway settings page.

$endpoints =   array
(
    "v1" => [
        "orders" => "/place_order",
        "capture-payment" => "/capture_payment",
        "cancel-payment" => "/cancel_payment",
        "refund" => "/refund"
    ]
);
?>
