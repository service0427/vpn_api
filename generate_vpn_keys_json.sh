#!/bin/bash

# VPN 서버 설치 시 키 정보를 JSON으로 생성하는 스크립트
# install_vpn_server.sh 의 끝부분에 추가할 내용

# 변수 설정 (install_vpn_server.sh에서 가져옴)
SERVER_IP="${1:-$(curl -s ifconfig.me)}"
VPN_PORT="${2:-51820}"
START_IP="${3:-10}"
END_IP="${4:-19}"
OUTPUT_FILE="${5:-/root/vpn_keys.json}"

echo "🔑 VPN 키 JSON 파일 생성 중..."

# 서버 공개키 가져오기
SERVER_PUBLIC_KEY=$(wg show wg0 public-key)

# JSON 파일 시작
cat > "$OUTPUT_FILE" << EOF
{
  "public_ip": "${SERVER_IP}",
  "port": ${VPN_PORT},
  "server_pubkey": "${SERVER_PUBLIC_KEY}",
  "memo": "VPN Server ${SERVER_IP}",
  "keys": [
EOF

# 키 생성 및 JSON 추가
FIRST=true
for i in $(seq $START_IP $END_IP); do
    CLIENT_IP="10.8.0.$i"

    # WireGuard 키 쌍 생성
    CLIENT_PRIVATE=$(wg genkey)
    CLIENT_PUBLIC=$(echo "$CLIENT_PRIVATE" | wg pubkey)

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
done

# JSON 파일 종료
cat >> "$OUTPUT_FILE" << EOF

  ]
}
EOF

# WireGuard 설정 저장
wg-quick save wg0

echo ""
echo "✅ JSON 파일 생성 완료: $OUTPUT_FILE"
echo ""
echo "다음 명령으로 API에 등록하세요:"
echo "  curl -X POST http://220.121.120.83/vpn_api/server/register \\"
echo "    -H 'Content-Type: application/json' \\"
echo "    -d @$OUTPUT_FILE"
echo ""
echo "또는 등록 스크립트 사용:"
echo "  bash <(curl -s http://220.121.120.83/vpn_api/register_vpn_server.sh) $OUTPUT_FILE"
