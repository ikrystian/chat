<?php
/**
 * Main plugin class
 * 
 * @package WP_Messenger_Chat
 */

// Zabezpieczenie przed bezpośrednim dostępem
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Główna klasa pluginu
 */
class WP_Messenger_Chat {

    /**
     * Instancja klasy (Singleton)
     *
     * @var WP_Messenger_Chat
     */
    private static $instance = null;

    /**
     * Zwraca instancję klasy (Singleton)
     *
     * @return WP_Messenger_Chat
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Konstruktor
     */
    private function __construct() {
        // Inicjalizacja pluginu
        add_action('init', array($this, 'init'));

        // Rejestracja skryptów i styli
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        // Dodanie shortcode dla czatu
        add_shortcode('messenger_chat', array($this, 'messenger_chat_shortcode'));
;

        // Dodanie menu w panelu admina
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Rejestracja tabeli w bazie danych przy aktywacji
        register_activation_hook(WP_MESSENGER_CHAT_FILE, array($this, 'activate'));
        
        // Utworzenie katalogu na załączniki
        $this->create_attachments_directory();
    }

    /**
     * Aktywacja pluginu
     */
    public function activate() {
        // Utwórz tabele w bazie danych
        require_once WP_MESSENGER_CHAT_DIR . 'includes/class-database.php';
        $database = new WP_Messenger_Chat_Database();
        $database->create_tables();
    }

    /**
     * Inicjalizacja pluginu
     */
    public function init() {
        // Utworzenie niestandardowego typu postów dla konwersacji
        register_post_type('messenger_conv', array(
            'public' => false,
            'has_archive' => false,
            'supports' => array('title')
        ));

        // Załaduj klasy obsługujące AJAX
        require_once WP_MESSENGER_CHAT_DIR . 'includes/class-api.php';
        new WP_Messenger_Chat_API();
    }

    /**
     * Rejestracja skryptów i styli
     */
    public function enqueue_scripts() {
        // CSS
        wp_enqueue_style('messenger-chat-style', WP_MESSENGER_CHAT_URL . 'assets/css/messenger-chat.css', array(), WP_MESSENGER_CHAT_VERSION);
        wp_enqueue_style('dashicons');

        // JavaScript
        wp_enqueue_script('socket-io', 'https://cdnjs.cloudflare.com/ajax/libs/socket.io/4.4.1/socket.io.min.js', array(), '4.4.1', true);
        wp_enqueue_script('messenger-chat-js', WP_MESSENGER_CHAT_URL . 'assets/js/messenger-chat.js', array('jquery', 'socket-io'), WP_MESSENGER_CHAT_VERSION, true);

        // Przekazanie zmiennych do JavaScript
        $user_id = get_current_user_id();
        $upload_dir = wp_upload_dir();
        
        wp_localize_script('messenger-chat-js', 'messengerChat', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'user_id' => $user_id,
            'nonce' => wp_create_nonce('messenger_chat_nonce'),
            'websocket_server' => get_option('messenger_chat_websocket_server', 'ws://localhost:3000'),
            'uploads_url' => $upload_dir['baseurl'] . '/messenger-attachments'
        ));
    }

    /**
     * Shortcode dla czatu
     *
     * @param array $atts Atrybuty shortcode
     * @return string Kod HTML czatu
     */
    public function messenger_chat_shortcode($atts) {
        // Sprawdź, czy użytkownik jest zalogowany
        if (!is_user_logged_in()) {
            return '<div class="messenger-login-required">Zaloguj się, aby korzystać z czatu.</div>';
        }

        // Pobierz ID zalogowanego użytkownika
        $current_user_id = get_current_user_id();

        // Pobierz konwersacje użytkownika
        require_once WP_MESSENGER_CHAT_DIR . 'includes/class-database.php';
        $database = new WP_Messenger_Chat_Database();
        $conversations = $database->get_user_conversations($current_user_id);

        // Rozpocznij buforowanie
        ob_start();

        // Szablon czatu
        include(WP_MESSENGER_CHAT_DIR . 'templates/messenger-chat.php');

        return ob_get_clean();
    }


    /**
     * Dodanie menu w panelu admina
     */
    public function add_admin_menu() {
        add_menu_page(
            'WP Messenger Chat',
            'WP Messenger',
            'manage_options',
            'wp-messenger-chat',
            array($this, 'admin_page'),
            'dashicons-format-chat',
            30
        );
    }

    /**
     * Strona administracyjna
     */
    public function admin_page() {
        require_once WP_MESSENGER_CHAT_DIR . 'includes/class-admin.php';
        $admin = new WP_Messenger_Chat_Admin();
        $admin->render_admin_page();
    }

    /**
     * Tworzy katalog na załączniki
     */
    private function create_attachments_directory() {
        require_once WP_MESSENGER_CHAT_DIR . 'includes/class-attachments.php';
        $attachments = new WP_Messenger_Chat_Attachments();
        $attachments->create_directory();
    }
}
