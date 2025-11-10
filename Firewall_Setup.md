# pfSense Firewall Setup for AmneziaWG

This guide covers the essential firewall configuration needed for AmneziaWG. Most settings remain at their defaults.

---

## Step 1: Create Network Alias

Navigate to **Firewall > Aliases > Edit**

Configure the following settings (leave others as default):

- **Name**: `AWG_NET`
- **Description**: `Amnezia all networks`
- **Type**: `Network(s)`
- **Network**:
  - `172.16.0.0/12`
  - `10.0.0.0/8`

**Save** and **Apply Changes**

---

## Step 2: Allow DNS Traffic

Navigate to **Firewall > Rules > AmneziaWG > Add**

Configure the following settings (leave others as default):

- **Action**: `Pass`
- **Interface**: `AmneziaWG`
- **Protocol**: `TCP/UDP`
- **Source**: `Any`
- **Destination**: `Any`
- **Destination Port Range**: `DNS (53)` to `DNS (53)`
- **Description**: `Allow DNS`

**Save** and **Apply Changes**

---

## Step 3: Allow Internet Access

Navigate to **Firewall > Rules > AmneziaWG > Add**

Configure the following settings (leave others as default):

- **Action**: `Pass`
- **Interface**: `AmneziaWG`
- **Address Family**: `IPv4+IPv6`
- **Protocol**: `Any`
- **Source**: `AWG_NET`
- **Destination**: `Any`
- **Description**: `Allow WireGuard clients internet access`

**Save** and **Apply Changes**

---

## Step 4: Allow WireGuard Traffic on WAN

Navigate to **Firewall > Rules > WAN > Add**

Configure the following settings (leave others as default):

- **Action**: `Pass`
- **Interface**: `WAN`
- **Protocol**: `TCP/UDP`
- **Source**: `Any`
- **Destination**: `Any`
- **Destination Port Range**: `8081` to `8083` *(adjust ports as needed)*
- **Description**: `Allow AmneziaWireGuard traffic`

**Save** and **Apply Changes**

---

## Step 5: Configure NAT Outbound

Navigate to **Firewall > NAT > Outbound > Add**

Configure the following settings (leave others as default):

- **Interface**: `WAN`
- **Address Family**: `IPv4+IPv6`
- **Protocol**: `Any`
- **Source Type**: `Network or Alias`
- **Source**: `AWG_NET`
- **Destination**: `Any`
- **Translation Address**: `WAN address`
- **Static Port**: `checked`
- **Description**: `Allow Wireguard to go to WAN Interface`

**Save** and **Apply Changes**

---

## Notes

- Port range `8081-8083` in Step 4 is an example. Adjust to match your tunnel listen ports.
- The `AWG_NET` alias covers both common private IP ranges. Add more networks if needed.
- All other settings not mentioned should remain at their default values.

