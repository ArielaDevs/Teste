# Mattermost channel: setup

This guide connects a Mattermost **support channel** to FreeITSM. Customers post in the channel, and each post opens a ticket. Replies from analysts go to the customer as a **direct message** from the bot.

## What you need

- A **system admin** on the Mattermost server (to enable bot accounts and outgoing webhooks).
- A **support channel** for customers to write in. The bot must be a member of it.
- A public **https** address for this FreeITSM install. Mattermost calls the webhook there.

## 1. Enable bot accounts and outgoing webhooks

1. **System Console → Integrations → Bot Accounts**: set **Enable Bot Account Creation** to on.
2. **System Console → Integrations → Outgoing Webhooks**: set **Enable Outgoing Webhooks** to on.
3. Mattermost may restrict webhook addresses. If the callback URL is rejected, allow the FreeITSM host under **Allow untrusted internal connections** (only if the server is internal) or add it to the allowed domains.

## 2. Create the bot account

1. **Integrations → Bot Accounts → Add Bot Account**.
2. Username: for example `servicedesk`. Display name: "Service desk".
3. Permissions: **Post All** and **Read**.
4. Create the bot, then copy its **access token**. Mattermost shows it only once.
5. Add the bot to the support channel (**Members → Add**).

## 3. Note the support channel and server

1. Open the support channel → **channel name → View Info**. Copy the **ID** (26 letters and digits). This is the **Support channel ID** in FreeITSM.
2. The server address (for example `https://mattermost.example.com`) is the **Server address** in FreeITSM.

## 4. Create the outgoing webhook

1. **Integrations → Outgoing Webhooks → Add Outgoing Webhook**.
2. **Channel**: the support channel.
3. **Trigger Words**: leave empty, so every post in the channel is sent.
4. **Callback URLs**: the **webhook URL** shown in FreeITSM's channel list (Tickets → Settings → Messaging). It looks like `https://<your-domain>/api/messaging/webhook.php?channel=<id>`.
5. **Content Type**: `application/x-www-form-urlencoded` (the default).
6. Save. Mattermost shows the webhook's **token**. Copy it.

## 5. Add the channel in FreeITSM

1. Tickets → Settings → Messaging → **Add channel**.
2. Provider: **Mattermost**.
3. Fill in:
   - **Server address**: from step 3,
   - **Support channel ID**: from step 3,
   - **Bot access token**: from step 2,
   - **Outgoing webhook token**: from step 4.
4. Save, then click **Test connection**. A green result means FreeITSM reached the server with the bot token.

## 6. Try it

1. In the support channel, post a message as a customer.
2. A ticket appears in FreeITSM with the **Mattermost** origin.
3. Reply from the ticket's composer. The customer gets the reply as a direct message from the bot.

## Attachments

FreeITSM uploads a file to Mattermost and attaches it to the direct message. Any file type works, unlike Teams.

Customers' files in the support channel are **not** downloaded: Mattermost's outgoing webhook does not include them. The ticket keeps the text and says nothing about the file.

## Limits

- **One support channel per configuration.** Use another channel entry for another team.
- **Replies are direct messages**, not in the thread the customer wrote in. Mattermost threads for a bot reply need the thread id from each post, which the outgoing webhook does not give consistently.
- **Threads are per customer**: all of a customer's posts go to one open ticket until it is closed. A second, unrelated question while the first ticket is open lands on that ticket.
- **Inbound files are not downloaded** (see above).
- **No read receipts** for bot messages.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| Test connection: "Mattermost rejected the request" with 401 | The bot access token is wrong or was revoked. Create a new one under Bot Accounts. |
| Test connection: "Mattermost rejected the request" with 404 | The server address is wrong, or the path is behind a proxy that rewrites `/api/v4`. |
| Posts in the channel do not create tickets | The outgoing webhook's channel is not the support channel, its callback URL is wrong, or the webhook token in FreeITSM does not match. |
| Webhook returns 403 | The outgoing webhook token in FreeITSM is not the one Mattermost shows for this webhook. |
| Replies never arrive | The customer has not been reached yet, or the bot cannot open a direct message with them (check the bot's permissions and whether the user is deactivated). |
| Callback URL rejected by Mattermost | Allow the host under **Allow untrusted internal connections** or the allowed domains list (System Console → Environment). |
