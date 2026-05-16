// server.js
import express from 'express';
import fetch from 'node-fetch';
import cors from 'cors';

const app = express();
app.use(cors()); // разрешаем фронтенду доступ
app.use(express.json());

const CRYPTOBOT_TOKEN = 'YOUR_CRYPTOBOT_TOKEN'; // твой токен

// Endpoint для создания платежа
app.post('/create-payment', async (req, res) => {
    const { booking, amount } = req.body;

    try {
        const response = await fetch('https://api.cryptobot.site/create_payment', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                token: CRYPTOBOT_TOKEN,
                amount: amount,
                currency: 'USD',
                comment: booking
            })
        });

        const data = await response.json();
        res.json(data); // отправляем pay_url фронтенду
    } catch (err) {
        console.error(err);
        res.status(500).json({ error: 'Server error' });
    }
});

app.listen(3000, () => console.log('Server running on port 3000'));