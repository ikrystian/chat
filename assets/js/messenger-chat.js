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
                
                // Oznacz wiadomości jako przeczytane
                markMessagesAsRead(data.conversation_id);
            } else {
                // Powiadomienie o nowej wiadomości w innej konwersacji
                notifyNewMessage(data.conversation_id);
            }
        });
        
        socket.on('new_conversation', function(data) {
            console.log('Otrzymano nową konwersację:', data);

            // Odśwież listę konwersacji, aby pokazać nową konwersację
            refreshConversationsList();
            
            // Powiadomienie o nowej wiadomości
            playNotificationSound();
        });
        
        socket.on('message_read', function (data) {
            console.log('Otrzymano potwierdzenie przeczytania:', data);
            
            // Aktualizuj wskaźniki przeczytania dla wiadomości w konwersacji
            updateReadReceipts(data.conversation_id, data.read_at);
        });

        socket.on('typing', function (data) {
            if (data.conversation_id === activeConversation) {
                showTypingIndicator(data.user_id);
            }
        });
        
        socket.on('message_seen', function (data) {
            if (data.conversation_id === activeConversation) {
                // Aktualizuj status "seen" dla wiadomości wysłanych przez bieżącego użytkownika
                updateMessageSeenStatus(data.seen_at);
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
    
    // Sprawdź, czy konwersacja istnieje na liście
    if (conversationItem.length === 0) {
        // Jeśli konwersacja nie istnieje, odśwież całą listę konwersacji
        refreshConversationsList();
        return;
    }
    
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
    
    // Wysyłanie powiadomienia o przeczytaniu wiadomości
    function sendMessageSeenNotification(conversationId, recipientId) {
        if (!conversationId || !recipientId || !socket.connected) return;
        
        // Wyślij powiadomienie o przeczytaniu wiadomości
        socket.emit('message_seen', {
            conversation_id: parseInt(conversationId),
            recipient_id: parseInt(recipientId),
            user_id: currentUserId,
            seen_at: new Date().toISOString()
        });
    }
    
    // Aktualizacja statusu "seen" dla wiadomości
    function updateMessageSeenStatus(seenAt) {
        // Znajdź wszystkie wiadomości wysłane przez bieżącego użytkownika
        const myMessages = $('.message-item.my-message');
        
        // Dodaj status "seen" tylko do ostatniej wiadomości
        if (myMessages.length > 0) {
            const lastMessage = myMessages.last();
            
            // Sprawdź, czy już ma status "seen"
            if (!lastMessage.find('.message-seen').length) {
                // Dodaj status "seen" do ostatniej wiadomości
                lastMessage.find('.message-time').after(`
                    <div class="message-seen">
                        Wyświetlono ${formatTime(seenAt)}
                    </div>
                `);
            } else {
                // Zaktualizuj istniejący status "seen"
                lastMessage.find('.message-seen').text(`Wyświetlono ${formatTime(seenAt)}`);
            }
        }
    }
    
    // Wysyłanie powiadomienia o przeczytaniu wiadomości
    function sendMessageSeenNotification(conversationId, recipientId) {
        if (!conversationId || !recipientId || !socket.connected) return;
        
        // Wyślij powiadomienie o przeczytaniu wiadomości
        socket.emit('message_seen', {
            conversation_id: parseInt(conversationId),
            recipient_id: parseInt(recipientId),
            user_id: currentUserId,
            seen_at: new Date().toISOString()
        });
    }
    
    // Aktualizacja statusu "seen" dla wiadomości
    function updateMessageSeenStatus(seenAt) {
        // Znajdź wszystkie wiadomości wysłane przez bieżącego użytkownika
        const myMessages = $('.message-item.my-message');
        
        // Dodaj status "seen" tylko do ostatniej wiadomości
        if (myMessages.length > 0) {
            const lastMessage = myMessages.last();
            
            // Sprawdź, czy już ma status "seen"
            if (!lastMessage.find('.message-seen').length) {
                // Dodaj status "seen" do ostatniej wiadomości
                lastMessage.find('.message-time').after(`
                    <div class="message-seen">
                        Wyświetlono ${formatTime(seenAt)}
                    </div>
                `);
            } else {
                // Zaktualizuj istniejący status "seen"
                lastMessage.find('.message-seen').text(`Wyświetlono ${formatTime(seenAt)}`);
            }
        }
    }

    // Pobranie ID odbiorcy z ID konwersacji
    function getRecipientIdFromConversation(conversationId) {
        // Pobierz z atrybutu data DOM lub z cache
        const conversationItem = $(`.conversation-item[data-conversation-id="${conversationId}"]`);
        return conversationItem.data('recipient-id');
    }

    // Inicjalizacja emoji pickera
    function initEmojiPicker() {
        // Sprawdź, czy biblioteka emoji-picker-element jest już załadowana
        if (typeof customElements.get('emoji-picker') === 'undefined') {
            // Jeśli nie, załaduj bibliotekę dynamicznie
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/emoji-picker-element@^1/index.js';
            script.type = 'module';
            document.head.appendChild(script);
            
            // Dodaj style dla emoji-picker-element
            const style = document.createElement('link');
            style.rel = 'stylesheet';
            style.href = 'https://cdn.jsdelivr.net/npm/emoji-picker-element@^1/index.css';
            document.head.appendChild(style);
            
            // Poczekaj na załadowanie biblioteki
            script.onload = function() {
                createEmojiPicker();
            };
        } else {
            createEmojiPicker();
        }
    }
    
    // Tworzenie i konfiguracja emoji pickera
    function createEmojiPicker() {
        const emojiPickerContainer = document.getElementById('emoji-picker-container');
        
        // Sprawdź, czy kontener istnieje i czy nie zawiera już emoji-picker
        if (emojiPickerContainer && !emojiPickerContainer.querySelector('emoji-picker')) {
            // Utwórz element emoji-picker
            const picker = document.createElement('emoji-picker');
            emojiPickerContainer.appendChild(picker);
            
            // Obsługa wyboru emoji
            picker.addEventListener('emoji-click', event => {
                const messageTextarea = document.getElementById('messenger-message');
                const emoji = event.detail.unicode;
                
                // Wstaw emoji w miejscu kursora
                const start = messageTextarea.selectionStart;
                const end = messageTextarea.selectionEnd;
                const text = messageTextarea.value;
                const before = text.substring(0, start);
                const after = text.substring(end, text.length);
                
                messageTextarea.value = before + emoji + after;
                
                // Ustaw kursor za wstawionym emoji
                messageTextarea.selectionStart = messageTextarea.selectionEnd = start + emoji.length;
                
                // Ukryj picker po wyborze emoji
                toggleEmojiPicker(false);
                
                // Ustaw focus na textarea
                messageTextarea.focus();
            });
            
            // Obsługa kliknięcia przycisku emoji
            document.getElementById('messenger-emoji-btn').addEventListener('click', function(e) {
                e.preventDefault();
                toggleEmojiPicker();
            });
            
            // Zamknij picker po kliknięciu poza nim
            document.addEventListener('click', function(e) {
                const emojiBtn = document.getElementById('messenger-emoji-btn');
                const emojiPicker = document.querySelector('emoji-picker');
                
                if (emojiPicker && 
                    !emojiPicker.contains(e.target) && 
                    e.target !== emojiBtn && 
                    !emojiBtn.contains(e.target)) {
                    toggleEmojiPicker(false);
                }
            });
        }
    }
    
    // Przełączanie widoczności emoji pickera
    function toggleEmojiPicker(forceState) {
        const picker = document.querySelector('emoji-picker');
        if (picker) {
            if (forceState !== undefined) {
                if (forceState) {
                    picker.classList.add('visible');
                } else {
                    picker.classList.remove('visible');
                }
            } else {
                picker.classList.toggle('visible');
            }
        }
    }

    // Inicjalizacja czatu
    function initChat() {
        connectWebSocket();
        handleFileSelect();
        initEmojiPicker();
        
        // Odśwież listę konwersacji przy inicjalizacji
        refreshConversationsList();

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

        // Obsługa przycisku archiwizacji/przywracania w nagłówku konwersacji
        $(document).on('click', '.toggle-archive-btn', function() {
            const conversationId = $('#messenger-conversation-id').val();
            if (!conversationId || conversationId === '0') {
                return; // Nie ma aktywnej konwersacji
            }
            
            // Sprawdź, czy konwersacja jest zarchiwizowana
            const isArchived = $(this).hasClass('is-archived');
            
            if (isArchived) {
                unarchiveConversation(conversationId);
            } else {
                archiveConversation(conversationId);
            }
        });
        
        // Obsługa przycisku usuwania w nagłówku konwersacji
        $(document).on('click', '.delete-conversation-btn', function() {
            const conversationId = $('#messenger-conversation-id').val();
            if (!conversationId || conversationId === '0') {
                return; // Nie ma aktywnej konwersacji
            }
            
            if (confirm('Czy na pewno chcesz usunąć tę konwersację?')) {
                deleteConversation(conversationId);
            }
        });
        
        // Obsługa przycisku załączników
        $(document).on('click', '.view-attachments', function(e) {
            e.stopPropagation(); // Zapobiega otwieraniu konwersacji
            const conversationId = $(this).data('conversation-id');
            if (!conversationId) {
                return;
            }
            
            // Pobierz załączniki dla konwersacji
            getConversationAttachments(conversationId);
        });
        
        // Obsługa zamykania popupu z załącznikami
        $(document).on('click', '.attachments-close, .attachments-popup', function(e) {
            if (e.target === this) {
                closeAttachmentsPopup();
            }
        });
        
        // Obsługa przycisku informacji o użytkowniku
        $(document).on('click', '.info-btn', function() {
            const conversationId = $('#messenger-conversation-id').val();
            if (!conversationId || conversationId === '0') {
                return; // Nie ma aktywnej konwersacji
            }
            
            // Pobierz ID odbiorcy z aktywnej konwersacji
            const recipientId = getRecipientIdFromConversation(conversationId);
            if (!recipientId) {
                return;
            }
            
            // Pobierz informacje o użytkowniku
            getUserInfo(recipientId);
        });
        
        // Obsługa zamykania popupu z informacjami o użytkowniku
        $(document).on('click', '.user-info-close, .user-info-popup', function(e) {
            if (e.target === this) {
                closeUserInfoPopup();
            }
        });
        
        // Obsługa przycisku blokowania użytkownika
        $(document).on('click', '.block-user-btn', function() {
            const userId = $(this).data('user-id');
            if (!userId) {
                return;
            }
            
            if (confirm('Czy na pewno chcesz zablokować tego użytkownika? Nie będzie mógł wysyłać do Ciebie wiadomości.')) {
                blockUser(userId);
            }
        });
        
        // Obsługa przycisku odblokowania użytkownika
        $(document).on('click', '.unblock-user-btn', function() {
            const userId = $(this).data('user-id');
            if (!userId) {
                return;
            }
            
            if (confirm('Czy na pewno chcesz odblokować tego użytkownika?')) {
                unblockUser(userId);
            }
        });
        
        // Obsługa przycisku wyświetlania zablokowanych użytkowników
        $(document).on('click', '.show-blocked-users-btn', function() {
            getBlockedUsers();
        });
        
        // Obsługa zamykania popupu z zablokowanymi użytkownikami
        $(document).on('click', '.blocked-users-close, .blocked-users-popup', function(e) {
            if (e.target === this) {
                closeBlockedUsersPopup();
            }
        });
        
        // Obsługa przycisku odblokowania użytkownika z listy zablokowanych
        $(document).on('click', '.blocked-user-item .unblock-user', function() {
            const userId = $(this).data('user-id');
            if (!userId) {
                return;
            }
            
            if (confirm('Czy na pewno chcesz odblokować tego użytkownika?')) {
                unblockUser(userId, true);
            }
        });

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

            // Ukryj wszystkie listy
            $('.messenger-conversations-list, .messenger-contacts-list, .messenger-archived-list, .messenger-deleted-list').hide();

            if (tab === 'conversations') {
                $('.messenger-conversations-list').show();
                // Odśwież listę konwersacji przy przełączeniu na zakładkę konwersacji
                refreshConversationsList();
            } else if (tab === 'archived') {
                $('.messenger-archived-list').show();
                // Załaduj zarchiwizowane konwersacje
                loadArchivedConversations();
            } else if (tab === 'deleted') {
                $('.messenger-deleted-list').show();
                // Załaduj usunięte konwersacje
                loadDeletedConversations();
            } else if (tab === 'contacts') {
                $('.messenger-contacts-list').show();
            }
        });

        // Obsługa archiwizacji konwersacji
        $(document).on('click', '.archive-conversation', function (e) {
            e.stopPropagation();
            const conversationId = $(this).data('conversation-id');
            archiveConversation(conversationId);
        });

        // Obsługa przywracania zarchiwizowanych konwersacji
        $(document).on('click', '.unarchive-conversation', function (e) {
            e.stopPropagation();
            const conversationId = $(this).data('conversation-id');
            unarchiveConversation(conversationId);
        });
        
        // Obsługa przywracania usuniętych konwersacji
        $(document).on('click', '.restore-conversation', function (e) {
            e.stopPropagation();
            const conversationId = $(this).data('conversation-id');
            restoreConversation(conversationId);
        });

        // Domyślnie wyświetl listę konwersacji
        $('.messenger-contacts-list, .messenger-archived-list, .messenger-deleted-list').hide();
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
        
        // Pobierz ID odbiorcy z konwersacji
        const recipientId = getRecipientIdFromConversation(conversationId);
        
        // Sprawdź, czy konwersacja jest zarchiwizowana
        const isArchived = $(`.conversation-item[data-conversation-id="${conversationId}"]`).hasClass('archived');
        const toggleArchiveBtn = $('.toggle-archive-btn');
        
        if (isArchived) {
            toggleArchiveBtn.addClass('is-archived');
            toggleArchiveBtn.attr('title', 'Przywróć konwersację');
            toggleArchiveBtn.find('i').removeClass('dashicons-archive').addClass('dashicons-undo');
        } else {
            toggleArchiveBtn.removeClass('is-archived');
            toggleArchiveBtn.attr('title', 'Archiwizuj konwersację');
            toggleArchiveBtn.find('i').removeClass('dashicons-undo').addClass('dashicons-archive');
        }

        // Resetuj zmienne paginacji
        window.messagesOffset = 0;
        window.hasMoreMessages = true;
        window.isLoadingMoreMessages = false;

        // Pobierz wiadomości (początkowo tylko 20 najnowszych)
        loadMessages(conversationId, 20, 0, function() {
            // Przewiń do najnowszej wiadomości
            scrollToBottom();

            // Wyślij powiadomienie o przeczytaniu wiadomości
            if (recipientId) {
                socket.emit('message_seen', {
                    conversation_id: parseInt(conversationId),
                    recipient_id: parseInt(recipientId),
                    user_id: currentUserId,
                    seen_at: new Date().toISOString()
                });
            }

            // Pokaż pole wpisywania wiadomości
            $('.messenger-input').show();

            // Zaktualizuj nagłówek konwersacji
            const conversationName = $(`.conversation-item[data-conversation-id="${conversationId}"]`).find('.user-name').text();
            $('.conversation-header h3').text(conversationName);

            // Pokaż czat na urządzeniach mobilnych
            $('.messenger-conversations').removeClass('active');
            $('.messenger-chat-area').addClass('active');

            // Dodaj obsługę przewijania dla lazy loading
            setupScrollListener();
        });
    }

    // Ładowanie wiadomości z paginacją
    function loadMessages(conversationId, limit, offset, callback) {
        $.ajax({
            url: messengerChat.ajaxurl,
            type: 'POST',
            data: {
                action: 'get_messages',
                conversation_id: conversationId,
                limit: limit,
                offset: offset,
                nonce: messengerChat.nonce
            },
            success: function (response) {
                $('.messenger-loading').hide();

                // Usuń wskaźnik ładowania starszych wiadomości, jeśli istnieje
                $('.load-more-messages-indicator').remove();

                if (response.success) {
                    // Aktualizuj zmienne paginacji
                    window.messagesOffset = offset + limit;
                    window.hasMoreMessages = response.data.has_more;
                    window.isLoadingMoreMessages = false;

                    // Zapisz pozycję przewijania i wysokość zawartości przed dodaniem nowych wiadomości
                    const messagesContainer = $('.messenger-messages');
                    const oldScrollHeight = messagesContainer[0].scrollHeight;
                    const oldScrollTop = messagesContainer.scrollTop();

                    // Wyświetl wiadomości
                    if (offset === 0) {
                        // Pierwsze ładowanie - dodaj wiadomości na koniec
                        response.data.messages.forEach(function (message) {
                            appendMessage(message);
                        });
                    } else {
                        // Ładowanie starszych wiadomości - dodaj na początek
                        response.data.messages.forEach(function (message) {
                            prependMessage(message);
                        });

                        // Zachowaj pozycję przewijania po dodaniu nowych wiadomości
                        const newScrollHeight = messagesContainer[0].scrollHeight;
                        messagesContainer.scrollTop(oldScrollTop + (newScrollHeight - oldScrollHeight));
                    }

                    // Wywołaj callback, jeśli został przekazany
                    if (typeof callback === 'function') {
                        callback();
                    }
                } else {
                    $('.messenger-messages').html('<div class="error-message">Błąd podczas pobierania wiadomości</div>');
                }
            },
            error: function () {
                $('.messenger-loading').hide();
                $('.load-more-messages-indicator').remove();
                window.isLoadingMoreMessages = false;
                $('.messenger-messages').html('<div class="error-message">Błąd podczas pobierania wiadomości</div>');
            }
        });
    }

    // Konfiguracja nasłuchiwania przewijania dla lazy loading
    function setupScrollListener() {
        const messagesContainer = $('.messenger-messages');

        // Usuń poprzedni event listener, jeśli istnieje
        messagesContainer.off('scroll.lazyLoading');

        // Dodaj nowy event listener
        messagesContainer.on('scroll.lazyLoading', function() {
            // Sprawdź, czy użytkownik przewinął do góry (do najstarszych wiadomości)
            if (messagesContainer.scrollTop() < 50 && window.hasMoreMessages && !window.isLoadingMoreMessages) {
                // Pokaż wskaźnik ładowania
                messagesContainer.prepend('<div class="load-more-messages-indicator">Ładowanie starszych wiadomości...</div>');

                // Ustaw flagę, aby zapobiec wielokrotnym żądaniom
                window.isLoadingMoreMessages = true;

                // Załaduj więcej wiadomości
                loadMessages(activeConversation, 20, window.messagesOffset);
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
        console.log('Odświeżanie listy konwersacji...');
        
        // Pokaż wskaźnik ładowania, jeśli istnieje
        $('.messenger-conversations-loading').show();
        
        $.ajax({
            url: messengerChat.ajaxurl,
            type: 'POST',
            data: {
                action: 'get_conversations',
                nonce: messengerChat.nonce
            },
            success: function(response) {
                // Ukryj wskaźnik ładowania
                $('.messenger-conversations-loading').hide();
                
                if (response.success) {
                    // Zaktualizuj listę konwersacji
                    $('.messenger-conversations-list').html(response.data);
                    
                    // Zaznacz aktywną konwersację, jeśli istnieje
                    if (activeConversation > 0) {
                        $(`.conversation-item[data-conversation-id="${activeConversation}"]`).addClass('active');
                    }
                    
                    console.log('Lista konwersacji została zaktualizowana');
                } else {
                    console.error('Błąd podczas odświeżania listy konwersacji:', response.data);
                }
            },
            error: function(xhr, status, error) {
                // Ukryj wskaźnik ładowania
                $('.messenger-conversations-loading').hide();
                
                console.error('Błąd AJAX podczas odświeżania listy konwersacji:', status, error);
            }
        });
    }


// Dodawanie wiadomości na początek listy (dla starszych wiadomości)
    function prependMessage(message) {
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
        const messageId = message.id || 'msg-' + Date.now();
        const readAt = message.read_at || null;

        // Określ klasę wiadomości
        const messageClass = isMine ? 'my-message' : 'their-message';

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

        // Przygotuj HTML dla statusu przeczytania, jeśli to moja wiadomość
        let readStatusHtml = '';
        if (isMine) {
            if (readAt) {
                readStatusHtml = `<div class="message-read">Przeczytano ${formatTime(readAt)}</div>`;
            } else {
                readStatusHtml = `<div class="message-not-read">Nieprzeczytane</div>`;
            }
        }

        const messageHtml = `
        <div class="message-item ${messageClass}" data-sender-id="${senderId}" data-message-id="${messageId}">
            ${!isMine ? `
                <div class="message-avatar">
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

        // Dodaj wiadomość na początek listy
        $('.messenger-messages').prepend(messageHtml);
    }

// Dodawanie wiadomości na koniec listy (dla nowych wiadomości)
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
        const messageId = message.id || 'msg-' + Date.now();
        const readAt = message.read_at || null;

        // Debugowanie
        console.log('Dane wiadomości:', {
            messageContent: messageContent,
            senderId: senderId,
            senderName: senderName,
            senderAvatar: senderAvatar,
            sentAt: sentAt,
            isMine: isMine,
            attachment: attachment,
            messageId: messageId,
            readAt: readAt
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

        // Przygotuj HTML dla statusu przeczytania, jeśli to moja wiadomość
        let readStatusHtml = '';
        if (isMine) {
            if (readAt) {
                readStatusHtml = `<div class="message-read">Przeczytano ${formatTime(readAt)}</div>`;
            } else {
                readStatusHtml = `<div class="message-not-read">Nieprzeczytane</div>`;
            }
        }

        const messageHtml = `
        <div class="message-item ${messageClass}" data-sender-id="${senderId}" data-message-id="${messageId}">
            ${!isMine ? `
                <div class="message-avatar">
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
        
        scrollToBottom();
    }
    
    // Oznaczanie wiadomości jako przeczytane
    function markMessagesAsRead(conversationId) {
        if (!conversationId) return;
        
        // Pobierz ID odbiorcy
        const recipientId = getRecipientIdFromConversation(conversationId);
        
        // Wyślij żądanie AJAX do oznaczenia wiadomości jako przeczytane
        $.ajax({
            url: messengerChat.ajaxurl,
            type: 'POST',
            data: {
                action: 'mark_messages_as_read',
                conversation_id: conversationId,
                nonce: messengerChat.nonce
            },
            success: function(response) {
                console.log('Wiadomości oznaczone jako przeczytane:', response);
            },
            error: function(xhr, status, error) {
                console.error('Błąd podczas oznaczania wiadomości jako przeczytane:', status, error);
            }
        });
    }
    
    // Aktualizacja wskaźników przeczytania dla wiadomości
    function updateReadReceipts(conversationId, readAt) {
        if (conversationId !== activeConversation) return;
        
        // Znajdź wszystkie wiadomości wysłane przez bieżącego użytkownika
        $('.message-item.my-message').each(function() {
            // Zaktualizuj status przeczytania
            const readStatus = $(this).find('.message-not-read');
            if (readStatus.length > 0) {
                readStatus.removeClass('message-not-read').addClass('message-read')
                    .text(`Przeczytano ${formatTime(readAt)}`);
            } else {
                // Jeśli nie ma statusu, dodaj go
                const messageTime = $(this).find('.message-time');
                if (messageTime.length > 0 && !$(this).find('.message-read').length) {
                    messageTime.after(`<div class="message-read">Przeczytano ${formatTime(readAt)}</div>`);
                }
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
    
    // Archiwizacja konwersacji
    function archiveConversation(conversationId) {
        $.ajax({
            url: messengerChat.ajaxurl,
            type: 'POST',
            data: {
                action: 'archive_conversation',
                conversation_id: conversationId,
                nonce: messengerChat.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Jeśli to aktywna konwersacja, zaktualizuj przycisk w nagłówku
                    if (activeConversation === parseInt(conversationId)) {
                        const toggleArchiveBtn = $('.toggle-archive-btn');
                        toggleArchiveBtn.addClass('is-archived');
                        toggleArchiveBtn.attr('title', 'Przywróć konwersację');
                        toggleArchiveBtn.find('i').removeClass('dashicons-archive').addClass('dashicons-undo');
                    }
                    
                    // Usuń konwersację z listy aktywnych
                    $(`.conversation-item[data-conversation-id="${conversationId}"]`).fadeOut(300, function() {
                        $(this).remove();
                        
                        // Jeśli to była aktywna konwersacja, wyczyść obszar czatu
                        if (activeConversation === parseInt(conversationId)) {
                            // Nie resetujemy activeConversation, aby przycisk archiwizacji działał poprawnie
                            // Użytkownik może przywrócić konwersację bezpośrednio z nagłówka
                        }
                    });
                    
                    // Pokaż powiadomienie o sukcesie
                    showNotification('Konwersacja została zarchiwizowana');
                } else {
                    // Pokaż błąd
                    showNotification('Nie udało się zarchiwizować konwersacji', 'error');
                }
            },
            error: function() {
                showNotification('Wystąpił błąd podczas archiwizacji konwersacji', 'error');
            }
        });
    }
    
    // Przywracanie zarchiwizowanej konwersacji
    function unarchiveConversation(conversationId) {
        $.ajax({
            url: messengerChat.ajaxurl,
            type: 'POST',
            data: {
                action: 'unarchive_conversation',
                conversation_id: conversationId,
                nonce: messengerChat.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Jeśli to aktywna konwersacja, zaktualizuj przycisk w nagłówku
                    if (activeConversation === parseInt(conversationId)) {
                        const toggleArchiveBtn = $('.toggle-archive-btn');
                        toggleArchiveBtn.removeClass('is-archived');
                        toggleArchiveBtn.attr('title', 'Archiwizuj konwersację');
                        toggleArchiveBtn.find('i').removeClass('dashicons-undo').addClass('dashicons-archive');
                    }
                    
                    // Usuń konwersację z listy zarchiwizowanych
                    $(`.conversation-item[data-conversation-id="${conversationId}"]`).fadeOut(300, function() {
                        $(this).remove();
                    });
                    
                    // Odśwież listę aktywnych konwersacji
                    refreshConversationsList();
                    
                    // Pokaż powiadomienie o sukcesie
                    showNotification('Konwersacja została przywrócona');
                    
                    // Przełącz na zakładkę konwersacji
                    $('.messenger-tabs a[data-tab="conversations"]').click();
                } else {
                    // Pokaż błąd
                    showNotification('Nie udało się przywrócić konwersacji', 'error');
                }
            },
            error: function() {
                showNotification('Wystąpił błąd podczas przywracania konwersacji', 'error');
            }
        });
    }
    
    // Ładowanie zarchiwizowanych konwersacji
    function loadArchivedConversations() {
        // Pokaż wskaźnik ładowania
        $('.messenger-archived-list').html('<div class="loading-archived">Ładowanie zarchiwizowanych konwersacji...</div>');
        
        $.ajax({
            url: messengerChat.ajaxurl,
            type: 'POST',
            data: {
                action: 'get_archived_conversations',
                nonce: messengerChat.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Zaktualizuj listę zarchiwizowanych konwersacji
                    $('.messenger-archived-list').html(response.data);
                } else {
                    // Pokaż błąd
                    $('.messenger-archived-list').html('<div class="error-message">Błąd podczas ładowania zarchiwizowanych konwersacji</div>');
                }
            },
            error: function() {
                $('.messenger-archived-list').html('<div class="error-message">Błąd podczas ładowania zarchiwizowanych konwersacji</div>');
            }
        });
    }
    
    // Usuwanie konwersacji (soft delete)
    function deleteConversation(conversationId) {
        $.ajax({
            url: messengerChat.ajaxurl,
            type: 'POST',
            data: {
                action: 'delete_conversation',
                conversation_id: conversationId,
                nonce: messengerChat.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Usuń konwersację z listy
                    $(`.conversation-item[data-conversation-id="${conversationId}"]`).fadeOut(300, function() {
                        $(this).remove();
                    });
                    
                    // Jeśli to była aktywna konwersacja, wyczyść obszar czatu
                    if (activeConversation === parseInt(conversationId)) {
                        // Wyczyść obszar czatu
                        $('.messenger-messages').empty();
                        $('.conversation-header h3').text('');
                        $('#messenger-conversation-id').val(0);
                        activeConversation = 0;
                    }
                    
                    // Pokaż powiadomienie o sukcesie
                    showNotification('Konwersacja została usunięta wraz ze wszystkimi wiadomościami i załącznikami');
                    
                    // Odśwież listę konwersacji
                    refreshConversationsList();
                } else {
                    // Pokaż błąd
                    showNotification('Nie udało się usunąć konwersacji', 'error');
                }
            },
            error: function() {
                showNotification('Wystąpił błąd podczas usuwania konwersacji', 'error');
            }
        });
    }
    
    // Przywracanie usuniętej konwersacji
    function restoreConversation(conversationId) {
        $.ajax({
            url: messengerChat.ajaxurl,
            type: 'POST',
            data: {
                action: 'restore_conversation',
                conversation_id: conversationId,
                nonce: messengerChat.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Usuń konwersację z listy usuniętych
                    $(`.conversation-item[data-conversation-id="${conversationId}"]`).fadeOut(300, function() {
                        $(this).remove();
                    });
                    
                    // Odśwież listę aktywnych konwersacji
                    refreshConversationsList();
                    
                    // Pokaż powiadomienie o sukcesie
                    showNotification('Konwersacja została przywrócona');
                    
                    // Przełącz na zakładkę konwersacji
                    $('.messenger-tabs a[data-tab="conversations"]').click();
                } else {
                    // Pokaż błąd
                    showNotification('Nie udało się przywrócić konwersacji', 'error');
                }
            },
            error: function() {
                showNotification('Wystąpił błąd podczas przywracania konwersacji', 'error');
            }
        });
    }
    
    // Ładowanie usuniętych konwersacji
    function loadDeletedConversations() {
        // Pokaż wskaźnik ładowania
        $('.messenger-deleted-list').html('<div class="loading-deleted">Ładowanie usuniętych konwersacji...</div>');
        
        $.ajax({
            url: messengerChat.ajaxurl,
            type: 'POST',
            data: {
                action: 'get_deleted_conversations',
                nonce: messengerChat.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Zaktualizuj listę usuniętych konwersacji
                    $('.messenger-deleted-list').html(response.data);
                } else {
                    // Pokaż błąd
                    $('.messenger-deleted-list').html('<div class="error-message">Błąd podczas ładowania usuniętych konwersacji</div>');
                }
            },
            error: function() {
                $('.messenger-deleted-list').html('<div class="error-message">Błąd podczas ładowania usuniętych konwersacji</div>');
            }
        });
    }
    
    // Pobieranie załączników konwersacji
    function getConversationAttachments(conversationId) {
        // Pokaż popup z ładowaniem
        $('#attachments-popup').addClass('active');
        $('.attachments-loading').show();
        $('.attachments-list').empty();
        $('.attachments-empty').hide();
        
        $.ajax({
            url: messengerChat.ajaxurl,
            type: 'POST',
            data: {
                action: 'get_conversation_attachments',
                conversation_id: conversationId,
                nonce: messengerChat.nonce
            },
            success: function(response) {
                $('.attachments-loading').hide();
                
                if (response.success && response.data && response.data.length > 0) {
                    // Wyświetl listę załączników
                    const attachmentsList = $('.attachments-list');
                    
                    response.data.forEach(function(attachment) {
                        const attachmentItem = `
                            <div class="attachment-item">
                                <span class="attachment-icon dashicons dashicons-pdf"></span>
                                <div class="attachment-info">
                                    <div class="attachment-name">${attachment.filename}</div>
                                    <div class="attachment-date">${attachment.formatted_date}</div>
                                </div>
                                <a href="${attachment.url}" target="_blank" class="attachment-download">Pobierz</a>
                            </div>
                        `;
                        
                        attachmentsList.append(attachmentItem);
                    });
                } else {
                    // Pokaż komunikat o braku załączników
                    $('.attachments-empty').show();
                }
            },
            error: function() {
                $('.attachments-loading').hide();
                $('.attachments-list').html('<div class="error-message">Błąd podczas pobierania załączników</div>');
            }
        });
    }
    
    // Zamykanie popupu z załącznikami
    function closeAttachmentsPopup() {
        $('#attachments-popup').removeClass('active');
    }
    
    // Pobieranie informacji o użytkowniku
    function getUserInfo(userId) {
        $.ajax({
            url: messengerChat.ajaxurl,
            type: 'POST',
            data: {
                action: 'get_user_info',
                user_id: userId,
                nonce: messengerChat.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Wypełnij popup danymi użytkownika
                    fillUserInfoPopup(response.data);
                    
                    // Pokaż popup
                    showUserInfoPopup();
                } else {
                    showNotification('Nie udało się pobrać informacji o użytkowniku', 'error');
                }
            },
            error: function() {
                showNotification('Wystąpił błąd podczas pobierania informacji o użytkowniku', 'error');
            }
        });
    }
    
    // Wypełnianie popupu danymi użytkownika
    function fillUserInfoPopup(userData) {
        // Ustaw avatar
        $('.user-info-avatar img').attr('src', userData.avatar);
        
        // Ustaw imię i nazwisko
        $('.user-info-name').text(userData.display_name);
        
        // Ustaw rolę
        $('.user-info-role').text(userData.role);
        
        // Ustaw email
        $('.user-info-email').text(userData.user_email);
        
        // Ustaw datę rejestracji
        $('.user-info-registered').text(userData.user_registered);
        
        // Ustaw opis (jeśli istnieje)
        if (userData.description) {
            $('.user-info-description').text(userData.description);
            $('.user-info-section:last-child').show();
        } else {
            $('.user-info-description').text('Brak opisu');
            $('.user-info-section:last-child').show();
        }
    }
    
    // Pokazywanie popupu z informacjami o użytkowniku
    function showUserInfoPopup() {
        $('#user-info-popup').addClass('active');
    }
    
    // Zamykanie popupu z informacjami o użytkowniku
    function closeUserInfoPopup() {
        $('#user-info-popup').removeClass('active');
    }
    
    // Blokowanie użytkownika
    function blockUser(userId) {
        $.ajax({
            url: messengerChat.ajaxurl,
            type: 'POST',
            data: {
                action: 'block_user',
                user_id: userId,
                nonce: messengerChat.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Aktualizuj UI
                    $('.block-user-btn').hide();
                    $('.unblock-user-btn').show();
                    $('.unblock-user-btn').data('user-id', userId);
                    
                    // Pokaż powiadomienie o sukcesie
                    showNotification('Użytkownik został zablokowany');
                    
                    // Zamknij popup z informacjami o użytkowniku
                    closeUserInfoPopup();
                    
                    // Odśwież listę konwersacji
                    refreshConversationsList();
                } else {
                    // Pokaż błąd
                    showNotification('Nie udało się zablokować użytkownika', 'error');
                }
            },
            error: function() {
                showNotification('Wystąpił błąd podczas blokowania użytkownika', 'error');
            }
        });
    }
    
    // Odblokowanie użytkownika
    function unblockUser(userId, refreshList = false) {
        $.ajax({
            url: messengerChat.ajaxurl,
            type: 'POST',
            data: {
                action: 'unblock_user',
                user_id: userId,
                nonce: messengerChat.nonce
            },
            success: function(response) {
                if (response.success) {
                    if (refreshList) {
                        // Odśwież listę zablokowanych użytkowników
                        getBlockedUsers();
                    } else {
                        // Aktualizuj UI
                        $('.unblock-user-btn').hide();
                        $('.block-user-btn').show();
                        $('.block-user-btn').data('user-id', userId);
                    }
                    
                    // Pokaż powiadomienie o sukcesie
                    showNotification('Użytkownik został odblokowany');
                    
                    // Odśwież listę konwersacji
                    refreshConversationsList();
                } else {
                    // Pokaż błąd
                    showNotification('Nie udało się odblokować użytkownika', 'error');
                }
            },
            error: function() {
                showNotification('Wystąpił błąd podczas odblokowania użytkownika', 'error');
            }
        });
    }
    
    // Pobieranie listy zablokowanych użytkowników
    function getBlockedUsers() {
        // Pokaż popup z ładowaniem
        $('#blocked-users-popup').addClass('active');
        $('.blocked-users-loading').show();
        $('.blocked-users-list').empty();
        
        $.ajax({
            url: messengerChat.ajaxurl,
            type: 'POST',
            data: {
                action: 'get_blocked_users',
                nonce: messengerChat.nonce
            },
            success: function(response) {
                $('.blocked-users-loading').hide();
                
                if (response.success) {
                    // Wyświetl listę zablokowanych użytkowników
                    $('.blocked-users-list').html(response.data);
                } else {
                    // Pokaż błąd
                    $('.blocked-users-list').html('<div class="error-message">Błąd podczas pobierania zablokowanych użytkowników</div>');
                }
            },
            error: function() {
                $('.blocked-users-loading').hide();
                $('.blocked-users-list').html('<div class="error-message">Błąd podczas pobierania zablokowanych użytkowników</div>');
            }
        });
    }
    
    // Zamykanie popupu z zablokowanymi użytkownikami
    function closeBlockedUsersPopup() {
        $('#blocked-users-popup').removeClass('active');
    }
    
    // Wypełnianie popupu danymi użytkownika
    function fillUserInfoPopup(userData) {
        // Ustaw avatar
        $('.user-info-avatar img').attr('src', userData.avatar);
        
        // Ustaw imię i nazwisko
        $('.user-info-name').text(userData.display_name);
        
        // Ustaw rolę
        $('.user-info-role').text(userData.role);
        
        // Ustaw email
        $('.user-info-email').text(userData.user_email);
        
        // Ustaw datę rejestracji
        $('.user-info-registered').text(userData.user_registered);
        
        // Ustaw opis (jeśli istnieje)
        if (userData.description) {
            $('.user-info-description').text(userData.description);
            $('.user-info-section:nth-last-child(2)').show();
        } else {
            $('.user-info-description').text('Brak opisu');
            $('.user-info-section:nth-last-child(2)').show();
        }
        
        // Ustaw przyciski blokowania/odblokowania
        const blockUserBtn = $('.block-user-btn');
        const unblockUserBtn = $('.unblock-user-btn');
        
        blockUserBtn.data('user-id', userData.id);
        unblockUserBtn.data('user-id', userData.id);
        
        if (userData.is_blocked) {
            blockUserBtn.hide();
            unblockUserBtn.show();
        } else {
            blockUserBtn.show();
            unblockUserBtn.hide();
        }
    }
    
    // Wyświetlanie powiadomień
    function showNotification(message, type = 'success') {
        // Usuń istniejące powiadomienia
        $('.messenger-notification').remove();
        
        // Utwórz nowe powiadomienie
        const notification = $(`<div class="messenger-notification ${type}">${message}</div>`);
        
        // Dodaj do DOM
        $('body').append(notification);
        
        // Pokaż powiadomienie
        setTimeout(function() {
            notification.addClass('show');
        }, 10);
        
        // Ukryj po 3 sekundach
        setTimeout(function() {
            notification.removeClass('show');
            setTimeout(function() {
                notification.remove();
            }, 300);
        }, 3000);
    }

    // Inicjalizacja po załadowaniu dokumentu
    $(document).ready(function () {
        initChat();
    });

})(jQuery);
