<?php
//These are the environments used by the SplitOff Pay for WooCommerce

$this->environments = array
(
	"sandbox" => array
			(
			"name"       =>  "Sandbox",
			"api_url"    =>  "https://api-sandbox.splitoff.io",
			"web_url"    =>  "https://togetherpay.io/pay/sandbox",
			"api_us_url" =>  "",
			"web_us_url" =>  "",
			"static_url" =>  "https://togetherpay.io/pay/sandbox"
			),
	"production" => array
			(
			"name"       => "Production",
			"api_url"    => "https://api.splitoff.io",
			"web_url"    => "https://togetherpay.io/pay/app",
			"api_us_url" => "",
			"web_us_url" => "",
			"static_url" =>  "https://togetherpay.io/pay/app"
			)
);
