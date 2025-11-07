-- VPN 서버 테이블
CREATE TABLE IF NOT EXISTS vpn_servers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_ip VARCHAR(45) NOT NULL,
    port INT NOT NULL,
    server_pubkey TEXT NOT NULL,
    memo VARCHAR(255),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_server (public_ip, port)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- VPN 키 테이블
CREATE TABLE IF NOT EXISTS vpn_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    server_id INT NOT NULL,
    internal_ip VARCHAR(45) NOT NULL,
    private_key TEXT NOT NULL,
    public_key TEXT NOT NULL,
    in_use TINYINT(1) DEFAULT 0,
    assigned_to VARCHAR(255),
    assigned_at TIMESTAMP NULL,
    released_at TIMESTAMP NULL,
    last_used_at TIMESTAMP NULL,
    use_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_key (server_id, internal_ip),
    FOREIGN KEY (server_id) REFERENCES vpn_servers(id) ON DELETE CASCADE,
    INDEX idx_in_use (in_use),
    INDEX idx_internal_ip (internal_ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- VPN 사용 로그 테이블
CREATE TABLE IF NOT EXISTS vpn_usage_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    key_id INT NOT NULL,
    server_id INT NOT NULL,
    client_ip VARCHAR(255),
    connected_at TIMESTAMP NULL,
    disconnected_at TIMESTAMP NULL,
    duration_seconds INT,
    status VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (key_id) REFERENCES vpn_keys(id) ON DELETE CASCADE,
    FOREIGN KEY (server_id) REFERENCES vpn_servers(id) ON DELETE CASCADE,
    INDEX idx_key_id (key_id),
    INDEX idx_status (status),
    INDEX idx_connected_at (connected_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- VPN 트래픽 일별 집계 테이블
CREATE TABLE IF NOT EXISTS vpn_traffic_daily (
    id INT AUTO_INCREMENT PRIMARY KEY,
    server_ip VARCHAR(45) NOT NULL,
    interface VARCHAR(20) NOT NULL,
    date DATE NOT NULL,
    init_rx_bytes BIGINT DEFAULT 0,
    current_rx_bytes BIGINT DEFAULT 0,
    init_tx_bytes BIGINT DEFAULT 0,
    current_tx_bytes BIGINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_daily (server_ip, interface, date),
    INDEX idx_date (date),
    INDEX idx_server (server_ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
