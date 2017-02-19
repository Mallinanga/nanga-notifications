<?php
/*
Author URI: https://www.vgwebthings.com/
Author: VG web things
Description: Send automatic email notifications
Domain Path: /languages/
Plugin Name: VG web things Notifications
Text Domain: nanga-notifications
Version: 1.0.0
*/
if ( ! defined('WPINC')) {
    die;
}
require_once 'vendor/autoload.php';

//if ( ! class_exists('Nanga\Notifications')) {
//    add_action('admin_notices', function () {
//        echo '<div class="error"><p><strong>Notifications</strong> are not activated. Make sure you activate the plugin in <a href="' . esc_url(admin_url('plugins.php')) . '">' . esc_url(admin_url('plugins.php')) . '</a></p></div>';
//    });
//
//    return;
//}

//$config        = [
//    'logger'      => 'Loggly API Key',
//    'providerKey' => 'SendGrid API Key',
//];
//$notifications = new Nanga\Notifications($config);
