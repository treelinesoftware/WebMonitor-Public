# Alerts and services

## Where alerts go

**Settings → Notifications → Send alerts to → Add a destination**:

| Type | Settings |
|---|---|
| Email | SMTP server, port, encryption, login, from and to addresses |
| ntfy | Topic URL (on ntfy.sh or your own server), optional access token. Subscribe to the same topic in the ntfy phone app. |
| Webhook | A URL. Discord and Slack webhook URLs get messages in their own format; any other URL (Teams, Home Assistant, your own) gets JSON with `title`, `text` and `priority`. |

Each destination can be turned off and on with its checkbox, and has a
**Send test** button. Alerts found in the same minute are sent as one message, so an outage
that takes 20 hosts down is one alert, not 20.

## When to alert

**Settings → Notifications → When to alert**:

| Alert | Default |
|---|---|
| Host down | After 3 minutes |
| Agent offline | After 5 minutes |
| Disk space | Warning at 85%, critical at 95% |
| Failed services (agent) | On |
| Antivirus off or out of date (Windows agent) | On |
| Monitored service down, certificate expiring | On |
| Reboots | On (crashes always alert) |
| Switch configuration changed | On |
| High bandwidth (NetFlow) | 50 Mbit/s for 15 minutes, or 50 GB a day |
| Internet speed below a minimum | Set on the Network tab |
| Send "resolved" messages | On |
| Remind about open problems | Off |

**Recent alerts** and the **Delivery log** below show what was raised and sent.

To silence a device for a while (maintenance, say), open its card and use
**Mute**. Muted hosts don't alert.

## Services

The **Services** tab checks things on top of plain up/down. Add a check with
**Add check**, or from a host's card.

| Type | Checks |
|---|---|
| Web | A URL answers with the expected status, optionally contains a keyword, answers within a time limit, and its certificate isn't about to expire |
| Port | A TCP port accepts connections (`host:port`, or a port on the chosen host) |
| DNS | A name resolves, optionally to an expected answer |
| Agent | A systemd unit (Linux) or Windows service is running. These have a **Restart** button. |

Web, port and DNS checks run from the WebMonitor server with no agent. Problems
appear under **Problems** and raise a **Service down** or **Certificate** alert.
