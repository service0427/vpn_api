# VPN 서버 관리자 가이드

중앙 API에 VPN 서버를 등록하고 관리하는 가이드입니다.

---

## 개요

- **API URL**: http://220.121.120.83/vpn_api
- **대상**: VPN 서버를 설치하고 관리하는 시스템 관리자
- **소요 시간**: 약 5분

---

## 신규 VPN 서버 등록

### 사전 준비

1. WireGuard VPN 서버 설치 완료
2. 10개의 클라이언트 키 생성 (IP: 10.8.0.10 ~ 10.8.0.19)
3. `curl`, `jq` 설치

```bash
# CentOS/RHEL
yum install -y curl jq

# Ubuntu/Debian
apt install -y curl jq
```

---

### 방법 1: 자동 등록 (권장)

VPN 서버에서 다음 명령 한 줄만 실행:

```bash
curl -s http://220.121.120.83/vpn_api/one_line_register.sh | bash
```

**자동으로 처리되는 작업:**
1. 서버 IP, 포트, 공개키 감지
2. WireGuard 키 10개 수집/생성
3. JSON 파일 생성 (`/root/vpn_keys.json`)
4. API에 서버 등록
5. API에 키 일괄 등록

**예상 출력:**
```
=========================================
   VPN 서버 자동 등록 스크립트
=========================================

📡 서버 정보 수집 중...
  ✓ 서버 IP: 111.222.333.444
  ✓ 포트: 55555
  ✓ 서버 공개키: BHhFN2+AOR3AjJAx7Q+...

🔑 VPN 키 JSON 파일 생성 중...
  ✓ 10.8.0.10 ~ 10.8.0.19 키 생성 완료

📡 API에 서버 등록 중...
  ✓ 서버 등록 완료 (ID: 123)

🔑 VPN 키 일괄 등록 중...
  ✓ 키 10개 등록 완료

✅ 모든 작업 완료!
```

---

### 방법 2: 수동 등록

#### 1단계: 서버 정보 수집

```bash
# 서버 공개 IP
SERVER_IP=$(curl -s ifconfig.me)

# WireGuard 포트 (기본값: 55555)
VPN_PORT=55555

# 서버 공개키
SERVER_PUBKEY=$(wg show wg0 public-key)

echo "서버 IP: $SERVER_IP"
echo "포트: $VPN_PORT"
echo "공개키: $SERVER_PUBKEY"
```

#### 2단계: JSON 파일 생성

```bash
# 스크립트 다운로드
curl -o /root/generate_keys.sh http://220.121.120.83/vpn_api/generate_vpn_keys_json.sh
chmod +x /root/generate_keys.sh

# JSON 파일 생성 (10.8.0.10 ~ 10.8.0.19)
/root/generate_keys.sh $SERVER_IP $VPN_PORT 10 19 /root/vpn_keys.json

# 생성된 파일 확인
cat /root/vpn_keys.json | jq '.'
```

#### 3단계: API에 등록

```bash
# 등록 스크립트 다운로드
curl -o /root/register.sh http://220.121.120.83/vpn_api/register_vpn_server.sh
chmod +x /root/register.sh

# API에 등록
/root/register.sh /root/vpn_keys.json
```

---

## VPN 서버 재설치 시

서버를 재설치하거나 키를 다시 생성해야 할 때 기존 정보를 완전히 삭제합니다.

### 서버 삭제

```bash
# 서버 IP 확인
SERVER_IP=$(curl -s ifconfig.me)

# 서버 및 모든 키 삭제
curl "http://220.121.120.83/vpn_api/release/all?ip=$SERVER_IP&delete=true"
```

**응답 예시:**
```json
{
  "success": true,
  "message": "Server and all related data deleted successfully",
  "deleted": {
    "server_ip": "111.222.333.444",
    "server_port": 55555,
    "keys_deleted": 10,
    "keys_were_in_use": 2
  }
}
```

