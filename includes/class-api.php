<?php
/**
 * API endpoints for the plugin
 * 
 * @package WP_Messenger_Chat
 */

// Zabezpieczenie przed bezpośrednim dostępem
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Klasa obsługująca endpointy AJAX
 */
class WP_Messenger_Chat_API {

    /**
     * Konstruktor
     */
    public function __construct() {
        // Ajax dla pobrania wiadomości
        add_action('wp_ajax_get_messages', array($this, 'get_messages'));
        add_action('wp_ajax_nopriv_get_messages', array($this, 'get_messages'));

        // Ajax dla wysyłania wiadomości
        add_action('wp_ajax_send_message', array($this, 'send_message'));
        add_action('wp_ajax_nopriv_send_message', array($this, 'send_message'));
    }

    /**
     * Obsługuje pobieranie wiadomości
     */
    public function get_messages() {
        // Sprawdź nonce
        check_ajax_referer('messenger_chat_nonce', 'nonce');

        $conversation_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : 0;
        $user_id = get_current_user_id();

        // Pobierz wiadomości
        require_once WP_MESSENGER_CHAT_DIR . 'includes/class-database.php';
        $database = new WP_Messenger_Chat_Database();
        $messages = $database->get_messages($conversation_id, $user_id);

        if ($messages === false) {
            wp_send_json_error(array('message' => 'Brak dostępu do tej konwersacji'));
            return;
        }

        wp_send_json_success($messages);
    }

    /**
     * Obsługuje wysyłanie wiadomości wraz z załącznikami
     */
    public function send_message() {
        // Sprawdź nonce
        check_ajax_referer('messenger_chat_nonce', 'nonce');

        $conversation_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : 0;
        $message = isset($_POST['message']) ? sanitize_textarea_field($_POST['message']) : '';
        $recipient_id = isset($_POST['recipient_id']) ? intval($_POST['recipient_id']) : 0;
        $user_id = get_current_user_id();

        // Sprawdź czy wiadomość lub załącznik istnieje
        if (empty($message) && empty($_FILES['attachment'])) {
            wp_send_json_error(array('message' => 'Wiadomość lub załącznik jest wymagany'));
            return;
        }

        // Załaduj klasy
        require_once WP_MESSENGER_CHAT_DIR . 'includes/class-database.php';
        $database = new WP_Messenger_Chat_Database();
        
        require_once WP_MESSENGER_CHAT_DIR . 'includes/class-attachments.php';
        $attachments = new WP_Messenger_Chat_Attachments();
        
        require_once WP_MESSENGER_CHAT_DIR . 'includes/class-websocket.php';
        $websocket = new WP_Messenger_Chat_WebSocket();

        $is_new_conversation = false;

        // Jeśli nie ma ID konwersacji, ale jest odbiorca, utwórz nową konwersację
        if ($conversation_id == 0 && $recipient_id > 0) {
            $conversation_id = $database->create_conversation($user_id, $recipient_id);
            $is_new_conversation = true;
        }

        // Sprawdź czy użytkownik ma dostęp do konwersacji
        if (!$database->user_in_conversation($user_id, $conversation_id)) {
            wp_send_json_error(array('message' => 'Brak dostępu do tej konwersacji'));
            return;
        }

        // Obsługa załącznika
        $attachment_path = null;
        if (!empty($_FILES['attachment']) && $_FILES['attachment']['error'] == 0) {
            $attachment_path = $attachments->handle_upload($_FILES['attachment'], $conversation_id);
            if (is_wp_error($attachment_path)) {
                wp_send_json_error(array('message' => $attachment_path->get_error_message()));
                return;
            }
        }

        // Zapisz wiadomość
        $message_data = array(
            'conversation_id' => $conversation_id,
            'sender_id' => $user_id,
            'message' => $message,
            'sent_at' => current_time('mysql')
        );

        // Dodaj załącznik, jeśli istnieje
        if ($attachment_path) {
            $message_data['attachment'] = $attachment_path;
        }

        $message_id = $database->save_message($message_data);

        if ($message_id) {
            // Pobierz dane wiadomości
            global $wpdb;
            $table_messages = $wpdb->prefix . 'messenger_messages';
            $new_message = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_messages} WHERE id = %d",
                $message_id
            ));

            // Dodaj dane nadawcy
            $sender = get_userdata($user_id);
            $new_message->sender_name = $sender ? $sender->display_name : 'Nieznany';
            $new_message->sender_avatar = get_avatar_url($user_id);
            $new_message->is_mine = true;

            // Pobierz ID odbiorcy (drugi uczestnik konwersacji)
            if (!$recipient_id) {
                $recipient_id = $database->get_recipient_id($conversation_id, $user_id);
            }

            // Wyślij powiadomienie przez WebSocket
            if ($is_new_conversation) {
                $websocket->send_new_conversation($conversation_id, $recipient_id, $user_id, $sender, $new_message);
            } else {
                $websocket->send_message($conversation_id, $recipient_id, $new_message);
            }

            wp_send_json_success(array(
                'message' => $new_message,
                'conversation_id' => $conversation_id
            ));
        } else {
            wp_send_json_error(array('message' => 'Błąd przy zapisywaniu wiadomości'));
        }
    }
}
