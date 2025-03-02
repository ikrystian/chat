<?php
/**
 * Admin functionality for the plugin
 * 
 * @package WP_Messenger_Chat
 */

// Zabezpieczenie przed bezpośrednim dostępem
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Klasa obsługująca funkcje administracyjne
 */
class WP_Messenger_Chat_Admin {

    /**
     * Renderuje stronę administracyjną
     */
    public function render_admin_page() {
        // Obsługa zapisywania ustawień
        if (isset($_POST['messenger_chat_save_settings']) && check_admin_referer('messenger_chat_settings')) {
            $websocket_server = isset($_POST['messenger_chat_websocket_server']) ? 
                esc_url_raw($_POST['messenger_chat_websocket_server']) : 'ws://localhost:3000';
            
            update_option('messenger_chat_websocket_server', $websocket_server);
            
            echo '<div class="notice notice-success is-dismissible"><p>Ustawienia zostały zapisane.</p></div>';
        }
        
        // Pobierz aktualne ustawienia
        $websocket_server = get_option('messenger_chat_websocket_server', 'ws://localhost:3000');
        
        // Wyświetl formularz ustawień
        ?>
        <div class="wrap">
            <h1>Ustawienia WP Messenger Chat</h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('messenger_chat_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="messenger_chat_websocket_server">Adres serwera WebSocket</label></th>
                        <td>
                            <input type="text" id="messenger_chat_websocket_server" name="messenger_chat_websocket_server" 
                                value="<?php echo esc_attr($websocket_server); ?>" class="regular-text">
                            <p class="description">
                                Adres serwera WebSocket, np. ws://localhost:3000 (dla WebSocket) lub http://localhost:3000 (dla HTTP).
                            </p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="messenger_chat_save_settings" class="button button-primary" value="Zapisz ustawienia">
                </p>
            </form>
            
            <hr>
            
            <h2>Instrukcje</h2>
            
            <h3>Uruchomienie serwera WebSocket</h3>
            <p>Aby czat działał prawidłowo, musisz uruchomić serwer WebSocket. Wykonaj następujące kroki:</p>
            <ol>
                <li>Upewnij się, że masz zainstalowany Node.js na serwerze.</li>
                <li>Przejdź do katalogu pluginu: <code><?php echo esc_html(WP_MESSENGER_CHAT_DIR); ?></code></li>
                <li>Uruchom serwer poleceniem: <code>node server.js</code></li>
                <li>Serwer powinien wyświetlić komunikat: "Serwer WebSocket uruchomiony na porcie 3000"</li>
            </ol>
            
            <h3>Użycie shortcode</h3>
            <p>Aby wyświetlić czat na stronie, użyj shortcode:</p>
            <code>[messenger_chat]</code>
            
            <p>Aby wyświetlić przycisk do rozpoczęcia czatu z konkretnym użytkownikiem:</p>
            <code>[chat_button user_id="123" text="Rozpocznij czat" class="my-custom-class"]</code>
            
            <h3>Parametry przycisku czatu</h3>
            <ul>
                <li><strong>user_id</strong> (wymagane) - ID użytkownika, z którym chcemy rozpocząć czat</li>
                <li><strong>text</strong> (opcjonalne) - Tekst przycisku (domyślnie: "Rozpocznij czat")</li>
                <li><strong>class</strong> (opcjonalne) - Dodatkowe klasy CSS</li>
                <li><strong>redirect_url</strong> (opcjonalne) - URL strony z chatem</li>
            </ul>
        </div>
        <?php
    }
}
