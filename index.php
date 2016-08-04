<?php
/*
Plugin Name: Gava Payment Gateway For WooCommerce
Plugin URI: https://pay4app.com
Description: Receive mobile money payments with Gava
Version: 0.1.3
Author: Sam Takunda
Author URI: https://github.com/ihatehandles
*/


function woocommerce_gava_init() 
{	
	
    if (class_exists('WC_Payment_Gateway'))
    {	
    	include_once('gava.php');
    }
    
}

add_action('plugins_loaded', 'woocommerce_gava_init', 0);
