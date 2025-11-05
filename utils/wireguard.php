<?php
// WireGuard 유틸리티 함수들

class WireGuard {

    // WireGuard 키 쌍 생성
    public static function generateKeys() {
        try {
            // Private key 생성
            $privateKey = trim(shell_exec('wg genkey 2>/dev/null'));

            if (empty($privateKey)) {
                throw new Exception('Failed to generate private key');
            }

            // Public key 생성
            $publicKey = trim(shell_exec("echo '{$privateKey}' | wg pubkey 2>/dev/null"));

            if (empty($publicKey)) {
                throw new Exception('Failed to generate public key');
            }

            return [
                'privateKey' => $privateKey,
                'publicKey' => $publicKey
            ];
        } catch (Exception $e) {
            error_log('Error generating WireGuard keys: ' . $e->getMessage());
            throw $e;
        }
    }

    // 서버 공개키 가져오기
    public static function getServerPublicKey($interface = 'wg0') {
        try {
            $publicKey = trim(shell_exec("wg show {$interface} public-key 2>/dev/null"));

            if (empty($publicKey)) {
                throw new Exception('Failed to get server public key');
            }

            return $publicKey;
        } catch (Exception $e) {
            error_log('Error getting server public key: ' . $e->getMessage());
            throw $e;
        }
    }

    // WireGuard peer 추가
    public static function addPeer($publicKey, $internalIp, $interface = 'wg0') {
        try {
            $command = "wg set {$interface} peer {$publicKey} allowed-ips {$internalIp}/32 2>&1";
            $output = shell_exec($command);

            return [
                'success' => true,
                'message' => 'Peer added successfully'
            ];
        } catch (Exception $e) {
            error_log('Error adding WireGuard peer: ' . $e->getMessage());
            throw $e;
        }
    }

    // WireGuard peer 제거
    public static function removePeer($publicKey, $interface = 'wg0') {
        try {
            $command = "wg set {$interface} peer {$publicKey} remove 2>&1";
            $output = shell_exec($command);

            return [
                'success' => true,
                'message' => 'Peer removed successfully'
            ];
        } catch (Exception $e) {
            error_log('Error removing WireGuard peer: ' . $e->getMessage());
            throw $e;
        }
    }

    // WireGuard 설정 저장
    public static function saveConfig($interface = 'wg0') {
        try {
            $command = "wg-quick save {$interface} 2>&1";
            $output = shell_exec($command);

            return [
                'success' => true,
                'message' => 'Config saved successfully'
            ];
        } catch (Exception $e) {
            error_log('Error saving WireGuard config: ' . $e->getMessage());
            throw $e;
        }
    }

    // WireGuard 인터페이스 상태 확인
    public static function getInterfaceStatus($interface = 'wg0') {
        try {
            $output = shell_exec("wg show {$interface} 2>/dev/null");

            if (empty($output)) {
                return [
                    'success' => false,
                    'message' => 'Interface not found or not running'
                ];
            }

            return [
                'success' => true,
                'output' => $output
            ];
        } catch (Exception $e) {
            error_log('Error getting interface status: ' . $e->getMessage());
            throw $e;
        }
    }
}
?>
