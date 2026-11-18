#!/bin/bash
# Runs WebMonitor's background jobs in the container, on the same timetable as
# the systemd timers of the bare-metal install. A job is skipped while its
# previous run is still going, and each has a time limit.
APP=/var/www/html/webmonitor

#        name       every  at second  time limit  command
JOBS=(  "cron       60     0          55s         php $APP/cli/cron.php"
        "vuln       60     15         12h         nice -n 10 php $APP/cli/vulnscan.php"
        "speedtest  60     30         5m          php $APP/cli/speedtest.php --if-due"
        "snmp       300    30         240s        php $APP/cli/poll-snmp.php" )

run() {
    local name=$1 limit=$2; shift 2
    (
        flock -n 9 || exit 0
        timeout "$limit" runuser -u apache -- "$@" 2>&1 | sed -u "s/^/[$name] /"
    ) 9>"/run/webmonitor/$name.lock" &
}

# Next time after $3 that is a multiple of $1 seconds plus $2.
next_at() { echo $(( ($3 - $2) / $1 * $1 + $2 + $1 )); }

declare -A next
now=$(date +%s)
for j in "${JOBS[@]}"; do
    read -r name every at _ <<<"$j"
    next[$name]=$(next_at "$every" "$at" "$now")
done

while true; do
    sleep 1
    now=$(date +%s)
    for j in "${JOBS[@]}"; do
        read -r name every at limit cmd <<<"$j"
        if [ "$now" -ge "${next[$name]}" ]; then
            # shellcheck disable=SC2086
            run "$name" "$limit" $cmd
            next[$name]=$(next_at "$every" "$at" "$now")
        fi
    done
done
