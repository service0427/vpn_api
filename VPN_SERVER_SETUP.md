# VPN 서버 설치 후 API 연결 가이드

신규 VPN 서버 설치 후 중앙 API에 연결하는 완전한 가이드입니다.

---

## 🎯 개요

- **API 서버**: http://220.121.120.83/vpn_api/
- **목적**: 설치한 VPN 서버의 키를 중앙 API에 등록하여 클라이언트가 자동으로 키를 할당받을 수 있도록 함
- **소요 시간**: 약 5분

---

## ✅ 사전 준비

1. WireGuard VPN 서버 설치 완료
2. 10개의 클라이언트 키 생성 완료 (IP: 10.8.0.10 ~ 10.8.0.19)
3. 서버에 `curl`, `jq` 설치

```bash
# CentOS/RHEL
yum install -y curl jq

# Ubuntu/Debian
apt install -y curl jq
```

---

## 🚀 방법 1: 자동 등록 (권장)

### 원라인 명령

VPN 서버에서 다음 명령 한 줄만 실행:

```bash
curl -s http://220.121.120.83/vpn_api/one_line_register.sh | bash
```

**이 명령이 하는 일:**
1. 서버 IP, 포트, 공개키 자동 감지
2. WireGuard에서 10개 키 수집/생성 (10.8.0.10~19)
3. JSON 파일 생성 (`/root/vpn_keys.json`)
4. API에 서버 정보 등록
5. API에 키 정보 일괄 등록
6. 등록 완료 확인

**예상 출력:**
```
=========================================
   VPN 서버 자동 등록 스크립트
=========================================

📡 서버 정보 수집 중...
  ✓ 서버 IP: 111.222.333.444
  ✓ 포트: 51820
  ✓ 서버 공개키: BHhFN2+AOR3AjJAx7Q+...

🔑 VPN 키 JSON 파일 생성 중...
  ✓ 10.8.0.10 키 생성 완료
  ✓ 10.8.0.11 키 생성 완료
  ...
  ✓ 총 10개 키 생성 완료

📡 API에 서버 등록 중...
  ✓ 서버 등록 완료 (ID: 123, Action: created)

🔑 VPN 키 일괄 등록 중...
  ✓ 키 등록 완료 (10/10)

🔍 등록 확인 중...
  ✓ 등록 확인 완료

=========================================
✅ 모든 작업 완료!
=========================================
```

---

## 📝 방법 2: 단계별 수동 등록

### 1단계: 서버 정보 확인

```bash
# 서버 공개 IP
SERVER_IP=$(curl -s ifconfig.me)
echo "서버 IP: $SERVER_IP"

# WireGuard 포트
VPN_PORT=51820

# 서버 공개키
SERVER_PUBKEY=$(wg show wg0 public-key)
echo "서버 공개키: $SERVER_PUBKEY"
```

### 2단계: JSON 파일 생성

```bash
# 스크립트 다운로드
curl -o /root/generate_keys.sh http://220.121.120.83/vpn_api/generate_vpn_keys_json.sh
chmod +x /root/generate_keys.sh

# JSON 파일 생성 (10.8.0.10 ~ 10.8.0.19)
/root/generate_keys.sh $SERVER_IP $VPN_PORT 10 19 /root/vpn_keys.json
```

**생성되는 JSON 형식:**
```json
{
  "public_ip": "111.222.333.444",
  "port": 51820,
  "server_pubkey": "BHhFN2+AOR3AjJAx7Q+...",
  "memo": "VPN Server 111.222.333.444",
  "keys": [
    {
      "internal_ip": "10.8.0.10",
      "private_key": "aEGrqf/GbRjD9eK6ZwW...",
      "public_key": "BMbXYCsfVxc1ee/gyh1..."
    },
    ...
  ]
}
```

JSON 파일 확인:
```bash
cat /root/vpn_keys.json
jq '.' /root/vpn_keys.json  # JSON 유효성 검사
```

### 3단계: API에 등록

```bash
# 등록 스크립트 다운로드
curl -o /root/register.sh http://220.121.120.83/vpn_api/register_vpn_server.sh
chmod +x /root/register.sh

# API에 등록
/root/register.sh /root/vpn_keys.json
```

---

## 🔍 등록 확인

### 1. 서버 목록 확인

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

