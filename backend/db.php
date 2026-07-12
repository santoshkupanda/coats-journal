<?php
// backend/db.php
// Database connection utility with automatic initialization and seeding

require_once __DIR__ . '/config.php';

try {
    // Connect to MySQL server first (without database to check if it exists)
    $dsn = "mysql:host=" . DB_HOST . ";charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

    // Create database if not exists
    $dbName = DB_NAME;
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;");
    $pdo->exec("USE `$dbName`;");

    // Initialize tables from schema.sql if users table doesn't exist
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'users'")->fetch();
    if (!$tableCheck) {
        $sql = file_get_contents(__DIR__ . '/../database/schema.sql');
        if ($sql) {
            $pdo->exec($sql);
        }
    }

    // Seed default users if table is empty
    $userCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($userCount == 0) {
        $defaultUsers = [
            [
                'username' => 'admin',
                'email' => 'admin@journal.com',
                'password' => password_hash('admin123', PASSWORD_DEFAULT),
                'role' => 'Super Admin',
                'can_submit' => 1, 'can_review' => 1, 'can_assign' => 1, 'can_approve' => 1, 'can_publish' => 1
            ],
            [
                'username' => 'sarah',
                'email' => 'sarah@journal.com',
                'password' => password_hash('author123', PASSWORD_DEFAULT),
                'role' => 'Contributor',
                'can_submit' => 1, 'can_review' => 0, 'can_assign' => 0, 'can_approve' => 0, 'can_publish' => 0
            ],
            [
                'username' => 'john',
                'email' => 'john@journal.com',
                'password' => password_hash('reviewer123', PASSWORD_DEFAULT),
                'role' => 'Reviewer',
                'can_submit' => 0, 'can_review' => 1, 'can_assign' => 0, 'can_approve' => 0, 'can_publish' => 0
            ],
            [
                'username' => 'editor',
                'email' => 'editor@journal.com',
                'password' => password_hash('editor123', PASSWORD_DEFAULT),
                'role' => 'Editor-In-Chief',
                'can_submit' => 1, 'can_review' => 1, 'can_assign' => 1, 'can_approve' => 1, 'can_publish' => 1
            ],
            [
                'username' => 'managing',
                'email' => 'managing@journal.com',
                'password' => password_hash('managing123', PASSWORD_DEFAULT),
                'role' => 'Managing Editor',
                'can_submit' => 0, 'can_review' => 0, 'can_assign' => 1, 'can_approve' => 1, 'can_publish' => 0
            ],
            [
                'username' => 'board',
                'email' => 'board@journal.com',
                'password' => password_hash('board123', PASSWORD_DEFAULT),
                'role' => 'Editorial Board',
                'can_submit' => 0, 'can_review' => 1, 'can_assign' => 0, 'can_approve' => 1, 'can_publish' => 0
            ],
            [
                'username' => 'production',
                'email' => 'production@journal.com',
                'password' => password_hash('prod123', PASSWORD_DEFAULT),
                'role' => 'Production Staff',
                'can_submit' => 0, 'can_review' => 0, 'can_assign' => 0, 'can_approve' => 0, 'can_publish' => 1
            ]
        ];

        $stmt = $pdo->prepare("INSERT INTO users (username, email, password, role, can_submit, can_review, can_assign, can_approve, can_publish) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($defaultUsers as $u) {
            $stmt->execute([
                $u['username'],
                $u['email'],
                $u['password'],
                $u['role'],
                $u['can_submit'],
                $u['can_review'],
                $u['can_assign'],
                $u['can_approve'],
                $u['can_publish']
            ]);
        }
    }

} catch (PDOException $e) {
    // If the database has not been set up or local environment has different credentials, catch gracefully
    error_log("Database initialization error: " . $e->getMessage());
    $pdo = null;
}

function getDbConnection() {
    global $pdo;
    return $pdo;
}
