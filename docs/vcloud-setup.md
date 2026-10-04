# VMware Cloud Director servers: setup

FreeITSM reads VMs, their NICs and disks, and edge gateways from one or more VMware Cloud Director servers. It only reads: it never changes anything in Director.

You can add several servers. Each is synced on its own schedule, and its data is kept separately.

## What you need

- A vCloud Director user with read access to the organization whose VMs you want.
- The address of the Director portal, for example `https://vcd.example.com`.
- Network access from the FreeITSM server to that address.

## 1. Choose the organization and the user

| Case | Organization | User |
|---|---|---|
| One customer organization | its name, for example `ukrcleaninghouse` | a user with **Organization Administrator**, or a custom read-only role |
| All organizations (provider) | `System` | a provider administrator |

**Organization** is the name Director uses in its login URL, not the display name. You can see it in the URL when you log in as that organization, or in **Administration → Organizations** (the **Name** column).

## 2. Create a read-only role (optional, recommended)

An Organization Administrator can read everything in the organization, but also change it. If you want FreeITSM to read only:

1. In the organization, **Administration → Roles → New role**.
2. Give it the rights to view virtual machines, networks and edge gateways. Do not give it any right to create, change or delete.
3. **Administration → Users → New user**, assign that role, and set a password.

The exact rights differ between Director versions. If the sync reports a refused request, add the right it names.

## 3. Find the API version

| Director version | API version |
|---|---|
| 10.5 | `38.0` |
| 10.3 | `36.2` |

Use the version that matches your Director release. If Test fails with an HTTP error about the request, try the other version from the table.

## 4. Add the server in FreeITSM

1. **Asset management → Settings → VMware Cloud Director servers → Add server**.
2. Fill in:

| Field | Value |
|---|---|
| Name | any name for the server, for example `vcd-prod` |
| Server address | `https://<vcd-host>` |
| Organization | the organization name from step 1 |
| User | the user from step 1 |
| Password | the user's password |
| API version | `38.0`, or the version from step 3 |
| Sync every (minutes) | how often to sync, 5 to 10080 |
| Verify SSL certificate | on for a trusted certificate; off for a self-signed one |
| Active | on |

3. Click **Save**.
4. Click **Test**. A green message names the organization and confirms the VM list is readable.
5. Click **Sync now**, then **Show VMs**. The table lists each VM with its VDC, vApp, status, resources and addresses. Edge gateways are listed below.

## 5. Sync hypervisors

The **Sync hypervisors** button on the Servers page syncs everything at once: vCenter, each active Proxmox server and each active vCloud Director server. Each source reports its own result. One failing source does not stop the others.

## 6. Scheduled sync

The scheduled sync runs `php cron/vcloud_sync.php` from the command line. It syncs every active server whose interval has passed. Run it every few minutes, for example from the host's cron:

```
*/5 * * * * cd /var/www/html && php cron/vcloud_sync.php >> /var/log/freeitsm-vcloud.log 2>&1
```

The script refuses to run over HTTP.

## What a sync does

- Logs in with `user@organization` and its password. The session token comes back in a response header and is used for the rest of the sync.
- Reads the VM list page by page. Each VM is identified by its UUID, so a rename in Director updates the VM rather than creating a duplicate.
- For each VM, reads its NICs (MAC address, network, connected state, IP address) and its disks (name, provisioned size, storage profile).
- Reads the edge gateways and their uplink addresses.
- Removes a VM only after the whole VM list was read. If a page failed, the list is partial and nothing is removed.

**SAFETY GUARD.** If fewer than half of the known VMs are seen in a cycle, nothing is deleted. The usual cause is a lost right or a changed organization, not a real deletion. Fix the cause, then sync again.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| Test: "refused the login (HTTP 401)" | Wrong user or password, or the user belongs to a different organization. |
| Test: "refused the login (HTTP 403)" | The user is locked, or has no right to log in to that organization. |
| Test: "did not return a session" | Wrong address, or a proxy that does not pass the session header. |
| Test: "Logged in, but the VM inventory could not be read" | The API version is wrong for this Director, or the user cannot view VMs. Check the API version first. |
| Test: "Could not reach the vCloud Director server" | Wrong address or port, or a firewall. |
| A VM has no IP address | Its NIC is disconnected, or it has no address yet. Disconnected NICs are shown with their state. |
| "inventory was only partly readable" | One page of the VM list failed. Nothing was removed; sync again. |
| "SAFETY GUARD" warning | Fewer than half of the known VMs were seen. Nothing was deleted. |
| Certificate error with verify on | The certificate is self-signed or from an internal authority. Turn verification off, or install the authority's certificate on the FreeITSM server. |
