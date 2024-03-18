<?php
session_start();
date_default_timezone_set("Asia/Tashkent");

$token = 'ваш_токен';
$database_file = 'путь_к_файлу_базы_данных'; // Например: 'database.json'
$channel_id = '@ваш_ID_канала'; // Например: '@cosmos2511'

$update = json_decode(file_get_contents('php://input'), true);

// Измените обработку входящего сообщения
if (isset($update['message'])) {
    $message = $update['message'];
    $from_id = $message['from']['id'];
    $chat_id = $message['chat']['id'];

    if (isset($message['voice'])) {
        // Если пользователь отправил голосовое сообщение, вызываем функцию addVoiceMessage
        addVoiceMessage($message);
    } elseif (isset($message['text'])) {
        $text = $message['text'];
        
        if ($text == '/start') {
            subscribeToChannel($chat_id, $from_id);
        } elseif ($text == '/otmena') {
            cancelAction($chat_id);
        } elseif ($_SESSION['waiting_for_title']) {
            addVoiceMessage($message); // Если ожидается название голосового сообщения, вызываем функцию addVoiceMessage
        } else {
            handleMessage($message);
        }
    }
}

function subscribeToChannel($chat_id, $from_id) {
    global $token, $channel_id;

    $forchannel = json_decode(file_get_contents("https://api.telegram.org/bot$token/getChatMember?chat_id=$channel_id&user_id=$from_id"));
    $status = $forchannel->result->status;

    if ($status == "member" || $status == "administrator" || $status == "creator") {
        sendMessage($chat_id, "Вы уже подписаны на канал. Можете продолжать работу.");
    } else {
        sendMessage($chat_id, "Для продолжения работы, пожалуйста, подпишитесь на наш канал и подтвердите подписку.");
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "Присоединиться к каналу: $channel_id",
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => 'Перейти в канал', 'url' => "https://t.me/$channel_id"]
                    ],
                    [
                        ['text' => 'Подтвердить подписку', 'callback_data' => 'confirm_subscription']
                    ]
                ]
            ])
        ]);
    }
}


if (isset($update['callback_query'])) {
    handleCallbackQuery($update['callback_query']);
}

function cancelAction($chat_id) {
    unset($_SESSION['waiting_for_title']);
    unset($_SESSION['pending_voice_message']);
    sendMessage($chat_id, "Действие отменено.");
}

function addVoiceMessage($message) {
    global $database_file;
    $voice = $message['voice'];
    $voice_file_id = $voice['file_id'];
    $user_id = $message['from']['id'];
    $chat_id = $message['chat']['id'];
    $_SESSION['waiting_for_title'] = true; // Устанавливаем флаг ожидания названия
    sendMessage($message['chat']['id'], "Голосовое сообщение обрабатывается.");
    file_put_contents("data/$chat_id.txt", $voice_file_id);
}

function handleMessage($message) {
    global $database_file, $chat_id;
    $chat_id = $message['chat']['id']; // Получаем chat_id

    // Добавим отладочное сообщение, чтобы увидеть, доходит ли бот до этой части кода
    sendMessage($chat_id, "Сообщение обработано в функции handleMessage.");
    $voicee = file_get_contents("data/$chat_id.txt");
    $title = $message['text'];
    file_put_contents("data/tx$chat_id.txt", $title);
    sendVoiceConfirmation($chat_id, $title, $voicee);
    $_SESSION['waiting_for_title'] = false;
}

function sendVoiceConfirmation($chat_id, $title, $voicee) {
    // Отправляем аудио с описанием и кнопками для подтверждения или отмены операции
    bot('sendAudio', [
        'chat_id' => $chat_id,
        'audio' => $voicee,
        'caption' => $title,
        'reply_markup' => json_encode([
            'inline_keyboard' => [
                [
                    ['text' => 'Сохранить', 'callback_data' => 'confirm_save'],
                    ['text' => 'Отмена', 'callback_data' => 'cancel_save']
                ]
            ]
        ])
    ]);
}


function handleCallbackQuery($callback_query) {
    global $database_file;

    $data = $callback_query['data'];
    $message = $callback_query['message'];
    $chat_id = $message['chat']['id'];
 
    if ($data == 'confirm_subscription') {
        $chat_id = $message['chat']['id'];
        // Здесь вы можете добавить логику для подтверждения подписки
        sendMessage($chat_id, "Спасибо за подписку!");
    }

   
        $voicee = file_get_contents("data/$chat_id.txt");
        $title = file_get_contents("data/tx$chat_id.txt");
        if ($data == 'confirm_save') {
            $record = [
                'id' => uniqid(), // Уникальный идентификатор записи
                'title' => $title,
                'voice_file_id' => $voicee,
            ];

            if (addToDatabase($record)) {
                sendMessage($chat_id, "Голосовое сообщение '$title' добавлено в базу данных.");
            } else {
                sendMessage($chat_id, "Ошибка при добавлении голосового сообщения в базу данных.");
            }
        } elseif ($data == 'cancel_save') {
            unlink("data/$chat_id.txt");
            unlink("data/tx$chat_id.txt");
            sendMessage($chat_id, "Добавление голосового сообщения отменено.");
        }

    }

// Функция добавления записи в базу данных
function addToDatabase($record) {
    global $database_file;
    // Читаем текущее содержимое файла базы данных
    $data = json_decode(file_get_contents($database_file), true);
    // Добавляем новую запись
    $data[] = $record;
    // Записываем обновленные данные обратно в файл
    return file_put_contents($database_file, json_encode($data, JSON_PRETTY_PRINT));
}


// Обработка инлайн-запросов
if (isset($update['inline_query'])) {
    handleInlineQuery($update['inline_query']);
}

function handleInlineQuery($inline_query) {
    global $database_file, $token;
    
    $query = $inline_query['query'];
    $inline_query_id = $inline_query['id'];

    if (empty($query)) {
        // Если запрос пустой, отправляем пустой результат
        sendInlineResults($inline_query_id, []);
        return;
    }

    // Читаем базу данных
    $data = json_decode(file_get_contents($database_file), true);

    $results = [];

    foreach ($data as $record) {
        // Проверяем, содержит ли название записи запрос пользователя
        if (mb_stripos($record['title'], $query) !== false) {
            // Если да, добавляем эту запись в результаты
            $results[] = [
                'type' => 'voice',
                'id' => uniqid(),
                'voice_file_id' => $record['voice_file_id'],
                'title' => $record['title']
            ];
        }
    }

    // Отправляем результаты обратно пользователю
    sendInlineResults($inline_query_id, $results);
}

function sendInlineResults($inline_query_id, $results) {
    $data = [
        'inline_query_id' => $inline_query_id,
        'results' => json_encode($results)
    ];

    // Отправляем результаты обратно пользователю
    bot('answerInlineQuery', $data);
}




// Функция отправки сообщения
function sendMessage($chat_id, $text) {
    bot('sendMessage', [
        'chat_id' => $chat_id,
        'text' => $text
    ]);
}

// Функция отправки запроса к API Телеграма
function bot($method, $data) {
    global $token;
    $url = "https://api.telegram.org/bot{$token}/{$method}";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    $res = curl_exec($ch);
    if (curl_error($ch)) {
        var_dump(curl_error($ch));
    } else {
        return json_decode($res, true);
    }
}