### 재등록

삭제 후 [신규 VPN 서버 등록](#신규-vpn-서버-등록) 절차를 다시 진행합니다.

```bash
# 1. 서버 삭제
curl "http://220.121.120.83/vpn_api/release/all?ip=$(curl -s ifconfig.me)&delete=true"

# 2. 재등록
curl -s http://220.121.120.83/vpn_api/one_line_register.sh | bash
```

---

## VPN 서버 헬스체크 및 트래픽 모니터링

### 개요

VPN 서버는 매 1분마다 중앙 API에 헬스체크(heartbeat)와 트래픽 정보를 전송해야 합니다.

**헬스체크 기능:**
- **90초 이상 헬스체크가 없으면**: 해당 서버는 자동으로 키 할당에서 제외됨
- **목적**: 장애 서버에 키 할당 방지, 가용 서버만 사용

**트래픽 모니터링:**
- **네트워크 인터페이스 RX/TX 바이트 수집**: 일별로 집계 저장
- **목적**: 서버별 트래픽 사용량 모니터링

### Cron 설정 (트래픽 모니터링 포함)

VPN 서버에 다음 Cron job 추가:

```bash
# Crontab 편집
crontab -e

# 다음 줄 추가 (매 1분마다 헬스체크 + 트래픽 전송)
* * * * * /usr/local/bin/vpn_heartbeat.sh > /dev/null 2>&1
```

**헬스체크 스크립트 생성:**

```bash
# 스크립트 생성
cat > /usr/local/bin/vpn_heartbeat.sh << 'EOF'
#!/bin/bash
SERVER_IP=$(curl -s ifconfig.me)
INTERFACE="eno1"  # 서버의 네트워크 인터페이스 이름 (ifconfig로 확인)

# RX/TX 바이트 수 읽기
RX=$(cat /sys/class/net/$INTERFACE/statistics/rx_bytes)
TX=$(cat /sys/class/net/$INTERFACE/statistics/tx_bytes)

# API 전송
curl -s "http://220.121.120.83/vpn_api/server/heartbeat?ip=$SERVER_IP&interface=$INTERFACE&rx=$RX&tx=$TX" > /dev/null 2>&1
EOF

# 실행 권한 부여
chmod +x /usr/local/bin/vpn_heartbeat.sh
```

**한 줄로 Cron 추가:**

```bash
(crontab -l 2>/dev/null; echo "* * * * * /usr/local/bin/vpn_heartbeat.sh > /dev/null 2>&1") | crontab -
```

### 네트워크 인터페이스 확인

서버의 주요 네트워크 인터페이스 이름 확인:

```bash
# 모든 인터페이스 목록
ip link show

# 또는
ifconfig
```

일반적인 인터페이스 이름:
- `eth0`, `eth1`: 전통적인 이더넷 인터페이스
- `eno1`, `eno2`: 최신 리눅스 (Rocky, Ubuntu 등)
- `ens33`, `ens192`: VMware 가상 머신
- `enp0s3`: VirtualBox 가상 머신

### 헬스체크 수동 테스트

```bash
# 서버 IP 확인
SERVER_IP=$(curl -s ifconfig.me)

# 트래픽 정보 없이 기본 헬스체크
curl "http://220.121.120.83/vpn_api/server/heartbeat?ip=$SERVER_IP"

# 트래픽 정보 포함 헬스체크 (권장)
INTERFACE="eno1"
RX=$(cat /sys/class/net/$INTERFACE/statistics/rx_bytes)
TX=$(cat /sys/class/net/$INTERFACE/statistics/tx_bytes)
curl "http://220.121.120.83/vpn_api/server/heartbeat?ip=$SERVER_IP&interface=$INTERFACE&rx=$RX&tx=$TX"
```

**응답:**
```json
{
  "success": true,
  "message": "Heartbeat received",
  "server_ip": "111.222.333.444"
}
```

### 헬스체크 확인

서버 상태 조회로 마지막 업데이트 시간 확인:

```bash
curl "http://220.121.120.83/vpn_api/status?ip=$SERVER_IP"
```

### 트래픽 데이터 확인

트래픽 데이터는 `vpn_traffic_daily` 테이블에 일자별로 저장됩니다:

```bash
# 데이터베이스 접속
mysql -u vpnuser -pvpn1324 vpn

# 오늘 수집된 트래픽 데이터 조회
SELECT
    server_ip,
    interface,
    date,
    (current_rx_bytes - init_rx_bytes) / 1024 / 1024 / 1024 AS rx_gb,
    (current_tx_bytes - init_tx_bytes) / 1024 / 1024 / 1024 AS tx_gb,
    updated_at
FROM vpn_traffic_daily
WHERE date = CURDATE()
ORDER BY updated_at DESC;

# 특정 서버의 최근 7일 트래픽
SELECT
    date,
    interface,
    ROUND((current_rx_bytes - init_rx_bytes) / 1024 / 1024 / 1024, 2) AS rx_gb,
    ROUND((current_tx_bytes - init_tx_bytes) / 1024 / 1024 / 1024, 2) AS tx_gb
FROM vpn_traffic_daily
WHERE server_ip = '111.222.333.444'
    AND date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
ORDER BY date DESC;
```

**참고사항:**
- `init_*_bytes`: 당일 첫 헬스체크 시 네트워크 카운터 값
- `current_*_bytes`: 당일 마지막 헬스체크 시 네트워크 카운터 값
- 실제 사용량 = current - init
- 서버 재부팅 시 카운터가 0으로 초기화되므로 음수값이 발생할 수 있음

---

## 서버 상태 관리

### 등록된 서버 목록 조회

```bash
curl http://220.121.120.83/vpn_api/list
```

**응답:**
```json
{
  "success": true,
  "servers": [
    "111.222.333.444",
    "112.161.221.82",
    "123.123.123.123"
  ]
}
```

---

### 서버 상태 조회

```bash
# 특정 서버 상태
SERVER_IP=$(curl -s ifconfig.me)
curl "http://220.121.120.83/vpn_api/status?ip=$SERVER_IP"

# 전체 서버 상태
curl "http://220.121.120.83/vpn_api/status"
```

**응답:**
```json
{
  "success": true,
  "statistics": {
    "total_keys": 10,
    "keys_in_use": 3,
    "keys_available": 7
  },
  "active_connections": [
    {
      "internal_ip": "10.8.0.10",
      "assigned_to": "220.121.120.83",
      "assigned_at": "2025-11-05 22:13:25",
      "duration_seconds": 1234,
      "public_ip": "111.222.333.444"
    }
  ]
}
```

---

### 모든 키 강제 반납

사용 중인 모든 키를 강제로 반납합니다 (서버는 유지).

```bash
SERVER_IP=$(curl -s ifconfig.me)
curl "http://220.121.120.83/vpn_api/release/all?ip=$SERVER_IP"
```

**응답:**
```json
{
  "success": true,
  "message": "All VPN keys released successfully",
  "released": 3,
  "keys": [
    {"internal_ip": "10.8.0.10", "assigned_to": "1.2.3.4"},
    {"internal_ip": "10.8.0.11", "assigned_to": "5.6.7.8"}
  ]
}
```

---

### 오래된 연결 정리

10분 이상 사용 중인 키를 자동으로 반납합니다.

```bash
curl -X POST http://220.121.120.83/vpn_api/cleanup \
  -H "Content-Type: application/json" \
  -d '{"minutes": 10}'
```

**응답:**
```json
{
  "success": true,
  "cleaned": 5
}
```

**참고**: 기본값은 10분이므로 파라미터 없이 호출 가능:
```bash
curl -X POST http://220.121.120.83/vpn_api/cleanup \
  -H "Content-Type: application/json" \
  -d '{}'
```

---

## API 테스트

### API 서버 상태 확인

```bash
# 헬스체크
curl http://220.121.120.83/vpn_api/health

# 데이터베이스 연결 테스트
curl http://220.121.120.83/vpn_api/test/db
```

---

## 문제 해결

### Q: "Server not found" 오류
**원인**: 서버가 등록되지 않음
**해결**: 서버 등록부터 진행

```bash
curl -s http://220.121.120.83/vpn_api/one_line_register.sh | bash
```

### Q: "No available VPN keys" 오류
**원인**: 모든 키가 사용 중
**해결**: 키 반납 또는 정리

```bash
# 모든 키 강제 반납
curl "http://220.121.120.83/vpn_api/release/all?ip=$(curl -s ifconfig.me)"

# 오래된 연결 정리
curl -X POST http://220.121.120.83/vpn_api/cleanup \
  -H "Content-Type: application/json" \
  -d '{"hours": 1}'
```

### Q: JSON 파일 형식 오류
**원인**: JSON 구문 오류
**해결**: jq로 검증

```bash
jq '.' /root/vpn_keys.json
```

### Q: WireGuard 인터페이스를 찾을 수 없음
**원인**: WireGuard 서비스 미실행
**해결**: 서비스 시작

```bash
# 상태 확인
wg show

# 서비스 시작
systemctl start wg-quick@wg0
systemctl enable wg-quick@wg0
```

---

## 관리자 전용 API 요약

| 작업 | 명령 |
|------|------|
| 신규 서버 등록 | `curl -s http://220.121.120.83/vpn_api/one_line_register.sh \| bash` |
| 서버 완전 삭제 | `curl "http://220.121.120.83/vpn_api/release/all?ip=SERVER_IP&delete=true"` |
| 서버 목록 조회 | `curl http://220.121.120.83/vpn_api/list` |
| 서버 상태 조회 | `curl "http://220.121.120.83/vpn_api/status?ip=SERVER_IP"` |
| 모든 키 반납 | `curl "http://220.121.120.83/vpn_api/release/all?ip=SERVER_IP"` |
| 오래된 연결 정리 | `curl -X POST http://220.121.120.83/vpn_api/cleanup -H "Content-Type: application/json" -d '{"hours": 1}'` |

---

## 보안 권장사항

1. **방화벽 설정**: API 서버는 신뢰할 수 있는 IP에서만 접근 허용
2. **로그 모니터링**: 정기적으로 사용 로그 확인
3. **키 순환**: 주기적으로 서버 키 갱신
4. **백업**: `/root/vpn_keys.json` 파일 안전하게 보관

---

## 자동화 예시

### Cron으로 자동 정리 설정

```bash
# /etc/cron.d/vpn-cleanup
# 매 10분마다 10분 이상 사용 중인 키 정리
*/10 * * * * root curl -s -X POST http://220.121.120.83/vpn_api/cleanup -H "Content-Type: application/json" -d '{}' > /dev/null 2>&1
```

### 서버 상태 모니터링

```bash
#!/bin/bash
# /usr/local/bin/vpn-monitor.sh

SERVER_IP=$(curl -s ifconfig.me)
STATUS=$(curl -s "http://220.121.120.83/vpn_api/status?ip=$SERVER_IP")

AVAILABLE=$(echo "$STATUS" | jq -r '.statistics.keys_available')

if [ "$AVAILABLE" -lt 3 ]; then
    echo "⚠️  경고: 사용 가능한 키가 ${AVAILABLE}개 남았습니다"
    # 알림 발송 (이메일, Slack 등)
fi
```

---

## 관련 문서

- [CLIENT_API.md](CLIENT_API.md) - 클라이언트 사용자용 API 가이드
- [CLAUDE.md](CLAUDE.md) - 개발자용 기술 문서
- [README.md](README.md) - 프로젝트 전체 개요
