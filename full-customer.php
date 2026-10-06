<?php

use FC\Root;

defined('ABSPATH') || exit;

/**
 * Plugin Name:         FULL.Cliente
 * Description:         Este plugin adiciona novas extensões úteis e conecta-o ao painel da FULL. para ativações de outros plugins.
 * Version:             4.1.7
 * Requires at least:   6.5
 * Tested up to:        7.0.2
 * Requires PHP:        7.4
 * Author:              FULL.
 * Author URI:          https://full.services/
 */

if (!defined('FULL_CUSTOMER_VERSION')) {
  define('FULL_CUSTOMER_FILE', __FILE__);
  define('FULL_CUSTOMER_PATH', __DIR__);
  define('FULL_CUSTOMER_REST_NAMESPACE', 'fc/v1');

  if (file_exists(FULL_CUSTOMER_PATH . '/dev.php')) {
    require_once FULL_CUSTOMER_PATH . '/dev.php';
  }

  defined('FULL_CUSTOMER_DEV') || define('FULL_CUSTOMER_DEV', false);
  defined('FULL_CUSTOMER_API_DOMAIN') || define('FULL_CUSTOMER_API_DOMAIN', 'https://api.full.services/');
  defined('FULL_CUSTOMER_VERSION') || define('FULL_CUSTOMER_VERSION', '4.1.7');
  defined('FULL_CUSTOMER_API_URL') || define('FULL_CUSTOMER_API_URL', FULL_CUSTOMER_API_DOMAIN . 'wp-json/fc-ai');

  require_once FULL_CUSTOMER_PATH . '/vendor/autoload.php';

  (new Root)->init();
}
