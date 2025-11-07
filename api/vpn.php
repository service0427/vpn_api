<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/wireguard.php';

class VpnApi {
    private $db;
    private $conn;

    public function __construct() {
        $this->db = new Database();
        $this->conn = $this->db->getConnection();
    }

    // 1. VPN 할당받기 (public_ip 기반)
    public function allocate($public_ip = null) {
        try {
            $this->conn->beginTransaction();

            // public_ip 파라미터로 특정 서버의 키 할당
            if ($public_ip) {
                $query = "
                    SELECT
                        k.id,
                        k.internal_ip,
                        k.private_key,
                        k.public_key,
                        s.public_ip,
                        s.port,
                        s.server_pubkey
                    FROM vpn_keys k
                    JOIN vpn_servers s ON k.server_id = s.id
                    WHERE k.in_use = 0
                        AND s.is_active = 1
                        AND s.public_ip = ?
                        AND TIMESTAMPDIFF(SECOND, s.updated_at, NOW()) < 90
                    ORDER BY k.last_used_at ASC, k.use_count ASC
                    LIMIT 1
                    FOR UPDATE
                ";
                $stmt = $this->conn->prepare($query);
                $stmt->execute([$public_ip]);
            } else {
                // public_ip 없으면 활성화된 아무 서버에서나 할당
                $query = "
                    SELECT
                        k.id,
                        k.internal_ip,
                        k.private_key,
                        k.public_key,
                        s.public_ip,
                        s.port,
                        s.server_pubkey
                    FROM vpn_keys k
                    JOIN vpn_servers s ON k.server_id = s.id
                    WHERE k.in_use = 0
                        AND s.is_active = 1
                        AND TIMESTAMPDIFF(SECOND, s.updated_at, NOW()) < 90
                    ORDER BY k.last_used_at ASC, k.use_count ASC
                    LIMIT 1
                    FOR UPDATE
                ";
                $stmt = $this->conn->prepare($query);
                $stmt->execute();
            }

            $vpnKey = $stmt->fetch();

            if (!$vpnKey) {
                $this->conn->rollBack();
                return [
                    'success' => false,
                    'error' => $public_ip
                        ? "No available VPN keys for server {$public_ip}"
                        : 'No available VPN keys'
                ];
            }

            // 클라이언트 IP 가져오기
            $clientIp = $this->getClientIp();

            // 키를 사용 중으로 표시
            $updateStmt = $this->conn->prepare("
                UPDATE vpn_keys
                SET
                    in_use = 1,
                    assigned_to = ?,
                    assigned_at = NOW(),
                    last_used_at = NOW(),
                    use_count = use_count + 1
                WHERE id = ?
            ");
            $updateStmt->execute([$clientIp, $vpnKey['id']]);

            // 사용 로그 기록
            $logStmt = $this->conn->prepare("
                INSERT INTO vpn_usage_logs
                (key_id, server_id, client_ip, connected_at, status)
                VALUES (?,
                    (SELECT server_id FROM vpn_keys WHERE id = ?),
                    ?, NOW(), 'connected')
            ");
            $logStmt->execute([$vpnKey['id'], $vpnKey['id'], $clientIp]);

            $this->conn->commit();

            // WireGuard 설정 생성
            $config = "[Interface]
PrivateKey = {$vpnKey['private_key']}
Address = {$vpnKey['internal_ip']}/24
DNS = 1.1.1.1, 8.8.8.8

[Peer]
PublicKey = {$vpnKey['server_pubkey']}
Endpoint = {$vpnKey['public_ip']}:{$vpnKey['port']}
AllowedIPs = 0.0.0.0/0
PersistentKeepalive = 25";

            error_log("✅ VPN allocated: {$vpnKey['internal_ip']} to {$clientIp}");

            return [
                'success' => true,
                'server_ip' => $vpnKey['public_ip'],
                'server_port' => (int)$vpnKey['port'],
                'server_pubkey' => $vpnKey['server_pubkey'],
                'private_key' => $vpnKey['private_key'],
                'public_key' => $vpnKey['public_key'],
                'internal_ip' => $vpnKey['internal_ip'],
                'config' => $config
            ];

        } catch (Exception $e) {
            $this->conn->rollBack();
            error_log('Error allocating VPN: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to allocate VPN: ' . $e->getMessage()
            ];
        }
    }

    // 2. VPN 키 반납하기
    public function release($public_key) {
        if (!$public_key) {
            return [
                'success' => false,
                'error' => 'public_key is required'
            ];
        }

        try {
            // 키 정보 조회
            $stmt = $this->conn->prepare("
                SELECT id, internal_ip, assigned_to
                FROM vpn_keys
                WHERE public_key = ? AND in_use = 1
            ");
            $stmt->execute([$public_key]);
            $vpnKey = $stmt->fetch();

            if (!$vpnKey) {
                return [
                    'success' => false,
                    'error' => 'Key not found or not in use'
                ];
            }

            // 키 반납
            $updateStmt = $this->conn->prepare("
                UPDATE vpn_keys
                SET
                    in_use = 0,
                    assigned_to = NULL,
                    released_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([$vpnKey['id']]);

            // 사용 로그 업데이트
            $logStmt = $this->conn->prepare("
                UPDATE vpn_usage_logs
                SET
                    disconnected_at = NOW(),
                    status = 'disconnected',
                    duration_seconds = TIMESTAMPDIFF(SECOND, connected_at, NOW())
                WHERE key_id = ?
                    AND status = 'connected'
                ORDER BY connected_at DESC
                LIMIT 1
            ");
            $logStmt->execute([$vpnKey['id']]);

            error_log("✅ VPN released: {$vpnKey['internal_ip']} from {$vpnKey['assigned_to']}");

            return [
                'success' => true,
                'message' => 'VPN key released successfully'
            ];

        } catch (Exception $e) {
            error_log('Error releasing VPN: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to release VPN'
            ];
        }
    }

    // 2-1. 모든 VPN 키 반납하기
    public function releaseAll($public_ip = null) {
        try {
            // 반납할 키 조회
            if ($public_ip) {
                $selectStmt = $this->conn->prepare("
                    SELECT k.id, k.internal_ip, k.assigned_to
                    FROM vpn_keys k
                    JOIN vpn_servers s ON k.server_id = s.id
                    WHERE k.in_use = 1 AND s.public_ip = ?
                ");
                $selectStmt->execute([$public_ip]);
            } else {
                $selectStmt = $this->conn->prepare("
                    SELECT id, internal_ip, assigned_to
                    FROM vpn_keys
                    WHERE in_use = 1
                ");
                $selectStmt->execute();
            }

            $keys = $selectStmt->fetchAll();
            $count = count($keys);

            if ($count === 0) {
                return [
                    'success' => true,
                    'message' => 'No keys to release',
                    'released' => 0
                ];
            }

            // 모든 키 반납
            if ($public_ip) {
                $updateStmt = $this->conn->prepare("
                    UPDATE vpn_keys k
                    JOIN vpn_servers s ON k.server_id = s.id
                    SET
                        k.in_use = 0,
                        k.assigned_to = NULL,
                        k.released_at = NOW()
                    WHERE k.in_use = 1 AND s.public_ip = ?
                ");
                $updateStmt->execute([$public_ip]);
            } else {
                $updateStmt = $this->conn->prepare("
                    UPDATE vpn_keys
                    SET
                        in_use = 0,
                        assigned_to = NULL,
                        released_at = NOW()
                    WHERE in_use = 1
                ");
                $updateStmt->execute();
            }

            // 모든 사용 로그 업데이트
            $keyIds = array_column($keys, 'id');
            if (!empty($keyIds)) {
                $placeholders = str_repeat('?,', count($keyIds) - 1) . '?';
                $logStmt = $this->conn->prepare("
                    UPDATE vpn_usage_logs
                    SET
                        disconnected_at = NOW(),
                        status = 'disconnected',
                        duration_seconds = TIMESTAMPDIFF(SECOND, connected_at, NOW())
                    WHERE key_id IN ($placeholders)
                        AND status = 'connected'
                ");
                $logStmt->execute($keyIds);
            }

            error_log("✅ Released all VPN keys: {$count} keys" . ($public_ip ? " for server {$public_ip}" : ""));

            return [
                'success' => true,
                'message' => 'All VPN keys released successfully',
                'released' => $count,
                'keys' => array_map(function($key) {
                    return [
                        'internal_ip' => $key['internal_ip'],
                        'assigned_to' => $key['assigned_to']
                    ];
                }, $keys)
            ];

        } catch (Exception $e) {
            error_log('Error releasing all VPN keys: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to release all VPN keys'
            ];
        }
    }

    // 3. VPN 키 상태 조회
    public function status($public_ip = null) {
        try {
            // 전체 통계
            if ($public_ip) {
                $statsStmt = $this->conn->prepare("
                    SELECT
                        COUNT(*) as total_keys,
                        SUM(CASE WHEN in_use = 1 THEN 1 ELSE 0 END) as keys_in_use,
                        SUM(CASE WHEN in_use = 0 THEN 1 ELSE 0 END) as keys_available
                    FROM vpn_keys k
                    JOIN vpn_servers s ON k.server_id = s.id
                    WHERE s.public_ip = ?
                ");
                $statsStmt->execute([$public_ip]);
            } else {
                $statsStmt = $this->conn->prepare("
                    SELECT
                        COUNT(*) as total_keys,
                        SUM(CASE WHEN in_use = 1 THEN 1 ELSE 0 END) as keys_in_use,
                        SUM(CASE WHEN in_use = 0 THEN 1 ELSE 0 END) as keys_available
                    FROM vpn_keys k
                    JOIN vpn_servers s ON k.server_id = s.id
                    WHERE s.is_active = 1
                ");
                $statsStmt->execute();
            }

            $stats = $statsStmt->fetch();

            // 서버 목록 조회
            if ($public_ip) {
                $serverStmt = $this->conn->prepare("
                    SELECT
                        s.public_ip,
                        s.port,
                        COUNT(k.id) as total_keys,
                        SUM(CASE WHEN k.in_use = 1 THEN 1 ELSE 0 END) as keys_in_use,
                        SUM(CASE WHEN k.in_use = 0 THEN 1 ELSE 0 END) as keys_available
                    FROM vpn_servers s
                    LEFT JOIN vpn_keys k ON k.server_id = s.id
                    WHERE s.public_ip = ?
                        AND s.server_pubkey IS NOT NULL
                        AND s.server_pubkey != ''
                        AND s.is_active = 1
                    GROUP BY s.id, s.public_ip, s.port
                ");
                $serverStmt->execute([$public_ip]);
            } else {
                $serverStmt = $this->conn->prepare("
                    SELECT
                        s.public_ip,
                        s.port,
                        COUNT(k.id) as total_keys,
                        SUM(CASE WHEN k.in_use = 1 THEN 1 ELSE 0 END) as keys_in_use,
                        SUM(CASE WHEN k.in_use = 0 THEN 1 ELSE 0 END) as keys_available
                    FROM vpn_servers s
                    LEFT JOIN vpn_keys k ON k.server_id = s.id
                    WHERE s.server_pubkey IS NOT NULL
                        AND s.server_pubkey != ''
                        AND s.is_active = 1
                    GROUP BY s.id, s.public_ip, s.port
                    ORDER BY s.public_ip
                ");
                $serverStmt->execute();
            }

            $servers = $serverStmt->fetchAll();

            // 서버 목록 포맷팅
            $serverList = array_map(function($server) {
                return [
                    'endpoint' => $server['public_ip'] . ':' . $server['port'],
                    'total_keys' => (int)$server['total_keys'],
                    'keys_in_use' => (int)($server['keys_in_use'] ?? 0),
                    'keys_available' => (int)($server['keys_available'] ?? 0)
                ];
            }, $servers);

            // 현재 사용 중인 키 목록
            if ($public_ip) {
                $activeStmt = $this->conn->prepare("
                    SELECT
                        k.internal_ip,
                        k.assigned_to,
                        k.assigned_at,
                        TIMESTAMPDIFF(SECOND, k.assigned_at, NOW()) as duration_seconds
                    FROM vpn_keys k
                    JOIN vpn_servers s ON k.server_id = s.id
                    WHERE k.in_use = 1 AND s.public_ip = ?
                    ORDER BY k.assigned_at DESC
                ");
                $activeStmt->execute([$public_ip]);
            } else {
                $activeStmt = $this->conn->prepare("
                    SELECT
                        k.internal_ip,
                        k.assigned_to,
                        k.assigned_at,
                        s.public_ip,
                        TIMESTAMPDIFF(SECOND, k.assigned_at, NOW()) as duration_seconds
                    FROM vpn_keys k
                    JOIN vpn_servers s ON k.server_id = s.id
                    WHERE k.in_use = 1
                    ORDER BY k.assigned_at DESC
                ");
                $activeStmt->execute();
            }

            $activeKeys = $activeStmt->fetchAll();

            return [
                'success' => true,
                'statistics' => [
                    'total_keys' => (int)$stats['total_keys'],
                    'keys_in_use' => (int)$stats['keys_in_use'],
                    'keys_available' => (int)$stats['keys_available']
                ],
                'server_list' => $serverList,
                'active_connections' => $activeKeys
            ];

        } catch (Exception $e) {
            error_log('Error getting status: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to get status'
            ];
        }
    }

    // 4. VPN 서버 목록 조회 (활성화된 서버의 public_ip만)
    public function listIPs() {
        try {
            // server_pubkey가 있고 is_active가 true인 서버들의 public_ip 목록
            $stmt = $this->conn->prepare("
                SELECT public_ip
                FROM vpn_servers
                WHERE server_pubkey IS NOT NULL
                    AND server_pubkey != ''
                    AND is_active = 1
                ORDER BY public_ip
            ");
            $stmt->execute();

            $servers = $stmt->fetchAll();

            return [
                'success' => true,
                'servers' => array_map(function($row) {
                    return $row['public_ip'];
                }, $servers)
            ];

        } catch (Exception $e) {
            error_log('Error listing servers: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to list servers'
            ];
        }
    }

    // 5. VPN 서버 등록
    public function registerServer($data) {
        if (!isset($data['public_ip']) || !isset($data['port']) || !isset($data['server_pubkey'])) {
            return [
                'success' => false,
                'error' => 'public_ip, port, and server_pubkey are required'
            ];
        }

        try {
            $public_ip = $data['public_ip'];
            $port = (int)$data['port'];
            $server_pubkey = $data['server_pubkey'];
            $memo = $data['memo'] ?? null;

            // 중복 체크
            $checkStmt = $this->conn->prepare("
                SELECT id FROM vpn_servers
                WHERE public_ip = ? AND port = ?
            ");
            $checkStmt->execute([$public_ip, $port]);
            $existing = $checkStmt->fetch();

            if ($existing) {
                // 기존 서버 업데이트
                $updateStmt = $this->conn->prepare("
                    UPDATE vpn_servers
                    SET server_pubkey = ?, memo = ?, is_active = 1, updated_at = NOW()
                    WHERE id = ?
                ");
                $updateStmt->execute([$server_pubkey, $memo, $existing['id']]);

                error_log("✅ VPN server updated: {$public_ip}:{$port}");

                return [
                    'success' => true,
                    'server_id' => $existing['id'],
                    'action' => 'updated',
                    'message' => 'Server updated successfully'
                ];
            } else {
                // 새 서버 등록
                $insertStmt = $this->conn->prepare("
                    INSERT INTO vpn_servers (public_ip, port, server_pubkey, memo, is_active)
                    VALUES (?, ?, ?, ?, 1)
                ");
                $insertStmt->execute([$public_ip, $port, $server_pubkey, $memo]);
                $serverId = $this->conn->lastInsertId();

                error_log("✅ VPN server registered: {$public_ip}:{$port}");

                return [
                    'success' => true,
                    'server_id' => $serverId,
                    'action' => 'created',
                    'message' => 'Server registered successfully'
                ];
            }

        } catch (Exception $e) {
            error_log('Error registering server: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to register server: ' . $e->getMessage()
            ];
        }
    }

    // 6. VPN 키 일괄 등록
    public function registerKeysBulk($data) {
        if (!isset($data['public_ip']) || !isset($data['port']) || !isset($data['keys'])) {
            return [
                'success' => false,
                'error' => 'public_ip, port, and keys are required'
            ];
        }

        try {
            $public_ip = $data['public_ip'];
            $port = (int)$data['port'];
            $keys = $data['keys'];

            if (!is_array($keys) || empty($keys)) {
                return [
                    'success' => false,
                    'error' => 'keys must be a non-empty array'
                ];
            }

            // 서버 ID 찾기
            $serverStmt = $this->conn->prepare("
                SELECT id FROM vpn_servers
                WHERE public_ip = ? AND port = ?
            ");
            $serverStmt->execute([$public_ip, $port]);
            $server = $serverStmt->fetch();

            if (!$server) {
                return [
                    'success' => false,
                    'error' => 'Server not found. Please register the server first.'
                ];
            }

            $serverId = $server['id'];

            // 기존 키 삭제 (옵션)
            $deleteStmt = $this->conn->prepare("
                DELETE FROM vpn_keys WHERE server_id = ?
            ");
            $deleteStmt->execute([$serverId]);

            // 새 키 일괄 삽입
            $insertStmt = $this->conn->prepare("
                INSERT INTO vpn_keys (server_id, internal_ip, private_key, public_key)
                VALUES (?, ?, ?, ?)
            ");

            $successCount = 0;
            $errors = [];

            foreach ($keys as $index => $key) {
                if (!isset($key['internal_ip']) || !isset($key['private_key']) || !isset($key['public_key'])) {
                    $errors[] = [
                        'index' => $index,
                        'error' => 'Missing required fields (internal_ip, private_key, public_key)'
                    ];
                    continue;
                }

                try {
                    $insertStmt->execute([
                        $serverId,
                        $key['internal_ip'],
                        $key['private_key'],
                        $key['public_key']
                    ]);
                    $successCount++;
                } catch (Exception $e) {
                    $errors[] = [
                        'index' => $index,
                        'internal_ip' => $key['internal_ip'],
                        'error' => $e->getMessage()
                    ];
                }
            }

            error_log("✅ VPN keys registered: {$successCount} keys for server {$public_ip}:{$port}");

            return [
                'success' => true,
                'message' => "{$successCount} keys registered successfully",
                'server_id' => $serverId,
                'total' => count($keys),
                'registered' => $successCount,
                'errors' => $errors
            ];

        } catch (Exception $e) {
            error_log('Error registering keys: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to register keys: ' . $e->getMessage()
            ];
        }
    }

    // 7. 오래된 연결 정리
    public function cleanup($minutes = 10) {
        try {
            $stmt = $this->conn->prepare("
                UPDATE vpn_keys
                SET
                    in_use = 0,
                    assigned_to = NULL,
                    released_at = NOW()
                WHERE in_use = 1
                    AND TIMESTAMPDIFF(MINUTE, assigned_at, NOW()) > ?
            ");
            $stmt->execute([$minutes]);

            $cleaned = $stmt->rowCount();

            error_log("✅ Cleaned up {$cleaned} stale connections");

            return [
                'success' => true,
                'cleaned' => $cleaned
            ];

        } catch (Exception $e) {
            error_log('Error cleaning up: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to cleanup'
            ];
        }
    }

    // 8. VPN 서버 완전 삭제 (재설치 시 사용)
    public function deleteServer($public_ip) {
        if (!$public_ip) {
            return [
                'success' => false,
                'error' => 'public_ip is required'
            ];
        }

        try {
            // 서버 정보 조회
            $serverStmt = $this->conn->prepare("
                SELECT id, public_ip, port,
                       (SELECT COUNT(*) FROM vpn_keys WHERE server_id = vpn_servers.id) as key_count,
                       (SELECT COUNT(*) FROM vpn_keys WHERE server_id = vpn_servers.id AND in_use = 1) as keys_in_use
                FROM vpn_servers
                WHERE public_ip = ?
            ");
            $serverStmt->execute([$public_ip]);
            $server = $serverStmt->fetch();

            if (!$server) {
                return [
                    'success' => false,
                    'error' => 'Server not found'
                ];
            }

            // 서버 삭제 (CASCADE로 키와 로그도 자동 삭제됨)
            $deleteStmt = $this->conn->prepare("
                DELETE FROM vpn_servers WHERE id = ?
            ");
            $deleteStmt->execute([$server['id']]);

            error_log("🗑️  VPN server deleted: {$public_ip} (Keys: {$server['key_count']}, In use: {$server['keys_in_use']})");

            return [
                'success' => true,
                'message' => 'Server and all related data deleted successfully',
                'deleted' => [
                    'server_ip' => $server['public_ip'],
                    'server_port' => (int)$server['port'],
                    'keys_deleted' => (int)$server['key_count'],
                    'keys_were_in_use' => (int)$server['keys_in_use']
                ]
            ];

        } catch (Exception $e) {
            error_log('Error deleting server: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to delete server: ' . $e->getMessage()
            ];
        }
    }

    // 9. VPN 서버 헬스체크 (하트비트)
    public function heartbeat($public_ip, $interface = null, $rx_bytes = null, $tx_bytes = null) {
        if (!$public_ip) {
            return [
                'success' => false,
                'error' => 'public_ip is required'
            ];
        }

        try {
            // 서버의 updated_at 업데이트
            $stmt = $this->conn->prepare("
                UPDATE vpn_servers
                SET updated_at = NOW()
                WHERE public_ip = ? AND is_active = 1
            ");
            $stmt->execute([$public_ip]);

            $updated = $stmt->rowCount();

            if ($updated === 0) {
                return [
                    'success' => false,
                    'error' => 'Server not found or not active'
                ];
            }

            // 트래픽 데이터가 있으면 저장
            if ($interface && $rx_bytes !== null && $tx_bytes !== null) {
                $this->saveTrafficData($public_ip, $interface, $rx_bytes, $tx_bytes);
            }

            return [
                'success' => true,
                'message' => 'Heartbeat received',
                'server_ip' => $public_ip
            ];

        } catch (Exception $e) {
            error_log('Error processing heartbeat: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to process heartbeat'
            ];
        }
    }

    // 트래픽 데이터 저장 (일별 집계)
    private function saveTrafficData($server_ip, $interface, $rx_bytes, $tx_bytes) {
        try {
            $today = date('Y-m-d');

            // 오늘 데이터 조회
            $checkStmt = $this->conn->prepare("
                SELECT id FROM vpn_traffic_daily
                WHERE server_ip = ? AND interface = ? AND date = ?
            ");
            $checkStmt->execute([$server_ip, $interface, $today]);
            $existing = $checkStmt->fetch();

            if ($existing) {
                // 기존 데이터 업데이트 (current 값만)
                $updateStmt = $this->conn->prepare("
                    UPDATE vpn_traffic_daily
                    SET current_rx_bytes = ?,
                        current_tx_bytes = ?
                    WHERE id = ?
                ");
                $updateStmt->execute([$rx_bytes, $tx_bytes, $existing['id']]);
            } else {
                // 새 데이터 삽입 (init과 current에 동일한 값)
                $insertStmt = $this->conn->prepare("
                    INSERT INTO vpn_traffic_daily
                    (server_ip, interface, date, init_rx_bytes, current_rx_bytes, init_tx_bytes, current_tx_bytes)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $insertStmt->execute([$server_ip, $interface, $today, $rx_bytes, $rx_bytes, $tx_bytes, $tx_bytes]);
            }

        } catch (Exception $e) {
            error_log('Error saving traffic data: ' . $e->getMessage());
            // 트래픽 저장 실패해도 헬스체크는 성공으로 처리
        }
    }

    // 클라이언트 IP 가져오기
    private function getClientIp() {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = $_SERVER['HTTP_X_REAL_IP'];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        } else {
            $ip = 'unknown';
        }
        return trim($ip);
    }
}
?>
