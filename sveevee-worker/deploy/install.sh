#!/usr/bin/env bash

set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
    echo "Run this installer as root." >&2
    exit 1
fi

for command in php rsync systemctl; do
    if ! command -v "${command}" >/dev/null 2>&1; then
        echo "Required command is missing: ${command}" >&2
        exit 1
    fi
done

for unit in sveevee-worker.timer sveevee-worker.service; do
    state="$(systemctl show --property=ActiveState --value "${unit}" 2>/dev/null || true)"
    case "${state}" in
        active|activating|deactivating|reloading)
            echo "Stop sveevee-worker.timer and let the current import finish before installing (${unit}: ${state})." >&2
            exit 1
            ;;
    esac
done

SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET_DIR=/var/www/sveevee-worker
CONFIG_DIR=/etc/sveevee-worker
DATA_DIR=/var/lib/sveevee-worker
SERVICE_USER=sveevee-worker

if ! id "${SERVICE_USER}" >/dev/null 2>&1; then
    useradd --system --home-dir "${DATA_DIR}" --shell /usr/sbin/nologin "${SERVICE_USER}"
fi

install -d -m 0755 -o root -g root "${TARGET_DIR}"
install -d -m 0750 -o root -g "${SERVICE_USER}" "${CONFIG_DIR}"
install -d -m 0700 -o "${SERVICE_USER}" -g "${SERVICE_USER}" "${DATA_DIR}"

rsync -a \
    --exclude='.env' \
    --exclude='config/worker.json' \
    --exclude='config/seeds.json' \
    --exclude='var/' \
    "${SOURCE_DIR}/" "${TARGET_DIR}/"

chown -R root:root "${TARGET_DIR}"
chmod 0755 "${TARGET_DIR}/bin/worker"

if [[ ! -f "${CONFIG_DIR}/worker.json" ]]; then
    install -m 0640 -o root -g "${SERVICE_USER}" \
        "${SOURCE_DIR}/config/worker.example.json" "${CONFIG_DIR}/worker.json"
fi
if [[ ! -f "${CONFIG_DIR}/worker.env" ]]; then
    install -m 0640 -o root -g "${SERVICE_USER}" \
        "${SOURCE_DIR}/.env.example" "${CONFIG_DIR}/worker.env"
fi

install -m 0644 "${SOURCE_DIR}/deploy/systemd/sveevee-worker.service" \
    /etc/systemd/system/sveevee-worker.service
install -m 0644 "${SOURCE_DIR}/deploy/systemd/sveevee-worker.timer" \
    /etc/systemd/system/sveevee-worker.timer
install -d -m 0755 /etc/systemd/system/sveevee-worker.timer.d
if [[ -f /etc/systemd/system/sveevee-worker.timer.d/schedule.conf ]]; then
    install -d -m 0700 /var/backups/sveevee
    cp -p /etc/systemd/system/sveevee-worker.timer.d/schedule.conf \
        "/var/backups/sveevee/worker-schedule-$(date -u +%Y%m%dT%H%M%SZ)-$$.conf"
fi
install -m 0644 "${SOURCE_DIR}/deploy/systemd/sveevee-worker.timer.d/schedule.conf" \
    /etc/systemd/system/sveevee-worker.timer.d/schedule.conf
ln -sfn "${TARGET_DIR}/bin/worker" /usr/local/bin/sveevee-worker

systemctl daemon-reload

echo "Sveevee worker installed. The timer was NOT enabled."
echo "Configure ${CONFIG_DIR}/worker.env and ${CONFIG_DIR}/worker.json before testing."
echo "Existing schedule.conf overrides were backed up and replaced with the ten-minute schedule."