### 2. 서버 상태 확인

```bash
curl "http://220.121.120.83/vpn_api/status?ip=$SERVER_IP"
```

**응답:**
```json
{
  "success": true,
  "statistics": {
    "total_keys": 10,
    "keys_in_use": 0,
    "keys_available": 10
  },
  "active_connections": []
}
```

### 3. 키 할당 테스트

```bash
curl "http://220.121.120.83/vpn_api/allocate?ip=$SERVER_IP"
```

**응답:**
```json
{
  "success": true,
  "server_ip": "111.222.333.444",
  "server_port": 51820,
  "server_pubkey": "BHhFN2+...",
  "private_key": "aEGrqf/G...",
  "public_key": "BMbXYCs...",
  "internal_ip": "10.8.0.10",
  "config": "[Interface]\nPrivateKey = ...\n..."
}
```

---

## 🔄 install_vpn_server.sh에 통합

기존 VPN 설치 스크립트의 **맨 끝에** 다음을 추가:

```bash
# ========================================
# API에 자동 등록
# ========================================

echo ""
echo "========================================="
echo "중앙 API에 VPN 서버 등록 중..."
echo "========================================="
echo ""

# 원라인 자동 등록
curl -s http://220.121.120.83/vpn_api/one_line_register.sh | bash

echo ""
echo "✅ VPN 서버 설치 및 API 등록 완료!"
```

또는 단계별로:

```bash
# ========================================
# API에 등록
# ========================================

# 1. JSON 생성
curl -s http://220.121.120.83/vpn_api/generate_vpn_keys_json.sh | \
  bash -s -- "$SERVER_IP" "$VPN_PORT" 10 19 /root/vpn_keys.json

# 2. API 등록
curl -s http://220.121.120.83/vpn_api/register_vpn_server.sh | \
  bash -s -- /root/vpn_keys.json

echo ""
echo "✅ API 등록 완료!"
echo "서버 목록: curl http://220.121.120.83/vpn_api/list"
```

---

## 🔧 문제 해결

### Q1: "Server not found" 오류

**원인:** 서버를 먼저 등록하지 않음

**해결:**
```bash
# 서버만 먼저 등록
curl -X POST http://220.121.120.83/vpn_api/server/register \
  -H "Content-Type: application/json" \
  -d "{
    \"public_ip\": \"$SERVER_IP\",
    \"port\": 51820,
    \"server_pubkey\": \"$SERVER_PUBKEY\",
    \"memo\": \"My VPN Server\"
  }"

# 그 다음 키 등록
curl -X POST http://220.121.120.83/vpn_api/keys/register \
  -H "Content-Type: application/json" \
  -d @/root/vpn_keys.json
```

### Q2: JSON 형식 오류

**확인:**
```bash
jq '.' /root/vpn_keys.json
```

**일반적인 오류:**
- 마지막 항목에 쉼표(`,`) 있음
- 따옴표 누락
- 중괄호/대괄호 불균형

### Q3: WireGuard 인터페이스를 찾을 수 없음

```bash
# WireGuard 상태 확인
wg show

# 서비스 시작
systemctl start wg-quick@wg0
systemctl status wg-quick@wg0
```

### Q4: 서버 공개키가 비어있음

```bash
# 공개키 확인
wg show wg0 public-key

# 없으면 생성
wg genkey | tee /etc/wireguard/privatekey | wg pubkey > /etc/wireguard/publickey
```

### Q5: curl 또는 jq가 없음

```bash
# CentOS/RHEL
yum install -y curl jq

# Ubuntu/Debian
apt install -y curl jq
```

---

## 🔄 재등록 (서버 재설치 시)

서버를 재설치하거나 키를 다시 생성한 경우:

```bash
# 1. 기존 키 모두 반납
curl "http://220.121.120.83/vpn_api/release/all?ip=$SERVER_IP"

# 2. 새로운 JSON 생성
/root/generate_keys.sh $SERVER_IP 51820 10 19 /root/vpn_keys.json

# 3. 재등록 (기존 서버 정보 자동 업데이트)
/root/register.sh /root/vpn_keys.json
```

---

## 📚 API 엔드포인트 (참고)

### 클라이언트용 API

