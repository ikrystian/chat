// assets/js/messenger-chat.js

(function ($) {
    'use strict';

    // Inicjalizacja WebSocket
    let socket;
    let activeConversation = 0;
    const currentUserId = parseInt(messengerChat.user_id);

    // Połączenie z serwerem WebSocket
    function connectWebSocket() {
        socket = io(messengerChat.websocket_server);

        socket.on('connect', function () {
            console.log('Połączono z serwerem WebSocket');
            socket.emit('register', {userId: currentUserId});
        });

        socket.on('new_message', function (data) {
            console.log('Otrzymano nową wiadomość:', data);

            if (data.conversation_id === activeConversation) {
                // Dodaj wiadomość do czatu
                appendMessage(data.message);

                // Przewiń do najnowszej wiadomości
                scrollToBottom();
            } else {
                // Powiadomienie o nowej wiadomości w innej konwersacji
                notifyNewMessage(data.conversation_id);
            }
        });

        socket.on('typing', function (data) {
            if (data.conversation_id === activeConversation) {
                showTypingIndicator(data.user_id);
            }
        });

        socket.on('disconnect', function () {
            console.log('Rozłączono z serwerem WebSocket');
            // Próba ponownego połączenia po 5 sekundach
            setTimeout(connectWebSocket, 5000);
        });
    }

    // Powiadomienie o nowej wiadomości
    function notifyNewMessage(conversationId) {
        // Znajdź konwersację na liście i dodaj wskaźnik nowej wiadomości
        const conversationItem = $(`.conversation-item[data-conversation-id="${conversationId}"]`);
        conversationItem.addClass('has-new-message');

        // Opcjonalnie - dodaj dźwięk powiadomienia
        playNotificationSound();

        // Przenieś konwersację na górę listy
        const conversationsList = $('.messenger-conversations-list');
        conversationsList.prepend(conversationItem);
    }

    // Odtwarzanie dźwięku powiadomienia
    function playNotificationSound() {
        const audio = new Audio('data:audio/mp3;base64,SUQzAwAAAAABEVRYWFgAAAAUAAADTGF2ZjU2LjQwLjEwMQAAAAAAAAAAAAAA//tAwAAAAAAAAAAAAAAAAAAAAAAAWGluZwAAAA8AAAACAAAD+AD///////////////////////8AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAD/+xDEAAAJdAN79BEAIrDDKn81kBI0AAAAAWGluZwAAAA8AAAACAAAApQC/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA//sQxAAADIAFX9AAAA2nKfP/NZAAAAABYaW5nAAAADwAAAAIAAAClAL+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v7+/v78AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA');

        // Ustawienie głośności i odtwarzanie
        audio.volume = 0.3;
        audio.play().catch(e => console.log("Automatyczne odtwarzanie dźwięku zablokowane przez przeglądarkę", e));
    }

    // Wyświetlanie wskaźnika pisania
    function showTypingIndicator(userId) {
        // Usuń istniejący wskaźnik
        $('.typing-indicator').remove();

        // Dodaj wskaźnik pisania z animowanymi kropkami
        $('.messenger-messages').append(`
        <div class="typing-indicator">
            <div class="typing-dots">
                <span class="typing-dot"></span>
                <span class="typing-dot"></span>
                <span class="typing-dot"></span>
            </div>
        </div>
    `);

        // Ukryj wskaźnik po 3 sekundach, jeśli nie otrzymano nowego zdarzenia
        clearTimeout(window.typingTimeout);
        window.typingTimeout = setTimeout(function () {
            $('.typing-indicator').remove();
        }, 3000);
    }

    // Wysyłanie informacji o pisaniu
    function sendTypingNotification() {
        const conversationId = $('#messenger-conversation-id').val();
        const recipientId = $('#messenger-recipient-id').val() || getRecipientIdFromConversation(conversationId);

        if (conversationId && socket.connected) {
            socket.emit('typing', {
                conversation_id: parseInt(conversationId),
                recipient_id: parseInt(recipientId),
                user_id: currentUserId
            });
        }
    }

    // Pobranie ID odbiorcy z ID konwersacji
    function getRecipientIdFromConversation(conversationId) {
        // Pobierz z atrybutu data DOM lub z cache
        const conversationItem = $(`.conversation-item[data-conversation-id="${conversationId}"]`);
        return conversationItem.data('recipient-id');
    }

    // Inicjalizacja czatu
    function initChat() {
        connectWebSocket();

        // Obsługa kliknięcia na konwersację
        $(document).on('click', '.conversation-item', function () {
            const conversationId = $(this).data('conversation-id');

            // Usuń klasę wskazującą nową wiadomość
            $(this).removeClass('has-new-message');

            openConversation(conversationId);
        });

        // Obsługa wysyłania wiadomości
        $('#messenger-chat-form').on('submit', function (e) {
            e.preventDefault();
            sendMessage();
        });

        // Obsługa przycisku wysyłania
        $('#messenger-send-btn').on('click', function () {
            sendMessage();
        });

        // Powiadomienie o pisaniu podczas wprowadzania tekstu
        $('#messenger-message').on('input', function () {
            sendTypingNotification();
        });

        // Obsługa otwierania nowej konwersacji
        $(document).on('click', '.new-conversation', function () {
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

            // Zachowaj ID odbiorcy w elemencie DOM
            $(this).attr('data-recipient-id', recipientId);

            // Zamknij listę kontaktów na urządzeniach mobilnych
            $('.messenger-conversations').removeClass('active');
            $('.messenger-chat-area').addClass('active');
        });

        // Obsługa przycisków mobilnych
        $('.messenger-back-btn').on('click', function () {
            $('.messenger-conversations').addClass('active');
            $('.messenger-chat-area').removeClass('active');
        });

        // Obsługa wyszukiwania kontaktów i konwersacji
        $('#messenger-search').on('input', function () {
            const searchTerm = $(this).val().toLowerCase();

            $('.conversation-item, .contact-item').each(function () {
                const userName = $(this).find('.user-name').text().toLowerCase();
                if (userName.includes(searchTerm)) {
                    $(this).show();
                } else {
                    $(this).hide();
                }
            });
        });

        // Obsługa przełączania między zakładkami
        $('.messenger-tabs a').on('click', function (e) {
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
        activeConversation = parseInt(conversationId);

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
            success: function (response) {
                $('.messenger-loading').hide();

                if (response.success) {
                    // Wyświetl wiadomości
                    response.data.forEach(function (message) {
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
            error: function () {
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

        // Tymczasowo dodaj wiadomość do interfejsu (przed potwierdzeniem z serwera)
        const tempMessageId = 'temp-' + Date.now();
        const currentUserId = parseInt(messengerChat.user_id);
        const currentUserAvatar = $('.conversation-item.active').find('img').attr('src') || '';

        const tempMessage = {
            id: tempMessageId,
            sender_id: currentUserId,
            message: messageText, // Tu jest treść wiadomości
            sent_at: new Date().toISOString(),
            sender_name: 'Ty',
            sender_avatar: currentUserAvatar,
            is_mine: true
        };

        // Dodaj wiadomość z klasą "sending"
        appendMessage(tempMessage);

        // Dodaj klasę "sending" do tymczasowej wiadomości
        $('.message-item').last().addClass('sending');

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
                console.log('Odpowiedź z serwera:', response);

                if (response.success) {
                    // Usuń tymczasową wiadomość
                    $('.message-item.sending').remove();

                    // Sprawdź, czy response.data.message zawiera właściwą treść wiadomości
                    if (response.data && response.data.message) {
                        // Sprawdź strukturę obiektu wiadomości
                        if (typeof response.data.message.message === 'undefined') {
                            // Jeśli brakuje właściwości 'message', dodaj ją
                            response.data.message.message = messageText;
                        }

                        // Dodaj potwierdzoną wiadomość z serwera
                        appendMessage(response.data.message);
                    } else {
                        // Jeśli struktura odpowiedzi jest nieprawidłowa, utwórz obiekt wiadomości ręcznie
                        appendMessage({
                            id: response.data ? response.data.id : tempMessageId,
                            sender_id: currentUserId,
                            message: messageText,
                            sent_at: new Date().toISOString(),
                            sender_name: 'Ty',
                            sender_avatar: currentUserAvatar,
                            is_mine: true
                        });
                    }

                    // Jeśli to była nowa konwersacja, zaktualizuj ID konwersacji
                    if (conversationId === '0' && response.data && response.data.conversation_id) {
                        activeConversation = parseInt(response.data.conversation_id);
                        $('#messenger-conversation-id').val(response.data.conversation_id);
                        $('#messenger-recipient-id').val('');

                        // Odśwież listę konwersacji
                        refreshConversationsList();
                    }
                } else {
                    // Pokaż błąd wysyłania
                    $('.message-item.sending').addClass('error')
                        .find('.message-text').append('<div class="message-error">Błąd wysyłania</div>');
                }
            },
            error: function(xhr, status, error) {
                console.error('Błąd AJAX:', status, error);

                // Pokaż błąd wysyłania
                $('.message-item.sending').addClass('error')
                    .find('.message-text').append('<div class="message-error">Błąd wysyłania</div>');
            }
        });
    }

    function refreshConversationsList() {
        $.ajax({
            url: messengerChat.ajaxurl,
            type: 'POST',
            data: {
                action: 'get_conversations',
                nonce: messengerChat.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Zaktualizuj listę konwersacji
                    $('.messenger-conversations-list').html(response.data);
                }
            }
        });
    }


// Dodawanie wiadomości do interfejsu
    function appendMessage(message) {
        // Sprawdź, czy mamy wszystkie wymagane pola w obiekcie wiadomości
        if (!message || typeof message !== 'object') {
            console.error('Błąd: Obiekt wiadomości jest nieprawidłowy', message);
            return;
        }

        // Bezpiecznie pobierz właściwości wiadomości z domyślnymi wartościami w przypadku ich braku
        const messageContent = message.message || '';
        const senderId = message.sender_id || 0;
        const senderName = message.sender_name || 'Nieznany';
        const senderAvatar = message.sender_avatar || '/wp-content/plugins/wp-messenger-chat/assets/images/default-avatar.png';
        const sentAt = message.sent_at || new Date().toISOString();
        const isMine = !!message.is_mine; // konwersja na boolean

        // Debugowanie
        console.log('Dane wiadomości:', {
            messageContent: messageContent,
            senderId: senderId,
            senderName: senderName,
            senderAvatar: senderAvatar,
            sentAt: sentAt,
            isMine: isMine
        });

        // Określ klasę wiadomości
        const messageClass = isMine ? 'my-message' : 'their-message';

        // Pobierz poprzednią wiadomość i sprawdź, czy jest od tego samego nadawcy
        const previousMessage = $('.message-item').last();
        const showAvatar = !isMine &&
            (!previousMessage.length ||
                previousMessage.hasClass('my-message') ||
                previousMessage.data('sender-id') !== senderId.toString());

        const messageHtml = `
        <div class="message-item ${messageClass}" data-sender-id="${senderId}">
            ${!isMine ? `
                <div class="message-avatar" ${!showAvatar ? 'style="visibility: hidden;"' : ''}>
                    <img src="${senderAvatar}" alt="${senderName}">
                </div>` : ''}
            <div class="message-content">
                <div class="message-text">${messageContent}</div>
                <div class="message-time">${formatTime(sentAt)}</div>
            </div>
        </div>
    `;

        $('.messenger-messages').append(messageHtml);
        scrollToBottom();
    }
    // Formatowanie czasu
    function formatTime(timeString) {
        try {
            const date = new Date(timeString);
            if (isNaN(date.getTime())) {
                return 'teraz';
            }

            const now = new Date();

            // Różnica w minutach
            const diffMinutes = Math.floor((now - date) / (1000 * 60));

            // Przed chwilą (mniej niż 2 minuty)
            if (diffMinutes < 2) {
                return 'przed chwilą';
            }

            // Kilka minut temu (mniej niż 60 minut)
            if (diffMinutes < 60) {
                return `${diffMinutes} min temu`;
            }

            // Ten sam dzień
            if (date.toDateString() === now.toDateString()) {
                return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            }

            // Wczoraj
            const yesterday = new Date(now);
            yesterday.setDate(yesterday.getDate() - 1);
            if (date.toDateString() === yesterday.toDateString()) {
                return 'wczoraj ' + date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            }

            // W tym tygodniu
            const daysDiff = Math.floor((now - date) / (1000 * 60 * 60 * 24));
            if (daysDiff < 7) {
                const days = ['Niedziela', 'Poniedziałek', 'Wtorek', 'Środa', 'Czwartek', 'Piątek', 'Sobota'];
                return days[date.getDay()];
            }

            // Dawniej
            return date.toLocaleDateString();
        } catch (e) {
            console.error('Błąd formatowania czasu:', e);
            return 'teraz';
        }
    }

    // Przewijanie do najnowszej wiadomości
    function scrollToBottom() {
        const messagesContainer = $('.messenger-messages');
        messagesContainer.stop().animate({
            scrollTop: messagesContainer[0].scrollHeight
        }, 300);
    }

    // Inicjalizacja po załadowaniu dokumentu
    $(document).ready(function () {
        initChat();
    });

})(jQuery);