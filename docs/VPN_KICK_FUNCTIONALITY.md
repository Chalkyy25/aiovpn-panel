# VPN User Kick Functionality

This documentation describes the VPN user kick functionality that allows administrators to terminate active VPN sessions.

## Overview

The VPN kick functionality provides administrators with the ability to:
- Kick VPN users from their active sessions
- Track session history and audit kicks
- View active sessions for users
- Monitor kick history for compliance

## Components

### Database Tables

#### `vpn_sessions`
Tracks active VPN sessions with the following fields:
- `user_id` - Reference to VPN user
- `session_id` - Unique session identifier
- `ip_address` - Client IP address
- `connected_at` - Session start time
- `disconnected_at` - Session end time
- `kicked_at` - Time when session was kicked (if applicable)
- `kicked_by` - Admin user who kicked the session
- `is_active` - Boolean indicating if session is active

#### `kick_history`
Audit log for kick events:
- `user_id` - VPN user who was kicked
- `kicked_by` - Admin who performed the kick
- `kicked_at` - Timestamp of kick action
- `reason` - Optional reason for the kick

### Models

#### `VpnSession`
- Manages session lifecycle
- Provides relationships to VPN users and admin users
- Includes helper methods for session management

#### `KickHistory`
- Tracks kick audit trail
- Provides filtering and querying capabilities

### Service Layer

#### `VpnSessionService`
Business logic for session management:
- `kickUser($userId, $admin, $reason)` - Kicks user from active sessions
- `getActiveSessions($userId)` - Retrieves active sessions
- `getKickHistory($userId, $limit)` - Gets kick history
- OpenVPN integration for actual session termination

### Controller

#### `VpnSessionController`
API endpoints for session management:
- Protected by admin authentication middleware
- Provides RESTful interface for session operations
- Includes proper error handling and validation

## API Endpoints

### Kick User Sessions
```
POST /api/vpn/sessions/{userId}/kick
```

**Headers:**
- `Authorization: Bearer {token}` (Sanctum token for admin user)

**Body:**
```json
{
    "reason": "Optional reason for kicking user"
}
```

**Response:**
```json
{
    "success": true,
    "message": "Successfully kicked 2 session(s).",
    "data": {
        "kicked_sessions": 2,
        "total_sessions": 2,
        "errors": []
    }
}
```

### Get Active Sessions
```
GET /api/vpn/sessions/{userId}/active
```

**Response:**
```json
{
    "success": true,
    "data": [
        {
            "id": 1,
            "session_id": "abc123",
            "ip_address": "192.168.1.100",
            "connected_at": "2025-08-09T10:00:00Z",
            "is_active": true
        }
    ],
    "count": 1
}
```

### Get Kick History
```
GET /api/vpn/sessions/{userId}/kick-history?limit=50
```

**Response:**
```json
{
    "success": true,
    "data": [
        {
            "id": 1,
            "kicked_at": "2025-08-09T10:30:00Z",
            "reason": "Policy violation",
            "kicked_by_user": {
                "id": 1,
                "email": "admin@example.com"
            }
        }
    ],
    "count": 1,
    "limit": 50
}
```

### Health Check
```
GET /api/vpn/sessions/health
```

**Response:**
```json
{
    "success": true,
    "message": "VPN Session Service is operational",
    "timestamp": "2025-08-09T10:00:00Z",
    "service": "VpnSessionService",
    "version": "1.0.0"
}
```

## Authentication & Authorization

- All endpoints require authentication via Laravel Sanctum
- Only users with `admin` role can access these endpoints
- Role checking is handled by the `RoleMiddleware`

## Error Handling

The API returns appropriate HTTP status codes and error messages:

- `403 Forbidden` - Insufficient permissions
- `404 Not Found` - User or sessions not found
- `422 Unprocessable Entity` - Validation errors
- `500 Internal Server Error` - Server errors

## OpenVPN Integration

The service integrates with OpenVPN servers using:
- OpenVPN management interface (port 7505)
- Remote command execution via SSH
- Process termination as fallback

## Logging

All kick actions are logged with:
- Admin user information
- Target VPN user
- Timestamp and reason
- Success/failure status
- IP address and user agent

## Installation

1. Run the migrations:
```bash
php artisan migrate
```

2. Ensure admin users have the `admin` role set in the database

3. Configure OpenVPN management interface on port 7505

4. Test the endpoints using the health check endpoint

## Security Considerations

- All kick actions are audited in `kick_history` table
- Admin authentication is required for all operations
- Input validation prevents injection attacks
- Rate limiting should be configured for API endpoints
- OpenVPN commands are properly escaped

## Monitoring

Monitor the kick functionality by:
- Checking the health endpoint regularly
- Reviewing kick history for unusual patterns
- Monitoring logs for failed kick attempts
- Alerting on high kick frequencies