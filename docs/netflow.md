# NetFlow

With NetFlow, WebMonitor shows who is using the bandwidth: top talkers,
per-device traffic, services and conversations (**Reports → Traffic**), and what
is using each switch port (**Network → a switch → a port**). It can also alert
on high bandwidth.

Switches and routers send flow records to WebMonitor on **UDP 2055**. NetFlow
v5, v9 and IPFIX are supported. sFlow is not.

## Where to export from

- **The core switch or router** sees everything that crosses it, including all
  internet traffic. Start there.
- **Access switches** also see traffic that stays on them (two devices on the
  same switch talking to each other), which the core never sees.

Exporting from several switches is fine. When a flow crosses two switches that
both export it, WebMonitor counts it once in the traffic totals, and still shows
it on each switch's ports.

For exact per-port data, include the **input interface** in the flow record.
Without it, WebMonitor matches flows to ports by IP address through the port map.

## Cisco Catalyst 9000 / 3850 / 3650 (IOS-XE)

```
flow record WM-RECORD
 match ipv4 protocol
 match ipv4 source address
 match ipv4 destination address
 match transport source-port
 match transport destination-port
 match interface input
 collect interface output
 collect counter bytes long
 collect counter packets long
!
flow exporter WM-EXPORTER
 destination <WebMonitor server IP>
 source <management interface, e.g. Vlan10>
 transport udp 2055
 template data timeout 60
!
flow monitor WM-MONITOR
 exporter WM-EXPORTER
 record WM-RECORD
 cache timeout active 60
!
interface range GigabitEthernet1/0/1 - 48
 ip flow monitor WM-MONITOR input
```

Apply the monitor to the access ports (or the VLAN interfaces). Leave out the
uplink to another switch that also exports, so its traffic isn't recorded twice.
If the switch rejects `collect interface output`, leave that line out.

## Cisco Catalyst 2960-X (IOS 15.2, LAN Base)

Same as above, but leave out `collect interface output`, and use the physical
ports (the 2960-X has no flow monitoring on VLAN interfaces). Check that your
image supports it with `flow record ?` in configuration mode. Some images use
`collect counter bytes` without `long`.

## Routers and firewalls

- **pfSense / OPNsense:** install softflowd (pfSense) or turn on NetFlow under
  Reporting (OPNsense), version 9, destination `<server>:2055`, on the LAN interfaces.
- **MikroTik:** `/ip traffic-flow set enabled=yes` and
  `/ip traffic-flow target add dst-address=<server> port=2055 version=9`.
- **Others:** send NetFlow v5/v9 or IPFIX to `<server>:2055`.

## Check it's working

**Reports → Traffic** shows the collector's state: when the last flow arrived and
from which addresses. Data appears within 2 minutes.

If nothing arrives:

- check the exporter's destination and that UDP 2055 is open on the server's
  firewall (the Rocky Linux installer opens it);
- with Docker, use host networking (`compose.yaml`) so flows arrive from the
  switch's own address;
- if `WEBMON_FLOW_EXPORTERS` (Docker) or `WM_FLOW_EXPORTERS` is set, the
  switch's address must be in it.

## Load

A switch exports one small batch of records per minute per active flow: usually
a few kbit/s. The server keeps per-minute summaries, not every flow.

## Bandwidth alerts

**Settings → Notifications → When to alert**: alert when a device averages more
than a set Mbit/s over a number of minutes, or uses more than a set number of GB
in a day. By default only internet traffic counts. To exempt a device (a backup
server, say), open its card → **Edit** → **Expected high traffic**.
