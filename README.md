# VPN Key Pool Management API

중앙 집중식 VPN 키 풀 관리 시스템입니다. 여러 VPN 서버의 WireGuard 키를 중앙에서 관리하고, 클라이언트가 자동으로 키를 할당받고 반납할 수 있습니다.

---

## 시스템 정보

- **API URL**: http://220.121.120.83/vpn_api
- **플랫폼**: Apache 2.4.62 + PHP 8.4.13 (Rocky Linux)
- **데이터베이스**: MariaDB
- **프로토콜**: WireGuard

---

## 문서

### 사용자별 가이드

| 대상 | 문서 | 설명 |
|------|------|------|
| **VPN 사용자** | [CLIENT_API.md](CLIENT_API.md) | VPN 키 할당/반납 방법 |
| **서버 관리자** | [SERVER_ADMIN.md](SERVER_ADMIN.md) | VPN 서버 등록 및 관리 |
| **개발자** | [CLAUDE.md](CLAUDE.md) | 코드베이스 아키텍처 및 개발 가이드 |

---

## 빠른 시작

### VPN 사용자 (키 할당받기)

```bash
# 1. VPN 키 할당
curl "http://220.121.120.83/vpn_api/allocate"

# 2. 응답에서 config 내용을 WireGuard 설정으로 사용

# 3. 사용 완료 후 키 반납
curl -X POST http://220.121.120.83/vpn_api/release \
  -H "Content-Type: application/json" \
  -d '{"public_key": "YOUR_PUBLIC_KEY"}'
```

자세한 내용: [CLIENT_API.md](CLIENT_API.md)

---

### 서버 관리자 (신규 서버 등록)

```bash
# VPN 서버에서 실행 (자동 등록)
curl -s http://220.121.120.83/vpn_api/one_line_register.sh | bash
```

자세한 내용: [SERVER_ADMIN.md](SERVER_ADMIN.md)

---

## 주요 기능

### 1. 자동 키 할당
- 클라이언트 요청 시 사용 가능한 키 자동 할당
- LRU (Least Recently Used) 전략으로 공평한 분배
- 트랜잭션 기반 동시성 제어

### 2. 다중 서버 지원
- 여러 VPN 서버의 키를 중앙에서 관리
- 서버별 또는 전체 서버에서 자동 할당
- 서버별 독립적인 키 풀 관리

### 3. 사용 추적
- 클라이언트 IP 자동 감지 (프록시 환경 지원)
- 모든 할당/반납 작업 로그 기록
- 사용 통계 및 활성 연결 모니터링

### 4. 자동 정리
- 오래된 연결 자동 반납 (기본 10분)
- 키 재사용 최적화
- 서버 재설치 시 완전 삭제 지원

---

## API 엔드포인트

### 클라이언트용

| 엔드포인트 | 메서드 | 설명 |
|-----------|--------|------|
| `/list` | GET | 사용 가능한 VPN 서버 목록 |
| `/allocate` | GET | VPN 키 할당 |
| `/release` | POST | VPN 키 반납 |
| `/status` | GET | 서버 상태 조회 |

### 관리자용

| 엔드포인트 | 메서드 | 설명 |
|-----------|--------|------|
| `/server/register` | POST | VPN 서버 등록 |
| `/keys/register` | POST | 키 일괄 등록 |
| `/release/all` | GET | 모든 키 반납 |
| `/release/all?delete=true` | GET | 서버 완전 삭제 |
| `/cleanup` | POST | 오래된 연결 정리 |

전체 API 문서: [CLIENT_API.md](CLIENT_API.md) | [SERVER_ADMIN.md](SERVER_ADMIN.md)

---

## 데이터베이스 구조

### vpn_servers
VPN 서버 정보 (IP, 포트, 공개키)

### vpn_keys
클라이언트 키 정보 (내부 IP, 키 쌍, 사용 상태)

### vpn_usage_logs
사용 기록 (할당/반납 시각, 클라이언트 IP, 연결 시간)

**관계**: `vpn_servers` → `vpn_keys` → `vpn_usage_logs` (CASCADE DELETE)

---

## 시스템 요구사항

### VPN 서버 (WireGuard)
- WireGuard 설치
- 10개 클라이언트 키 사전 생성 (10.8.0.10 ~ 10.8.0.19)
- curl, jq 패키지

### API 서버
- Apache 2.4+
- PHP 8.0+
- MariaDB/MySQL 5.7+
- PDO extension

---

## 설치 및 설정

### 데이터베이스 초기화

```bash
mysql -u vpnuser -p vpn < schema.sql
```

### Apache 설정

`.htaccess` 파일이 URL 라우팅을 자동으로 처리합니다.

```apache
RewriteEngine On
RewriteBase /vpn_api/
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php [QSA,L]
```

---

## 테스트

```bash
# API 서버 상태
curl http://220.121.120.83/vpn_api/health

# 데이터베이스 연결
curl http://220.121.120.83/vpn_api/test/db

# 서버 목록
curl http://220.121.120.83/vpn_api/list
```

---

## 프로젝트 구조

```
/var/www/html/vpn_api/
├── index.php              # API 엔트리 포인트 및 라우팅
├── .htaccess             # Apache URL 리라이트 규칙
├── config/
│   └── database.php      # 데이터베이스 연결
├── api/
│   └── vpn.php          # VpnApi 클래스 (비즈니스 로직)
├── utils/
│   └── wireguard.php    # WireGuard 유틸리티
├── schema.sql           # 데이터베이스 스키마
├── *.sh                 # 서버 등록 자동화 스크립트
├── CLIENT_API.md        # 클라이언트 사용 가이드
├── SERVER_ADMIN.md      # 서버 관리자 가이드
└── CLAUDE.md           # 개발자 기술 문서
```

---

## 보안

### 현재 구현
- 클라이언트 IP 기반 추적
- 데이터베이스 트랜잭션으로 동시성 제어
- Prepared statements로 SQL injection 방지

### 권장 사항
- API 인증 추가 (API Key, JWT 등)
- HTTPS 사용
- 방화벽으로 API 접근 제한
- 로그 정기 모니터링

---

## 라이선스

이 프로젝트는 내부 사용을 위한 것입니다.

---

## 지원

- **버그 리포트**: 시스템 관리자에게 문의
- **기능 요청**: GitHub Issues (내부)
- **긴급 지원**: 24/7 on-call 담당자

---

## 변경 이력

### v1.0.0 (2025-11-05)
- ✨ 초기 릴리스
- ✨ VPN 키 자동 할당/반납
- ✨ 다중 서버 지원
- ✨ 사용 로그 및 통계
- ✨ 서버 완전 삭제 기능
