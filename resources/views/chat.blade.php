<!DOCTYPE html>
<html>

<head>
    <title>Chatbot</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>

<body>
    <h1>Chat with AI</h1>
    <div id="chat-box"></div>

    <input type="text" id="message" placeholder="اكتب رسالتك...">
    <button onclick="sendMessage()">إرسال</button>

    <script>
    function sendMessage() {
        const message = document.getElementById('message').value;
        fetch('chat', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({
                    message
                })
            })
            .then(res => res.json())
            .then(data => {
                document.getElementById('chat-box').innerHTML += `<p><b>أنت:</b> ${message}</p>`;
                document.getElementById('chat-box').innerHTML += `<p><b>الروبوت:</b> ${data.response}</p>`;
                document.getElementById('message').value = '';
            });
    }
    </script>
</body>

</html>