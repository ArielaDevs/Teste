# Proxmox VE servers: setup

FreeITSM reads virtual machines, containers, nodes, IP addresses and MAC addresses from one or more Proxmox VE servers. It only reads: it never changes anything in Proxmox.

You can add several servers. Each one is synced on its own schedule, and its VMs are kept separately from the others.

## What you need

- Access to the Proxmox web UI with a user that can manage permissions (for example `root@pam`). You need it once, for the setup.
- The address of a Proxmox node, normally `https://<host>:8006`.
- Network access from the FreeITSM server to port 8006 on that node.

## 1. Create a user for FreeITSM

1. Proxmox web UI → **Datacenter → Permissions → Users → Add**.
2. **User name**: `freeitsm`.
3. **Realm**: **Proxmox VE authentication server** (`pve`). Pick `pve`, not `pam`, so the user is not a Linux login.
4. **Password**: set one, or leave it for a token-only user. FreeITSM will use an API token, not this password.
5. **Expire**: `never` (or a date that suits you). **Enabled**: on.
6. Click **Add**.

## 2. Create a read-only role

1. **Datacenter → Permissions → Roles → Create**.
2. **Name**: `FreeITSMSync`.
3. Tick these privileges:

| Privilege | Why |
|---|---|
| `Sys.Audit` | reads the cluster and node list |
| `VM.Audit` | reads VM and container configuration |
| `VM.Monitor` | reads IP addresses through the QEMU guest agent |
| `Datastore.Audit` | reads storage, needed for disk information |

Do not give the role any write privilege. FreeITSM does not need one.

If you skip `VM.Monitor`, the sync still finds every VM and container, and their MAC addresses, but not the IP addresses of KVM VMs.

## 3. Give the role to the user

1. **Datacenter → Permissions → Add → User Permission**.
2. **Path**: `/`. This covers every node and VM.
3. **User**: `freeitsm@pve`.
4. **Role**: `FreeITSMSync`.
5. **Propagate**: on.
6. Click **Add**.

## 4. Create an API token

An API token is the recommended way to log in. It has its own secret and can be revoked without changing the user's password.

1. **Datacenter → Permissions → API Tokens → Add**.
2. **User**: `freeitsm@pve`.
3. **Token ID**: `itsm`. Use letters, digits, `-` or `_`.
4. **Privilege Separation**:
   - **Untick it** (simplest): the token gets the user's permissions, which are the role from step 3.
   - **Tick it**: the token gets no permissions by itself. Give the role to the token too: **Permissions → Add → API Token Permission**, path `/`, token `freeitsm@pve!itsm`, role `FreeITSMSync`.
5. Click **Add**.
6. Proxmox shows the **secret** once, as a UUID such as `1a2b3c4d-5e6f-7a8b-9c0d-1e2f3a4b5c6d`. Copy it now. If you lose it, delete the token and create a new one.

The token has two parts:

- **Token ID**: `freeitsm@pve!itsm`. This goes in the **User or API token** field of FreeITSM.
- **Secret**: the UUID. This goes in the **Password** field of FreeITSM.

## 5. Add the server in FreeITSM

1. **Asset management → Settings → Proxmox VE servers → Add server**.
2. Fill in:

| Field | Value |
|---|---|
| Name | any name for the server, for example `pve-lab` |
| Server address | `https://<host>:8006`, with the scheme and port |
| User or API token | `freeitsm@pve!itsm` (or a user such as `freeitsm@pve` if you use a password) |
| Password | the token secret (or the user's password) |
| Sync every (minutes) | how often to sync, 5 to 10080 |
| Verify SSL certificate | on for a certificate from a trusted authority; off for Proxmox's default self-signed certificate |
| Active | on |

3. Click **Save**.
4. Click **Test**. A green message means FreeITSM logged in and read the node list.
5. Click **Sync now**. The status column then shows the result, for example `12 VMs and containers, 3 node(s)`.
6. Click **Show VMs** to see what was found.

## 6. Guest IP addresses

- **KVM VMs**: Proxmox knows an address only through the **QEMU Guest Agent**. Turn it on in the VM's **Options → QEMU Guest Agent**, and install `qemu-guest-agent` inside the guest (for example `apt install qemu-guest-agent` on Debian or Ubuntu). Reboot the VM after enabling it.
- **LXC containers**: no agent is needed. The address comes from the container's network configuration. A container set to DHCP shows no address, because Proxmox does not know its lease.

## 7. Scheduled sync

The scheduled sync runs `php cron/proxmox_sync.php` from the command line. It syncs every active server whose interval has passed. Run it every few minutes, for example from the host's cron:

```
*/5 * * * * cd /var/www/html && php cron/proxmox_sync.php >> /var/log/freeitsm-proxmox.log 2>&1
```

The script refuses to run over HTTP, so it cannot be triggered from the web.

## What a sync does

- Reads the cluster and each node, and each VM and container on the nodes that are online.
- Identifies a VM by its server and its VMID. A rename in Proxmox updates the VM; it never creates a duplicate.
- Records the provisioned CPU, memory and disk sizes from the configuration, not live usage.
- Removes a VM only when its node answered this cycle and the VM is really gone.

**SAFETY GUARD.** If fewer than half of the known VMs are seen in a cycle, nothing is deleted. The usual cause is a node that did not answer or a permission problem, not a real mass deletion. Fix the cause, then sync again.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| Test: "Proxmox refused this request (HTTP 401)" | Wrong token ID or secret, or a wrong realm. The realm is `pve`, not `pam`. |
| Test: "Proxmox refused this request (HTTP 403)" | The user or token has no permissions. Check step 3, and step 4 if privilege separation is on. |
| Test: "Logged in, but the node list could not be read" | The user lacks `Sys.Audit` on `/`. |
| Test: "Could not reach the Proxmox server" | Wrong address or port, or a firewall between FreeITSM and the node. |
| Test: "The server address must start with https://" | The address is missing its scheme. |
| A VM is listed without IP addresses | KVM: the guest agent is not running, or `VM.Monitor` is missing. LXC: the container uses DHCP. |
| A node is "not reachable this cycle" | The node is offline, or its API did not answer. Its VMs are kept. |
| "SAFETY GUARD" warning | Fewer than half of the known VMs were seen. Nothing was deleted. Check the nodes and the user's permissions. |
| Certificate error with verify on | The certificate is self-signed or from an internal CA. Turn verification off, or install the CA certificate on the FreeITSM server. |
