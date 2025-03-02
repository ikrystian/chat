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
                
                // Oznacz wiadomość jako przeczytaną w czasie rzeczywistym
                markMessageAsRead(data.conversation_id, data.message.sender_id);
            } else {
                // Powiadomienie o nowej wiadomości w innej konwersacji
                notifyNewMessage(data.conversation_id);
            }
        });

        socket.on('message_read', function (data) {
            console.log('Wiadomość przeczytana:', data);
            
            if (data.conversation_id === activeConversation) {
                // Aktualizuj status przeczytania dla wszystkich wiadomości w konwersacji
                updateReadStatus(data.conversation_id, data.read_at);
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
        handleFileSelect();

        // Sprawdź, czy w URL jest ID konwersacji (zakodowane base64)
        const urlParams = new URLSearchParams(window.location.search);
        const encodedConversationId = urlParams.get('conversation');
        
        if (encodedConversationId) {
            try {
                // Dekoduj ID konwersacji z base64
                const conversationIdFromUrl = atob(encodedConversationId);
                // Otwórz konwersację z URL
                openConversation(parseInt(conversationIdFromUrl));
            } catch (e) {
                // Obsługa błędu dekodowania (np. gdy URL zawiera niezakodowane ID)
                console.error('Błąd dekodowania ID konwersacji:', e);
                // Spróbuj otworzyć konwersację traktując parametr jako niezakodowane ID
                openConversation(parseInt(encodedConversationId));
            }
        }

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

        // Aktualizuj URL z ID konwersacji
        updateUrlWithConversationId(conversationId);

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

    // Obsługa załączników
    let selectedFile = null;

    // Obsługa wyboru pliku
    function handleFileSelect() {
        $('#messenger-attachment').on('change', function(e) {
            const file = e.target.files[0];
            if (!file) return;

            // Sprawdź czy to plik PDF
            if (file.type !== 'application/pdf') {
                alert('Dozwolone są tylko pliki PDF.');
                $(this).val('');
                return;
            }

            // Zapisz wybrany plik
            selectedFile = file;

            // Pokaż podgląd załącznika
            showAttachmentPreview(file);
        });
    }

    // Wyświetlanie podglądu załącznika
    function showAttachmentPreview(file) {
        const previewContainer = $('#messenger-attachments-preview');
        previewContainer.empty();

        const preview = `
            <div class="attachment-preview">
                <span class="attachment-icon dashicons dashicons-pdf"></span>
                <span class="attachment-name">${file.name}</span>
                <button type="button" class="attachment-remove">
                    <span class="dashicons dashicons-no-alt"></span>
                </button>
            </div>
        `;

        previewContainer.append(preview);

        // Obsługa usuwania załącznika
        $('.attachment-remove').on('click', function() {
            $(this).closest('.attachment-preview').remove();
            $('#messenger-attachment').val('');
            selectedFile = null;
        });
    }

    // Wysyłanie wiadomości
    function sendMessage() {
        const messageText = $('#messenger-message').val().trim();
        const conversationId = $('#messenger-conversation-id').val();
        const recipientId = $('#messenger-recipient-id').val();

        if (messageText === '' && !selectedFile) {
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
            is_mine: true,
            attachment: selectedFile ? selectedFile.name : null
        };

        // Dodaj wiadomość z klasą "sending"
        appendMessage(tempMessage);

        // Dodaj klasę "sending" do tymczasowej wiadomości
        $('.message-item').last().addClass('sending');

        // Przygotuj dane do wysłania
        const formData = new FormData();
        formData.append('action', 'send_message');
        formData.append('conversation_id', conversationId);
        formData.append('recipient_id', recipientId);
        formData.append('message', messageText);
        formData.append('nonce', messengerChat.nonce);
        
        // Dodaj plik, jeśli został wybrany
        if (selectedFile) {
            formData.append('attachment', selectedFile);
        }

        // Wyczyść podgląd załącznika i zresetuj wybrany plik
        $('#messenger-attachments-preview').empty();
        selectedFile = null;

        // Wyślij wiadomość przez AJAX
        $.ajax({
            url: messengerChat.ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
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
                            attachment: response.data && response.data.attachment ? response.data.attachment : null,
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
        const attachment = message.attachment || null;
        const readAt = message.read_at || null;

        // Debugowanie
        console.log('Dane wiadomości:', {
            messageContent: messageContent,
            senderId: senderId,
            senderName: senderName,
            senderAvatar: senderAvatar,
            sentAt: sentAt,
            isMine: isMine,
            attachment: attachment
        });

        // Określ klasę wiadomości
        const messageClass = isMine ? 'my-message' : 'their-message';

        // Pobierz poprzednią wiadomość i sprawdź, czy jest od tego samego nadawcy
        const previousMessage = $('.message-item').last();
        const showAvatar = false; // Domyślnie ukrywamy avatar, później go pokażemy jeśli to ostatnia wiadomość

        // Przygotuj HTML dla załącznika PDF, jeśli istnieje
        let attachmentHtml = '';
        if (attachment) {
            const attachmentUrl = attachment.startsWith('http') 
                ? attachment 
                : `${messengerChat.uploads_url}/${attachment}`;
                
            attachmentHtml = `
                <div class="message-attachment">
                    <a href="${attachmentUrl}" target="_blank" class="pdf-attachment">
                        <span class="attachment-icon dashicons dashicons-pdf"></span>
                        <span class="attachment-name">${attachment.split('/').pop()}</span>
                    </a>
                </div>
            `;
        }

        // Przygotuj HTML dla statusu przeczytania, jeśli wiadomość jest moja i została przeczytana
        let readStatusHtml = '';
        if (isMine && readAt) {
            readStatusHtml = '<div class="message-read-status">Przeczytano</div>';
        }

        const messageHtml = `
        <div class="message-item ${messageClass}" data-sender-id="${senderId}">
            ${!isMine ? `
                <div class="message-avatar" style="visibility: hidden;">
                    <img src="${senderAvatar}" alt="${senderName}">
                </div>` : ''}
            <div class="message-content">
                <div class="message-text">${messageContent}</div>
                ${attachmentHtml}
                <div class="message-time">${formatTime(sentAt)}</div>
                ${readStatusHtml}
            </div>
        </div>
    `;

        $('.messenger-messages').append(messageHtml);
        
        // Aktualizuj widoczność avatarów po dodaniu nowej wiadomości
        updateAvatarsVisibility();
        
        scrollToBottom();
    }
    
    // Funkcja aktualizująca widoczność avatarów - pokazuje avatar tylko przy ostatniej wiadomości od danego nadawcy
    function updateAvatarsVisibility() {
        // Pobierz wszystkie wiadomości pogrupowane według nadawcy
        const messageGroups = {};
        
        // Przejdź przez wszystkie wiadomości i pogrupuj je według nadawcy
        $('.message-item.their-message').each(function() {
            const senderId = $(this).data('sender-id');
            if (!messageGroups[senderId]) {
                messageGroups[senderId] = [];
            }
            messageGroups[senderId].push($(this));
        });
        
        // Dla każdej grupy wiadomości od tego samego nadawcy
        Object.values(messageGroups).forEach(function(messages) {
            // Ukryj avatary we wszystkich wiadomościach
            messages.forEach(function(message) {
                message.find('.message-avatar').css('visibility', 'hidden');
            });
            
            // Pokaż avatar tylko w ostatniej wiadomości od danego nadawcy
            if (messages.length > 0) {
                messages[messages.length - 1].find('.message-avatar').css('visibility', 'visible');
            }
        });
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

    // Oznaczanie wiadomości jako przeczytanej
    function markMessageAsRead(conversationId, senderId) {
        // Wyślij powiadomienie o przeczytaniu przez AJAX
        $.ajax({
            url: messengerChat.ajaxurl,
            type: 'POST',
            data: {
                action: 'mark_message_read',
                conversation_id: conversationId,
                sender_id: senderId,
                nonce: messengerChat.nonce
            },
            success: function(response) {
                console.log('Wiadomość oznaczona jako przeczytana:', response);
            }
        });
    }

    // Aktualizacja statusu przeczytania wiadomości
    function updateReadStatus(conversationId, readAt) {
        // Znajdź wszystkie wiadomości wysłane przez bieżącego użytkownika
        $('.message-item.my-message').each(function() {
            // Dodaj wskaźnik przeczytania, jeśli jeszcze nie istnieje
            if (!$(this).find('.message-read-status').length) {
                $(this).find('.message-time').after('<div class="message-read-status">Przeczytano</div>');
            }
        });
    }

    // Aktualizacja URL z ID konwersacji (zakodowane base64)
    function updateUrlWithConversationId(conversationId) {
        if (!conversationId) return;
        
        // Utwórz nowy obiekt URLSearchParams z bieżącego URL
        const urlParams = new URLSearchParams(window.location.search);
        
        // Zakoduj ID konwersacji za pomocą base64
        const encodedId = btoa(conversationId.toString());
        
        // Ustaw parametr conversation
        urlParams.set('conversation', encodedId);
        
        // Zaktualizuj URL bez przeładowania strony
        const newUrl = window.location.pathname + '?' + urlParams.toString();
        window.history.pushState({path: newUrl}, '', newUrl);
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
