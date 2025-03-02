<?php
/**
 * Plugin Name: WP Messenger Chat
 * Description: Czat między użytkownikami przypominający Messengera, wykorzystujący WebSocket
 * Version: 1.0.0
 * Author: Claude
 * Text Domain: wp-messenger-chat
 */

// Zabezpieczenie przed bezpośrednim dostępem
if (!defined('ABSPATH')) {
    exit;
}

// Definicje stałych
define('WP_MESSENGER_CHAT_VERSION', '1.0.0');
define('WP_MESSENGER_CHAT_FILE', __FILE__);
define('WP_MESSENGER_CHAT_DIR', plugin_dir_path(__FILE__));
define('WP_MESSENGER_CHAT_URL', plugin_dir_url(__FILE__));

// Załaduj główną klasę pluginu
require_once WP_MESSENGER_CHAT_DIR . 'includes/class-wp-messenger-chat.php';

// Inicjalizacja pluginu
function wp_messenger_chat_init() {
    return WP_Messenger_Chat::get_instance();
}

// Uruchom plugin
wp_messenger_chat_init();
