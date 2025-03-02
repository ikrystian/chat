<?php
/**
 * Shortcodes for the plugin
 * 
 * @package WP_Messenger_Chat
 */

// Zabezpieczenie przed bezpośrednim dostępem
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Klasa obsługująca shortcodes
 */
class WP_Messenger_Chat_Shortcodes {

    /**
     * Shortcode generujący przycisk do czatu z konkretnym użytkownikiem
     *
     * @param array $atts Atrybuty shortcode
     * @return string Kod HTML przycisku
     */
    public function chat_button_shortcode($atts) {
        $atts = shortcode_atts(array(
            'user_id' => 0,        // ID użytkownika, z którym chcemy rozpocząć czat
            'text' => 'Rozpocznij czat',  // Tekst przycisku
            'class' => '',         // Dodatkowe klasy CSS
            'redirect_url' => '',  // URL strony z chatem (opcjonalnie)
        ), $atts);

        // Sprawdź, czy użytkownik jest zalogowany
        if (!is_user_logged_in()) {
            return '<div class="messenger-login-required">Zaloguj się, aby rozpocząć czat.</div>';
        }

        // Pobierz ID zalogowanego użytkownika
        $current_user_id = get_current_user_id();

        // Sprawdź, czy podano prawidłowy ID użytkownika
        if (empty($atts['user_id']) || $atts['user_id'] == $current_user_id) {
            return '';
        }

        // Sprawdź, czy użytkownik docelowy istnieje
        $target_user = get_userdata($atts['user_id']);
        if (!$target_user) {
            return '';
        }

        // Ustal URL przekierowania
        $redirect_url = !empty($atts['redirect_url']) ? $atts['redirect_url'] : '';

        // Jeśli nie podano URL, poszukaj strony z shortcode [messenger_chat]
        if (empty($redirect_url)) {
            global $wpdb;
            $messenger_page = $wpdb->get_var("
                SELECT ID FROM {$wpdb->posts} 
                WHERE post_content LIKE '%[messenger_chat]%' 
                AND post_status = 'publish' 
                AND post_type IN ('post', 'page')
                LIMIT 1
            ");

            if ($messenger_page) {
                $redirect_url = get_permalink($messenger_page);
            } else {
                // Jeśli nie znaleziono strony, użyj aktualnej strony
                $redirect_url = get_permalink();
            }
        }

        // Dodaj parametr chat_with do URL
        $chat_url = add_query_arg('chat_with', $atts['user_id'], $redirect_url);

        // Wygeneruj przycisk
        $button_class = 'messenger-chat-button';
        if (!empty($atts['class'])) {
            $button_class .= ' ' . sanitize_html_class($atts['class']);
        }

        $button = sprintf(
            '<a href="%s" class="%s">%s</a>',
            esc_url($chat_url),
            esc_attr($button_class),
            esc_html($atts['text'])
        );

        // Dodaj style CSS dla przycisku
        $css = '
        <style>
            .messenger-chat-button {
                display: inline-block;
                padding: 8px 16px;
                background-color: #0084ff;
                color: #fff;
                border-radius: 20px;
                text-decoration: none;
                font-weight: bold;
                transition: background-color 0.3s;
            }
            
            .messenger-chat-button:hover {
                background-color: #006acc;
                color: #fff;
            }
        </style>';

        return $css . $button;
    }

    /**
     * Obsługuje przekierowanie i automatyczne otwieranie czatu
     */
    public function handle_chat_redirect() {
        // Sprawdź, czy istnieje parametr chat_with w URL
        if (!isset($_GET['chat_with'])) {
            return;
        }

        // Sprawdź, czy użytkownik jest zalogowany
        if (!is_user_logged_in()) {
            return;
        }

        $target_user_id = intval($_GET['chat_with']);

        // Sprawdź, czy ID użytkownika jest prawidłowy
        if ($target_user_id <= 0) {
            return;
        }

        // Dodaj skrypt JavaScript, który automatycznie otworzy konwersację
        add_action('wp_footer', function() use ($target_user_id) {
            ?>
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    // Poczekaj, aż czat się załaduje
                    setTimeout(function() {
                        // Znajdź kontakt z podanym ID i kliknij go
                        const contactItem = $('.contact-item[data-user-id="<?php echo $target_user_id; ?>"]');
                        if (contactItem.length) {
                            // Przełącz na zakładkę kontaktów, jeśli nie jest aktywna
                            $('.messenger-tabs a[data-tab="contacts"]').click();

                            // Kliknij na kontakt
                            contactItem.click();
                        } else {
                            // Sprawdź, czy istnieje już konwersacja
                            const existingConversation = $('.conversation-item').filter(function() {
                                // Sprawdź, czy konwersacja zawiera wybranego użytkownika
                                // Ta logika jest uproszczona - może wymagać dostosowania
                                return $(this).find('.user-name').text().includes(
                                    $('.contact-item[data-user-id="<?php echo $target_user_id; ?>"] .user-name').text()
                                );
                            });

                            if (existingConversation.length) {
                                existingConversation.first().click();
                            } else {
                                // Jeśli nie znaleziono konwersacji, przełącz na kontakty
                                $('.messenger-tabs a[data-tab="contacts"]').click();
                            }
                        }
                    }, 500);
                });
            </script>
            <?php
        });
    }
}
