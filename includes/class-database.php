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
            PRIMARY KEY  (id)
        ) $charset_collate;";

        // Tabela konwersacji
        $table_conversations = $wpdb->prefix . 'messenger_conversations';
        $sql_conversations = "CREATE TABLE $table_conversations (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            archived tinyint(1) DEFAULT 0 NOT NULL,
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

    /**
     * Pobiera konwersacje użytkownika
     *
     * @param int $user_id ID użytkownika
     * @param bool $archived Czy pobierać zarchiwizowane konwersacje
     * @return array Lista konwersacji
     */
    public function get_user_conversations($user_id, $archived = false) {
        global $wpdb;

        $table_participants = $wpdb->prefix . 'messenger_participants';
        $table_conversations = $wpdb->prefix . 'messenger_conversations';
        $table_messages = $wpdb->prefix . 'messenger_messages';

        $query = "
            SELECT c.id, c.updated_at, c.archived,
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
            WHERE p.user_id = %d AND c.archived = %d
            ORDER BY c.updated_at DESC
        ";

        $results = $wpdb->get_results($wpdb->prepare($query, $user_id, $user_id, $archived ? 1 : 0));

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

        // Dodaj dane użytkowników
        foreach ($messages as $message) {
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
}
