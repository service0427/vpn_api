# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

This is a VPN Key Pool Management API server built with Apache + PHP. It provides centralized management for WireGuard VPN server keys, allowing automatic allocation and release of VPN keys across multiple VPN servers.

**Server Information:**
- **URL**: http://220.121.120.83/vpn_api/
- **PHP**: 8.4.13
- **Web Server**: Apache 2.4.62 (Rocky Linux)
- **Database**: MariaDB (database name: `vpn`)

## Database Configuration

The database connection is configured in `config/database.php`:
- Host: localhost:3306
- User: vpnuser
- Password: vpn1324
- Database: vpn

## Architecture

### Request Flow

1. **Entry Point** (`index.php`): All HTTP requests are routed through Apache's `.htaccess` rewrite rules to `index.php`
2. **Routing Logic**: `index.php` parses the request path and method, then delegates to appropriate handlers
3. **API Logic** (`api/vpn.php`): Contains the `VpnApi` class with all business logic
4. **Database Layer** (`config/database.php`): Provides PDO-based database connections
5. **Utilities** (`utils/wireguard.php`): WireGuard-specific helper functions (key generation, peer management)

### Database Schema

Three main tables:

1. **vpn_servers**: Stores VPN server information (public_ip, port, server_pubkey)
2. **vpn_keys**: Stores client VPN keys (internal_ip, private_key, public_key, usage status)
3. **vpn_usage_logs**: Tracks allocation/release events for auditing

Key relationship: `vpn_servers` → `vpn_keys` → `vpn_usage_logs` (CASCADE DELETE)

### Key Allocation Strategy

- Keys are allocated using database transactions with `FOR UPDATE` locks to prevent race conditions
- Priority is given to least recently used keys (LRU) with lowest use_count
- Client IP is automatically detected from `X-Forwarded-For`, `X-Real-IP`, or `REMOTE_ADDR` headers

## API Endpoints

### Client Endpoints
- `GET /allocate?ip={public_ip}` - Allocate a VPN key (optionally from specific server)
- `POST /release` - Release a VPN key (body: `{"public_key": "..."}`)
- `GET /status?ip={public_ip}` - View server status and active connections
- `GET /list` - List all available VPN servers

### Server Registration Endpoints
- `POST /server/register` - Register or update a VPN server
- `POST /keys/register` - Bulk register VPN keys for a server

### Maintenance Endpoints
- `GET /release/all?ip={public_ip}` - Release all keys (optionally for specific server)
- `GET /release/all?ip={public_ip}&delete=true` - Delete server and all related data (for server reinstallation)
- `POST /cleanup` - Clean up stale connections (body: `{"minutes": 10}`, default: 10)

### Testing Endpoints
- `GET /` - API information
- `GET /health` - Health check
- `GET /test/db` - Database connection test

## Testing Commands

### Basic Testing
```bash
# Health check
curl http://220.121.120.83/vpn_api/health

# Database connection test
curl http://220.121.120.83/vpn_api/test/db

# List available servers
curl http://220.121.120.83/vpn_api/list

# Allocate a key
curl "http://220.121.120.83/vpn_api/allocate?ip=123.123.123.123"

# Check status
curl "http://220.121.120.83/vpn_api/status?ip=123.123.123.123"

# Release a key
curl -X POST http://220.121.120.83/vpn_api/release \
  -H "Content-Type: application/json" \
  -d '{"public_key": "public_key_value"}'

# Delete server completely (for server reinstallation)
curl "http://220.121.120.83/vpn_api/release/all?ip=123.123.123.123&delete=true"
```

### Database Operations
```bash
# Connect to database
mysql -u vpnuser -pvpn1324 vpn

# Initialize schema
mysql -u vpnuser -pvpn1324 vpn < schema.sql

# Load test data
mysql -u vpnuser -pvpn1324 vpn < test_data.sql
```

## VPN Server Registration Workflow

When a new WireGuard VPN server is installed:

1. Generate JSON file with server info and keys: `./generate_vpn_keys_json.sh [SERVER_IP] [PORT] [START_IP] [END_IP] [OUTPUT_FILE]`
2. Register to API: `./register_vpn_server.sh /root/vpn_keys.json`

This process is documented in `REGISTRATION_GUIDE.md` and `QUICK_START.md`.

## Important Implementation Details

### Transaction Safety
- VPN key allocation uses database transactions with row-level locking (`FOR UPDATE`)
- Always wrap multi-step operations in transactions to prevent inconsistent state

### Client IP Detection
- The `getClientIp()` method checks headers in order: `HTTP_X_FORWARDED_FOR` → `HTTP_X_REAL_IP` → `REMOTE_ADDR`
- This ensures correct IP tracking even behind proxies

### WireGuard Configuration Format
- The allocate endpoint returns a complete WireGuard config in the `config` field
- DNS servers: 1.1.1.1, 8.8.8.8
- PersistentKeepalive: 25 seconds
- AllowedIPs: 0.0.0.0/0 (full tunnel)

### Server Registration Behavior
- If a server with the same `public_ip` and `port` exists, it updates the existing record
- When bulk registering keys, existing keys for that server are deleted first

### Server Deletion Behavior
- Use `GET /release/all?ip=xxx&delete=true` to completely delete a server
- Deletion is CASCADE: removing a server automatically deletes all associated keys and usage logs
- Returns statistics about what was deleted (server info, number of keys, how many were in use)
- Useful when reinstalling a VPN server and need to start fresh
- Without `delete=true`, `/release/all` only releases keys without deleting server data

## File Structure

```
/var/www/html/vpn_api/
├── index.php                    # Main entry point + routing
├── .htaccess                    # Apache URL rewrite rules
├── config/
│   └── database.php            # Database connection class
├── api/
│   └── vpn.php                 # VpnApi class with all business logic
├── utils/
│   └── wireguard.php           # WireGuard utility functions
├── schema.sql                   # Database schema definition
├── test_data.sql               # Test data for development
├── register_vpn_server.sh      # Server registration automation script
├── generate_vpn_keys_json.sh   # JSON generation script for new servers
└── one_line_register.sh        # One-line registration command
```

## Logging

All operations are logged using PHP's `error_log()`. Check Apache error logs:
```bash
tail -f /var/log/httpd/error_log
```

## Migration Notes

This codebase was migrated from Node.js (server.js) to PHP while maintaining all original functionality. The Node.js version may still exist in the repository but is not actively used.
