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
        // Emituj wiadomość do odbiorcy
        io.to(activeUsers[data.recipient_id]).emit('new_message', {
            conversation_id: data.conversation_id,
            message: data.message
        });
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

    // Obsługa bezpośrednich wiadomości z klienta (opcjonalnie)
    socket.on('direct_message', (data) => {
        const recipientId = data.recipient_id;

        // Jeśli odbiorca jest online, wyślij wiadomość
        if (activeUsers[recipientId]) {
            io.to(activeUsers[recipientId]).emit('new_message', {
                conversation_id: data.conversation_id,
                message: data.message
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