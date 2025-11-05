const express = require('express');
const mysql = require('mysql2/promise');
const cors = require('cors');
const morgan = require('morgan');
const dotenv = require('dotenv');
const { exec } = require('child_process');
const util = require('util');

const execPromise = util.promisify(exec);

// 환경변수 로드
dotenv.config();

const app = express();

// 미들웨어
app.use(cors());
app.use(express.json());
app.use(morgan('combined'));

// DB 연결 풀
const pool = mysql.createPool({
    host: process.env.DB_HOST,
    port: process.env.DB_PORT,
    user: process.env.DB_USER,
    password: process.env.DB_PASSWORD,
    database: process.env.DB_NAME,
    waitForConnections: true,
    connectionLimit: 10,
    queueLimit: 0
});

// =============================================
// API 엔드포인트
// =============================================

// 헬스체크
app.get('/health', (req, res) => {
    res.json({
        status: 'ok',
        timestamp: new Date().toISOString()
    });
});

// =============================================
// VPN 키 관리 API
// =============================================

// 1. 사용 가능한 VPN 할당받기 (특정 IP 지정 가능)
app.get('/api/vpn/allocate', async (req, res) => {
    const { ip } = req.query;  // 특정 IP 요청 (예: ?ip=10.8.0.25)
    const connection = await pool.getConnection();

    try {
        await connection.beginTransaction();

        let query;
        let params;

        if (ip) {
            // 특정 IP 요청된 경우
            query = `
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
                WHERE k.internal_ip = ?
                    AND k.in_use = 0
                    AND s.is_active = 1
                    AND s.public_ip = ?
                LIMIT 1
                FOR UPDATE
            `;
            params = [ip, process.env.VPN_SERVER_IP];
        } else {
            // IP 지정 없는 경우 (기존 로직)
            query = `
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
                ORDER BY k.last_used_at ASC, k.use_count ASC
                LIMIT 1
                FOR UPDATE
            `;
            params = [process.env.VPN_SERVER_IP];
        }

        const [keys] = await connection.execute(query, params);

        if (keys.length === 0) {
            await connection.rollback();
            const errorMsg = ip
                ? `IP ${ip} is not available or already in use`
                : 'No available VPN keys';
            return res.status(404).json({
                success: false,
                error: errorMsg
            });
        }

        const vpnKey = keys[0];
        const clientIp = req.ip || req.connection.remoteAddress;

        // 키를 사용 중으로 표시
        await connection.execute(`
            UPDATE vpn_keys
            SET
                in_use = 1,
                assigned_to = ?,
                assigned_at = NOW(),
                use_count = use_count + 1
            WHERE id = ?
        `, [clientIp, vpnKey.id]);

        // 사용 로그 기록
        await connection.execute(`
            INSERT INTO vpn_usage_logs
            (key_id, server_id, client_ip, connected_at, status)
            VALUES (?,
                (SELECT server_id FROM vpn_keys WHERE id = ?),
                ?, NOW(), 'connected')
        `, [vpnKey.id, vpnKey.id, clientIp]);

        await connection.commit();

        // WireGuard 설정 생성
        const config = `[Interface]
PrivateKey = ${vpnKey.private_key}
Address = ${vpnKey.internal_ip}/24
DNS = 1.1.1.1, 8.8.8.8

[Peer]
PublicKey = ${vpnKey.server_pubkey}
Endpoint = ${vpnKey.public_ip}:${vpnKey.port}
AllowedIPs = 0.0.0.0/0
PersistentKeepalive = 25`;

        res.json({
            success: true,
            server_ip: vpnKey.public_ip,
            server_port: vpnKey.port,
            server_pubkey: vpnKey.server_pubkey,
            private_key: vpnKey.private_key,
            public_key: vpnKey.public_key,
            internal_ip: vpnKey.internal_ip,
            config: config
        });

        console.log(`✅ VPN allocated: ${vpnKey.internal_ip} to ${clientIp}`);

    } catch (error) {
        await connection.rollback();
        console.error('Error allocating VPN:', error);
        res.status(500).json({
            success: false,
            error: 'Failed to allocate VPN'
        });
    } finally {
        connection.release();
    }
});

