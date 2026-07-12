-- Database Schema for COATS Journal Management Portal

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(50) NOT NULL, -- Super Admin, Editor-In-Chief, Managing Editor, Editorial Board, Reviewer, Production Staff, Contributor
    can_submit TINYINT(1) DEFAULT 0,
    can_review TINYINT(1) DEFAULT 0,
    can_assign TINYINT(1) DEFAULT 0,
    can_approve TINYINT(1) DEFAULT 0,
    can_publish TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS manuscripts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    keywords VARCHAR(255) DEFAULT '',
    abstract TEXT DEFAULT '',
    content LONGTEXT DEFAULT '', -- Fallback HTML content if OneDrive is offline
    status VARCHAR(50) DEFAULT 'Draft', -- Draft, Submitted, In Review, Editorial Board, Managing Editor, Editor-In-Chief, Approved, Published
    onedrive_file_id VARCHAR(255) DEFAULT '',
    onedrive_url TEXT DEFAULT '',
    author_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    manuscript_id INT NOT NULL,
    reviewer_id INT NOT NULL,
    comments TEXT DEFAULT '',
    recommendation VARCHAR(50) DEFAULT '', -- Accept, Revisions Required, Reject
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (manuscript_id) REFERENCES manuscripts(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pipeline_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    manuscript_id INT NOT NULL,
    stage VARCHAR(50) NOT NULL, -- Editorial Board, Managing Editor, Editor-In-Chief
    action VARCHAR(50) NOT NULL, -- Forward, Return, Approve, Reject
    remarks TEXT DEFAULT '',
    actor_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (manuscript_id) REFERENCES manuscripts(id) ON DELETE CASCADE,
    FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
