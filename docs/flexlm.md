# FlexLM licences

The **Licences** tab watches FlexLM (FlexNet Publisher) licence servers: the
`lmgrd` licence servers that hand out licences for engineering and design
software. For each server it shows:

- whether the licence server and its vendor daemons are up
- how many licences of each feature are in use, out of how many
- when each feature's licences expire
- who has each licence checked out, on which computer, and since when
- licence use over time (up to a year), and a history of checkouts with the
  heaviest users

It can alert when a licence server goes down, when every licence of a feature
is in use, and before licences expire.

## Install lmutil

WebMonitor reads licence servers with Flexera's `lmutil` tool
(`lmutil lmstat -a`). lmutil is Flexera's software and WebMonitor can't include
it, so you copy it to the WebMonitor server once.

**Where to get it:** most software that uses FlexLM ships lmutil with its
licence server files, often in a folder named `lnx64`, `linux64` or
`x64_lsb`. Software vendors and Flexera's download site also offer it. Use the
**64-bit Linux** build. One lmutil works with every vendor's licence servers;
a recent version (11.16 or newer) is best.

You don't need to install the LSB packages that lmutil sometimes asks for.
If the LSB loader is missing, WebMonitor starts lmutil through the system's
normal loader instead.

### Rocky Linux install

Copy lmutil to `/usr/local/bin` and make it executable:

```sh
sudo install -m 755 lmutil /usr/local/bin/lmutil
sudo restorecon /usr/local/bin/lmutil
```

`/opt/flexlm/lmutil` works too. To check it runs:

```sh
sudo -u apache /usr/local/bin/lmutil lmstat -a -c 27000@192.168.1.20
```

If it says `required file not found`, that's the missing LSB loader. WebMonitor
works around it, so the Licences tab will still work. To run it by hand, put
the loader first:

```sh
sudo -u apache /lib64/ld-linux-x86-64.so.2 /usr/local/bin/lmutil lmstat -a -c 27000@192.168.1.20
```

### Docker install

Copy lmutil onto the app's data volume. The container makes it executable when
it starts:

```sh
docker compose cp lmutil app:/var/lib/webmonitor/lmutil
docker compose restart app
```

It stays there through image upgrades. Or mount it from the host by adding a
line under the app's `volumes:` in `compose.yaml`:

```yaml
      - ./lmutil:/opt/flexlm/lmutil:ro
```

(`chmod 755 lmutil` on the host first.)

### Where WebMonitor looks

WebMonitor uses the first of these that exists and is executable:

| Path | Usual for |
|---|---|
| `/usr/local/bin/lmutil` | Rocky Linux install |
| `/opt/flexlm/lmutil` | Either, or a Docker mount |
| `/var/lib/webmonitor/lmutil` | Docker (on the data volume) |
| `/var/lib/webmonitor/flexlm/lmutil` | Either |
| `/usr/bin/lmutil` | Installed by a package |

If none is found, the Licences tab says so at the top.

## Add a licence server

On the **Licences** tab, press **Add licence server** (admins only) and enter:

| Field | What to enter |
|---|---|
| Server | `port@host`, as on the `SERVER` line of the licence file: for example `27000@192.168.1.20` or `27000@licsrv`. `@host` alone tries the default ports 27000 to 27009. For a three-server triad, enter all three separated by commas: `27000@192.168.1.21,27000@192.168.1.22,27000@192.168.1.23`. |
| Name | Optional. A name people recognise, such as the software it licenses. |
| Check every | How often to run lmstat. 5 minutes is the default and is enough for most sites. |
| Alert when down for | How long the server must be down before an alert (10 minutes by default). |
| Warn when a feature is … % in use | 100 (the default) warns only when every licence of a feature is in use. Use, say, 90 for an earlier warning, or 0 to turn it off. |
| Warn before licences expire | Days before a feature's expiry date (30 by default; 0 turns it off). |
| Notes | Optional, e.g. who to contact about renewals. |

The first check runs within a minute. Add one entry per licence server (per
`lmgrd`). A machine that runs several licence servers on different ports
needs one entry for each port.

## Reading the tab

**Servers** lists each licence server with its status, licences in use,
number of users and the next expiry date. Click a server's name for its
details: every feature with its licences in use, expiry date and who has it,
a chart of licences in use over time, and the last lmstat output (useful when
something looks wrong).

| Status | Meaning |
|---|---|
| Up | The licence server and its vendor daemons answer |
| Warning | Up, but a triad member or one of several vendor daemons is down, a feature is full, or licences expire soon. The reason is shown under the server. |
| Down | Two checks in a row failed: lmgrd didn't answer, or every vendor daemon is down |
| Unknown | lmutil is missing or can't run on the WebMonitor server |
| Pending | Not checked yet |

**In use now** lists every licence checked out right now, across all servers.
Search by user, computer, feature or server.

**History** shows checkouts over the last day to 180 days, with the users who
had licences checked out the longest. Times come from the checks, so with a
5-minute interval a checkout's length is accurate to about 5 minutes, and a
licence used for less than one interval may not appear at all.

The usage chart shows the licences in use at each check for the last 24 hours
and 7 days, and the highest number in use in each hour for longer periods.
Hourly peaks are kept for 400 days, which helps when deciding how many
licences to renew.

**Check now** asks the background job to check the server. It runs within a
minute, and the page updates when it's done.

## Alerts

Licence alerts go to the same places as every other alert (Settings →
Notifications). They are on by default; turn them all off with **FlexLM
licence servers down, licences all in use, or expiring** under **When to
alert**, or for one server by unticking **Send alerts for this server**.

| Alert | Severity | When |
|---|---|---|
| Licence server down | Critical | Down for longer than the server's "Alert when down for" time |
| Licences in use | Warning | A feature reaches the server's "% in use" level. One alert per feature. |
| Licence expiry | Warning, then critical once expired | Features expire within the warning period. Features that expire on the same day share one alert. |

Alerts clear by themselves when the problem goes away. Pausing or deleting a
server closes its alerts without a "resolved" message.

## Troubleshooting

**"Cannot connect to license server system"**: the WebMonitor server can't
reach lmgrd. Check the port and address, and that the licence server's
firewall allows the WebMonitor server in. lmstat needs the lmgrd port **and**
the vendor daemon's port. The vendor daemon port is random unless the licence
file's `VENDOR` line sets one (`VENDOR mlm port=27010`), so set a fixed port
if there's a firewall in between.

**It works with an IP address but lmstat hangs or fails to reach the vendor
daemon**: lmgrd tells lmutil the licence server's own hostname, and the
WebMonitor server must be able to resolve it. Add the name to DNS, or:

- Rocky Linux install: add a line to `/etc/hosts`, e.g.
  `192.168.1.20  licsrv`
- Docker: add it under the app service in `compose.yaml`:

```yaml
    extra_hosts:
      - "licsrv:192.168.1.20"
```

Then `docker compose up -d` to apply it.

**Unknown: "lmutil could not start"**: the message shows lmutil's own error.
`error while loading shared libraries` names a missing library; install the
package that provides it with `dnf provides '*/libname.so*'`. A 32-bit lmutil
needs `dnf install glibc.i686`; it's simpler to use the 64-bit build.

**Usernames or computers look wrong**: open the server's details and look at
**Last lmstat output**. If the lines under "Users of …" look unusual, send
that output with a bug report.
