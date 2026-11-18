# Network tab

The Network tab has two parts: internet speed tests, and a live map of your
switches with the traffic on every link.

## Internet speed tests

Tiles show the latest download, upload, ping and jitter, with a chart of recent
tests underneath. **Run now** starts one within a minute (admins).

**Schedule…** on the speed test panel (admins):

| Setting | What it does |
|---|---|
| Schedule | Every 15, 30 minutes, 1, 2, 3 (default), 4, 6, 12 or 24 hours, or off |
| Engine | **Built-in** (speed.cloudflare.com, nothing to install) or **Ookla Speedtest CLI** if it's installed on the server |
| Alert when the last two tests are slower than | Download and upload minimums in Mbit/s |
| Keep results for | How many days of results to keep |

Each test downloads and uploads for about 8 seconds each way, so the data it
uses grows with your speed: about 100 MB at 100 Mbit/s, about 1 GB at 1 Gbit/s.
On a metered or capped connection, pick a longer interval.

To use Ookla's CLI on a Rocky Linux install, install the `speedtest` package
from Ookla's repository (see speedtest.net/apps/cli) so it's at
`/usr/bin/speedtest`, then pick it in the settings. The Docker image only has the
built-in test.

## The network map

The map shows the internet, your router, switches and access points, linked the
way they're cabled (from CDP/LLDP and MAC tables). Each link is sized and
coloured by the traffic on it right now, and animates in the direction the
traffic flows.

- Drag to pan, scroll or pinch to zoom.
- **Show devices** adds every device on each switch port.
- Click a switch for its panel.

Live traffic is read over SNMP every few seconds while the tab is open, and once
a minute in the background for history. It needs SNMP on each switch and router
(see [Switches and SNMP](switches-and-snmp.md)).

## A switch's panel

- **Faceplate:** every port, coloured by status and load.
- **Ports:** a live table of each port's traffic in and out, speed, VLAN and
  what's connected.
- Click a port for its **traffic chart**: live, or history over 24 hours to a year.
- **What's using it:** with [NetFlow](netflow.md), the devices, services and
  conversations on that port. The panel says whether the numbers are exact
  (the switch reports which port each flow came in on) or matched by IP address.
- **NetFlow from this switch:** what the switch is exporting, per interface,
  with a configuration snippet if it isn't set up for per-port data yet.

## Network load

The map and per-port tables read each switch's interface counters: a few small
SNMP requests every few seconds, only while someone has the tab open.
Background history is one read per switch per minute. Neither is noticeable on
a switch.
