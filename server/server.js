// server.js
const http = require('http');
const express = require('express');
const app = express();
const server = http.createServer(app);
const io = require('socket.io')(server, {
    cors: {
        origin: '*',
        methods: ['GET', 'POST']
    }
});
const bodyParser = require('body-parser');

// Middleware dla obsługi JSON
app.use(bodyParser.json());

// Przechowywanie aktywnych użytkowników
const activeUsers = {};

// Endpointy HTTP
app.post('/send-message', (req, res) => {
    const data = req.body;

    // Sprawdź, czy odbiorca jest online
    if (activeUsers[data.recipient_id]) {
        // Sprawdź, czy to nowa konwersacja
        if (data.hasOwnProperty('sender_id') && data.hasOwnProperty('sender_name')) {
            // To jest nowa konwersacja
            io.to(activeUsers[data.recipient_id]).emit('new_conversation', {
                conversation_id: data.conversation_id,
                sender_id: data.sender_id,
                sender_name: data.sender_name,
                sender_avatar: data.sender_avatar,
                message: data.message
            });
        } else {
            // To jest wiadomość w istniejącej konwersacji
            // Stwórz kopię wiadomości i zmień is_mine na false dla odbiorcy
            const messageForRecipient = {...data.message};
            messageForRecipient.is_mine = false;  // Kluczowa zmiana: dla odbiorcy to NIE jest jego wiadomość

            // Emituj wiadomość do odbiorcy z poprawioną flagą is_mine
            io.to(activeUsers[data.recipient_id]).emit('new_message', {
                conversation_id: data.conversation_id,
                message: messageForRecipient
            });
        }
    }

    res.json({ success: true });
});


// Obsługa połączeń WebSocket
io.on('connection', (socket) => {
    console.log('Nowe połączenie: ' + socket.id);

    // Rejestracja użytkownika
    socket.on('register', (data) => {
        const userId = data.userId;
        activeUsers[userId] = socket.id;
        console.log(`Użytkownik ${userId} zarejestrowany`);
    });

// Dodaj to do sekcji inicjalizacji WebSocket w messenger-chat.js
// po istniejącym kodzie dla socket.on('new_message', ...)

    socket.on('new_conversation', function(data) {
        console.log('Otrzymano nową konwersację:', data);

        // Sprawdź, czy konwersacja już istnieje na liście
        const existingConversation = $(`.conversation-item[data-conversation-id="${data.conversation_id}"]`);

        if (existingConversation.length === 0) {
            // Dodaj nową konwersację do listy
            const conversationHtml = `
            <div class="conversation-item has-new-message" data-conversation-id="${data.conversation_id}" data-recipient-id="${data.sender_id}">
                <div class="conversation-avatar">
                    <img src="${data.sender_avatar}" alt="${data.sender_name}">
                </div>
                <div class="conversation-info">
                    <div class="conversation-header">
                        <span class="user-name">${data.sender_name}</span>
                        <span class="conversation-time">teraz</span>
                    </div>
                    <div class="conversation-preview">
                        ${data.sender_name}: ${data.message.message.substring(0, 40)}${data.message.message.length > 40 ? '...' : ''}
                    </div>
                </div>
            </div>
        `;

            // Dodaj na górę listy konwersacji
            $('.messenger-conversations-list').prepend(conversationHtml);

            // Jeśli lista była pusta, usuń komunikat o braku konwersacji
            $('.empty-state').remove();
        } else {
            // Zaktualizuj istniejącą konwersację
            existingConversation.addClass('has-new-message');
            existingConversation.find('.conversation-preview').text(
                `${data.sender_name}: ${data.message.message.substring(0, 40)}${data.message.message.length > 40 ? '...' : ''}`
            );
            existingConversation.find('.conversation-time').text('teraz');

            // Przenieś konwersację na górę listy
            $('.messenger-conversations-list').prepend(existingConversation);
        }

        // Powiadomienie o nowej wiadomości
        playNotificationSound();

        // Jeśli jesteśmy już w tej konwersacji, dodaj wiadomość do czatu
        if (activeConversation === parseInt(data.conversation_id)) {
            appendMessage(data.message);
            scrollToBottom();
        }

        // Przełącz widok na zakładkę konwersacji, jeśli jesteśmy w zakładce kontaktów
        if ($('.messenger-tabs a[data-tab="contacts"]').hasClass('active')) {
            $('.messenger-tabs a[data-tab="conversations"]').click();
        }
    });
    socket.on('direct_message', (data) => {
        const recipientId = data.recipient_id;

        // Jeśli odbiorca jest online, wyślij wiadomość
        if (activeUsers[recipientId]) {
            // Stwórz kopię wiadomości i zmień is_mine na false dla odbiorcy
            const messageForRecipient = {...data.message};
            messageForRecipient.is_mine = false;  // To nie jest wiadomość odbiorcy

            io.to(activeUsers[recipientId]).emit('new_message', {
                conversation_id: data.conversation_id,
                message: messageForRecipient
            });
        }
    });

    // Powiadomienie o pisaniu
    socket.on('typing', (data) => {
        const recipientId = data.recipient_id;

        if (activeUsers[recipientId]) {
            io.to(activeUsers[recipientId]).emit('typing', {
                conversation_id: data.conversation_id,
                user_id: data.user_id
            });
        }
    });

    // Rozłączenie
    socket.on('disconnect', () => {
        // Usuń użytkownika z aktywnych
        for (const userId in activeUsers) {
            if (activeUsers[userId] === socket.id) {
                delete activeUsers[userId];
                console.log(`Użytkownik ${userId} rozłączony`);
                break;
            }
        }
    });
});

// Uruchom serwer na porcie 3000
server.listen(3000, () => {
    console.log('Serwer WebSocket uruchomiony na porcie 3000');
});