| 메서드 | 경로 | 설명 |
|--------|------|------|
| GET | `/list` | 사용 가능한 VPN 서버 목록 |
| GET | `/allocate?ip=[public_ip]` | VPN 키 할당 |
| POST | `/release` | VPN 키 반납 |
| GET | `/release/all?ip=[public_ip]` | 모든 키 반납 |
| GET | `/status?ip=[public_ip]` | 서버 상태 조회 |

### 관리자용 API

| 메서드 | 경로 | 설명 |
|--------|------|------|
| POST | `/server/register` | 서버 등록/업데이트 |
| POST | `/keys/register` | 키 일괄 등록 |
| POST | `/cleanup` | 오래된 연결 정리 |

---

## 📋 전체 흐름

```
1. VPN 서버 설치 (WireGuard)
   ↓
2. 키 10개 생성 (10.8.0.10~19)
   ↓
3. 서버 정보 수집
   - 공개 IP
   - WireGuard 포트
   - 서버 공개키
   ↓
4. JSON 파일 생성
   - /root/vpn_keys.json
   ↓
5. API 등록
   - POST /server/register (서버 등록)
   - POST /keys/register (키 등록)
   ↓
6. 등록 완료 ✅
   ↓
7. 클라이언트 사용
   - GET /allocate (키 할당)
   - POST /release (키 반납)
```

---

## 💡 사용 예시

### 예시 1: 키 할당

```bash
# 클라이언트가 키 요청
curl "http://220.121.120.83/vpn_api/allocate?ip=111.222.333.444"

# 응답으로 받은 config를 파일로 저장
curl "http://220.121.120.83/vpn_api/allocate?ip=111.222.333.444" | \
  jq -r '.config' > client.conf

# WireGuard 연결
wg-quick up ./client.conf
```

### 예시 2: 키 반납

```bash
PUBLIC_KEY="BMbXYCsfVxc1ee/gyh1R74EVJ4LBVdH5QBkZ0HB+Jmo="

curl -X POST http://220.121.120.83/vpn_api/release \
  -H "Content-Type: application/json" \
  -d "{\"public_key\": \"$PUBLIC_KEY\"}"
```

### 예시 3: 서버 상태 모니터링

```bash
# Cron으로 1분마다 상태 확인
echo "* * * * * curl -s 'http://220.121.120.83/vpn_api/status?ip=111.222.333.444' >> /var/log/vpn_status.log" | crontab -
```

---

## 🎯 핵심 요약

### 가장 쉬운 방법 (권장)
```bash
curl -s http://220.121.120.83/vpn_api/one_line_register.sh | bash
```

### 단계별 방법
```bash
# 1. JSON 생성
curl -o /root/gen.sh http://220.121.120.83/vpn_api/generate_vpn_keys_json.sh
chmod +x /root/gen.sh
/root/gen.sh $(curl -s ifconfig.me) 51820 10 19 /root/vpn_keys.json

# 2. API 등록
curl -o /root/reg.sh http://220.121.120.83/vpn_api/register_vpn_server.sh
chmod +x /root/reg.sh
/root/reg.sh /root/vpn_keys.json

# 3. 확인
curl http://220.121.120.83/vpn_api/list
```

### 필수 파일
- `/root/vpn_keys.json` - 서버/키 정보 (재등록 시 필요하므로 보관)

### API 주소
- **메인**: http://220.121.120.83/vpn_api/
- **상태 확인**: http://220.121.120.83/vpn_api/health
- **서버 목록**: http://220.121.120.83/vpn_api/list

---

## ✅ 체크리스트

설치 완료 후 확인할 사항:

- [ ] WireGuard 서비스 실행 중: `systemctl status wg-quick@wg0`
- [ ] 서버 공개키 확인: `wg show wg0 public-key`
- [ ] JSON 파일 생성됨: `ls -lh /root/vpn_keys.json`
- [ ] API 등록 완료: `curl http://220.121.120.83/vpn_api/list` (본인 IP 포함)
- [ ] 키 할당 테스트: `curl "http://220.121.120.83/vpn_api/allocate?ip=$SERVER_IP"`
- [ ] 서버 상태 정상: `curl "http://220.121.120.83/vpn_api/status?ip=$SERVER_IP"`

---

## 📞 추가 지원

- API 문서: http://220.121.120.83/vpn_api/
- Health Check: http://220.121.120.83/vpn_api/health

**끝!** 🎉
