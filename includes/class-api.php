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
        
        // Ajax dla pobrania listy konwersacji
        add_action('wp_ajax_get_conversations', array($this, 'get_conversations'));
        add_action('wp_ajax_nopriv_get_conversations', array($this, 'get_conversations'));
        
        // Ajax dla archiwizacji konwersacji
        add_action('wp_ajax_archive_conversation', array($this, 'archive_conversation'));
        
        // Ajax dla przywracania zarchiwizowanej konwersacji
        add_action('wp_ajax_unarchive_conversation', array($this, 'unarchive_conversation'));
        
        // Ajax dla pobrania zarchiwizowanych konwersacji
        add_action('wp_ajax_get_archived_conversations', array($this, 'get_archived_conversations'));
        
        // Ajax dla usuwania konwersacji (soft delete)
        add_action('wp_ajax_delete_conversation', array($this, 'delete_conversation'));
        
        // Ajax dla przywracania usuniętej konwersacji
        add_action('wp_ajax_restore_conversation', array($this, 'restore_conversation'));
        
        // Ajax dla pobrania usuniętych konwersacji
        add_action('wp_ajax_get_deleted_conversations', array($this, 'get_deleted_conversations'));
        
        // Ajax dla oznaczania wiadomości jako przeczytane
        add_action('wp_ajax_mark_messages_as_read', array($this, 'mark_messages_as_read'));
        
        // Ajax dla pobrania statusu przeczytania wiadomości
        add_action('wp_ajax_get_message_read_status', array($this, 'get_message_read_status'));
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

        // Oznacz wiadomości jako przeczytane
        $database->mark_messages_as_read($conversation_id, $user_id);
        
        // Pobierz ID odbiorcy (nadawcy wiadomości)
        $recipient_id = $database->get_recipient_id($conversation_id, $user_id);
        
        // Wyślij powiadomienie o przeczytaniu wiadomości przez WebSocket
        if ($recipient_id) {
            require_once WP_MESSENGER_CHAT_DIR . 'includes/class-websocket.php';
            $websocket = new WP_Messenger_Chat_WebSocket();
            $websocket->send_read_receipt($conversation_id, $recipient_id, $user_id);
        }

        wp_send_json_success($messages);
    }

    /**
     * Obsługuje pobieranie listy konwersacji
     */
    public function get_conversations() {
        // Sprawdź nonce
        check_ajax_referer('messenger_chat_nonce', 'nonce');
        
        $user_id = get_current_user_id();
        
        // Pobierz konwersacje
        require_once WP_MESSENGER_CHAT_DIR . 'includes/class-database.php';
        $database = new WP_Messenger_Chat_Database();
        $conversations = $database->get_user_conversations($user_id);
        
        // Pobierz liczbę nieprzeczytanych wiadomości dla każdej konwersacji
        $unread_counts = $database->get_unread_messages_count($user_id);
        
        // Przygotuj HTML z listą konwersacji
        ob_start();
        
        if (empty($conversations)) {
            echo '<div class="no-conversations">Brak konwersacji</div>';
        } else {
            foreach ($conversations as $conv) {
                $has_unread = isset($unread_counts[$conv->id]) && $unread_counts[$conv->id] > 0;
                $unread_class = $has_unread ? 'has-new-message' : '';
                $unread_badge = $has_unread ? '<span class="unread-badge">' . $unread_counts[$conv->id] . '</span>' : '';
                
                ?>
                <div class="conversation-item <?php echo $unread_class; ?>" data-conversation-id="<?php echo esc_attr($conv->id); ?>" data-recipient-id="<?php echo esc_attr($conv->other_user_id); ?>">
                    <div class="conversation-avatar">
                        <img src="<?php echo esc_url($conv->other_user_avatar); ?>" alt="<?php echo esc_attr($conv->other_user_name); ?>">
                    </div>
                    <div class="conversation-info">
                        <div class="user-name"><?php echo esc_html($conv->other_user_name); ?></div>
                        <div class="last-message"><?php echo esc_html($conv->last_message); ?></div>
                    </div>
                    <?php echo $unread_badge; ?>
                </div>
                <?php
            }
        }
        
        $html = ob_get_clean();
        wp_send_json_success($html);
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
        
        require_once WP_MESSENGER_CHAT_DIR . 'includes/class-encryption.php';
        $encryption = new WP_Messenger_Chat_Encryption();

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

            // Odszyfruj wiadomość przed wysłaniem przez WebSocket
            $new_message->message = $encryption->decrypt($new_message->message);

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
    
    /**
     * Obsługuje archiwizację konwersacji
     */
    public function archive_conversation() {
        // Sprawdź nonce
        check_ajax_referer('messenger_chat_nonce', 'nonce');
        
        $conversation_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : 0;
        $user_id = get_current_user_id();
        
        if ($conversation_id <= 0) {
            wp_send_json_error(array('message' => 'Nieprawidłowe ID konwersacji'));
            return;
        }
        
        // Załaduj klasę bazy danych
        require_once WP_MESSENGER_CHAT_DIR . 'includes/class-database.php';
        $database = new WP_Messenger_Chat_Database();
        
        // Archiwizuj konwersację
        $result = $database->archive_conversation($conversation_id, $user_id);
        
        if ($result) {
            wp_send_json_success(array('message' => 'Konwersacja została zarchiwizowana'));
        } else {
            wp_send_json_error(array('message' => 'Nie udało się zarchiwizować konwersacji'));
        }
    }
    
    /**
     * Obsługuje przywracanie zarchiwizowanej konwersacji
     */
    public function unarchive_conversation() {
        // Sprawdź nonce
        check_ajax_referer('messenger_chat_nonce', 'nonce');
        
        $conversation_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : 0;
        $user_id = get_current_user_id();
        
        if ($conversation_id <= 0) {
            wp_send_json_error(array('message' => 'Nieprawidłowe ID konwersacji'));
            return;
        }
        
        // Załaduj klasę bazy danych
        require_once WP_MESSENGER_CHAT_DIR . 'includes/class-database.php';
        $database = new WP_Messenger_Chat_Database();
        
        // Przywróć konwersację
        $result = $database->unarchive_conversation($conversation_id, $user_id);
        
        if ($result) {
            wp_send_json_success(array('message' => 'Konwersacja została przywrócona'));
        } else {
            wp_send_json_error(array('message' => 'Nie udało się przywrócić konwersacji'));
        }
    }
    
    /**
     * Obsługuje pobieranie zarchiwizowanych konwersacji
     */
    public function get_archived_conversations() {
        // Sprawdź nonce
        check_ajax_referer('messenger_chat_nonce', 'nonce');
        
        $user_id = get_current_user_id();
        
        // Pobierz zarchiwizowane konwersacje
        require_once WP_MESSENGER_CHAT_DIR . 'includes/class-database.php';
        $database = new WP_Messenger_Chat_Database();
        $conversations = $database->get_user_conversations($user_id, true);
        
        // Przygotuj HTML z listą konwersacji
        ob_start();
        
        if (empty($conversations)) {
            echo '<div class="no-conversations">Brak zarchiwizowanych konwersacji</div>';
        } else {
            foreach ($conversations as $conv) {
                ?>
                <div class="conversation-item archived" data-conversation-id="<?php echo esc_attr($conv->id); ?>" data-recipient-id="<?php echo esc_attr($conv->other_user_id); ?>">
                    <div class="conversation-avatar">
                        <img src="<?php echo esc_url($conv->other_user_avatar); ?>" alt="<?php echo esc_attr($conv->other_user_name); ?>">
                    </div>
                    <div class="conversation-info">
                        <div class="user-name"><?php echo esc_html($conv->other_user_name); ?></div>
                        <div class="last-message"><?php echo esc_html($conv->last_message); ?></div>
                    </div>
                    <div class="conversation-actions">
                        <button class="unarchive-conversation" data-conversation-id="<?php echo esc_attr($conv->id); ?>">
                            <span class="dashicons dashicons-undo"></span>
                        </button>
                    </div>
                </div>
                <?php
            }
        }
        
        $html = ob_get_clean();
        wp_send_json_success($html);
    }
    
    /**
     * Obsługuje usuwanie konwersacji (soft delete)
     */
    public function delete_conversation() {
        // Sprawdź nonce
        check_ajax_referer('messenger_chat_nonce', 'nonce');
        
        $conversation_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : 0;
        $user_id = get_current_user_id();
        
        if ($conversation_id <= 0) {
            wp_send_json_error(array('message' => 'Nieprawidłowe ID konwersacji'));
            return;
        }
        
        // Załaduj klasę bazy danych
        require_once WP_MESSENGER_CHAT_DIR . 'includes/class-database.php';
        $database = new WP_Messenger_Chat_Database();
        
        // Usuń konwersację (soft delete)
        $result = $database->delete_conversation($conversation_id, $user_id);
        
        if ($result) {
            wp_send_json_success(array('message' => 'Konwersacja została usunięta'));
        } else {
            wp_send_json_error(array('message' => 'Nie udało się usunąć konwersacji'));
        }
    }
    
    /**
     * Obsługuje przywracanie usuniętej konwersacji
     */
    public function restore_conversation() {
        // Sprawdź nonce
        check_ajax_referer('messenger_chat_nonce', 'nonce');
        
        $conversation_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : 0;
        $user_id = get_current_user_id();
        
        if ($conversation_id <= 0) {
            wp_send_json_error(array('message' => 'Nieprawidłowe ID konwersacji'));
            return;
        }
        
        // Załaduj klasę bazy danych
        require_once WP_MESSENGER_CHAT_DIR . 'includes/class-database.php';
        $database = new WP_Messenger_Chat_Database();
        
        // Przywróć usuniętą konwersację
        $result = $database->restore_conversation($conversation_id, $user_id);
        
        if ($result) {
            wp_send_json_success(array('message' => 'Konwersacja została przywrócona'));
        } else {
            wp_send_json_error(array('message' => 'Nie udało się przywrócić konwersacji'));
        }
    }
    
    /**
     * Obsługuje pobieranie usuniętych konwersacji
     */
    public function get_deleted_conversations() {
        // Sprawdź nonce
        check_ajax_referer('messenger_chat_nonce', 'nonce');
        
        $user_id = get_current_user_id();
        
        // Pobierz usunięte konwersacje
        require_once WP_MESSENGER_CHAT_DIR . 'includes/class-database.php';
        $database = new WP_Messenger_Chat_Database();
        $conversations = $database->get_deleted_conversations($user_id);
        
        // Przygotuj HTML z listą konwersacji
        ob_start();
        
        if (empty($conversations)) {
            echo '<div class="no-conversations">Brak usuniętych konwersacji</div>';
        } else {
            foreach ($conversations as $conv) {
                ?>
                <div class="conversation-item deleted" data-conversation-id="<?php echo esc_attr($conv->id); ?>" data-recipient-id="<?php echo esc_attr($conv->other_user_id); ?>">
                    <div class="conversation-avatar">
                        <img src="<?php echo esc_url($conv->other_user_avatar); ?>" alt="<?php echo esc_attr($conv->other_user_name); ?>">
                    </div>
                    <div class="conversation-info">
                        <div class="user-name"><?php echo esc_html($conv->other_user_name); ?></div>
                        <div class="last-message"><?php echo esc_html($conv->last_message); ?></div>
                    </div>
                    <div class="conversation-actions">
                        <button class="restore-conversation" data-conversation-id="<?php echo esc_attr($conv->id); ?>">
                            <span class="dashicons dashicons-undo"></span>
                        </button>
                    </div>
                </div>
                <?php
            }
        }
        
        $html = ob_get_clean();
        wp_send_json_success($html);
    }
    
    /**
     * Obsługuje oznaczanie wiadomości jako przeczytane
     */
    public function mark_messages_as_read() {
        // Sprawdź nonce
        check_ajax_referer('messenger_chat_nonce', 'nonce');
        
        $conversation_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : 0;
        $user_id = get_current_user_id();
        
        if ($conversation_id <= 0) {
            wp_send_json_error(array('message' => 'Nieprawidłowe ID konwersacji'));
            return;
        }
        
        // Załaduj klasę bazy danych
        require_once WP_MESSENGER_CHAT_DIR . 'includes/class-database.php';
        $database = new WP_Messenger_Chat_Database();
        
        // Oznacz wiadomości jako przeczytane
        $result = $database->mark_messages_as_read($conversation_id, $user_id);
        
        // Pobierz ID odbiorcy (nadawcy wiadomości)
        $recipient_id = $database->get_recipient_id($conversation_id, $user_id);
        
        if ($result) {
            // Załaduj klasę WebSocket
            require_once WP_MESSENGER_CHAT_DIR . 'includes/class-websocket.php';
            $websocket = new WP_Messenger_Chat_WebSocket();
            
            // Wyślij powiadomienie o przeczytaniu wiadomości
            $websocket->send_read_receipt($conversation_id, $recipient_id, $user_id);
            
            wp_send_json_success(array('message' => 'Wiadomości oznaczone jako przeczytane'));
        } else {
            wp_send_json_error(array('message' => 'Nie udało się oznaczyć wiadomości jako przeczytane'));
        }
    }
    
    /**
     * Obsługuje pobieranie statusu przeczytania wiadomości
     */
    public function get_message_read_status() {
        // Sprawdź nonce
        check_ajax_referer('messenger_chat_nonce', 'nonce');
        
        $message_id = isset($_POST['message_id']) ? intval($_POST['message_id']) : 0;
        
        if ($message_id <= 0) {
            wp_send_json_error(array('message' => 'Nieprawidłowe ID wiadomości'));
            return;
        }
        
        // Załaduj klasę bazy danych
        require_once WP_MESSENGER_CHAT_DIR . 'includes/class-database.php';
        $database = new WP_Messenger_Chat_Database();
        
        // Sprawdź status przeczytania
        $read_at = $database->is_message_read($message_id);
        
        wp_send_json_success(array(
            'message_id' => $message_id,
            'is_read' => $read_at !== false,
            'read_at' => $read_at
        ));
    }
}
