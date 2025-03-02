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

class WP_Messenger_Chat {

    public function __construct() {
        // Inicjalizacja pluginu
        add_action('init', array($this, 'init'));

        // Rejestracja skryptów i styli
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        // Dodanie shortcode dla czatu
        add_shortcode('messenger_chat', array($this, 'messenger_chat_shortcode'));

        // Ajax dla pobrania wiadomości
        add_action('wp_ajax_get_messages', array($this, 'get_messages'));
        add_action('wp_ajax_nopriv_get_messages', array($this, 'get_messages'));

        // Ajax dla wysyłania wiadomości
        add_action('wp_ajax_send_message', array($this, 'send_message'));
        add_action('wp_ajax_nopriv_send_message', array($this, 'send_message'));

        // Dodanie menu w panelu admina
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Rejestracja tabeli w bazie danych przy aktywacji
        register_activation_hook(__FILE__, array($this, 'create_db_tables'));
    }

    public function init() {
        // Utworzenie niestandardowego typu postów dla konwersacji
        register_post_type('messenger_conv', array(
            'public' => false,
            'has_archive' => false,
            'supports' => array('title')
        ));
    }

    public function create_db_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Tabela wiadomości
        $table_messages = $wpdb->prefix . 'messenger_messages';
        $sql_messages = "CREATE TABLE $table_messages (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            conversation_id bigint(20) NOT NULL,
            sender_id bigint(20) NOT NULL,
            message text NOT NULL,
            attachment varchar(255) DEFAULT NULL,
            sent_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            read_at datetime DEFAULT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        // Tabela konwersacji
        $table_conversations = $wpdb->prefix . 'messenger_conversations';
        $sql_conversations = "CREATE TABLE $table_conversations (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        // Tabela uczestników konwersacji
        $table_participants = $wpdb->prefix . 'messenger_participants';
        $sql_participants = "CREATE TABLE $table_participants (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            conversation_id bigint(20) NOT NULL,
            user_id bigint(20) NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY conversation_user (conversation_id, user_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_messages);
        dbDelta($sql_conversations);
        dbDelta($sql_participants);
    }

    public function enqueue_scripts() {
        // CSS
        wp_enqueue_style('messenger-chat-style', plugin_dir_url(__FILE__) . 'assets/css/messenger-chat.css', array(), '1.0.0');

        // JavaScript
        wp_enqueue_script('socket-io', 'https://cdnjs.cloudflare.com/ajax/libs/socket.io/4.4.1/socket.io.min.js', array(), '4.4.1', true);
        wp_enqueue_script('messenger-chat-js', plugin_dir_url(__FILE__) . 'assets/js/messenger-chat.js', array('jquery', 'socket-io'), '1.0.0', true);

        // Przekazanie zmiennych do JavaScript
        $user_id = get_current_user_id();
        wp_localize_script('messenger-chat-js', 'messengerChat', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'user_id' => $user_id,
            'nonce' => wp_create_nonce('messenger_chat_nonce'),
            'websocket_server' => get_option('messenger_chat_websocket_server', 'ws://localhost:3000')
        ));
    }

    public function messenger_chat_shortcode($atts) {
        // Sprawdź, czy użytkownik jest zalogowany
        if (!is_user_logged_in()) {
            return '<div class="messenger-login-required">Zaloguj się, aby korzystać z czatu.</div>';
        }

        // Pobierz ID zalogowanego użytkownika
        $current_user_id = get_current_user_id();

        // Pobierz kontakty (wszyscy użytkownicy oprócz bieżącego)
        $users = get_users(array(
            'exclude' => $current_user_id
        ));

        // Pobierz konwersacje użytkownika
        $conversations = $this->get_user_conversations($current_user_id);

        // Rozpocznij buforowanie
        ob_start();

        // Szablon czatu
        include(plugin_dir_path(__FILE__) . 'templates/messenger-chat.php');

        return ob_get_clean();
    }

    public function get_user_conversations($user_id) {
        global $wpdb;

        $table_participants = $wpdb->prefix . 'messenger_participants';
        $table_conversations = $wpdb->prefix . 'messenger_conversations';
        $table_messages = $wpdb->prefix . 'messenger_messages';

        $query = "
            SELECT c.id, c.updated_at, 
                (SELECT m.message FROM {$table_messages} m 
                 WHERE m.conversation_id = c.id 
                 ORDER BY m.sent_at DESC LIMIT 1) as last_message,
                (SELECT m.sender_id FROM {$table_messages} m 
                 WHERE m.conversation_id = c.id 
                 ORDER BY m.sent_at DESC LIMIT 1) as last_sender,
                (SELECT p2.user_id FROM {$table_participants} p2 
                 WHERE p2.conversation_id = c.id AND p2.user_id != %d) as other_user_id
            FROM {$table_conversations} c
            JOIN {$table_participants} p ON c.id = p.conversation_id
            WHERE p.user_id = %d
            ORDER BY c.updated_at DESC
        ";

        $results = $wpdb->get_results($wpdb->prepare($query, $user_id, $user_id));

        // Dodaj dane użytkowników
        foreach ($results as $conv) {
            if ($conv->other_user_id) {
                $user = get_userdata($conv->other_user_id);
                $conv->other_user_name = $user ? $user->display_name : 'Nieznany użytkownik';
                $conv->other_user_avatar = get_avatar_url($conv->other_user_id);
            }
        }

        return $results;
    }

    public function get_messages() {
        // Sprawdź nonce
        check_ajax_referer('messenger_chat_nonce', 'nonce');

        $conversation_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : 0;
        $user_id = get_current_user_id();

        // Sprawdź czy użytkownik ma dostęp do konwersacji
        if (!$this->user_in_conversation($user_id, $conversation_id)) {
            wp_send_json_error(array('message' => 'Brak dostępu do tej konwersacji'));
            return;
        }

        // Pobierz wiadomości
        global $wpdb;
        $table_messages = $wpdb->prefix . 'messenger_messages';

        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_messages} 
             WHERE conversation_id = %d 
             ORDER BY sent_at ASC",
            $conversation_id
        ));

        // Dodaj dane użytkowników
        foreach ($messages as $message) {
            $sender = get_userdata($message->sender_id);
            $message->sender_name = $sender ? $sender->display_name : 'Nieznany';
            $message->sender_avatar = get_avatar_url($message->sender_id);
            $message->is_mine = ($message->sender_id == $user_id);
        }

        // Oznacz jako przeczytane
        $this->mark_messages_as_read($conversation_id, $user_id);

        wp_send_json_success($messages);
    }

    public function send_message() {
        // Sprawdź nonce
        check_ajax_referer('messenger_chat_nonce', 'nonce');

        $conversation_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : 0;
        $message = isset($_POST['message']) ? sanitize_textarea_field($_POST['message']) : '';
        $recipient_id = isset($_POST['recipient_id']) ? intval($_POST['recipient_id']) : 0;
        $user_id = get_current_user_id();

        if (empty($message)) {
            wp_send_json_error(array('message' => 'Wiadomość nie może być pusta'));
            return;
        }

        // Jeśli nie ma ID konwersacji, ale jest odbiorca, utwórz nową konwersację
        if ($conversation_id == 0 && $recipient_id > 0) {
            $conversation_id = $this->create_conversation($user_id, $recipient_id);
        }

        // Sprawdź czy użytkownik ma dostęp do konwersacji
        if (!$this->user_in_conversation($user_id, $conversation_id)) {
            wp_send_json_error(array('message' => 'Brak dostępu do tej konwersacji'));
            return;
        }

        // Zapisz wiadomość
        global $wpdb;
        $table_messages = $wpdb->prefix . 'messenger_messages';
        $table_conversations = $wpdb->prefix . 'messenger_conversations';

        $result = $wpdb->insert(
            $table_messages,
            array(
                'conversation_id' => $conversation_id,
                'sender_id' => $user_id,
                'message' => $message,
                'sent_at' => current_time('mysql')
            )
        );

        // Aktualizuj czas aktualizacji konwersacji
        $wpdb->update(
            $table_conversations,
            array('updated_at' => current_time('mysql')),
            array('id' => $conversation_id)
        );

        if ($result) {
            $message_id = $wpdb->insert_id;
            $new_message = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_messages} WHERE id = %d",
                $message_id
            ));

            // Dodaj dane nadawcy
            $sender = get_userdata($user_id);
            $new_message->sender_name = $sender ? $sender->display_name : 'Nieznany';
            $new_message->sender_avatar = get_avatar_url($user_id);
            $new_message->is_mine = true;

            // WebSocket event (do zaimplementowania w node.js)

            wp_send_json_success(array(
                'message' => $new_message,
                'conversation_id' => $conversation_id
            ));
        } else {
            wp_send_json_error(array('message' => 'Błąd przy zapisywaniu wiadomości'));
        }
    }

    public function create_conversation($user1_id, $user2_id) {
        global $wpdb;
        $table_conversations = $wpdb->prefix . 'messenger_conversations';
        $table_participants = $wpdb->prefix . 'messenger_participants';

        // Sprawdź czy konwersacja już istnieje
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT c.id FROM {$table_conversations} c
             JOIN {$table_participants} p1 ON c.id = p1.conversation_id
             JOIN {$table_participants} p2 ON c.id = p2.conversation_id
             WHERE p1.user_id = %d AND p2.user_id = %d AND 
             (SELECT COUNT(*) FROM {$table_participants} p3 WHERE p3.conversation_id = c.id) = 2",
            $user1_id, $user2_id
        ));

        if ($existing) {
            return $existing->id;
        }

        // Utwórz nową konwersację
        $wpdb->insert(
            $table_conversations,
            array(
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            )
        );

        $conversation_id = $wpdb->insert_id;

        // Dodaj uczestników
        $wpdb->insert(
            $table_participants,
            array(
                'conversation_id' => $conversation_id,
                'user_id' => $user1_id
            )
        );

        $wpdb->insert(
            $table_participants,
            array(
                'conversation_id' => $conversation_id,
                'user_id' => $user2_id
            )
        );

        return $conversation_id;
    }

    public function user_in_conversation($user_id, $conversation_id) {
        global $wpdb;
        $table_participants = $wpdb->prefix . 'messenger_participants';

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_participants} 
             WHERE conversation_id = %d AND user_id = %d",
            $conversation_id, $user_id
        ));

        return intval($count) > 0;
    }

    public function mark_messages_as_read($conversation_id, $user_id) {
        global $wpdb;
        $table_messages = $wpdb->prefix . 'messenger_messages';

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table_messages} 
             SET read_at = %s 
             WHERE conversation_id = %d 
             AND sender_id != %d 
             AND read_at IS NULL",
            current_time('mysql'), $conversation_id, $user_id
        ));
    }

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

    public function admin_page() {
        include(plugin_dir_path(__FILE__) . 'templates/admin-page.php');
    }
}

// Inicjalizacja pluginu
$wp_messenger_chat = new WP_Messenger_Chat();