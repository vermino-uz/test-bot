-- Admins table
CREATE TABLE admins (
    user_id BIGINT PRIMARY KEY,
    username VARCHAR(255),
    first_name VARCHAR(255),
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Quizzes table
CREATE TABLE quizzes (
    quiz_id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id BIGINT,
    title VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES admins(user_id)
);

-- Questions table
CREATE TABLE questions (
    question_id INT AUTO_INCREMENT PRIMARY KEY,
    quiz_id INT,
    question_text TEXT,
    option_a TEXT,
    option_b TEXT,
    option_c TEXT,
    option_d TEXT,
    correct_option CHAR(1),
    FOREIGN KEY (quiz_id) REFERENCES quizzes(quiz_id)
);

-- User attempts table
CREATE TABLE user_attempts (
    attempt_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT,
    quiz_id INT,
    score INT DEFAULT 0,
    completed BOOLEAN DEFAULT FALSE,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (quiz_id) REFERENCES quizzes(quiz_id)
);

-- User states table
CREATE TABLE user_states (
    user_id BIGINT PRIMARY KEY,
    state VARCHAR(50),
    quiz_id INT,
    current_question TEXT,
    options TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (quiz_id) REFERENCES quizzes(quiz_id)
);
