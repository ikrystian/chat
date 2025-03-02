<?php
/**
 * WebSocket integration for the plugin
 * 
 * @package WP_Messenger_Chat
 */

// Zabezpieczenie przed bezpośrednim dostępem
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Klasa obsługująca integrację z WebSocket
 */
class WP_Messenger_Chat_WebSocket {

    /**
     * Wysyła wiadomość do serwera WebSocket
     *
     * @param int $conversation_id ID konwersacji
     * @param int $recipient_id ID odbiorcy
     * @param object $message Obiekt wiadomości
     * @return bool Czy wysłano pomyślnie
     */
    public function send_message($conversation_id, $recipient_id, $message) {
        // Upewnij się, że wiadomość jest odszyfrowana przed wysłaniem przez WebSocket
        // Wiadomość jest już odszyfrowana w klasie API przed wywołaniem tej metody
        
        // Dane dla istniejącej konwersacji
        $socket_data = array(
            'conversation_id' => $conversation_id,
            'recipient_id' => $recipient_id,
            'message' => $message
        );

        return $this->send_to_server($socket_data);
    }

    /**
     * Wysyła powiadomienie o nowej konwersacji do serwera WebSocket
     *
     * @param int $conversation_id ID konwersacji
     * @param int $recipient_id ID odbiorcy
     * @param int $sender_id ID nadawcy
     * @param object $sender Obiekt użytkownika nadawcy
     * @param object $message Obiekt wiadomości
     * @return bool Czy wysłano pomyślnie
     */
    public function send_new_conversation($conversation_id, $recipient_id, $sender_id, $sender, $message) {
        // Upewnij się, że wiadomość jest odszyfrowana przed wysłaniem przez WebSocket
        // Wiadomość jest już odszyfrowana w klasie API przed wywołaniem tej metody
        
        // Dane dla nowej konwersacji
        $socket_data = array(
            'conversation_id' => $conversation_id,
            'recipient_id' => $recipient_id,
            'sender_id' => $sender_id,
            'sender_name' => $sender ? $sender->display_name : 'Nieznany użytkownik',
            'sender_avatar' => get_avatar_url($sender_id),
            'message' => array(
                'message' => $message->message,
                'attachment' => $message->attachment,
                'sent_at' => $message->sent_at,
                'sender_id' => $sender_id,
                'sender_name' => $sender ? $sender->display_name : 'Nieznany użytkownik',
                'sender_avatar' => get_avatar_url($sender_id),
                'is_mine' => false
            )
        );

        return $this->send_to_server($socket_data);
    }

    /**
     * Wysyła dane do serwera WebSocket
     *
     * @param array $data Dane do wysłania
     * @return bool Czy wysłano pomyślnie
     */
    private function send_to_server($data) {
        $socket_url = get_option('messenger_chat_websocket_server', 'http://localhost:3000') . '/send-message';
        
        $response = wp_remote_post($socket_url, array(
            'body' => json_encode($data),
            'headers' => array('Content-Type' => 'application/json'),
            'timeout' => 5
        ));

        if (is_wp_error($response)) {
            error_log('WebSocket Error: ' . $response->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        return $response_code >= 200 && $response_code < 300;
    }
}
