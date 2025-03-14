<?php
// templates/messenger-chat.php
?>
<div class="messenger-container">
    <!-- Pasek boczny z konwersacjami -->
    <div class="messenger-conversations active">
        <div class="messenger-search-container">
            <input type="text" id="messenger-search" class="messenger-search" placeholder="Szukaj konwersacji...">
        </div>

        <div class="messenger-tabs">
            <a href="#" class="active" data-tab="conversations">Konwersacje</a>
            <a href="#" data-tab="archived">Archiwum</a>
            <a href="#" data-tab="deleted">Usunięte</a>
        </div>

        <!-- Lista konwersacji -->
        <div class="messenger-conversations-list">
            <?php if (empty($conversations)): ?>
                <div class="empty-state">
                    <p>Nie masz jeszcze żadnych konwersacji</p>
                    <p>Wybierz kontakt, aby rozpocząć czat</p>
                </div>
            <?php else: ?>
                <?php foreach ($conversations as $conversation): ?>
                    <div class="conversation-item" data-conversation-id="<?php echo esc_attr($conversation->id); ?>">
                        <div class="conversation-avatar">
                            <img src="<?php echo esc_url($conversation->other_user_avatar); ?>" alt="<?php echo esc_attr($conversation->other_user_name); ?>">
                            <?php if (rand(0, 1)): // Przykładowy status online - w rzeczywistej aplikacji bazowałby na danych użytkownika ?>
                                <span class="online-status"></span>
                            <?php endif; ?>
                        </div>
                        <div class="conversation-info">
                            <div class="conversation-header">
                                <span class="user-name"><?php echo esc_html($conversation->other_user_name); ?></span>
                                <span class="conversation-time"><?php echo esc_html(human_time_diff(strtotime($conversation->updated_at), current_time('timestamp'))); ?></span>
                            </div>
                            <div class="conversation-preview">
                                <?php
                                if ($conversation->last_sender == $current_user_id) {
                                    echo 'Ty: ';
                                }
                                echo esc_html(substr($conversation->last_message, 0, 50));
                                if (strlen($conversation->last_message) > 50) {
                                    echo '...';
                                }
                                ?>
                            </div>
                        </div>
                        <div class="conversation-actions">
                            <button class="view-attachments" data-conversation-id="<?php echo esc_attr($conversation->id); ?>" title="Załączniki">
                                <span class="dashicons dashicons-paperclip"></span>
                            </button>
                            <button class="archive-conversation" data-conversation-id="<?php echo esc_attr($conversation->id); ?>" title="Archiwizuj">
                                <span class="dashicons dashicons-archive"></span>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- Lista zarchiwizowanych konwersacji -->
        <div class="messenger-archived-list" style="display: none;">
            <div class="loading-archived">Ładowanie zarchiwizowanych konwersacji...</div>
        </div>
        
        <!-- Lista usuniętych konwersacji -->
        <div class="messenger-deleted-list" style="display: none;">
            <div class="loading-deleted">Ładowanie usuniętych konwersacji...</div>
        </div>
    </div>

    <!-- Obszar czatu -->
    <div class="messenger-chat-area">
        <div class="conversation-header">
            <span class="messenger-back-btn">← Wróć</span>
            <h3>Wybierz konwersację</h3>
            <div class="conversation-actions">
                <button class="toggle-archive-btn" title="Archiwizuj/Przywróć konwersację">
                    <i class="dashicons dashicons-archive"></i>
                </button>
                <button class="delete-conversation-btn" title="Usuń konwersację">
                    <i class="dashicons dashicons-trash"></i>
                </button>
                <button class="info-btn"><i class="dashicons dashicons-info"></i></button>
            </div>
        </div>

        <div class="messenger-messages">
            <div class="messenger-loading">Wybierz konwersację, aby rozpocząć czat</div>
        </div>

        <div class="messenger-input">
            <form id="messenger-chat-form">
                <div style="display: flex; align-items: flex-start">
                <input type="hidden" id="messenger-conversation-id" value="0">
                <input type="hidden" id="messenger-recipient-id" value="0">
                <div class="messenger-input-container">
                    <textarea id="messenger-message" placeholder="Napisz wiadomość..." onkeydown="if(event.key==='Enter' && !event.shiftKey){event.preventDefault(); document.getElementById('messenger-send-btn').click(); return false;}"></textarea>
                </div>
                <div class="messenger-actions">
                    <div class="messenger-attachments-preview" id="messenger-attachments-preview"></div>
                    <label for="messenger-attachment" class="messenger-attachment-btn">
                        <i class="dashicons dashicons-paperclip"></i>
                        <input type="file" id="messenger-attachment" accept=".pdf" style="display: none;">
                    </label>
                    <button type="button" id="messenger-emoji-btn" class="messenger-emoji-btn">
                        <i class="dashicons dashicons-smiley"></i>
                    </button>
                    <button type="submit" id="messenger-send-btn">
                        <i class="dashicons dashicons-arrow-right-alt2"></i>
                    </button>
                </div>
                <div id="emoji-picker-container"></div>
                </div>

            </form>
        </div>
    </div>
</div>

<!-- Popup z załącznikami -->
<div id="attachments-popup" class="attachments-popup">
    <div class="attachments-content">
        <div class="attachments-header">
            <h2>Załączniki</h2>
            <button class="attachments-close"><span class="dashicons dashicons-no-alt"></span></button>
        </div>
        <div class="attachments-body">
            <div class="attachments-loading">Ładowanie załączników...</div>
            <div class="attachments-list"></div>
            <div class="attachments-empty" style="display: none;">Brak załączników w tej konwersacji</div>
        </div>
    </div>
</div>

<!-- Popup z informacjami o użytkowniku -->
<div id="user-info-popup" class="user-info-popup">
    <div class="user-info-content">
        <div class="user-info-header">
            <button class="user-info-close"><span class="dashicons dashicons-no-alt"></span></button>
            <div class="user-info-avatar">
                <img src="" alt="Avatar użytkownika">
            </div>
            <h2 class="user-info-name"></h2>
            <p class="user-info-role"></p>
        </div>
        <div class="user-info-body">
            <div class="user-info-section">
                <div class="user-info-label">Email</div>
                <p class="user-info-value user-info-email"></p>
            </div>
            <div class="user-info-section">
                <div class="user-info-label">Data rejestracji</div>
                <p class="user-info-value user-info-registered"></p>
            </div>
            <div class="user-info-section">
                <div class="user-info-label">O użytkowniku</div>
                <div class="user-info-description"></div>
            </div>
            <div class="user-info-actions">
                <button class="block-user-btn" data-user-id="">
                    <span class="dashicons dashicons-lock"></span> Zablokuj użytkownika
                </button>
                <button class="unblock-user-btn" data-user-id="" style="display: none;">
                    <span class="dashicons dashicons-unlock"></span> Odblokuj użytkownika
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Popup z listą zablokowanych użytkowników -->
<div id="blocked-users-popup" class="blocked-users-popup">
    <div class="blocked-users-content">
        <div class="blocked-users-header">
            <h2>Zablokowani użytkownicy</h2>
            <button class="blocked-users-close"><span class="dashicons dashicons-no-alt"></span></button>
        </div>
        <div class="blocked-users-body">
            <div class="blocked-users-loading">Ładowanie zablokowanych użytkowników...</div>
            <div class="blocked-users-list"></div>
        </div>
    </div>
</div>
