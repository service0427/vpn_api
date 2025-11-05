#!/bin/bash

# VPN 서버 키 등록 스크립트
# 사용법: ./register_vpn_server.sh [JSON_FILE]

API_URL="http://220.121.120.83/vpn_api"
JSON_FILE="${1:-/root/vpn_keys.json}"

if [ ! -f "$JSON_FILE" ]; then
    echo "❌ JSON 파일을 찾을 수 없습니다: $JSON_FILE"
    exit 1
fi

echo "📝 VPN 서버 키 등록 시작..."
echo "API URL: $API_URL"
echo "JSON 파일: $JSON_FILE"
echo ""

# JSON 파일에서 서버 정보 추출
PUBLIC_IP=$(jq -r '.public_ip' "$JSON_FILE")
PORT=$(jq -r '.port' "$JSON_FILE")
SERVER_PUBKEY=$(jq -r '.server_pubkey' "$JSON_FILE")
MEMO=$(jq -r '.memo // "VPN Server"' "$JSON_FILE")

echo "서버 정보:"
echo "  - Public IP: $PUBLIC_IP"
echo "  - Port: $PORT"
echo "  - Server Pubkey: ${SERVER_PUBKEY:0:30}..."
echo ""

# 1. 서버 등록
echo "📡 1단계: VPN 서버 등록..."
SERVER_RESPONSE=$(curl -s -X POST "$API_URL/server/register" \
  -H "Content-Type: application/json" \
  -d "{
    \"public_ip\": \"$PUBLIC_IP\",
    \"port\": $PORT,
    \"server_pubkey\": \"$SERVER_PUBKEY\",
    \"memo\": \"$MEMO\"
  }")

echo "$SERVER_RESPONSE" | jq '.'

SUCCESS=$(echo "$SERVER_RESPONSE" | jq -r '.success')
if [ "$SUCCESS" != "true" ]; then
    echo "❌ 서버 등록 실패"
    exit 1
fi

SERVER_ID=$(echo "$SERVER_RESPONSE" | jq -r '.server_id')
ACTION=$(echo "$SERVER_RESPONSE" | jq -r '.action')
echo "✅ 서버 등록 완료 (ID: $SERVER_ID, Action: $ACTION)"
echo ""

# 2. 키 일괄 등록
echo "🔑 2단계: VPN 키 일괄 등록..."
KEYS_RESPONSE=$(curl -s -X POST "$API_URL/keys/register" \
  -H "Content-Type: application/json" \
  -d @"$JSON_FILE")

echo "$KEYS_RESPONSE" | jq '.'

SUCCESS=$(echo "$KEYS_RESPONSE" | jq -r '.success')
if [ "$SUCCESS" != "true" ]; then
    echo "❌ 키 등록 실패"
    exit 1
fi

REGISTERED=$(echo "$KEYS_RESPONSE" | jq -r '.registered')
TOTAL=$(echo "$KEYS_RESPONSE" | jq -r '.total')
echo "✅ 키 등록 완료 ($REGISTERED/$TOTAL)"
echo ""

# 3. 등록 확인
echo "🔍 3단계: 등록 확인..."
STATUS_RESPONSE=$(curl -s "$API_URL/status?ip=$PUBLIC_IP")
echo "$STATUS_RESPONSE" | jq '.'

echo ""
echo "✅ 모든 등록 작업 완료!"
echo ""
echo "사용 가능한 명령어:"
echo "  - 서버 목록: curl http://220.121.120.83/vpn_api/list"
echo "  - 키 할당: curl \"http://220.121.120.83/vpn_api/allocate?ip=$PUBLIC_IP\""
echo "  - 상태 조회: curl \"http://220.121.120.83/vpn_api/status?ip=$PUBLIC_IP\""
