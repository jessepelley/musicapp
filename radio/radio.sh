#!/bin/bash
# ─── Shuffle Radio — Management Script ────────────────────────────────────────
# Start, stop, restart, and check status of Icecast + Liquidsoap.
#
# Usage:
#   ./radio.sh start | stop | restart | status
#
# Designed for Synology NAS. Adjust paths below for your environment.
# Can be added to Synology Task Scheduler for auto-start on boot.
# ──────────────────────────────────────────────────────────────────────────────

# ─── Configuration ────────────────────────────────────────────────────────────

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ICECAST_CONF="${SCRIPT_DIR}/icecast.xml"
LIQUIDSOAP_CONF="${SCRIPT_DIR}/radio.liq"

# PID files
PID_DIR="/var/run"
ICECAST_PID="${PID_DIR}/icecast.pid"
LIQUIDSOAP_PID="${PID_DIR}/liquidsoap.pid"

# Log directories (created if missing)
ICECAST_LOG_DIR="/var/log/icecast2"
LIQUIDSOAP_LOG_DIR="/var/log/liquidsoap"

# Binaries — adjust if installed elsewhere (e.g., Docker, /usr/local, entware)
ICECAST_BIN="icecast2"
LIQUIDSOAP_BIN="liquidsoap"

# ─── Functions ────────────────────────────────────────────────────────────────

ensure_dirs() {
    mkdir -p "$ICECAST_LOG_DIR" "$LIQUIDSOAP_LOG_DIR" "$PID_DIR" 2>/dev/null
}

is_running() {
    local pidfile="$1"
    if [ -f "$pidfile" ]; then
        local pid
        pid=$(cat "$pidfile")
        if kill -0 "$pid" 2>/dev/null; then
            return 0
        fi
        # Stale PID file
        rm -f "$pidfile"
    fi
    return 1
}

start_icecast() {
    if is_running "$ICECAST_PID"; then
        echo "Icecast is already running (PID $(cat "$ICECAST_PID"))"
        return 0
    fi
    echo "Starting Icecast..."
    "$ICECAST_BIN" -c "$ICECAST_CONF" -b 2>&1
    # Icecast with -b backgrounds itself; find its PID
    sleep 1
    pgrep -f "icecast2.*${ICECAST_CONF}" > "$ICECAST_PID" 2>/dev/null
    if is_running "$ICECAST_PID"; then
        echo "Icecast started (PID $(cat "$ICECAST_PID"))"
    else
        echo "ERROR: Icecast failed to start. Check ${ICECAST_LOG_DIR}/error.log"
        return 1
    fi
}

start_liquidsoap() {
    if is_running "$LIQUIDSOAP_PID"; then
        echo "Liquidsoap is already running (PID $(cat "$LIQUIDSOAP_PID"))"
        return 0
    fi
    echo "Starting Liquidsoap..."
    "$LIQUIDSOAP_BIN" --daemon "$LIQUIDSOAP_CONF" 2>&1
    sleep 2
    pgrep -f "liquidsoap.*${LIQUIDSOAP_CONF}" > "$LIQUIDSOAP_PID" 2>/dev/null
    if is_running "$LIQUIDSOAP_PID"; then
        echo "Liquidsoap started (PID $(cat "$LIQUIDSOAP_PID"))"
    else
        echo "ERROR: Liquidsoap failed to start. Check ${LIQUIDSOAP_LOG_DIR}/radio.log"
        return 1
    fi
}

stop_service() {
    local name="$1"
    local pidfile="$2"
    if is_running "$pidfile"; then
        local pid
        pid=$(cat "$pidfile")
        echo "Stopping ${name} (PID ${pid})..."
        kill "$pid" 2>/dev/null
        # Wait up to 5 seconds for graceful shutdown
        for i in $(seq 1 10); do
            if ! kill -0 "$pid" 2>/dev/null; then
                break
            fi
            sleep 0.5
        done
        # Force kill if still running
        if kill -0 "$pid" 2>/dev/null; then
            echo "Force killing ${name}..."
            kill -9 "$pid" 2>/dev/null
        fi
        rm -f "$pidfile"
        echo "${name} stopped."
    else
        echo "${name} is not running."
    fi
}

do_start() {
    ensure_dirs
    start_icecast
    # Small delay to let Icecast fully initialize before Liquidsoap connects
    sleep 1
    start_liquidsoap
    echo ""
    echo "Radio stream should be available at: http://localhost:8000/radio.mp3"
}

do_stop() {
    stop_service "Liquidsoap" "$LIQUIDSOAP_PID"
    stop_service "Icecast" "$ICECAST_PID"
}

do_status() {
    echo "─── Radio Status ───"
    if is_running "$ICECAST_PID"; then
        echo "Icecast:    RUNNING (PID $(cat "$ICECAST_PID"))"
    else
        echo "Icecast:    STOPPED"
    fi
    if is_running "$LIQUIDSOAP_PID"; then
        echo "Liquidsoap: RUNNING (PID $(cat "$LIQUIDSOAP_PID"))"
    else
        echo "Liquidsoap: STOPPED"
    fi

    # Check if the stream is actually accessible
    if command -v curl &>/dev/null; then
        local http_code
        http_code=$(curl -s -o /dev/null -w "%{http_code}" --max-time 3 "http://localhost:8000/radio.mp3")
        if [ "$http_code" = "200" ]; then
            echo "Stream:     LIVE (http://localhost:8000/radio.mp3)"
        else
            echo "Stream:     NOT AVAILABLE (HTTP ${http_code})"
        fi
    fi
    echo "────────────────────"
}

# ─── Main ─────────────────────────────────────────────────────────────────────

case "${1}" in
    start)
        do_start
        ;;
    stop)
        do_stop
        ;;
    restart)
        do_stop
        sleep 2
        do_start
        ;;
    status)
        do_status
        ;;
    *)
        echo "Usage: $0 {start|stop|restart|status}"
        exit 1
        ;;
esac
