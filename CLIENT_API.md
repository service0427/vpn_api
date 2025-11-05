# VPN 키 할당 API - 클라이언트 사용 가이드

VPN 연결을 위한 키를 자동으로 할당받고 반납하는 API입니다.

---

## API 서버 정보

- **API URL**: `http://220.121.120.83/vpn_api`
- **인증**: 없음 (클라이언트 IP로 자동 식별)

---

## 사용 흐름

```
1. 사용 가능한 VPN 서버 목록 조회 (선택)
   ↓
2. VPN 키 할당받기
   ↓
3. WireGuard 설정 파일 생성 및 VPN 연결
   ↓
4. VPN 사용
   ↓
5. 사용 완료 후 VPN 키 반납
```

---

## 1. VPN 서버 목록 조회 (선택)

사용 가능한 VPN 서버 IP 목록을 조회합니다.

### 요청
```bash
curl http://220.121.120.83/vpn_api/list
```

### 응답
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

## 2. VPN 키 할당받기

VPN 연결에 필요한 키와 설정 정보를 할당받습니다.

### 요청

#### 방법 1: 특정 서버에서 키 할당
```bash
curl "http://220.121.120.83/vpn_api/allocate?ip=123.123.123.123"
```

#### 방법 2: 자동으로 가용한 서버에서 키 할당 (권장)
```bash
curl "http://220.121.120.83/vpn_api/allocate"
```

### 응답
```json
{
  "success": true,
  "server_ip": "123.123.123.123",
  "server_port": 55555,
  "server_pubkey": "BHhFN2+AOR3AjJAx7Q+XwfoEcVzZn+x43tiAF5MXs04=",
  "private_key": "aEGrqf/GbRjD9eK6ZwWvS2pZ7HLSmQpSjf0wh3tUgXI=",
  "public_key": "BMbXYCsfVxc1ee/gyh1R74EVJ4LBVdH5QBkZ0HB+Jmo=",
  "internal_ip": "10.8.0.10",
  "config": "[Interface]\nPrivateKey = aEGrqf...\nAddress = 10.8.0.10/24\n..."
}
```

### 응답 필드 설명
- `server_ip`: VPN 서버 IP 주소
- `server_port`: VPN 서버 포트 (기본값: 55555)
- `server_pubkey`: VPN 서버 공개키
- `private_key`: 클라이언트 개인키 (저장 필수)
- `public_key`: 클라이언트 공개키 (반납 시 필요)
- `internal_ip`: VPN 내부 IP 주소
- `config`: WireGuard 설정 파일 내용 (바로 사용 가능)

---

## 3. WireGuard 설정 파일 생성

할당받은 `config` 내용을 파일로 저장합니다.

### Linux/macOS
```bash
# config 내용을 파일로 저장
echo "[Interface]
PrivateKey = aEGrqf/GbRjD9eK6ZwWvS2pZ7HLSmQpSjf0wh3tUgXI=
Address = 10.8.0.10/24
DNS = 1.1.1.1, 8.8.8.8

[Peer]
PublicKey = BHhFN2+AOR3AjJAx7Q+XwfoEcVzZn+x43tiAF5MXs04=
Endpoint = 123.123.123.123:55555
AllowedIPs = 0.0.0.0/0
PersistentKeepalive = 25" > /etc/wireguard/wg0.conf

# VPN 연결
wg-quick up wg0

# VPN 연결 확인
wg show
```

### Windows
1. WireGuard 앱 실행
2. "Import tunnel from file" 또는 "Add empty tunnel"
3. `config` 내용 복사하여 붙여넣기
4. "Activate" 버튼 클릭

---

## 4. VPN 키 반납하기

VPN 사용 완료 후 키를 반납합니다.

### 요청
```bash
curl -X POST http://220.121.120.83/vpn_api/release \
  -H "Content-Type: application/json" \
  -d '{"public_key": "BMbXYCsfVxc1ee/gyh1R74EVJ4LBVdH5QBkZ0HB+Jmo="}'
```

### 응답
```json
{
  "success": true,
  "message": "VPN key released successfully"
}
```

### 주의사항
- 반드시 할당받은 `public_key` 값을 사용해야 합니다
- VPN 연결 종료 후 반납해야 다른 사용자가 사용할 수 있습니다

---

## 5. 상태 조회 (선택)

특정 서버 또는 전체 서버의 키 사용 현황을 조회합니다.

### 요청

#### 특정 서버 조회
```bash
curl "http://220.121.120.83/vpn_api/status?ip=123.123.123.123"
```

#### 전체 서버 조회
```bash
curl "http://220.121.120.83/vpn_api/status"
```

