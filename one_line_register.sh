#!/bin/bash

# VPN 서버 원라인 등록 스크립트
# 사용법: curl -s http://220.121.120.83/vpn_api/one_line_register.sh | bash

set -e

API_URL="http://220.121.120.83/vpn_api"
OUTPUT_FILE="/root/vpn_keys.json"

echo "========================================="
echo "   VPN 서버 자동 등록 스크립트"
echo "========================================="
echo ""

# 서버 정보 자동 감지
echo "📡 서버 정보 수집 중..."
SERVER_IP=$(curl -s ifconfig.me)
VPN_PORT=51820
SERVER_PUBKEY=$(wg show wg0 public-key 2>/dev/null || echo "")

if [ -z "$SERVER_PUBKEY" ]; then
    echo "❌ WireGuard 서버 공개키를 찾을 수 없습니다."
    echo "   WireGuard가 설치되어 있는지 확인하세요: wg show wg0"
    exit 1
fi

echo "  ✓ 서버 IP: $SERVER_IP"
echo "  ✓ 포트: $VPN_PORT"
echo "  ✓ 서버 공개키: ${SERVER_PUBKEY:0:30}..."
echo ""

# JSON 파일 생성 시작
echo "🔑 VPN 키 JSON 파일 생성 중..."
cat > "$OUTPUT_FILE" << EOF
{
  "public_ip": "${SERVER_IP}",
  "port": ${VPN_PORT},
  "server_pubkey": "${SERVER_PUBKEY}",
  "memo": "VPN Server ${SERVER_IP}",
  "keys": [
EOF

# 키 생성 (10.8.0.10 ~ 10.8.0.19)
START_IP=10
END_IP=19
FIRST=true
SUCCESS_COUNT=0

for i in $(seq $START_IP $END_IP); do
    CLIENT_IP="10.8.0.$i"

    # WireGuard에서 기존 peer 정보 확인
    EXISTING_PUBKEY=$(wg show wg0 | grep -A 2 "allowed ips: ${CLIENT_IP}/32" | grep "peer:" | awk '{print $2}' || echo "")

    if [ -n "$EXISTING_PUBKEY" ]; then
        # 기존 peer가 있으면 사용
        # private key는 알 수 없으므로 새로 생성
        CLIENT_PRIVATE=$(wg genkey)
        CLIENT_PUBLIC=$(echo "$CLIENT_PRIVATE" | wg pubkey)

        # 기존 peer 제거
        wg set wg0 peer "$EXISTING_PUBKEY" remove 2>/dev/null || true
    else
        # 새로운 키 쌍 생성
        CLIENT_PRIVATE=$(wg genkey)
        CLIENT_PUBLIC=$(echo "$CLIENT_PRIVATE" | wg pubkey)
    fi

    # WireGuard에 peer 추가
    wg set wg0 peer "$CLIENT_PUBLIC" allowed-ips "${CLIENT_IP}/32"

    # JSON에 추가
    if [ "$FIRST" = true ]; then
        FIRST=false
    else
        echo "," >> "$OUTPUT_FILE"
    fi

    cat >> "$OUTPUT_FILE" << EOF
    {
      "internal_ip": "${CLIENT_IP}",
      "private_key": "${CLIENT_PRIVATE}",
      "public_key": "${CLIENT_PUBLIC}"
    }
EOF

    echo "  ✓ ${CLIENT_IP} 키 생성 완료"
    SUCCESS_COUNT=$((SUCCESS_COUNT + 1))
done

# JSON 파일 종료
cat >> "$OUTPUT_FILE" << EOF

  ]
}
EOF

# WireGuard 설정 저장
wg-quick save wg0 2>/dev/null || echo "  ⚠ WireGuard 설정 저장 실패 (수동으로 저장 필요)"

echo "  ✓ 총 ${SUCCESS_COUNT}개 키 생성 완료"
echo ""

# JSON 유효성 검증
if command -v jq &> /dev/null; then
    if ! jq '.' "$OUTPUT_FILE" > /dev/null 2>&1; then
        echo "❌ JSON 파일 형식 오류"
        exit 1
    fi
    echo "  ✓ JSON 파일 유효성 검증 완료"
