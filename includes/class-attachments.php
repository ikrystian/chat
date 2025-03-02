<?php
/**
 * Attachments handling for the plugin
 * 
 * @package WP_Messenger_Chat
 */

// Zabezpieczenie przed bezpośrednim dostępem
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Klasa obsługująca załączniki
 */
class WP_Messenger_Chat_Attachments {

    /**
     * Tworzy katalog na załączniki
     */
    public function create_directory() {
        $upload_dir = wp_upload_dir();
        $attachments_dir = $upload_dir['basedir'] . '/messenger-attachments';
        
        if (!file_exists($attachments_dir)) {
            wp_mkdir_p($attachments_dir);
            
            // Dodaj plik .htaccess dla zabezpieczenia
            $htaccess_content = "Options -Indexes\n";
            $htaccess_content .= "<FilesMatch '\.(php|php5|phtml|pht|phar|phps)$'>\n";
            $htaccess_content .= "Order Deny,Allow\n";
            $htaccess_content .= "Deny from all\n";
            $htaccess_content .= "</FilesMatch>\n";
            
            file_put_contents($attachments_dir . '/.htaccess', $htaccess_content);
        }
    }

    /**
     * Obsługuje przesyłanie załączników
     * 
     * @param array $file Dane pliku z $_FILES
     * @param int $conversation_id ID konwersacji
     * @return string|WP_Error Ścieżka do zapisanego pliku lub obiekt błędu
     */
    public function handle_upload($file, $conversation_id) {
        // Sprawdź typ pliku
        $file_type = wp_check_filetype($file['name']);
        if ($file_type['ext'] !== 'pdf') {
            return new WP_Error('invalid_file_type', 'Dozwolone są tylko pliki PDF.');
        }

        // Sprawdź rozmiar pliku (max 5MB)
        $max_size = 5 * 1024 * 1024; // 5MB
        if ($file['size'] > $max_size) {
            return new WP_Error('file_too_large', 'Maksymalny rozmiar pliku to 5MB.');
        }

        // Utwórz katalog dla konwersacji, jeśli nie istnieje
        $upload_dir = wp_upload_dir();
        $conversation_dir = $upload_dir['basedir'] . '/messenger-attachments/' . $conversation_id;
        if (!file_exists($conversation_dir)) {
            wp_mkdir_p($conversation_dir);
        }

        // Generuj unikalną nazwę pliku
        $filename = sanitize_file_name($file['name']);
        $filename = wp_unique_filename($conversation_dir, $filename);
        $new_file = $conversation_dir . '/' . $filename;

        // Przenieś plik do docelowego katalogu
        if (!move_uploaded_file($file['tmp_name'], $new_file)) {
            return new WP_Error('upload_error', 'Błąd podczas przesyłania pliku.');
        }

        // Zwróć względną ścieżkę do pliku (bez ścieżki bazowej)
        return $conversation_id . '/' . $filename;
    }
}
