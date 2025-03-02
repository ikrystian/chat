// assets/js/messenger-chat.js

(function($) {
    'use strict';

    // Inicjalizacja WebSocket
    let socket;
    let activeConversation = 0;
    const currentUserId = parseInt(messengerChat.user_id);

    // Połączenie z serwerem WebSocket
    function connectWebSocket() {
        socket = io(messengerChat.websocket_server);

        socket.on('connect', function() {
            console.log('Połączono z serwerem WebSocket');
            socket.emit('register', { userId: currentUserId });
        });

        socket.on('new_message', function(data) {
            if (data.conversation_id === activeConversation) {
                appendMessage(data.message);
            } else {
                // Powiadomienie o nowej wiadomości
                updateConversationList();
            }
        });

        socket.on('disconnect', function() {
            console.log('Rozłączono z serwerem WebSocket');
            // Próba ponownego połączenia po 5 sekundach
            setTimeout(connectWebSocket, 5000);
        });
    }

    // Inicjalizacja czatu
    function initChat() {
        connectWebSocket();

        // Obsługa kliknięcia na konwersację
        $(document).on('click', '.conversation-item', function() {
            const conversationId = $(this).data('conversation-id');
            openConversation(conversationId);
        });

        // Obsługa wysyłania wiadomości
        $('#messenger-chat-form').on('submit', function(e) {
            e.preventDefault();
            sendMessage();
        });

        // Obsługa przycisku wysyłania
        $('#messenger-send-btn').on('click', function() {
            sendMessage();
        });

        // Obsługa otwierania nowej konwersacji
        $(document).on('click', '.new-conversation', function() {
            const recipientId = $(this).data('user-id');
            const recipientName = $(this).data('user-name');

            // Resetuj aktywną konwersację
            activeConversation = 0;

            // Zaktualizuj UI
            $('.conversation-header h3').text(recipientName);
            $('.messenger-messages').empty();
            $('.messenger-input').show();

            // Ustaw recipient_id dla formularza
            $('#messenger-recipient-id').val(recipientId);
            $('#messenger-conversation-id').val(0);

            // Zamknij listę kontaktów na urządzeniach mobilnych
            $('.messenger-conversations').removeClass('active');
        });

        // Obsługa przycisków mobilnych
        $('.messenger-back-btn').on('click', function() {
            $('.messenger-conversations').addClass('active');
            $('.messenger-chat-area').removeClass('active');
        });

        // Obsługa wyszukiwania kontaktów i konwersacji
        $('#messenger-search').on('input', function() {
            const searchTerm = $(this).val().toLowerCase();

            $('.conversation-item, .contact-item').each(function() {
                const userName = $(this).find('.user-name').text().toLowerCase();
                if (userName.includes(searchTerm)) {
                    $(this).show();
                } else {
                    $(this).hide();
                }
            });
        });

        // Obsługa przełączania między zakładkami
        $('.messenger-tabs a').on('click', function(e) {
            e.preventDefault();
            const tab = $(this).data('tab');

            $('.messenger-tabs a').removeClass('active');
            $(this).addClass('active');

            if (tab === 'conversations') {
                $('.messenger-contacts-list').hide();
                $('.messenger-conversations-list').show();
            } else {
                $('.messenger-conversations-list').hide();
                $('.messenger-contacts-list').show();
            }
        });

        // Domyślnie wyświetl listę konwersacji
        $('.messenger-contacts-list').hide();
    }

    // Otwieranie konwersacji
    function openConversation(conversationId) {
        activeConversation = conversationId;

        // Aktualizuj UI
        $('.conversation-item').removeClass('active');
        $(`.conversation-item[data-conversation-id="${conversationId}"]`).addClass('active');
        $('.messenger-messages').empty();
        $('.messenger-loading').show();

        // Ukryj pole recipient_id (nie jest potrzebne dla istniejącej konwersacji)
        $('#messenger-recipient-id').val('');
        $('#messenger-conversation-id').val(conversationId);

        // Pobierz wiadomości
        $.ajax({
            url: messengerChat.ajaxurl,
            type: 'POST',
            data: {
                action: 'get_messages',
                conversation_id: conversationId,
                nonce: messengerChat.nonce
            },
            success: function(response) {
                $('.messenger-loading').hide();

                if (response.success) {
                    // Wyświetl wiadomości
                    response.data.forEach(function(message) {
                        appendMessage(message);
                    });

                    // Przewiń do najnowszej wiadomości
                    scrollToBottom();

                    // Pokaż pole wpisywania wiadomości
                    $('.messenger-input').show();

                    // Zaktualizuj nagłówek konwersacji
                    const conversationName = $(`.conversation-item[data-conversation-id="${conversationId}"]`).find('.user-name').text();
                    $('.conversation-header h3').text(conversationName);

                    // Pokaż czat na urządzeniach mobilnych
                    $('.messenger-conversations').removeClass('active');
                    $('.messenger-chat-area').addClass('active');
                }
            },
            error: function() {
                $('.messenger-loading').hide();
                $('.messenger-messages').html('<div class="error-message">Błąd podczas pobierania wiadomości</div>');
            }
        });
    }

    // Wysyłanie wiadomości
    function sendMessage() {
        const messageText = $('#messenger-message').val().trim();
        const conversationId = $('#messenger-conversation-id').val();
        const recipientId = $('#messenger-recipient-id').val();

        if (messageText === '') {
            return;
        }

        // Wyczyść pole wiadomości
        $('#messenger-message').val('');

        // Wyślij wiadomość przez AJAX
        $.ajax({
            url: messengerChat.ajaxurl,
            type: 'POST',
            data: {
                action: 'send_message',
                conversation_id: conversationId,
                recipient_id: recipientId,
                message: messageText,
                nonce: messengerChat.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Dodaj wiadomość do czatu
                    appendMessage(response.data.message);

                    // Jeśli to była nowa konwersacja, zaktualizuj ID konwersacji
                    if (conversationId === '0') {
                        activeConversation = response.data.conversation_id;
                        $('#messenger-conversation-id').val(response.data.conversation_id);
                        $('#messenger-recipient-id').val('');

                        // Zaktualizuj listę konwersacji
                        updateConversationList();
                    }

                    // Przewiń do najnowszej wiadomości
                    scrollToBottom();
                }
            }
        });
    }

    // Dodawanie wiadomości do interfejsu
    function appendMessage(message) {
        const messageClass = message.is_mine ? 'my-message' : 'their-message';
        const messageHtml = `
            <div class="message-item ${messageClass}">
                <div class="message-avatar">
                    <img src="${message.sender_avatar}" alt="${message.sender_name}">
                </div>
                <div class="message-content">
                    <div class="message-text">${message.message}</div>
                    <div class="message-time">${formatTime(message.sent_at)}</div>
                </div>
            </div>
        `;

        $('.messenger-messages').append(messageHtml);
        scrollToBottom();
    }

    // Aktualizacja listy konwersacji
    function updateConversationList() {
        // Odśwież całą listę konwersacji z serwera
        // W prawdziwym scenariuszu możemy optymalizować i aktualizować tylko zmienione elementy
        location.reload();
    }

    // Formatowanie czasu
    function formatTime(timeString) {
        const date = new Date(timeString);
        const now = new Date();

        // Ten sam dzień
        if (date.toDateString() === now.toDateString()) {
            return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }

        // W tym tygodniu
        const daysDiff = Math.floor((now - date) / (1000 * 60 * 60 * 24));
        if (daysDiff < 7) {
            const days = ['Niedziela', 'Poniedziałek', 'Wtorek', 'Środa', 'Czwartek', 'Piątek', 'Sobota'];
            return days[date.getDay()];
        }

        // Dawniej
        return date.toLocaleDateString();
    }

    // Przewijanie do najnowszej wiadomości
    function scrollToBottom() {
        const messagesContainer = $('.messenger-messages');
        messagesContainer.scrollTop(messagesContainer[0].scrollHeight);
    }

    // Inicjalizacja po załadowaniu dokumentu
    $(document).ready(function() {
        initChat();
    });

})(jQuery);