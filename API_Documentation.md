
# Amnezia WireGuard API Documentation

This document describes the available API endpoints for the Amnezia WireGuard pfSense Package integration on pfSense. This API enables programmatic management of VPN tunnels and peers through authenticated requests.

## Authentication

All requests must include the following header for authentication:

```
X-API-KEY: your_api_key_here
```

You can generate and manage API keys from the section in the pfSense GUI under the **API** tab.

## Base URL

The base URL for all endpoints is configurable. By default:

Example:

```
http://172.16.13.1/awg/api
```

Use `{{base_url}}` as a variable placeholder in environments like Postman.

## Endpoints

### 1. Get Peers

- **Endpoint**: `POST {{base_url}}/`
- **Purpose**: Retrieve the list of all WireGuard peers.
- **Request Body**:

```json
{
  "act": "get_peers"
}
```

- **Response**: JSON object containing all configured peers.

### 2. Get Tunnels

- **Endpoint**: `POST {{base_url}}/`
- **Purpose**: Retrieve all configured WireGuard tunnels.
- **Request Body**:

```json
{
  "act": "get_tunnels"
}
```

- **Response**: JSON object containing all tunnel configurations.

### 3. Sync Peers

- **Endpoint**: `POST {{base_url}}/`
- **Purpose**: Sync a list of peers with a specified tunnel. Useful for bulk updating peers.
- **Request Body**:

```json
{
  "act": "sync_peers",
  "peers": [
    {
      "public_key": "...",
      "description": "...",
      "update_date": "YYYY-MM-DD",
      "allowed_ips": ["X.X.X.X/32"]
    }
  ],
  "tunnel": "tun_awg0"
}
```

- **Response**: Status of the synchronization.

### 4. Reload Tunnel

- **Endpoint**: `POST {{base_url}}/`
- **Purpose**: Reload a specific WireGuard tunnel interface.
- **Request Body**:

```json
{
  "act": "reload",
  "interface": "tun_awg0"
}
```

- **Response**: Reload confirmation message.

## Environment Variables

To simplify requests in environments such as Postman, define the following variables:

| Key       | Description              | Default Value                      |
|-----------|--------------------------|------------------------------------|
| base_url  | Base URL for the API     | http://xxx.xxx.xxx.xxx/awg/api     |
| api_key   | API key for authentication | your_api_key_here                 |

## Tips

- Always verify the API key and permissions before issuing requests.
- For bulk peer syncing, ensure the `public_key` is unique per peer.
- Ensure `tun_awg0` (or another specified tunnel) exists before reloading.