// 2. VPN 키 반납하기
app.post('/api/vpn/release', async (req, res) => {
    const { public_key } = req.body;

    if (!public_key) {
        return res.status(400).json({
            success: false,
            error: 'public_key is required'
        });
    }

    try {
        // 키 정보 조회
        const [keys] = await pool.execute(`
            SELECT id, internal_ip, assigned_to
            FROM vpn_keys
            WHERE public_key = ? AND in_use = 1
        `, [public_key]);

        if (keys.length === 0) {
            return res.status(404).json({
                success: false,
                error: 'Key not found or not in use'
            });
        }

        const vpnKey = keys[0];

        // 키 반납
        await pool.execute(`
            UPDATE vpn_keys
            SET
                in_use = 0,
                assigned_to = NULL,
                released_at = NOW()
            WHERE id = ?
        `, [vpnKey.id]);

        // 사용 로그 업데이트
        await pool.execute(`
            UPDATE vpn_usage_logs
            SET
                disconnected_at = NOW(),
                status = 'disconnected',
                duration_seconds = TIMESTAMPDIFF(SECOND, connected_at, NOW())
            WHERE key_id = ?
                AND status = 'connected'
            ORDER BY connected_at DESC
            LIMIT 1
        `, [vpnKey.id]);

        res.json({
            success: true,
            message: 'VPN key released successfully'
        });

        console.log(`✅ VPN released: ${vpnKey.internal_ip} from ${vpnKey.assigned_to}`);

    } catch (error) {
        console.error('Error releasing VPN:', error);
        res.status(500).json({
            success: false,
            error: 'Failed to release VPN'
        });
    }
});

// 3. VPN 키 상태 조회
app.get('/api/vpn/status', async (req, res) => {
    try {
        // 전체 통계
        const [stats] = await pool.execute(`
            SELECT
                COUNT(*) as total_keys,
                SUM(CASE WHEN in_use = 1 THEN 1 ELSE 0 END) as keys_in_use,
                SUM(CASE WHEN in_use = 0 THEN 1 ELSE 0 END) as keys_available
            FROM vpn_keys k
            JOIN vpn_servers s ON k.server_id = s.id
            WHERE s.public_ip = ?
        `, [process.env.VPN_SERVER_IP]);

        // 현재 사용 중인 키 목록
        const [activeKeys] = await pool.execute(`
            SELECT
                k.internal_ip,
                k.assigned_to,
                k.assigned_at,
                TIMESTAMPDIFF(SECOND, k.assigned_at, NOW()) as duration_seconds
            FROM vpn_keys k
            JOIN vpn_servers s ON k.server_id = s.id
            WHERE k.in_use = 1 AND s.public_ip = ?
            ORDER BY k.assigned_at DESC
        `, [process.env.VPN_SERVER_IP]);

        res.json({
            success: true,
            statistics: stats[0],
            active_connections: activeKeys
        });

    } catch (error) {
        console.error('Error getting status:', error);
        res.status(500).json({
            success: false,
            error: 'Failed to get status'
        });
    }
});

// 4. VPN 사용 가능한 IP 목록 조회 (간단한 목록)
app.get('/api/vpn/list', async (req, res) => {
    try {
        // 사용 가능한 IP만 조회
        const [availableIPs] = await pool.execute(`
            SELECT
                k.internal_ip as ip
            FROM vpn_keys k
            JOIN vpn_servers s ON k.server_id = s.id
            WHERE k.in_use = 0
                AND s.is_active = 1
                AND s.public_ip = ?
            ORDER BY INET_ATON(k.internal_ip)
        `, [process.env.VPN_SERVER_IP]);

        // 사용 중인 IP 조회
        const [inUseIPs] = await pool.execute(`
            SELECT
                k.internal_ip as ip
            FROM vpn_keys k
            JOIN vpn_servers s ON k.server_id = s.id
            WHERE k.in_use = 1
                AND s.public_ip = ?
            ORDER BY INET_ATON(k.internal_ip)
        `, [process.env.VPN_SERVER_IP]);

        res.json({
            success: true,
            server: process.env.VPN_SERVER_IP,
            port: parseInt(process.env.VPN_SERVER_PORT),
            available: availableIPs.map(row => row.ip),
            in_use: inUseIPs.map(row => row.ip)
        });

    } catch (error) {
        console.error('Error listing IPs:', error);
        res.status(500).json({
            success: false,
            error: 'Failed to list IPs'
        });
    }
});

