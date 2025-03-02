<?php
/**
 * Encryption utilities for the plugin
 * 
 * @package WP_Messenger_Chat
 */

// Zabezpieczenie przed bezpośrednim dostępem
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Klasa obsługująca szyfrowanie i deszyfrowanie wiadomości
 */
class WP_Messenger_Chat_Encryption {

    /**
     * Klucz szyfrowania
     * 
     * @var string
     */
    private $encryption_key;

    /**
     * Konstruktor
     */
    public function __construct() {
        // Dla celów testowych używamy stałego klucza
        // W środowisku produkcyjnym klucz powinien być pobierany z opcji WordPress
        $this->encryption_key = 'this_is_a_secure_encryption_key_for_testing';
        
        // W rzeczywistym środowisku WordPress kod wyglądałby tak:
        // $this->encryption_key = get_option('messenger_chat_encryption_key', 'default_encryption_key');
        // if ($this->encryption_key === 'default_encryption_key') {
        //     $this->encryption_key = $this->generate_encryption_key();
        //     update_option('messenger_chat_encryption_key', $this->encryption_key);
        // }
    }

    /**
     * Generuje losowy klucz szyfrowania
     * 
     * @return string Klucz szyfrowania
     */
    private function generate_encryption_key() {
        // Dla celów testowych generujemy prosty klucz
        // W środowisku produkcyjnym używalibyśmy wp_generate_password()
        return bin2hex(random_bytes(16));
    }

    /**
     * Szyfruje wiadomość
     * 
     * @param string $message Wiadomość do zaszyfrowania
     * @return string Zaszyfrowana wiadomość
     */
    public function encrypt($message) {
        if (empty($message)) {
            return $message;
        }

        // Użyj OpenSSL do szyfrowania
        $cipher = 'aes-256-cbc';
        $iv_length = openssl_cipher_iv_length($cipher);
        $iv = openssl_random_pseudo_bytes($iv_length);
        $encrypted = openssl_encrypt($message, $cipher, $this->encryption_key, 0, $iv);
        
        // Połącz IV i zaszyfrowaną wiadomość i zakoduj base64
        return base64_encode($iv . $encrypted);
    }

    /**
     * Deszyfruje wiadomość
     * 
     * @param string $encrypted_message Zaszyfrowana wiadomość
     * @return string Odszyfrowana wiadomość
     */
    public function decrypt($encrypted_message) {
        if (empty($encrypted_message)) {
            return $encrypted_message;
        }

        try {
            // Dekoduj base64
            $decoded = base64_decode($encrypted_message);
            if ($decoded === false) {
                // Nie jest to prawidłowy base64, zwróć oryginalną wiadomość
                return $encrypted_message;
            }
            
            // Pobierz IV i zaszyfrowaną wiadomość
            $cipher = 'aes-256-cbc';
            $iv_length = openssl_cipher_iv_length($cipher);
            
            // Sprawdź, czy zdekodowana wiadomość jest wystarczająco długa
            if (strlen($decoded) <= $iv_length) {
                // Wiadomość jest za krótka, zwróć oryginalną
                return $encrypted_message;
            }
            
            $iv = substr($decoded, 0, $iv_length);
            $encrypted = substr($decoded, $iv_length);
            
            // Deszyfruj
            $decrypted = openssl_decrypt($encrypted, $cipher, $this->encryption_key, 0, $iv);
            
            // Jeśli deszyfrowanie się nie powiodło, zwróć oryginalną wiadomość
            return $decrypted !== false ? $decrypted : $encrypted_message;
        } catch (Exception $e) {
            error_log('Błąd deszyfrowania: ' . $e->getMessage());
            return $encrypted_message; // Zwróć oryginalną wiadomość w przypadku błędu
        }
    }
}
