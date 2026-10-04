# Microsoft Teams channel: Office 365 setup

This guide sets up the FreeITSM bot in a Microsoft 365 tenant, so people can chat with the service desk in Teams. Each message becomes a ticket, just like email, WhatsApp and Telegram.

FreeITSM talks to the **Bot Framework** only. It makes no Microsoft Graph calls, so the bot needs **no Graph permissions**.

## What you need

- Global Administrator (or Application Administrator) on the Microsoft 365 tenant.
- Access to the Azure portal (portal.azure.com) with that tenant.
- Access to the Teams Admin Center (admin.teams.microsoft.com).
- A public **https** address for this FreeITSM install. Teams only delivers to https, and it fetches the images you send from the same host.

## 1. Register the application (Microsoft Entra ID)

1. Azure portal → **Microsoft Entra ID** → **App registrations** → **New registration**.
2. Name: for example `FreeITSM Teams bot`.
3. Supported account types: **Accounts in this organizational directory only (Single tenant)**.
4. Redirect URI: leave empty.
5. Click **Register**.
6. On the overview page, copy:
   - **Application (client) ID**: this is the **Bot App ID** in FreeITSM.
   - **Directory (tenant) ID**: this is the **Tenant ID** in FreeITSM.

## 2. Create a client secret

1. In the app registration, open **Certificates & secrets** → **New client secret**.
2. Choose an expiry. Note the date: when the secret expires, the bot stops working until you add a new one.
3. Copy the secret's **Value** (not its Secret ID) into **App secret** in FreeITSM. Azure shows it only once.

## 3. Create the Azure Bot

1. Azure portal → **Create a resource** → search **Azure Bot** → **Create**.
2. Type of App: **Single Tenant**.
3. Creation type: **Use existing app registration**. Enter the same **App ID** and tenant.
4. Finish creating the resource.
5. Open the resource → **Configuration**:
   - **Messaging endpoint**: the **webhook URL** shown in FreeITSM's channel list (Tickets → Settings → Messaging). It looks like `https://<your-domain>/api/messaging/webhook.php?channel=<id>`.
   - Click **Apply**.
6. Open **Channels** → add **Microsoft Teams** → accept the terms → save.

## 4. Make the Teams app and upload it

Teams needs an app package that points at the bot.

1. Create a folder with three files:
   - `manifest.json` (see the example below),
   - `color.png` (192 × 192 pixels),
   - `outline.png` (32 × 32 pixels, transparent background).
2. In `manifest.json`, set `bots[0].botId` to the **Application (client) ID**. Replace every `PASTE_…` placeholder.
3. Zip the three files **at the root of the zip** (not inside a subfolder).
4. Teams Admin Center → **Teams apps** → **Manage apps** → **Upload new app** → choose the zip.
5. Open the app → **Publishing status** → make sure it is **Published** and **Available to: All**, or to the users who should use it.

Example `manifest.json`:

```json
{
  "$schema": "https://developer.microsoft.com/en-us/json-schemas/teams/v1.17/MicrosoftTeams.schema.json",
  "manifestVersion": "1.17",
  "version": "1.0.0",
  "id": "<APPLICATION-CLIENT-ID>",
  "packageName": "com.example.freeitsm.bot",
  "developer": {
    "name": "Your company",
    "websiteUrl": "https://example.com",
    "privacyUrl": "https://example.com/privacy",
    "termsOfUseUrl": "https://example.com/terms"
  },
  "name": { "short": "Service desk", "full": "Service desk" },
  "description": { "short": "Chat with the service desk", "full": "Chat with the service desk. Each message becomes a ticket." },
  "icons": { "outline": "outline.png", "color": "color.png" },
  "accentColor": "#4F6BED",
  "bots": [
    {
      "botId": "<APPLICATION-CLIENT-ID>",
      "scopes": ["personal"],
      "supportsFiles": false,
      "isNotificationOnly": false
    }
  ],
  "permissions": ["identity"],
  "validDomains": []
}
```

## 5. Permissions

**None.** Do not add any Microsoft Graph permissions to the app registration. The bot receives messages and sends replies through the Bot Framework, which uses the app's client credentials. Adding Graph permissions does nothing for this integration and only widens what the app is allowed to do.

## 6. Add the channel in FreeITSM

1. Tickets → Settings → Messaging → **Add channel**.
2. Provider: **Microsoft Teams**.
3. Fill in **Bot App ID**, **Tenant ID** and **App secret** from steps 1 and 2.
4. Save the channel, then copy the **webhook URL** from the channel list into the Azure Bot's Messaging endpoint (step 3) if you have not already.
5. Click **Test connection**. A green result means FreeITSM got a token from Microsoft for this app.

## 7. Try it

1. In Teams, open the bot (search for its name under **Chat**) and send a message.
2. A ticket appears in FreeITSM with the **Teams** origin.
3. Reply from the ticket's composer. The reply arrives in the same Teams chat.

## Images

An image you attach to a Teams ticket is sent as an attachment with a link to `/api/messaging/media.php`. Microsoft fetches that link itself, so:

- the address must be https and reachable from the internet, with no login in front of it,
- a reverse proxy must not block Microsoft's address ranges (in nginx, do not limit the path with `allow`/`deny` to the office network).

Other files (documents, videos) cannot be sent to Teams from FreeITSM yet. The attachment button shows a clear message instead of failing silently.

## Limits

- **One-to-one chats only.** Group chats and channels are ignored, because one conversation is shared by many people.
- **No first contact.** The bot can only answer people who have already written to it. It cannot start a chat with a user.
- **No phone matching.** Teams does not share a person's phone number, so a new chat becomes a new contact.
- **No read receipts.** A message counts as sent when Teams accepts it, not when it is read.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| Test connection: "Microsoft rejected the Teams credentials" with `AADSTS700016` | The tenant ID is wrong, or the app is not in that tenant. Use the Directory (tenant) ID of the same tenant that holds the app. |
| Test connection: `invalid_client` | The secret is wrong or expired. Create a new client secret and paste its Value. |
| The bot does not answer in Teams | Check that the Messaging endpoint equals the webhook URL, and that the Microsoft Teams channel is enabled on the Azure Bot. |
| Messages arrive but are rejected with 403 | The App ID in FreeITSM does not match the Azure Bot's App ID. |
| Images show as broken | The image URL is not reachable from the internet, or the proxy blocks Microsoft. |
| Users cannot find the app | The app is not published or not available to their users (Teams Admin Center → Manage apps). |
