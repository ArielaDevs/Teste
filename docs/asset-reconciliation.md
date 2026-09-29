# Asset Reconciliation & Stable Device Identity

This document describes the device reconciliation architecture in FreeITSM, which governs how incoming hardware reports from discovery connectors (such as **Microsoft Intune**, the **PowerShell inventory agent**, and future discovery integrations) match against existing records in the asset register.

---

## 1. Problem Statement

Historically, automated device sync relied on `hostname` as the primary lookup key. In production IT environments, hostnames are mutable identifiers:
- Machines are renamed to match organizational naming conventions (e.g. `DESKTOP-ABC1234` &rarr; `LON-LT-042`).
- Devices are reassigned between departments, users, or offices.
- Multi-user staging or reprovisioning frequently renames computers while retaining hardware identity.

Under strict hostname matching, any rename operation caused incoming sync jobs to lose the link to the existing asset record, generating duplicate stub assets, fragmenting hardware audit logs, and breaking custody/assignment history.

---

## 2. Service Architecture

Reconciliation logic is centralized in `AssetsService` (`includes/services/assets.php`), structured into single-responsibility operations:
- `resolveAssetIdentity()`: Pure, side-effect-free identifier evaluation without database mutations.
- `updateAssetHostname()`: Atomic rename operation with collision guard and audit logging.
- `reconcileAsset()`: Orchestrates resolution and conditional mutation, returning explicit status flags (`asset_id`, `matched_by`, `ambiguous`, `hostname_updated`, `hostname_conflict`).

---

## 3. Connector-Independent Reconciliation Hierarchy

Incoming device payloads evaluate through 4 tiers in order, strictly scoped to the target company / tenant context:

```
+--------------------------------------------------------------------+
| Tier 1: Explicit Authoritative Connector Link                      |
| (intune_devices.asset_id already populated)                        |
+---------------------------------+----------------------------------+
                                  | No match
                                  v
+--------------------------------------------------------------------+
| Tier 2: Clean, Non-Generic Hardware Serial Number / Service Tag    |
| (assets.service_tag with ambiguity guard, scoped to company)       |
+---------------------------------+----------------------------------+
                                  | No match / Ambiguous / Blacklisted
                                  v
+--------------------------------------------------------------------+
| Tier 3: Hostname Fallback                                          |
| (assets.hostname, scoped to company)                               |
+---------------------------------+----------------------------------+
                                  | No match
                                  v
+--------------------------------------------------------------------+
| Tier 4: Genuine New Device                                         |
| (Create new asset stub row)                                        |
+------------------------------------+-------------------------------+
```

### Tier 1: Explicit Authoritative Connector Link
If an existing relationship was established by an authoritative integration (e.g. an existing `intune_devices` record linked to an `asset_id`), that link is preserved as the primary source of truth regardless of downstream hostname changes.

### Tier 2: Clean Hardware Serial / Service Tag
If no explicit link exists, FreeITSM matches against `assets.service_tag` (indexed via composite index `idx_assets_tenant_service_tag (tenant_id, service_tag)`).
- **Sanitization:** Whitespace is trimmed and casing is normalized.
- **Generic Serial Filter:** Configured generic/placeholder values (see §5) are rejected.
- **Ambiguity Guard:** If more than one asset within the same company shares the same serial number (e.g. legacy dirty data), the engine refuses to guess blindly. It flags the match as ambiguous and safely falls through to Tier 3.

### Tier 3: Hostname Fallback
If the device does not report a usable serial number, or if the serial was flagged as ambiguous/generic, FreeITSM falls back to matching by `hostname` within the same company context.

### Tier 4: New Asset Creation
If no existing asset matches through Tiers 1–3, the incoming payload is classified as a genuine new device. The calling process inserts a new row in `assets` stamped with `first_seen`, `last_seen`, and the target `tenant_id`.

---

## 4. Automated Hostname Mutation & Collision Protection

When an existing asset is matched via **Tier 1** (explicit link) or **Tier 2** (hardware serial number), and the incoming payload reports a different hostname:

1. **Collision Guard:** The engine queries for any other active asset in the same tenant already using the target hostname:
   ```sql
   SELECT id FROM assets WHERE hostname = ? AND tenant_id <=> ? AND id <> ? LIMIT 1
   ```
   If a collision exists, the hostname update is skipped and `hostname_conflict = true` is returned to prevent overwriting or data corruption.

2. **Atomic Mutation & Audit:** If no collision exists, the hostname update and audit log entry execute inside an atomic database transaction:
   ```sql
   UPDATE assets SET hostname = ?, last_seen = UTC_TIMESTAMP() WHERE id = ?;
   INSERT INTO asset_history (asset_id, analyst_id, field_name, old_value, new_value, created_datetime)
   VALUES (?, NULL, 'hostname', ?, ?, UTC_TIMESTAMP());
   ```
   If either operation fails, the transaction is rolled back completely. The audit record is stamped with `analyst_id = NULL` to denote system automation.

---

## 5. Configurable Ignored Serial Numbers

Certain virtual machines, white-box appliances, and OEM motherboards report generic placeholder strings instead of unique hardware serials.

FreeITSM maintains an administrator-configurable blocklist of ignored serial numbers stored in `system_settings` (`asset_reconciliation_ignored_serials`).

### Default Built-in Values
- `TO BE FILLED BY O.E.M.`
- `DEFAULT STRING`
- `NONE`
- `SYSTEM SERIAL NUMBER`
- `NOT SPECIFIED`
- `123456789`

### Configuration
Configured under **Assets &rarr; Settings &rarr; Discovery & reconciliation &rarr; Ignored Serial Numbers / Service Tags**.
- Case-insensitive comparison.
- One entry per line. Leading/trailing whitespace is stripped automatically.
- Consumed by all automated hardware discovery paths (Intune sync, PowerShell agent ingestion, and future discovery connectors).

---

## 6. Multi-Tenancy & Connector-to-Company Scoping

All database lookups enforce company isolation using MySQL's NULL-safe comparison operator (`tenant_id <=> ?`):
- **PowerShell Agent:** Scoped to the company assigned to the submitting API key.
- **Microsoft Intune:** Scoped to the company configured under **Assets &rarr; Settings &rarr; Intune &rarr; Company / tenant scope** (`intune_company_id`).
- A hardware serial matching an asset in Company A will never bind or mutate an asset in Company B.

---

## 7. Integration Endpoints

The reconciliation engine is invoked by:
- **Intune Device Sync:** `includes/intune.php` &rarr; `intuneLinkDevicesToAssets()`
- **PowerShell Agent Hardware Submission:** `api/external/system-info/submit/index.php`
