<?php
/**
 * TCP-based reachability checks. No raw ICMP sockets are used — a plain
 * TCP connect (or even a fast refusal/RST) proves a host is alive without
 * needing root, setuid, capabilities, or any SELinux policy changes.
 */

const NETCHECK_PORTS = [80, 443, 22, 445, 3389];

/**
 * Guesses an OS family from which port answered, when SNMP text isn't
 * available to do better. Best-effort only.
 */
function guess_os_from_port(?int $port, bool $open): string {
    if ($port === null) return 'other';
    if (!$open) return 'other'; // a bare refusal doesn't tell us much about the OS
    if ($port === 445 || $port === 3389) return 'windows';
    if ($port === 22) return 'linux';
    return 'other';
}

/**
 * Attempts a non-blocking TCP connect to every (ip, port) pair and returns,
 * per ip, the first port that responded within $timeoutSec. A completed
 * connection ('open' => true) and a fast refusal ('open' => false, still
 * 'up' => true) both prove the host is alive; only silence counts as down.
 *
 * @param string[] $ips
 * @param int[] $ports
 * @return array<string, array{up: bool, port: ?int, open: bool, ms: ?int}>
 */
function tcp_probe_hosts(array $ips, array $ports, float $timeoutSec): array {
    $results = [];
    foreach ($ips as $ip) $results[$ip] = ['up' => false, 'port' => null, 'open' => false, 'ms' => null];
    if (!$ips || !$ports) return $results;

    $start = microtime(true);
    $sockets = [];
    foreach ($ips as $ip) {
        foreach ($ports as $port) {
            $fp = @stream_socket_client(
                "tcp://$ip:$port", $errno, $errstr, $timeoutSec,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
            );
            if ($fp !== false) {
                $sockets[(int) $fp] = ['ip' => $ip, 'port' => $port, 'fp' => $fp];
            }
        }
    }

    while ($sockets) {
        $remaining = $timeoutSec - (microtime(true) - $start);
        if ($remaining <= 0) break;

        $write = array_column($sockets, 'fp');
        $read = [];
        $except = $write;
        $sec = (int) $remaining;
        $usec = (int) (($remaining - $sec) * 1_000_000);
        $n = @stream_select($read, $write, $except, $sec, $usec);
        if ($n === false || $n === 0) break;

        foreach (array_merge($write, $except) as $fp) {
            $id = (int) $fp;
            if (!isset($sockets[$id])) continue;
            $ip = $sockets[$id]['ip'];
            $port = $sockets[$id]['port'];
            $open = @stream_socket_get_name($fp, true) !== false;

            if (!$results[$ip]['up']) {
                $results[$ip] = [
                    'up' => true,
                    'port' => $port,
                    'open' => $open,
                    'ms' => (int) round((microtime(true) - $start) * 1000),
                ];
            }
            fclose($fp);
            unset($sockets[$id]);
        }
    }

    foreach ($sockets as $s) fclose($s['fp']);

    return $results;
}

/** Convenience wrapper for a single host. */
function tcp_probe_host(string $ip, array $ports, float $timeoutSec): array {
    return tcp_probe_hosts([$ip], $ports, $timeoutSec)[$ip];
}
