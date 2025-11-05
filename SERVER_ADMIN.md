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
