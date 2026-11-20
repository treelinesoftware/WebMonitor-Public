# WebMonitor Pro

WebMonitor is free and open source. **WebMonitor Pro** is an add-on with
features aimed at businesses. Without it, those features don't appear and
everything else works the same.

| Feature | What it adds |
|---|---|
| Change management and audit log | A change record for every update window and manual update, reboot, agent upgrade or service restart; change emails; optional approval; a tamper-evident audit log of everything changed in WebMonitor ([details](agents-and-updates.md#change-management-and-audit-log)) |
| Remote access | Remote buttons on host cards: Remote Desktop, SSH, VNC and the device's web page |
| Vulnerability scanning | The Security tab: scheduled nmap / CVE scans and a security report ([details](devices-and-security.md)) |
| Licence-server monitoring | The Licences tab: FlexLM servers, licences in use, expiry and alerts ([details](flexlm.md)) |
| Update schedules by group | Update windows that target card groups |

## Unlock it

Pro is installed with the Pro edition of the install or upgrade script.
Then, as an admin, open **Settings → WebMonitor Pro** and enter the Pro
password you received. The same page switches each Pro feature on or off;
switching one off hides its tabs and settings and keeps its data. **Lock Pro**
hides them all again until the password is entered.

Get WebMonitor Pro from [treelinesoftware.ca](https://treelinesoftware.ca).

## Remote access

Turn it on under **Settings → WebMonitor Pro**, then set it up in
**Settings → Remote access**:

- **Methods**: which kinds of remote button exist at all.
- **Who can use it**: admins only, or everyone who can sign in.
- **Default user names** for Remote Desktop and SSH (passwords are never
  stored).

Each host card then has a **Remote** button with the methods that suit it:

| Method | Shown for | What happens |
|---|---|---|
| Remote Desktop | Windows, Linux | Downloads a `.rdp` file; open it with Remote Desktop (Windows) or Windows App (macOS). Linux hosts need xrdp running |
| SSH | Linux, macOS, routers, switches, NAS | Opens `ssh://` in your terminal app; **Copy command** gives the `ssh` command |
| VNC / Screen Sharing | macOS | Opens `vnc://` (macOS Screen Sharing or a VNC viewer) |
| Web page | Routers, switches, access points, printers, cameras, NAS, UPS, IoT | Opens `https://<address>/` in a new tab |

A host's agent decides whether it's Windows or Linux; otherwise its OS in
**Edit** does. In a host's **Edit**, **Remote access** turns methods on or off
for that host and sets its own port, user name or web address.

Connections go straight from your computer to the device, so your computer
needs to reach it (same network or VPN) and have the app. Nothing passes
through the WebMonitor server. Each launch is written to the audit log when
change management is on.
