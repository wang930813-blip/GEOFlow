#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
NGINX_BIN="${GEOFLOW_NGINX_BIN:-/www/server/nginx/sbin/nginx}"
NGINX_CONF="${GEOFLOW_NGINX_CONF:-/www/server/nginx/conf/nginx.conf}"
VHOST_DIR="${GEOFLOW_NGINX_VHOST_DIR:-/www/server/panel/vhost/nginx}"
SITE_CONFIG_DIR="/www/server/nginx/conf/geoflow-customer-sites"
CERTIFICATE_DIR="/www/server/nginx/conf/geoflow-customer-certs"
SOCKET_PATH="/run/geoflow-domain-deployer/deployer.sock"
SOCKET_GROUP="${GEOFLOW_CUSTOM_DOMAIN_SOCKET_GROUP:-geoflow-deployer}"
INCLUDE_FILE="${VHOST_DIR}/geoflow-customer-domains.conf"
INCLUDE_CONTENT="include ${SITE_CONFIG_DIR}/*.conf;"
CREATED_INCLUDE=0

cleanup_on_exit() {
    exit_code="$?"
    if [[ "$exit_code" -ne 0 && "$CREATED_INCLUDE" -eq 1 ]]; then
        rm -f -- "$INCLUDE_FILE"
    fi
    exit "$exit_code"
}
trap cleanup_on_exit EXIT

fail() {
    printf '%s\n' "$1" >&2
    exit 1
}

[[ "$(id -u)" -eq 0 ]] || fail "Run this installer as root."
[[ -x "$NGINX_BIN" ]] || fail "Nginx binary was not found at the configured path."
[[ -f "$NGINX_CONF" ]] || fail "Nginx main configuration was not found at the configured path."
[[ -d "$VHOST_DIR" ]] || fail "The configured BaoTa virtual-host include directory does not exist."
if ! getent group "$SOCKET_GROUP" >/dev/null; then
    command -v groupadd >/dev/null || fail "groupadd is required to create the dedicated deployment socket group."
    groupadd --system "$SOCKET_GROUP"
fi
command -v python3 >/dev/null || fail "Python 3 is required on the production host."
command -v systemctl >/dev/null || fail "systemd is required to manage the deployment service."

python3 -c 'import sys; sys.exit(0 if sys.version_info >= (3, 6) else 1)' || fail "Python 3.6 or newer is required."
python3 -m venv --help >/dev/null 2>&1 || fail "Python venv support is required on the production host."
PYTHON_BIN="$(command -v python3)"
VENV_DIR="/opt/geoflow-domain-deployer/venv"
install -d -o root -g root -m 0755 "$(dirname -- "$VENV_DIR")"
if [[ ! -x "${VENV_DIR}/bin/python" ]]; then
    "$PYTHON_BIN" -m venv --without-pip "$VENV_DIR"
fi
"${VENV_DIR}/bin/python" -c 'import sys; sys.exit(0 if sys.version_info >= (3, 6) else 1)' || fail "The isolated deployment runtime is not usable."

NGINX_ARGS=(-c "$NGINX_CONF")
NGINX_DUMP="$("$NGINX_BIN" "${NGINX_ARGS[@]}" -T 2>&1)" || fail "Nginx configuration cannot be inspected; no files were changed."
printf '%s\n' "$NGINX_DUMP" | grep -F -- "include ${VHOST_DIR}/*.conf;" >/dev/null || fail "The configured BaoTa virtual-host wildcard is not present in the active Nginx configuration."

install -d -o root -g root -m 0755 "$SITE_CONFIG_DIR"
install -d -o root -g root -m 0700 "$CERTIFICATE_DIR"

if [[ -e "$INCLUDE_FILE" ]]; then
    [[ "$(<"$INCLUDE_FILE")" == "$INCLUDE_CONTENT" ]] || fail "The GEOFlow Nginx include file already exists with different contents."
