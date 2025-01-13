<?php

// Bot configuration
define("API_KEY", "6470914493:AAEZ_vKH5NZJWYl_qHpkxU1RIv8hVA6IsQk");

// Database configuration
define("DB_HOST", "localhost");
define("DB_USER", "testbot");
define("DB_PASS", "testbot");
define("DB_NAME", "testbot");

// Database connection
$connect = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
mysqli_set_charset($connect, "utf8mb4");

// Bot function for making requests to Telegram API
function bot($method, $datas = []) {
    $url = "https://api.telegram.org/bot" . API_KEY . "/" . $method;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $datas);
    $res = curl_exec($ch);
    if (curl_error($ch)) {
        var_dump(curl_error($ch));
    } else {
        return json_decode($res);
    }
}

// Get user state from database
function get_user_state($user_id) {
    global $connect;
    $result = mysqli_query($connect, "SELECT * FROM user_states WHERE user_id = $user_id");
    return mysqli_fetch_assoc($result);
}

// Update user state in database
function update_user_state($user_id, $state, $quiz_id = null, $current_question = null, $options = null) {
    global $connect;
    $state = mysqli_real_escape_string($connect, $state);
    $current_question = $current_question ? "'" . mysqli_real_escape_string($connect, $current_question) . "'" : "NULL";
    $options = $options ? "'" . mysqli_real_escape_string($connect, json_encode($options)) . "'" : "NULL";
    $quiz_id = $quiz_id ?: "NULL";

    mysqli_query($connect, "INSERT INTO user_states (user_id, state, quiz_id, current_question, options) 
                           VALUES ($user_id, '$state', $quiz_id, $current_question, $options)
                           ON DUPLICATE KEY UPDATE 
                           state = '$state',
                           quiz_id = $quiz_id,
                           current_question = $current_question,
                           options = $options");
}

// Clear user state
function clear_user_state($user_id) {
    global $connect;
    mysqli_query($connect, "DELETE FROM user_states WHERE user_id = $user_id");
}

// Get updates from Telegram
$update = json_decode(file_get_contents('php://input'), true);

// Handle both messages and callback queries
if (isset($update['message'])) {
    $message = $update['message'];
    $chat_id = $message['chat']['id'];
    $text = $message['text'] ?? '';
    $user_id = $message['from']['id'];
    $first_name = $message['from']['first_name'] ?? '';
    $last_name = $message['from']['last_name'] ?? '';
    $username = $message['from']['username'] ?? '';
    
    // Check if user is admin
    $admin_check = mysqli_query($connect, "SELECT * FROM admins WHERE user_id = $user_id");
    $is_admin = mysqli_num_rows($admin_check) > 0;

    if ($text === '/start') {
        clear_user_state($user_id);
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "Welcome to the Quiz Bot!\n" .
                     ($is_admin ? "You are an admin. Use /newquiz to create a quiz." : "Available quizzes will appear here.")
        ]);
    }
    
    // Admin commands
    elseif ($is_admin) {
        if ($text === '/newquiz') {
            mysqli_query($connect, "INSERT INTO quizzes (admin_id) VALUES ($user_id)");
            $quiz_id = mysqli_insert_id($connect);
            update_user_state($user_id, 'waiting_title', $quiz_id);
            
            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => 'Please send the title for your quiz:'
            ]);
        }
        // Handle quiz creation process
        else {
            $state = get_user_state($user_id);
            if ($state) {
                switch ($state['state']) {
                    case 'waiting_title':
                        mysqli_query($connect, "UPDATE quizzes SET title = '" . mysqli_real_escape_string($connect, $text) . "' WHERE quiz_id = {$state['quiz_id']}");
                        update_user_state($user_id, 'waiting_question', $state['quiz_id']);
                        
                        bot('sendMessage', [
                            'chat_id' => $chat_id,
                            'text' => "Send your question text:"
                        ]);
                        break;
                    
                    case 'waiting_question':
                        update_user_state($user_id, 'waiting_options', $state['quiz_id'], $text);
                        
                        bot('sendMessage', [
                            'chat_id' => $chat_id,
                            'text' => "Send the 4 options in this format:\nA) Option1\nB) Option2\nC) Option3\nD) Option4"
                        ]);
                        break;
                    
                    case 'waiting_options':
                        $options = explode("\n", $text);
                        if (count($options) === 4) {
                            update_user_state($user_id, 'waiting_correct', $state['quiz_id'], $state['current_question'], $options);
                            
                            bot('sendMessage', [
                                'chat_id' => $chat_id,
                                'text' => "Which option is correct? (Send A, B, C, or D)"
                            ]);
                        }
                        break;
                    
                    case 'waiting_correct':
                        if (in_array(strtoupper($text), ['A', 'B', 'C', 'D'])) {
                            $options = json_decode($state['options']);
                            $correct = strtoupper($text);
                            
                            mysqli_query($connect, "INSERT INTO questions (quiz_id, question_text, option_a, option_b, option_c, option_d, correct_option) 
                                                 VALUES ({$state['quiz_id']}, 
                                                         '" . mysqli_real_escape_string($connect, $state['current_question']) . "',
                                                         '" . mysqli_real_escape_string($connect, $options[0]) . "',
                                                         '" . mysqli_real_escape_string($connect, $options[1]) . "',
                                                         '" . mysqli_real_escape_string($connect, $options[2]) . "',
                                                         '" . mysqli_real_escape_string($connect, $options[3]) . "',
                                                         '$correct')");
                            
                            $quiz_link = "https://t.me/" . bot('getMe')->result->username . "?start=quiz_{$state['quiz_id']}";
                            
                            bot('sendMessage', [
                                'chat_id' => $chat_id,
                                'text' => "Question added! Quiz link:\n$quiz_link\n\nSend another question or /finish to complete the quiz."
                            ]);
                            
                            update_user_state($user_id, 'waiting_question', $state['quiz_id']);
                        }
                        break;
                }
            }
        }
    }
    
    // Handle quiz start command
    if (preg_match('/^\/start quiz_(\d+)$/', $text, $matches)) {
        $quiz_id = $matches[1];
        $questions = mysqli_query($connect, "SELECT * FROM questions WHERE quiz_id = $quiz_id ORDER BY question_id ASC LIMIT 1");
        
        if ($question = mysqli_fetch_assoc($questions)) {
            mysqli_query($connect, "INSERT INTO user_attempts (user_id, quiz_id) VALUES ($user_id, $quiz_id)");
            $attempt_id = mysqli_insert_id($connect);
            
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => substr($question['option_a'], 3), 'callback_data' => "ans_{$attempt_id}_{$question['question_id']}_A"]],
                    [['text' => substr($question['option_b'], 3), 'callback_data' => "ans_{$attempt_id}_{$question['question_id']}_B"]],
                    [['text' => substr($question['option_c'], 3), 'callback_data' => "ans_{$attempt_id}_{$question['question_id']}_C"]],
                    [['text' => substr($question['option_d'], 3), 'callback_data' => "ans_{$attempt_id}_{$question['question_id']}_D"]]
                ]
            ];
            
            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => $question['question_text'],
                'reply_markup' => json_encode($keyboard)
            ]);
        }
    }
}

