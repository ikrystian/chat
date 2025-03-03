<?php
/**
 * Database operations for the plugin
 * 
 * @package WP_Messenger_Chat
 */

// Zabezpieczenie przed bezpośrednim dostępem
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Klasa obsługująca operacje bazodanowe
 */
class WP_Messenger_Chat_Database {

    /**
     * Tworzy tabele w bazie danych
     */
    public function create_tables() {
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
            archived tinyint(1) DEFAULT 0 NOT NULL,
            deleted tinyint(1) DEFAULT 0 NOT NULL,
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
        
        // Tabela zablokowanych użytkowników
        $table_blocked_users = $wpdb->prefix . 'messenger_blocked_users';
        $sql_blocked_users = "CREATE TABLE $table_blocked_users (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            blocked_user_id bigint(20) NOT NULL,
            blocked_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY user_blocked_user (user_id, blocked_user_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_messages);
        dbDelta($sql_conversations);
        dbDelta($sql_participants);
        dbDelta($sql_blocked_users);
    }

    /**
     * Pobiera konwersacje użytkownika
     *
     * @param int $user_id ID użytkownika
     * @param bool $archived Czy pobierać zarchiwizowane konwersacje
     * @param bool $deleted Czy pobierać usunięte konwersacje
     * @return array Lista konwersacji
     */
    public function get_user_conversations($user_id, $archived = false, $deleted = false) {
        global $wpdb;

        $table_participants = $wpdb->prefix . 'messenger_participants';
        $table_conversations = $wpdb->prefix . 'messenger_conversations';
        $table_messages = $wpdb->prefix . 'messenger_messages';

        $query = "
            SELECT c.id, c.updated_at, c.archived, c.deleted,
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
            WHERE p.user_id = %d AND c.archived = %d AND c.deleted = %d
            ORDER BY c.updated_at DESC
        ";

        $results = $wpdb->get_results($wpdb->prepare($query, $user_id, $user_id, $archived ? 1 : 0, $deleted ? 1 : 0));

        // Załaduj klasę szyfrowania
        require_once WP_MESSENGER_CHAT_DIR . 'includes/class-encryption.php';
        $encryption = new WP_Messenger_Chat_Encryption();

        // Dodaj dane użytkowników i odszyfruj ostatnią wiadomość
        foreach ($results as $conv) {
            // Odszyfruj ostatnią wiadomość
            if (!empty($conv->last_message)) {
                $conv->last_message = $encryption->decrypt($conv->last_message);
            }
            
            if ($conv->other_user_id) {
                $user = get_userdata($conv->other_user_id);
                $conv->other_user_name = $user ? $user->display_name : 'Nieznany użytkownik';
                $conv->other_user_avatar = get_avatar_url($conv->other_user_id);
            }
        }

        return $results;
    }

