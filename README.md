# AmneziaWG - Amnezia WireGuard Package for pfSense

AmneziaWG is a pfSense package that integrates the [Amnezia VPN](https://amnezia.org) stack, offering a simplified interface and automation for managing Amnezia WireGuard VPN tunnels directly within the pfSense UI.

---

## Overview

This project provides:
- Seamless installation of Amnezia-based WireGuard on pfSense
- Configuration UI for Amnezia WireGuard tunnels and peers
- Automatic tunnel configuration generation
- API for remote management (e.g., via MDM)

It is designed for teams or individuals deploying secure VPN services through pfSense, with a strong focus on simplicity and automation.

---


## pfSense Compatibility

| Version        | Status                     |
|----------------|----------------------------|
| pfSense 2.7.2  | ✅ Fully Tested             |
| pfSense Plus   | ⚠️ Untested (likely works) |

> 💡 Always use the latest stable version of pfSense for best compatibility and security.

---

## Installation

### Prerequisites

- A running pfSense instance
- Administrator access to the pfSense Web UI
- Internet connectivity on the pfSense box (for pulling updates)

### CLI Installation

1. Download the CI-produced artifact, e.g.:
   ```
   pfSense-pkg-Amneziawireguard-1.0.0.pkg
   ```

2. Copy the package to your pfSense box via SSH:
   ```bash
   scp pfSense-pkg-Amneziawireguard-1.0.0.pkg admin@<pfsense-ip>:/root/
   ```

3. SSH into pfSense and run:
   ```bash
   pkg add /root/pfSense-pkg-Amneziawireguard-1.0.0.pkg
   ```

4. Once installed, log into the pfSense Web UI to begin configuration.

---

## Using the GUI

After installation, navigate to:

```
pfSense Web UI → VPN → AmneziaWG
```

Here you'll find tabs to manage Tunnels, Peers, and the API.

### Interface Preview

**Main Menu Entry:**

![AmneziaWG Menu](README/01.png)

**Tunnel and Peer Management:**

![Tunnel & Peers](README/02.png)

**API Settings:**

- Enable the API by checking the box
- Generate an API key for authentication
- Refer to the full API documentation for usage details

![API Setup](README/03.png)

Here is the [Link to the API Documentation](https://gitlab.qtsoftwareltd.com/qtphone/amneziawg/-/blob/main/API_Documentation.md) 