// Handle callback queries (answer selections)
if (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $data = $callback['data'];
    $user_id = $callback['from']['id'];
    $message = $callback['message'];
    $chat_id = $message['chat']['id'];
    
    if (preg_match('/^ans_(\d+)_(\d+)_([A-D])$/', $data, $matches)) {
        $attempt_id = $matches[1];
        $question_id = $matches[2];
        $selected_option = $matches[3];
        
        $question = mysqli_fetch_assoc(mysqli_query($connect, "SELECT * FROM questions WHERE question_id = $question_id"));
        $is_correct = $selected_option === $question['correct_option'];
        
        if ($is_correct) {
            mysqli_query($connect, "UPDATE user_attempts SET score = score + 1 WHERE attempt_id = $attempt_id");
            $response_text = "✅ Correct!";
        } else {
            $correct_option = $question["option_" . strtolower($question['correct_option'])];
            $response_text = "❌ Wrong! The correct answer is:\n" . $correct_option;
        }
        
        // Get next question
        $next_question = mysqli_fetch_assoc(mysqli_query($connect, 
            "SELECT * FROM questions WHERE quiz_id = {$question['quiz_id']} AND question_id > $question_id ORDER BY question_id ASC LIMIT 1"));
        
        bot('answerCallbackQuery', [
            'callback_query_id' => $callback['id'],
            'text' => $is_correct ? "Correct!" : "Wrong!",
            'show_alert' => true
        ]);
        
        bot('editMessageText', [
            'chat_id' => $chat_id,
            'message_id' => $message['message_id'],
            'text' => $question['question_text'] . "\n\n" . $response_text
        ]);
        
        if ($next_question) {
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => substr($next_question['option_a'], 3), 'callback_data' => "ans_{$attempt_id}_{$next_question['question_id']}_A"]],
                    [['text' => substr($next_question['option_b'], 3), 'callback_data' => "ans_{$attempt_id}_{$next_question['question_id']}_B"]],
                    [['text' => substr($next_question['option_c'], 3), 'callback_data' => "ans_{$attempt_id}_{$next_question['question_id']}_C"]],
                    [['text' => substr($next_question['option_d'], 3), 'callback_data' => "ans_{$attempt_id}_{$next_question['question_id']}_D"]]
                ]
            ];
            
            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => $next_question['question_text'],
                'reply_markup' => json_encode($keyboard)
            ]);
        } else {
            $attempt = mysqli_fetch_assoc(mysqli_query($connect, "SELECT * FROM user_attempts WHERE attempt_id = $attempt_id"));
            $quiz = mysqli_fetch_assoc(mysqli_query($connect, "SELECT * FROM quizzes WHERE quiz_id = {$attempt['quiz_id']}"));
            $total_questions = mysqli_fetch_assoc(mysqli_query($connect, "SELECT COUNT(*) as total FROM questions WHERE quiz_id = {$attempt['quiz_id']}"))['total'];
            
            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "Quiz '{$quiz['title']}' completed!\nYour score: {$attempt['score']}/$total_questions"
            ]);
            
            mysqli_query($connect, "UPDATE user_attempts SET completed = TRUE WHERE attempt_id = $attempt_id");
        }
    }
}