// 5. VPN 키 풀 초기화 (키 생성)
app.post('/api/vpn/init-keys', async (req, res) => {
    const { start_ip = 10, end_ip = 60 } = req.body;

    try {
        // 서버 정보 조회
        const [servers] = await pool.execute(`
            SELECT id, server_pubkey
            FROM vpn_servers
            WHERE public_ip = ? AND port = ?
        `, [process.env.VPN_SERVER_IP, process.env.VPN_SERVER_PORT]);

        if (servers.length === 0) {
            // 서버 등록
            const serverPubkey = await getServerPublicKey();
            const [result] = await pool.execute(`
                INSERT INTO vpn_servers
                (public_ip, port, server_pubkey, memo, is_active)
                VALUES (?, ?, ?, ?, ?)
            `, [
                process.env.VPN_SERVER_IP,
                process.env.VPN_SERVER_PORT,
                serverPubkey,
                'VPN Key Pool',
                1
            ]);

            var serverId = result.insertId;
        } else {
            var serverId = servers[0].id;
        }

        let created = 0;
        const errors = [];

        for (let i = start_ip; i <= end_ip; i++) {
            const internalIp = `10.8.0.${i}`;

            try {
                // 키 생성
                const { privateKey, publicKey } = await generateWireGuardKeys();

                // DB에 저장
                await pool.execute(`
                    INSERT INTO vpn_keys
                    (server_id, internal_ip, private_key, public_key)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                    private_key = VALUES(private_key)
                `, [serverId, internalIp, privateKey, publicKey]);

                // WireGuard에 peer 추가
                await addWireGuardPeer(publicKey, internalIp);

                created++;
                console.log(`✅ Created key for ${internalIp}`);

            } catch (error) {
                errors.push({ ip: internalIp, error: error.message });
                console.error(`❌ Failed to create key for ${internalIp}:`, error);
            }
        }

        // WireGuard 설정 저장
        await execPromise('wg-quick save wg0');

        res.json({
            success: true,
            created: created,
            total: end_ip - start_ip + 1,
            errors: errors
        });

    } catch (error) {
        console.error('Error initializing keys:', error);
        res.status(500).json({
            success: false,
            error: 'Failed to initialize keys'
        });
    }
});

// 5. 오래된 연결 정리 (1시간 이상)
app.post('/api/vpn/cleanup', async (req, res) => {
    const { hours = 1 } = req.body;

    try {
        const [result] = await pool.execute(`
            UPDATE vpn_keys
            SET
                in_use = 0,
                assigned_to = NULL,
                released_at = NOW()
            WHERE in_use = 1
                AND TIMESTAMPDIFF(HOUR, assigned_at, NOW()) > ?
        `, [hours]);

        res.json({
            success: true,
            cleaned: result.affectedRows
        });

        console.log(`✅ Cleaned up ${result.affectedRows} stale connections`);

    } catch (error) {
        console.error('Error cleaning up:', error);
        res.status(500).json({
            success: false,
            error: 'Failed to cleanup'
        });
    }
});

// =============================================
// 유틸리티 함수
// =============================================

// WireGuard 키 생성
async function generateWireGuardKeys() {
    const { stdout: privateKey } = await execPromise('wg genkey');
    const { stdout: publicKey } = await execPromise(`echo "${privateKey.trim()}" | wg pubkey`);

    return {
        privateKey: privateKey.trim(),
        publicKey: publicKey.trim()
    };
}

// 서버 공개키 가져오기
async function getServerPublicKey() {
    const { stdout } = await execPromise('wg show wg0 public-key');
    return stdout.trim();
}

// WireGuard peer 추가
async function addWireGuardPeer(publicKey, internalIp) {
    await execPromise(`wg set wg0 peer ${publicKey} allowed-ips ${internalIp}/32`);
}

// =============================================
// 서버 시작
// =============================================

const PORT = process.env.API_PORT || 3000;
const HOST = process.env.API_HOST || '0.0.0.0';

app.listen(PORT, HOST, () => {
    console.log('═══════════════════════════════════════');
    console.log('      VPN Key Pool API Server');
    console.log('═══════════════════════════════════════');
    console.log(`✅ Server running on http://${HOST}:${PORT}`);
    console.log(`📊 Database: ${process.env.DB_HOST}/${process.env.DB_NAME}`);
    console.log(`🔐 VPN Server: ${process.env.VPN_SERVER_IP}:${process.env.VPN_SERVER_PORT}`);
    console.log('═══════════════════════════════════════');
    console.log('');
    console.log('API Endpoints:');
    console.log('  GET  /api/vpn/allocate  - 키 할당받기');
    console.log('  POST /api/vpn/release   - 키 반납하기');
    console.log('  GET  /api/vpn/status    - 상태 조회');
    console.log('  GET  /api/vpn/list      - 전체 키 목록');
    console.log('  POST /api/vpn/init-keys - 키풀 초기화');
    console.log('  POST /api/vpn/cleanup   - 오래된 연결 정리');
    console.log('');
});