### 응답
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
      "duration_seconds": 1234
    }
  ]
}
```

---

## 전체 사용 예시 (Bash 스크립트)

```bash
#!/bin/bash

API_URL="http://220.121.120.83/vpn_api"

# 1. VPN 키 할당받기
echo "VPN 키 할당받는 중..."
RESPONSE=$(curl -s "$API_URL/allocate")

# 응답 확인
SUCCESS=$(echo "$RESPONSE" | jq -r '.success')
if [ "$SUCCESS" != "true" ]; then
    echo "❌ 키 할당 실패"
    exit 1
fi

# 필요한 정보 추출
PUBLIC_KEY=$(echo "$RESPONSE" | jq -r '.public_key')
CONFIG=$(echo "$RESPONSE" | jq -r '.config')

echo "✅ VPN 키 할당 완료"
echo "Public Key: $PUBLIC_KEY"

# 2. WireGuard 설정 파일 생성
echo "$CONFIG" > /tmp/wg-temp.conf

# 3. VPN 연결
echo "VPN 연결 중..."
wg-quick up /tmp/wg-temp.conf

# 4. VPN 사용 (여기서 필요한 작업 수행)
echo "VPN 연결됨. 작업 수행 중..."
sleep 10  # 실제 작업으로 대체

# 5. VPN 종료
wg-quick down /tmp/wg-temp.conf

# 6. 키 반납
echo "VPN 키 반납 중..."
curl -s -X POST "$API_URL/release" \
  -H "Content-Type: application/json" \
  -d "{\"public_key\": \"$PUBLIC_KEY\"}"

echo "✅ VPN 키 반납 완료"
rm /tmp/wg-temp.conf
```

---

## 오류 처리

### 키가 없을 때
```json
{
  "success": false,
  "error": "No available VPN keys"
}
```
**해결**: 잠시 후 재시도하거나 다른 서버 IP로 요청

### 키 반납 실패
```json
{
  "success": false,
  "error": "Key not found or not in use"
}
```
**해결**: `public_key` 값이 정확한지 확인

---

## Python 사용 예시

```python
import requests
import json
import subprocess

API_URL = "http://220.121.120.83/vpn_api"

# 1. VPN 키 할당
response = requests.get(f"{API_URL}/allocate")
data = response.json()

if not data['success']:
    print("❌ 키 할당 실패:", data.get('error'))
    exit(1)

public_key = data['public_key']
config = data['config']

print("✅ VPN 키 할당 완료")

# 2. 설정 파일 생성
with open('/tmp/wg-temp.conf', 'w') as f:
    f.write(config)

# 3. VPN 연결
subprocess.run(['wg-quick', 'up', '/tmp/wg-temp.conf'])

# 4. 작업 수행
print("VPN 작업 수행 중...")
# ... 실제 작업 ...

# 5. VPN 종료 및 키 반납
subprocess.run(['wg-quick', 'down', '/tmp/wg-temp.conf'])

response = requests.post(
    f"{API_URL}/release",
    json={"public_key": public_key}
)

print("✅ VPN 키 반납 완료")
```

---

## 키 반납 실패 시 처리

### 자동 정리

프로그램 강제 종료 등으로 키 반납에 실패하면 **10분 후 자동으로 반납**됩니다.

- 정상적인 경우: 프로그램이 자동으로 할당/반납 처리
- 비정상 종료 시: 10분 대기 후 시스템이 자동 반납
- 추가 작업 불필요: 클라이언트는 신경 쓸 필요 없음

### 수동 반납 (선택)

public_key를 알고 있다면 나중에 수동 반납 가능:

```bash
curl -X POST http://220.121.120.83/vpn_api/release \
  -H "Content-Type: application/json" \
  -d '{"public_key": "YOUR_PUBLIC_KEY"}'
```

---

## FAQ

### Q: 키를 반납하지 않으면 어떻게 되나요?
A: 10분 후 자동으로 반납됩니다.

### Q: 반납 실패 시 다시 할당받을 수 있나요?
A: 네, 언제든지 새로운 키를 할당받을 수 있습니다. 이전 키는 10분 후 자동 정리됩니다.

### Q: VPN 서버를 지정하지 않으면 어떻게 되나요?
A: 시스템이 자동으로 가장 여유 있는 서버에서 키를 할당합니다 (권장).

### Q: 동일한 키를 재사용할 수 있나요?
A: 아니요. 반납 후 다시 할당받아야 합니다.

---

## 문의

API 관련 문제나 질문이 있으면 시스템 관리자에게 문의하세요.