else
    echo "  ⚠ jq가 설치되지 않아 JSON 검증을 건너뜁니다"
fi

echo ""
echo "📡 API에 서버 등록 중..."

# 1. 서버 등록
SERVER_RESPONSE=$(curl -s -X POST "$API_URL/server/register" \
  -H "Content-Type: application/json" \
  -d "{
    \"public_ip\": \"$SERVER_IP\",
    \"port\": $VPN_PORT,
    \"server_pubkey\": \"$SERVER_PUBKEY\",
    \"memo\": \"VPN Server $SERVER_IP\"
  }")

SUCCESS=$(echo "$SERVER_RESPONSE" | grep -o '"success"[[:space:]]*:[[:space:]]*true' || echo "")
if [ -z "$SUCCESS" ]; then
    echo "❌ 서버 등록 실패"
    echo "$SERVER_RESPONSE"
    exit 1
fi

SERVER_ID=$(echo "$SERVER_RESPONSE" | grep -o '"server_id"[[:space:]]*:[[:space:]]*"[^"]*"' | sed 's/.*"\([^"]*\)".*/\1/')
ACTION=$(echo "$SERVER_RESPONSE" | grep -o '"action"[[:space:]]*:[[:space:]]*"[^"]*"' | sed 's/.*"\([^"]*\)".*/\1/')

echo "  ✓ 서버 등록 완료 (ID: $SERVER_ID, Action: $ACTION)"
echo ""

# 2. 키 일괄 등록
echo "🔑 VPN 키 일괄 등록 중..."
KEYS_RESPONSE=$(curl -s -X POST "$API_URL/keys/register" \
  -H "Content-Type: application/json" \
  -d @"$OUTPUT_FILE")

SUCCESS=$(echo "$KEYS_RESPONSE" | grep -o '"success"[[:space:]]*:[[:space:]]*true' || echo "")
if [ -z "$SUCCESS" ]; then
    echo "❌ 키 등록 실패"
    echo "$KEYS_RESPONSE"
    exit 1
fi

REGISTERED=$(echo "$KEYS_RESPONSE" | grep -o '"registered"[[:space:]]*:[[:space:]]*[0-9]*' | sed 's/.*: *//')
TOTAL=$(echo "$KEYS_RESPONSE" | grep -o '"total"[[:space:]]*:[[:space:]]*[0-9]*' | sed 's/.*: *//')

echo "  ✓ 키 등록 완료 ($REGISTERED/$TOTAL)"
echo ""

# 3. 등록 확인
echo "🔍 등록 확인 중..."
STATUS_RESPONSE=$(curl -s "$API_URL/status?ip=$SERVER_IP")

echo "$STATUS_RESPONSE" | grep -q '"success"[[:space:]]*:[[:space:]]*true' && echo "  ✓ 등록 확인 완료" || echo "  ⚠ 상태 조회 실패"

echo ""
echo "========================================="
echo "✅ 모든 작업 완료!"
echo "========================================="
echo ""
echo "📋 서버 정보:"
echo "  - IP: $SERVER_IP"
echo "  - Port: $VPN_PORT"
echo "  - Server ID: $SERVER_ID"
echo "  - 등록된 키: $REGISTERED개"
echo ""
echo "📝 JSON 파일 위치: $OUTPUT_FILE"
echo ""
echo "🔗 사용 가능한 명령어:"
echo "  # 서버 목록 확인"
echo "  curl http://220.121.120.83/vpn_api/list"
echo ""
echo "  # 서버 상태 확인"
echo "  curl \"http://220.121.120.83/vpn_api/status?ip=$SERVER_IP\""
echo ""
echo "  # 키 할당 테스트"
echo "  curl \"http://220.121.120.83/vpn_api/allocate?ip=$SERVER_IP\""
echo ""
echo "  # 모든 키 반납"
echo "  curl \"http://220.121.120.83/vpn_api/release/all?ip=$SERVER_IP\""
echo ""
