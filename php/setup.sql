-- Project TLS Database Setup Script

CREATE DATABASE IF NOT EXISTS project_tls CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE project_tls;

-- Users table for tracking conversions
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(128) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_active TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_session_id (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conversions table for tracking all conversion history
CREATE TABLE IF NOT EXISTS conversions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    conversion_type ENUM('background_remove', 'pdf_convert') NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    original_path VARCHAR(500) NOT NULL,
    output_path VARCHAR(500) NOT NULL,
    file_size INT NOT NULL,
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_conversion_type (conversion_type),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conversion history view
CREATE OR REPLACE VIEW conversion_history AS
SELECT 
    c.id,
    c.conversion_type,
    c.original_filename,
    c.file_size,
    c.status,
    c.created_at,
    c.completed_at,
    u.session_id
FROM conversions c
JOIN users u ON c.user_id = u.id
ORDER BY c.created_at DESC;