else
    INCLUDE_TMP="$(mktemp "${VHOST_DIR}/.geoflow-customer-domains.XXXXXX")"
    printf '%s\n' "$INCLUDE_CONTENT" > "$INCLUDE_TMP"
    install -o root -g root -m 0644 "$INCLUDE_TMP" "$INCLUDE_FILE"
    rm -f -- "$INCLUDE_TMP"
    CREATED_INCLUDE=1
fi

"$NGINX_BIN" "${NGINX_ARGS[@]}" -t || fail "Nginx configuration test failed; the new include was removed and no reload was performed."
"$NGINX_BIN" "${NGINX_ARGS[@]}" -s reload || fail "Nginx reload failed; inspect the service before retrying."
CREATED_INCLUDE=0

SERVICE_DIR="/usr/local/lib/geoflow-domain-deployer"
SERVICE_ENV="/etc/geoflow-domain-deployer.env"
UNIT_FILE="/etc/systemd/system/geoflow-customer-domain-deployer.service"
install -d -o root -g root -m 0755 "$SERVICE_DIR"
install -o root -g root -m 0755 "$SCRIPT_DIR/server.py" "$SERVICE_DIR/server.py"
install -o root -g root -m 0644 "$SCRIPT_DIR/geoflow-customer-domain-deployer.service" "$UNIT_FILE"

if [[ ! -e "$SERVICE_ENV" ]]; then
    SERVICE_ENV_TMP="$(mktemp /etc/.geoflow-domain-deployer.env.XXXXXX)"
    {
        printf 'GEOFLOW_NGINX_BIN=%s\n' "$NGINX_BIN"
        printf 'GEOFLOW_NGINX_CONF=%s\n' "$NGINX_CONF"
        printf 'GEOFLOW_CUSTOM_DOMAIN_SOCKET=%s\n' "$SOCKET_PATH"
        printf 'GEOFLOW_CUSTOM_DOMAIN_CONFIG_DIR=%s\n' "$SITE_CONFIG_DIR"
        printf 'GEOFLOW_CUSTOM_DOMAIN_CERT_DIR=%s\n' "$CERTIFICATE_DIR"
        printf 'GEOFLOW_CUSTOM_DOMAIN_BACKEND=http://127.0.0.1:18080\n'
    } > "$SERVICE_ENV_TMP"
    install -o root -g root -m 0600 "$SERVICE_ENV_TMP" "$SERVICE_ENV"
    rm -f -- "$SERVICE_ENV_TMP"
fi

systemd-analyze verify "$UNIT_FILE" || fail "systemd rejected the deployment service unit."
systemctl daemon-reload
systemctl enable geoflow-customer-domain-deployer.service
systemctl restart geoflow-customer-domain-deployer.service
systemctl is-active --quiet geoflow-customer-domain-deployer.service || fail "The deployment service did not become active."

for attempt in $(seq 1 10); do
    [[ -S "$SOCKET_PATH" ]] && break
    sleep 1
done
[[ -S "$SOCKET_PATH" ]] || fail "The deployment service did not create its Unix Socket."

SOCKET_GID="$(getent group "$SOCKET_GROUP" | cut -d: -f3)"
SOCKET_MODE="$(stat -c '%a' "$SOCKET_PATH")"
[[ "$SOCKET_MODE" == "660" ]] || fail "The deployment Socket permissions are not 0660."
[[ "$(stat -c '%g' "$SOCKET_PATH")" == "$SOCKET_GID" ]] || fail "The deployment Socket group does not match the configured application group."

printf 'Customer-domain deployment service is active.\n'
printf 'Socket: %s (group %s, gid %s, mode %s)\n' "$SOCKET_PATH" "$SOCKET_GROUP" "$SOCKET_GID" "$SOCKET_MODE"
printf 'Set these production application values before enabling auto deployment:\n'
printf 'GEOFLOW_CUSTOM_DOMAIN_DEPLOY_SOCKET=%s\n' "$SOCKET_PATH"
printf 'GEOFLOW_CUSTOM_DOMAIN_DEPLOY_SOCKET_DIR=%s\n' "$(dirname -- "$SOCKET_PATH")"
printf 'GEOFLOW_CUSTOM_DOMAIN_DEPLOY_GID=%s\n' "$SOCKET_GID"
