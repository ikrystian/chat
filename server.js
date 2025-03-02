// server.js
const http = require('http');
const server = http.createServer();
const io = require('socket.io')(server, {
    cors: {
        origin: '*',
        methods: ['GET', 'POST']
    }
});

// Przechowywanie aktywnych użytkowników
const activeUsers = {};

io.on('connection', (socket) => {
    console.log('Nowe połączenie: ' + socket.id);

    // Rejestracja użytkownika
    socket.on('register', (data) => {
        const userId = data.userId;
        activeUsers[userId] = socket.id;
        console.log(`Użytkownik ${userId} zarejestrowany`);
    });

    // Nowa wiadomość
    socket.on('new_message', (data) => {
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