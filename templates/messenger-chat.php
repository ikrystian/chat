<?php
// templates/messenger-chat.php
?>
<div class="messenger-container">
    <!-- Pasek boczny z konwersacjami -->
    <div class="messenger-conversations active">
        <div class="messenger-search-container">
            <input type="text" id="messenger-search" class="messenger-search" placeholder="Szukaj...">
        </div>

        <div class="messenger-tabs">
            <a href="#" class="active" data-tab="conversations">Konwersacje</a>
            <a href="#" data-tab="contacts">Kontakty</a>
        </div>

        <!-- Lista konwersacji -->
        <div class="messenger-conversations-list">
            <?php if (empty($conversations)): ?>
                <div class="empty-state">
                    <p>Nie masz jeszcze żadnych konwersacji</p>
                </div>
            <?php else: ?>
                <?php foreach ($conversations as $conversation): ?>
                    <div class="conversation-item" data-conversation-id="<?php echo esc_attr($conversation->id); ?>">
                        <div class="conversation-avatar">
                            <img src="<?php echo esc_url($conversation->other_user_avatar); ?>" alt="<?php echo esc_attr($conversation->other_user_name); ?>">
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
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Lista kontaktów -->
        <div class="messenger-contacts-list">
            <?php if (empty($users)): ?>
                <div class="empty-state">
                    <p>Brak dostępnych kontaktów</p>
                </div>
            <?php else: ?>
                <?php foreach ($users as $user): ?>
                    <div class="contact-item new-conversation" data-user-id="<?php echo esc_attr($user->ID); ?>" data-user-name="<?php echo esc_attr($user->display_name); ?>">
                        <div class="contact-avatar">
                            <img src="<?php echo esc_url(get_avatar_url($user->ID)); ?>" alt="<?php echo esc_attr($user->display_name); ?>">
                        </div>
                        <div class="contact-info">
                            <div class="contact-header">
                                <span class="user-name"><?php echo esc_html($user->display_name); ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Obszar czatu -->
    <div class="messenger-chat-area">
        <div class="conversation-header">
            <span class="messenger-back-btn">← Wróć</span>
            <h3>Wybierz konwersację</h3>
            <div class="conversation-actions">
                <button class="info-btn"><i class="dashicons dashicons-info"></i></button>
            </div>
        </div>

        <div class="messenger-messages">
            <div class="messenger-loading">Wybierz konwersację, aby rozpocząć czat</div>
        </div>

        <div class="messenger-input">
            <form id="messenger-chat-form">
                <input type="hidden" id="messenger-conversation-id" value="0">
                <input type="hidden" id="messenger-recipient-id" value="0">
                <textarea id="messenger-message" placeholder="Napisz wiadomość..."></textarea>
                <button type="submit" id="messenger-send-btn">
                    <i class="dashicons dashicons-arrow-right-alt2"></i>
                </button>
            </form>
        </div>
    </div>
</div>