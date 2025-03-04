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
        
        // Ajax dla pobrania informacji o użytkowniku
        add_action('wp_ajax_get_user_info', array($this, 'get_user_info'));
        add_action('wp_ajax_nopriv_get_user_info', array($this, 'get_user_info'));
        
        // Ajax dla pobrania załączników konwersacji
        add_action('wp_ajax_get_conversation_attachments', array($this, 'get_conversation_attachments'));
        add_action('wp_ajax_nopriv_get_conversation_attachments', array($this, 'get_conversation_attachments'));
        
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
        
        // Ajax dla blokowania użytkownika
        add_action('wp_ajax_block_user', array($this, 'block_user'));
        
        // Ajax dla odblokowania użytkownika
        add_action('wp_ajax_unblock_user', array($this, 'unblock_user'));
        
        // Ajax dla pobrania listy zablokowanych użytkowników
        add_action('wp_ajax_get_blocked_users', array($this, 'get_blocked_users'));
        
        // Ajax dla sprawdzenia, czy użytkownik jest zablokowany
        add_action('wp_ajax_is_user_blocked', array($this, 'is_user_blocked'));
    }

    /**
     * Obsługuje pobieranie wiadomości
     */
    public function get_messages() {
        // Sprawdź nonce
        check_ajax_referer('messenger_chat_nonce', 'nonce');

        $conversation_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : 0;
        $user_id = get_current_user_id();
        
        // Parametry paginacji
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 20; // Domyślnie 20 wiadomości
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;

        // Pobierz wiadomości
        require_once WP_MESSENGER_CHAT_DIR . 'includes/class-database.php';
        $database = new WP_Messenger_Chat_Database();
        $messages = $database->get_messages($conversation_id, $user_id, $limit, $offset);

        if ($messages === false) {
            wp_send_json_error(array('message' => 'Brak dostępu do tej konwersacji'));
            return;
        }

        // Oznacz wiadomości jako przeczytane tylko przy pierwszym ładowaniu (offset = 0)
        if ($offset === 0) {
            $database->mark_messages_as_read($conversation_id, $user_id);
            
            // Pobierz ID odbiorcy (nadawcy wiadomości)
            $recipient_id = $database->get_recipient_id($conversation_id, $user_id);
            
            // Wyślij powiadomienie o przeczytaniu wiadomości przez WebSocket
            if ($recipient_id) {
                require_once WP_MESSENGER_CHAT_DIR . 'includes/class-websocket.php';
                $websocket = new WP_Messenger_Chat_WebSocket();
                $websocket->send_read_receipt($conversation_id, $recipient_id, $user_id);
            }
        }
        
        // Pobierz całkowitą liczbę wiadomości w konwersacji
        $total_messages = $database->get_messages_count($conversation_id);

        wp_send_json_success(array(
            'messages' => $messages,
            'total' => $total_messages,
            'has_more' => ($offset + $limit < $total_messages)
        ));
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
                    <div class="conversation-actions">
                        <button class="view-attachments" data-conversation-id="<?php echo esc_attr($conv->id); ?>" title="Załączniki">
                            <span class="dashicons dashicons-paperclip"></span>
                        </button>
                        <button class="archive-conversation" data-conversation-id="<?php echo esc_attr($conv->id); ?>" title="Archiwizuj">
                            <span class="dashicons dashicons-archive"></span>
                        </button>
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
            // Sprawdź, czy użytkownik jest zablokowany
            if ($database->is_user_blocked($user_id, $recipient_id)) {
                wp_send_json_error(array('message' => 'Nie możesz wysłać wiadomości do tego użytkownika, ponieważ go zablokowałeś'));
                return;
            }
            
            // Sprawdź, czy użytkownik zablokował nadawcę
            if ($database->is_user_blocked($recipient_id, $user_id)) {
                wp_send_json_error(array('message' => 'Nie możesz wysłać wiadomości do tego użytkownika, ponieważ on Cię zablokował'));
                return;
            }
            
            $conversation_id = $database->create_conversation($user_id, $recipient_id);
            $is_new_conversation = true;
        }

        // Sprawdź czy użytkownik ma dostęp do konwersacji
        if (!$database->user_in_conversation($user_id, $conversation_id)) {
            wp_send_json_error(array('message' => 'Brak dostępu do tej konwersacji'));
            return;
        }
        
        // Pobierz ID odbiorcy, jeśli nie został podany
        if (!$recipient_id) {
            $recipient_id = $database->get_recipient_id($conversation_id, $user_id);
        }
        
        // Sprawdź, czy użytkownik jest zablokowany
        if ($database->is_user_blocked($user_id, $recipient_id)) {
            wp_send_json_error(array('message' => 'Nie możesz wysłać wiadomości do tego użytkownika, ponieważ go zablokowałeś'));
            return;
        }
        
        // Sprawdź, czy użytkownik zablokował nadawcę
        if ($database->is_user_blocked($recipient_id, $user_id)) {
            wp_send_json_error(array('message' => 'Nie możesz wysłać wiadomości do tego użytkownika, ponieważ on Cię zablokował'));
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
            
            // Pobierz imię i nazwisko nadawcy
            $first_name = get_user_meta($user_id, 'first_name', true);
            $last_name = get_user_meta($user_id, 'last_name', true);
            
            // Jeśli imię i nazwisko są dostępne, użyj ich
            if (!empty($first_name) && !empty($last_name)) {
                $new_message->sender_name = $first_name . ' ' . $last_name;
            } else {
                // W przeciwnym razie użyj display_name
                $new_message->sender_name = $sender ? $sender->display_name : 'Nieznany';
            }
            
            $new_message->sender_avatar = get_avatar_url($user_id);
            $new_message->is_mine = true;

            // Pobierz ID odbiorcy (drugi uczestnik konwersacji)
            if (!$recipient_id) {
                $recipient_id = $database->get_recipient_id($conversation_id, $user_id);
            }

            // Wyślij powiadomienie przez WebSocket
            if ($is_new_conversation) {
                $websocket->send_new_conversation($conversation_id, $recipient_id, $user_id, $sender, $new_message);
                
                // Wyślij powiadomienie email o nowej konwersacji
                $this->send_new_conversation_email($conversation_id, $recipient_id, $user_id, $sender, $new_message);
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
                        <button class="view-attachments" data-conversation-id="<?php echo esc_attr($conv->id); ?>" title="Załączniki">
                            <span class="dashicons dashicons-paperclip"></span>
                        </button>
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
                        <button class="view-attachments" data-conversation-id="<?php echo esc_attr($conv->id); ?>" title="Załączniki">
                            <span class="dashicons dashicons-paperclip"></span>
                        </button>
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
    
    /**
     * Obsługuje pobieranie załączników konwersacji
     */
    public function get_conversation_attachments() {
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
        
        // Pobierz załączniki
        $attachments = $database->get_conversation_attachments($conversation_id, $user_id);
        
        if ($attachments === false) {
            wp_send_json_error(array('message' => 'Brak dostępu do tej konwersacji'));
            return;
        }
        
        // Przygotuj dane załączników
        $upload_dir = wp_upload_dir();
        $attachments_url = $upload_dir['baseurl'] . '/messenger-attachments/';
        
        foreach ($attachments as &$attachment) {
            // Dodaj pełny URL do załącznika
            $attachment->url = $attachments_url . $attachment->attachment;
            
            // Dodaj nazwę pliku (bez ścieżki)
            $attachment->filename = basename($attachment->attachment);
            
            // Sformatuj datę
            $attachment->formatted_date = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($attachment->sent_at));
        }
        
        wp_send_json_success($attachments);
    }
    
    /**
     * Obsługuje pobieranie informacji o użytkowniku
     */
    public function get_user_info() {
        // Sprawdź nonce
        check_ajax_referer('messenger_chat_nonce', 'nonce');
        
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        
        if ($user_id <= 0) {
            wp_send_json_error(array('message' => 'Nieprawidłowe ID użytkownika'));
            return;
        }
        
        // Pobierz dane użytkownika
        $user = get_userdata($user_id);
        
        if (!$user) {
            wp_send_json_error(array('message' => 'Użytkownik nie istnieje'));
            return;
        }
        
        // Załaduj klasę bazy danych
        require_once WP_MESSENGER_CHAT_DIR . 'includes/class-database.php';
        $database = new WP_Messenger_Chat_Database();
        
        // Sprawdź, czy użytkownik jest zablokowany
        $current_user_id = get_current_user_id();
        $is_blocked = $database->is_user_blocked($current_user_id, $user_id);
        
        // Pobierz imię i nazwisko użytkownika
        $first_name = get_user_meta($user_id, 'first_name', true);
        $last_name = get_user_meta($user_id, 'last_name', true);
        
        // Ustal nazwę użytkownika do wyświetlenia
        $display_name = $user->display_name;
        if (!empty($first_name) && !empty($last_name)) {
            $display_name = $first_name . ' ' . $last_name;
        }
        
        // Przygotuj dane użytkownika do wyświetlenia
        $user_info = array(
            'id' => $user->ID,
            'display_name' => $display_name,
            'user_email' => $user->user_email,
            'user_registered' => date_i18n(get_option('date_format'), strtotime($user->user_registered)),
            'avatar' => get_avatar_url($user->ID, array('size' => 150)),
            'role' => implode(', ', array_map(function($role) {
                return translate_user_role($role);
            }, $user->roles)),
            'is_blocked' => $is_blocked
        );
        
        // Dodaj dodatkowe pola profilu, jeśli istnieją
        $user_info['description'] = get_user_meta($user->ID, 'description', true);
        
        wp_send_json_success($user_info);
    }
    
    /**
     * Obsługuje blokowanie użytkownika
     */
    public function block_user() {
        // Sprawdź nonce
        check_ajax_referer('messenger_chat_nonce', 'nonce');
        
        $blocked_user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        $user_id = get_current_user_id();
        
        if ($blocked_user_id <= 0) {
            wp_send_json_error(array('message' => 'Nieprawidłowe ID użytkownika'));
            return;
        }
        
        if ($blocked_user_id === $user_id) {
            wp_send_json_error(array('message' => 'Nie możesz zablokować samego siebie'));
            return;
        }
        
        // Załaduj klasę bazy danych
        require_once WP_MESSENGER_CHAT_DIR . 'includes/class-database.php';
        $database = new WP_Messenger_Chat_Database();
        
        // Zablokuj użytkownika
        $result = $database->block_user($user_id, $blocked_user_id);
        
        if ($result) {
            wp_send_json_success(array('message' => 'Użytkownik został zablokowany'));
        } else {
            wp_send_json_error(array('message' => 'Nie udało się zablokować użytkownika'));
        }
    }
    
    /**
     * Obsługuje odblokowanie użytkownika
     */
    public function unblock_user() {
        // Sprawdź nonce
        check_ajax_referer('messenger_chat_nonce', 'nonce');
        
        $blocked_user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        $user_id = get_current_user_id();
        
        if ($blocked_user_id <= 0) {
            wp_send_json_error(array('message' => 'Nieprawidłowe ID użytkownika'));
            return;
        }
        
        // Załaduj klasę bazy danych
        require_once WP_MESSENGER_CHAT_DIR . 'includes/class-database.php';
        $database = new WP_Messenger_Chat_Database();
        
        // Odblokuj użytkownika
        $result = $database->unblock_user($user_id, $blocked_user_id);
        
        if ($result) {
            wp_send_json_success(array('message' => 'Użytkownik został odblokowany'));
        } else {
            wp_send_json_error(array('message' => 'Nie udało się odblokować użytkownika'));
        }
    }
    
    /**
     * Obsługuje pobieranie listy zablokowanych użytkowników
     */
    public function get_blocked_users() {
        // Sprawdź nonce
        check_ajax_referer('messenger_chat_nonce', 'nonce');
        
        $user_id = get_current_user_id();
        
        // Załaduj klasę bazy danych
        require_once WP_MESSENGER_CHAT_DIR . 'includes/class-database.php';
        $database = new WP_Messenger_Chat_Database();
        
        // Pobierz zablokowanych użytkowników
        $blocked_users = $database->get_blocked_users($user_id);
        
        // Przygotuj HTML z listą zablokowanych użytkowników
        ob_start();
        
        if (empty($blocked_users)) {
            echo '<div class="no-blocked-users">Brak zablokowanych użytkowników</div>';
        } else {
            echo '<div class="blocked-users-list">';
            foreach ($blocked_users as $blocked_user) {
                ?>
                <div class="blocked-user-item" data-user-id="<?php echo esc_attr($blocked_user->blocked_user_id); ?>">
                    <div class="blocked-user-avatar">
                        <img src="<?php echo esc_url($blocked_user->avatar); ?>" alt="<?php echo esc_attr($blocked_user->display_name); ?>">
                    </div>
                    <div class="blocked-user-info">
                        <div class="blocked-user-name"><?php echo esc_html($blocked_user->display_name); ?></div>
                        <div class="blocked-at">Zablokowano: <?php echo date_i18n(get_option('date_format'), strtotime($blocked_user->blocked_at)); ?></div>
                    </div>
                    <div class="blocked-user-actions">
                        <button class="unblock-user" data-user-id="<?php echo esc_attr($blocked_user->blocked_user_id); ?>">
                            <span class="dashicons dashicons-unlock"></span> Odblokuj
                        </button>
                    </div>
                </div>
                <?php
            }
            echo '</div>';
        }
        
        $html = ob_get_clean();
        wp_send_json_success($html);
    }
    
    /**
     * Wysyła powiadomienie email o nowej konwersacji
     *
     * @param int $conversation_id ID konwersacji
     * @param int $recipient_id ID odbiorcy
     * @param int $sender_id ID nadawcy
     * @param object $sender Obiekt użytkownika nadawcy
     * @param object $message Obiekt wiadomości
     * @return bool Czy wysłano pomyślnie
     */
    private function send_new_conversation_email($conversation_id, $recipient_id, $sender_id, $sender, $message) {
        // Pobierz dane odbiorcy
        $recipient = get_userdata($recipient_id);
        
        if (!$recipient || !is_object($recipient) || !isset($recipient->user_email)) {
            return false;
        }
        
        // Pobierz imię i nazwisko odbiorcy
        $first_name = get_user_meta($recipient_id, 'first_name', true);
        $last_name = get_user_meta($recipient_id, 'last_name', true);
        
        // Ustal nazwę odbiorcy do wyświetlenia
        $recipient_name = $recipient->display_name;
        if (!empty($first_name) && !empty($last_name)) {
            $recipient_name = $first_name . ' ' . $last_name;
        }
        
        // Przygotuj dane do emaila
        $site_name = get_bloginfo('name');
        $site_url = get_bloginfo('url');
        $chat_page_url = get_option('messenger_chat_page', home_url());
        
        // Dodaj parametr do URL, aby automatycznie otworzyć konwersację
        $chat_url = add_query_arg('chat_with', $sender_id, $chat_page_url);
        
        // Przygotuj treść wiadomości
        $message_content = !empty($message->message) ? $message->message : '(Załącznik)';
        if (strlen($message_content) > 100) {
            $message_content = substr($message_content, 0, 97) . '...';
        }
        
        // Pobierz imię i nazwisko nadawcy
        $sender_first_name = get_user_meta($sender_id, 'first_name', true);
        $sender_last_name = get_user_meta($sender_id, 'last_name', true);
        
        // Ustal nazwę nadawcy do wyświetlenia
        $sender_name = $sender->display_name;
        if (!empty($sender_first_name) && !empty($sender_last_name)) {
            $sender_name = $sender_first_name . ' ' . $sender_last_name;
        }
        
        // Temat emaila
        $subject = sprintf(__('Nowa wiadomość od %s na %s', 'wp-messenger-chat'), $sender_name, $site_name);
        
        // Treść emaila
        $body = sprintf(
            __('Witaj %s,

Otrzymałeś nową wiadomość od %s na %s.

Treść wiadomości:
"%s"

Kliknij poniższy link, aby odpowiedzieć:
%s

Pozdrawiamy,
Zespół %s', 'wp-messenger-chat'),
            $recipient_name,
            $sender->display_name,
            $site_name,
            $message_content,
            $chat_url,
            $site_name
        );
        
        // Nagłówki emaila
        $headers = array('Content-Type: text/plain; charset=UTF-8');
        
        // Wyślij email
        $result = wp_mail($recipient->user_email, $subject, $body, $headers);
        
        return $result;
    }
    
    /**
     * Obsługuje sprawdzenie, czy użytkownik jest zablokowany
     */
    public function is_user_blocked() {
        // Sprawdź nonce
        check_ajax_referer('messenger_chat_nonce', 'nonce');
        
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        $current_user_id = get_current_user_id();
        
        if ($user_id <= 0) {
            wp_send_json_error(array('message' => 'Nieprawidłowe ID użytkownika'));
            return;
        }
        
        // Załaduj klasę bazy danych
        require_once WP_MESSENGER_CHAT_DIR . 'includes/class-database.php';
        $database = new WP_Messenger_Chat_Database();
        
        // Sprawdź, czy użytkownik jest zablokowany
        $is_blocked = $database->is_user_blocked($current_user_id, $user_id);
        $is_blocking_me = $database->is_user_blocked($user_id, $current_user_id);
        
        wp_send_json_success(array(
            'is_blocked' => $is_blocked,
            'is_blocking_me' => $is_blocking_me
        ));
    }
}