    /**
     * Pobiera wiadomości z konwersacji
     *
     * @param int $conversation_id ID konwersacji
     * @param int $user_id ID użytkownika
     * @return array|false Lista wiadomości lub false jeśli użytkownik nie ma dostępu
     */
    public function get_messages($conversation_id, $user_id) {
        // Sprawdź czy użytkownik ma dostęp do konwersacji
        if (!$this->user_in_conversation($user_id, $conversation_id)) {
            return false;
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

        // Załaduj klasę szyfrowania
        require_once WP_MESSENGER_CHAT_DIR . 'includes/class-encryption.php';
        $encryption = new WP_Messenger_Chat_Encryption();

        // Dodaj dane użytkowników i odszyfruj wiadomości
        foreach ($messages as $message) {
            // Odszyfruj wiadomość
            $message->message = $encryption->decrypt($message->message);
            
            $sender = get_userdata($message->sender_id);
            $message->sender_name = $sender ? $sender->display_name : 'Nieznany';
            $message->sender_avatar = get_avatar_url($message->sender_id);
            $message->is_mine = ($message->sender_id == $user_id);
        }

        return $messages;
    }

    /**
     * Zapisuje nową wiadomość
     *
     * @param array $message_data Dane wiadomości
     * @return int|false ID nowej wiadomości lub false w przypadku błędu
     */
    public function save_message($message_data) {
        global $wpdb;
        $table_messages = $wpdb->prefix . 'messenger_messages';
        $table_conversations = $wpdb->prefix . 'messenger_conversations';

        // Załaduj klasę szyfrowania
        require_once WP_MESSENGER_CHAT_DIR . 'includes/class-encryption.php';
        $encryption = new WP_Messenger_Chat_Encryption();

        // Zaszyfruj wiadomość przed zapisaniem
        if (isset($message_data['message']) && !empty($message_data['message'])) {
            $message_data['message'] = $encryption->encrypt($message_data['message']);
        }

        $result = $wpdb->insert($table_messages, $message_data);

        if ($result) {
            // Aktualizuj czas aktualizacji konwersacji
            $wpdb->update(
                $table_conversations,
                array('updated_at' => current_time('mysql')),
                array('id' => $message_data['conversation_id'])
            );

            return $wpdb->insert_id;
        }

        return false;
    }

    /**
     * Tworzy nową konwersację między dwoma użytkownikami
     *
     * @param int $user1_id ID pierwszego użytkownika
     * @param int $user2_id ID drugiego użytkownika
     * @return int ID konwersacji
     */
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

    /**
     * Sprawdza czy użytkownik jest uczestnikiem konwersacji
     *
     * @param int $user_id ID użytkownika
     * @param int $conversation_id ID konwersacji
     * @return bool Czy użytkownik ma dostęp
     */
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



    /**
     * Pobiera ID odbiorcy (drugiego uczestnika konwersacji)
     *
     * @param int $conversation_id ID konwersacji
     * @param int $user_id ID bieżącego użytkownika
     * @return int|null ID odbiorcy lub null
     */
    public function get_recipient_id($conversation_id, $user_id) {
        global $wpdb;
        $table_participants = $wpdb->prefix . 'messenger_participants';

        return $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$table_participants} 
             WHERE conversation_id = %d AND user_id != %d",
            $conversation_id, $user_id
        ));
    }

    /**
     * Archiwizuje konwersację
     *
     * @param int $conversation_id ID konwersacji
     * @param int $user_id ID użytkownika (dla weryfikacji dostępu)
     * @return bool Czy operacja się powiodła
     */
    public function archive_conversation($conversation_id, $user_id) {
        // Sprawdź czy użytkownik ma dostęp do konwersacji
        if (!$this->user_in_conversation($user_id, $conversation_id)) {
            return false;
        }

        global $wpdb;
        $table_conversations = $wpdb->prefix . 'messenger_conversations';

        $result = $wpdb->update(
            $table_conversations,
            array('archived' => 1),
            array('id' => $conversation_id)
        );

        return $result !== false;
    }

    /**
     * Przywraca zarchiwizowaną konwersację
     *
     * @param int $conversation_id ID konwersacji
     * @param int $user_id ID użytkownika (dla weryfikacji dostępu)
     * @return bool Czy operacja się powiodła
     */
    public function unarchive_conversation($conversation_id, $user_id) {
        // Sprawdź czy użytkownik ma dostęp do konwersacji
        if (!$this->user_in_conversation($user_id, $conversation_id)) {
            return false;
        }

        global $wpdb;
        $table_conversations = $wpdb->prefix . 'messenger_conversations';

        $result = $wpdb->update(
            $table_conversations,
            array('archived' => 0),
            array('id' => $conversation_id)
        );

        return $result !== false;
    }

    /**
     * Usuwa konwersację (soft delete)
     *
     * @param int $conversation_id ID konwersacji
     * @param int $user_id ID użytkownika (dla weryfikacji dostępu)
     * @return bool Czy operacja się powiodła
     */
    public function delete_conversation($conversation_id, $user_id) {
        // Sprawdź czy użytkownik ma dostęp do konwersacji
        if (!$this->user_in_conversation($user_id, $conversation_id)) {
            return false;
        }

        global $wpdb;
        $table_conversations = $wpdb->prefix . 'messenger_conversations';

        $result = $wpdb->update(
            $table_conversations,
            array('deleted' => 1),
            array('id' => $conversation_id)
        );

        return $result !== false;
    }

    /**
     * Przywraca usuniętą konwersację
     *
     * @param int $conversation_id ID konwersacji
     * @param int $user_id ID użytkownika (dla weryfikacji dostępu)
     * @return bool Czy operacja się powiodła
     */
    public function restore_conversation($conversation_id, $user_id) {
        // Sprawdź czy użytkownik ma dostęp do konwersacji
        if (!$this->user_in_conversation($user_id, $conversation_id)) {
            return false;
        }

        global $wpdb;
        $table_conversations = $wpdb->prefix . 'messenger_conversations';

        $result = $wpdb->update(
            $table_conversations,
            array('deleted' => 0),
            array('id' => $conversation_id)
        );

        return $result !== false;
    }

    /**
     * Pobiera usunięte konwersacje użytkownika
     *
     * @param int $user_id ID użytkownika
     * @return array Lista usuniętych konwersacji
     */
    public function get_deleted_conversations($user_id) {
        return $this->get_user_conversations($user_id, false, true);
    }

    /**
     * Oznacza wiadomości jako przeczytane
     *
     * @param int $conversation_id ID konwersacji
     * @param int $user_id ID użytkownika (odbiorca)
     * @return bool Czy operacja się powiodła
     */
    public function mark_messages_as_read($conversation_id, $user_id) {
        // Sprawdź czy użytkownik ma dostęp do konwersacji
        if (!$this->user_in_conversation($user_id, $conversation_id)) {
            return false;
        }

        global $wpdb;
        $table_messages = $wpdb->prefix . 'messenger_messages';

        // Oznacz jako przeczytane tylko wiadomości, które nie zostały wysłane przez bieżącego użytkownika
        // i które nie zostały jeszcze przeczytane
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table_messages} 
             SET read_at = %s 
             WHERE conversation_id = %d 
             AND sender_id != %d 
             AND (read_at IS NULL OR read_at = '0000-00-00 00:00:00')",
            current_time('mysql'),
            $conversation_id,
            $user_id
        ));

        return $result !== false;
    }

    /**
     * Sprawdza czy wiadomość została przeczytana
     *
     * @param int $message_id ID wiadomości
     * @return bool|string Data przeczytania lub false jeśli nie przeczytano
     */
    public function is_message_read($message_id) {
        global $wpdb;
        $table_messages = $wpdb->prefix . 'messenger_messages';

        $read_at = $wpdb->get_var($wpdb->prepare(
            "SELECT read_at FROM {$table_messages} WHERE id = %d",
            $message_id
        ));

        if ($read_at && $read_at != '0000-00-00 00:00:00') {
            return $read_at;
        }

        return false;
    }

    /**
     * Pobiera wszystkie załączniki dla konwersacji
     *
     * @param int $conversation_id ID konwersacji
     * @param int $user_id ID użytkownika (dla weryfikacji dostępu)
     * @return array|false Lista załączników lub false jeśli użytkownik nie ma dostępu
     */
    public function get_conversation_attachments($conversation_id, $user_id) {
        // Sprawdź czy użytkownik ma dostęp do konwersacji
        if (!$this->user_in_conversation($user_id, $conversation_id)) {
            return false;
        }

        global $wpdb;
        $table_messages = $wpdb->prefix . 'messenger_messages';

        $attachments = $wpdb->get_results($wpdb->prepare(
            "SELECT id, sender_id, attachment, sent_at 
             FROM {$table_messages} 
             WHERE conversation_id = %d AND attachment IS NOT NULL AND attachment != ''
             ORDER BY sent_at DESC",
            $conversation_id
        ));

        // Dodaj dane nadawców
        foreach ($attachments as $attachment) {
            $sender = get_userdata($attachment->sender_id);
            $attachment->sender_name = $sender ? $sender->display_name : 'Nieznany';
            $attachment->is_mine = ($attachment->sender_id == $user_id);
        }

        return $attachments;
    }

    /**
     * Pobiera nieprzeczytane wiadomości dla użytkownika
     *
     * @param int $user_id ID użytkownika
     * @return array Lista nieprzeczytanych wiadomości
     */
    public function get_unread_messages_count($user_id) {
        global $wpdb;
        $table_messages = $wpdb->prefix . 'messenger_messages';
        $table_participants = $wpdb->prefix . 'messenger_participants';

        // Pobierz liczbę nieprzeczytanych wiadomości dla każdej konwersacji
        $query = "
            SELECT m.conversation_id, COUNT(*) as count
            FROM {$table_messages} m
            JOIN {$table_participants} p ON m.conversation_id = p.conversation_id
            WHERE p.user_id = %d
            AND m.sender_id != %d
            AND (m.read_at IS NULL OR m.read_at = '0000-00-00 00:00:00')
            GROUP BY m.conversation_id
        ";

        $results = $wpdb->get_results($wpdb->prepare($query, $user_id, $user_id), ARRAY_A);
        
        // Przekształć wyniki na tablicę z kluczami conversation_id
        $unread_counts = array();
        foreach ($results as $row) {
            $unread_counts[$row['conversation_id']] = (int)$row['count'];
        }

        return $unread_counts;
    }

    /**
     * Blokuje użytkownika
     *
     * @param int $user_id ID użytkownika blokującego
     * @param int $blocked_user_id ID blokowanego użytkownika
     * @return bool Czy operacja się powiodła
     */
    public function block_user($user_id, $blocked_user_id) {
        global $wpdb;
        $table_blocked_users = $wpdb->prefix . 'messenger_blocked_users';

        // Sprawdź, czy użytkownik jest już zablokowany
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_blocked_users} 
             WHERE user_id = %d AND blocked_user_id = %d",
            $user_id, $blocked_user_id
        ));

        if ($existing > 0) {
            // Użytkownik jest już zablokowany
            return true;
        }

        // Zablokuj użytkownika
        $result = $wpdb->insert(
            $table_blocked_users,
            array(
                'user_id' => $user_id,
                'blocked_user_id' => $blocked_user_id,
                'blocked_at' => current_time('mysql')
            )
        );

        return $result !== false;
    }

    /**
     * Odblokowuje użytkownika
     *
     * @param int $user_id ID użytkownika odblokowującego
     * @param int $blocked_user_id ID odblokowanego użytkownika
     * @return bool Czy operacja się powiodła
     */
    public function unblock_user($user_id, $blocked_user_id) {
        global $wpdb;
        $table_blocked_users = $wpdb->prefix . 'messenger_blocked_users';

        // Usuń wpis z tabeli zablokowanych użytkowników
        $result = $wpdb->delete(
            $table_blocked_users,
            array(
                'user_id' => $user_id,
                'blocked_user_id' => $blocked_user_id
            )
        );

        return $result !== false;
    }

    /**
     * Sprawdza, czy użytkownik jest zablokowany
     *
     * @param int $user_id ID użytkownika
     * @param int $blocked_user_id ID potencjalnie zablokowanego użytkownika
     * @return bool Czy użytkownik jest zablokowany
     */
    public function is_user_blocked($user_id, $blocked_user_id) {
        global $wpdb;
        $table_blocked_users = $wpdb->prefix . 'messenger_blocked_users';

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_blocked_users} 
             WHERE user_id = %d AND blocked_user_id = %d",
            $user_id, $blocked_user_id
        ));

        return intval($count) > 0;
    }

    /**
     * Pobiera listę zablokowanych użytkowników
     *
     * @param int $user_id ID użytkownika
     * @return array Lista zablokowanych użytkowników
     */
    public function get_blocked_users($user_id) {
        global $wpdb;
        $table_blocked_users = $wpdb->prefix . 'messenger_blocked_users';

        $blocked_users = $wpdb->get_results($wpdb->prepare(
            "SELECT blocked_user_id, blocked_at 
             FROM {$table_blocked_users} 
             WHERE user_id = %d
             ORDER BY blocked_at DESC",
            $user_id
        ));

        // Dodaj dane użytkowników
        foreach ($blocked_users as $blocked_user) {
            $user = get_userdata($blocked_user->blocked_user_id);
            $blocked_user->display_name = $user ? $user->display_name : 'Nieznany użytkownik';
            $blocked_user->avatar = get_avatar_url($blocked_user->blocked_user_id);
        }

        return $blocked_users;
    }
}
