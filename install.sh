#!/usr/bin/env bash
#
# Installs BYOMailer on a server with Docker.
#
#   curl -fsSL https://raw.githubusercontent.com/amedina068/byomailer/main/install.sh | bash
#
# The script starts one container with one volume at /data. It asks for one
# value, the domain. Press Enter for localhost.
#
# Options:
#   --domain=<host>      Use this domain. The script does not ask.
#   --non-interactive    Ask nothing. Read the domain from APP_DOMAIN.
#   --env-file=<path>    Give the variables in this file to the container,
#                        for example the SQS settings of the SES events.
#   --dry-run            Print each docker command. Change nothing.
#   --help               Show this help.
#
# Environment variables:
#   APP_DOMAIN           The domain, for example mail.example.com.
#   BM_ENV_FILE          The same as --env-file.
#   BM_IMAGE             The image. Default: ghcr.io/amedina068/byomailer
#   BM_VERSION           The image tag. Default: latest
#   BM_CONTAINER         The container name. Default: byomailer
#   BM_VOLUME            The volume name. Default: byomailer
#   BM_HTTP_PORT         The HTTP port for localhost. Default: 8080
#   BM_LOG_FILE          The log file. Default: /tmp/byomailer-install.log
#   BM_WAIT_SECONDS      The time limit for the first start. Default: 300
#
# You can run the script again. It replaces the container and keeps the volume.
# This is also the upgrade procedure.
#
# All the code is in functions. The last line calls main, so a partial download
# runs nothing.

set -euo pipefail

BM_IMAGE="${BM_IMAGE:-ghcr.io/amedina068/byomailer}"
BM_VERSION="${BM_VERSION:-latest}"
BM_CONTAINER="${BM_CONTAINER:-byomailer}"
BM_VOLUME="${BM_VOLUME:-byomailer}"
BM_HTTP_PORT="${BM_HTTP_PORT:-8080}"
BM_LOG_FILE="${BM_LOG_FILE:-/tmp/byomailer-install.log}"
BM_WAIT_SECONDS="${BM_WAIT_SECONDS:-300}"
BM_ENV_FILE="${BM_ENV_FILE:-}"

DRY_RUN=false
INTERACTIVE=true
DOMAIN="${APP_DOMAIN:-}"
DOMAIN_FROM_FLAG=false
TOTAL_STEPS=6
STEP=0
STEP_STARTED=0
INSTALL_STARTED=0
EXISTING_CONTAINER=false
PORTS=()
APP_URL=""
SETUP_URL=""

# ---------------------------------------------------------------------------
# Output
# ---------------------------------------------------------------------------

if [ -t 1 ] && [ -z "${NO_COLOR:-}" ]; then
    BOLD=$'\033[1m'
    DIM=$'\033[2m'
    RED=$'\033[31m'
    GREEN=$'\033[32m'
    YELLOW=$'\033[33m'
    RESET=$'\033[0m'
else
    BOLD=""
    DIM=""
    RED=""
    GREEN=""
    YELLOW=""
    RESET=""
fi

now_ms() {
    if [ -n "${EPOCHREALTIME:-}" ]; then
        local now="${EPOCHREALTIME/[.,]/}"
        echo $((now / 1000))
    else
        echo $((SECONDS * 1000))
    fi
}

elapsed() {
    local ms=$(($(now_ms) - $1))
    printf '%d.%ds' $((ms / 1000)) $(((ms % 1000) / 100))
}

log() {
    printf '%s %s\n' "$(date '+%Y-%m-%dT%H:%M:%S')" "$*" >>"$BM_LOG_FILE"
}

info() {
    printf '      %s\n' "$*"
    log "INFO $*"
}

warn() {
    printf '      %s!%s %s\n' "$YELLOW" "$RESET" "$*"
    log "WARN $*"
}

die() {
    printf '\n%sError:%s %s\n' "$RED" "$RESET" "$1" >&2
    if [ -n "${2:-}" ]; then
        printf '%sFix:%s   %s\n' "$BOLD" "$RESET" "$2" >&2
    fi
    printf '%sLog:%s   %s\n' "$DIM" "$RESET" "$BM_LOG_FILE" >&2
    log "ERROR $1 ${2:-}"
    exit 1
}

# A failed check stops the install. In a dry run, the script shows a warning
# and continues, so that you can read all the commands.
check_failed() {
    if [ "$DRY_RUN" = true ]; then
        warn "$1 (without --dry-run, the install stops here)"
        return 0
    fi
    die "$1" "${2:-}"
}

step() {
    STEP=$((STEP + 1))
    STEP_STARTED=$(now_ms)
    printf '\n%s[%d/%d]%s %s\n' "$BOLD" "$STEP" "$TOTAL_STEPS" "$RESET" "$1"
    log "STEP $STEP/$TOTAL_STEPS $1"
}

step_done() {
    printf '      %s✓%s %s %s(%s)%s\n' "$GREEN" "$RESET" "${1:-Done}" "$DIM" "$(elapsed "$STEP_STARTED")" "$RESET"
}

# Runs a command that changes the system. In a dry run, it prints the command.
run() {
    log "RUN $*"
    if [ "$DRY_RUN" = true ]; then
        printf '      %s$ %s%s\n' "$DIM" "$*" "$RESET"
        return 0
    fi
    "$@" >>"$BM_LOG_FILE" 2>&1
}

# Runs a command that only reads. A dry run also runs it, and prints it.
probe() {
    log "PROBE $*"
    if [ "$DRY_RUN" = true ]; then
        printf '      %s$ %s%s\n' "$DIM" "$*" "$RESET" >&3
    fi
    "$@" 2>>"$BM_LOG_FILE"
}

usage() {
    cat <<'EOF'
Installs BYOMailer on a server with Docker.

Usage: install.sh [--domain=<host>] [--non-interactive] [--dry-run]

Options:
  --domain=<host>      Use this domain. The script does not ask.
  --non-interactive    Ask nothing. Read the domain from APP_DOMAIN.
  --env-file=<path>    Give the variables in this file to the container,
                       for example the SQS settings of the SES events.
  --dry-run            Print each docker command. Change nothing.
  --help               Show this help.

Environment variables:
  APP_DOMAIN           The domain. Empty means localhost.
  BM_ENV_FILE          The same as --env-file.
  BM_IMAGE             Default: ghcr.io/amedina068/byomailer
  BM_VERSION           Default: latest
  BM_CONTAINER         Default: byomailer
  BM_VOLUME            Default: byomailer
  BM_HTTP_PORT         The HTTP port for localhost. Default: 8080
  BM_LOG_FILE          Default: /tmp/byomailer-install.log
  BM_WAIT_SECONDS      Default: 300
EOF
}

# ---------------------------------------------------------------------------
# Input
# ---------------------------------------------------------------------------

parse_args() {
    while [ $# -gt 0 ]; do
        case "$1" in
            --dry-run) DRY_RUN=true ;;
            --non-interactive) INTERACTIVE=false ;;
            --domain=*)
                DOMAIN="${1#*=}"
                DOMAIN_FROM_FLAG=true
                ;;
            --domain)
                [ $# -ge 2 ] || die "The option --domain needs a value." "Use --domain=mail.example.com"
                DOMAIN="$2"
                DOMAIN_FROM_FLAG=true
                shift
                ;;
            --env-file=*) BM_ENV_FILE="${1#*=}" ;;
            --env-file)
                [ $# -ge 2 ] || die "The option --env-file needs a value." "Use --env-file=/root/byomailer.env"
                BM_ENV_FILE="$2"
                shift
                ;;
            -h | --help)
                usage
                exit 0
                ;;
            *) die "Unknown option: $1" "Run the script with --help to see the options." ;;
        esac
        shift
    done

    # With curl | bash, stdin is the script. The script reads the answer from
    # the terminal. If there is no terminal, it asks nothing.
    if [ "$INTERACTIVE" = true ] && ! { : </dev/tty; } 2>/dev/null; then
        INTERACTIVE=false
    fi
}

normalize_domain() {
    local value
    value="$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]' | tr -d '[:space:]')"
    value="${value#http://}"
    value="${value#https://}"
    value="${value%%/*}"
    printf '%s' "$value"
}

ask_domain() {
    if [ "$INTERACTIVE" = true ] && [ "$DOMAIN_FROM_FLAG" = false ] && [ -z "$DOMAIN" ]; then
        printf '\n%sDomain%s for BYOMailer, for example mail.example.com.\n' "$BOLD" "$RESET"
        printf 'Press Enter for localhost: '
        read -r DOMAIN </dev/tty || DOMAIN=""
    fi

    DOMAIN="$(normalize_domain "$DOMAIN")"

    case "$DOMAIN" in
        "" | localhost | 127.0.0.1)
            DOMAIN="localhost"
            return
            ;;
    esac

    if ! printf '%s' "$DOMAIN" | grep -Eq '^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$'; then
        die "\"$DOMAIN\" is not a valid domain." "Use a domain like mail.example.com, or press Enter for localhost."
    fi
}

# ---------------------------------------------------------------------------
# Steps
# ---------------------------------------------------------------------------

check_system() {
    step "Check the system"

    local os arch
    os="$(uname -s)"
    arch="$(uname -m)"
    case "$arch" in
        x86_64 | amd64) arch="amd64" ;;
        aarch64 | arm64) arch="arm64" ;;
        *) check_failed "The CPU architecture $arch is not supported." "Use a server with amd64 or arm64." ;;
    esac
    info "System: $os $arch"

    if ! command -v docker >/dev/null 2>&1; then
        check_failed "Docker is not installed." "Install Docker: curl -fsSL https://get.docker.com | sh"
        step_done "Checked"
        return
    fi

    local docker_output
    if docker_output="$(probe docker info --format '{{.ServerVersion}}' 2>&1)"; then
        info "Docker: $docker_output"
    else
        case "$docker_output" in
            *"permission denied"*)
                check_failed "Your user cannot use Docker." "Run the script with sudo, or add your user to the docker group."
                ;;
            *)
                check_failed "The Docker daemon does not answer." "Start Docker: sudo systemctl start docker"
                ;;
        esac
    fi

    local compose_version
    if compose_version="$(probe docker compose version --short 2>/dev/null)"; then
        info "Docker Compose: $compose_version"
    else
        warn "Docker Compose v2 is not installed. You need it only for MySQL and Redis."
    fi

    if [ -r /proc/meminfo ]; then
        local memory_mb
        memory_mb=$(($(awk '/^MemTotal:/ { print $2 }' /proc/meminfo) / 1024))
        if [ "$memory_mb" -lt 900 ]; then
            warn "The server has $memory_mb MB of memory. Use 1 GB or more."
        fi
    fi

    if [ -n "$BM_ENV_FILE" ]; then
        if [ -r "$BM_ENV_FILE" ]; then
            info "Variables: $BM_ENV_FILE"
        else
            check_failed "The file $BM_ENV_FILE does not exist or is not readable." "Give the path of a readable file to --env-file."
        fi
    fi

    step_done "Docker is ready"
}

port_in_use() {
    local port="$1"
    if command -v ss >/dev/null 2>&1; then
        ss -ltnH "sport = :$port" 2>/dev/null | grep -q .
    elif command -v nc >/dev/null 2>&1; then
        nc -z 127.0.0.1 "$port" >/dev/null 2>&1
    else
        (exec 3<>"/dev/tcp/127.0.0.1/$port") 2>/dev/null
    fi
}

# Prints the host ports that the old container publishes. The new container
# gets these ports, so they are not "in use".
container_ports() {
    probe docker port "$BM_CONTAINER" 2>/dev/null | sed -n 's/.*:\([0-9][0-9]*\)$/\1/p' | sort -u || true
}

check_ports() {
    step "Check the ports"

    if command -v docker >/dev/null 2>&1 && probe docker container inspect "$BM_CONTAINER" >/dev/null 2>&1; then
        EXISTING_CONTAINER=true
        info "The container \"$BM_CONTAINER\" exists. The script replaces it. The data in the volume stays."
    fi

    local owned=" "
    if [ "$EXISTING_CONTAINER" = true ]; then
        owned=" $(container_ports | tr '\n' ' ') "
    fi

    if [ "$DOMAIN" = "localhost" ]; then
        local port="$BM_HTTP_PORT" last=$((BM_HTTP_PORT + 20))
        while [ "$port" -le "$last" ]; do
            if [[ "$owned" == *" $port "* ]] || ! port_in_use "$port"; then
                break
            fi
            port=$((port + 1))
        done
        if [ "$port" -gt "$last" ]; then
            check_failed "The ports $BM_HTTP_PORT to $last are in use." "Set BM_HTTP_PORT to a free port."
            port="$BM_HTTP_PORT"
        elif [ "$port" != "$BM_HTTP_PORT" ]; then
            warn "Port $BM_HTTP_PORT is in use. The script uses port $port."
        fi
        BM_HTTP_PORT="$port"
        PORTS=("$BM_HTTP_PORT:8080")
        APP_URL="http://localhost:$BM_HTTP_PORT"
        step_done "Port $BM_HTTP_PORT is free"
        return
    fi

    local port
    for port in 80 443; do
        if [[ "$owned" != *" $port "* ]] && port_in_use "$port"; then
            check_failed "Port $port is in use. Automatic HTTPS needs ports 80 and 443." "Stop the web server on this port, for example: sudo systemctl stop nginx"
        fi
    done
    PORTS=("80:80" "443:443" "443:443/udp")
    APP_URL="https://$DOMAIN"

    check_dns
    step_done "Ports 80 and 443 are free"
}

# Shows a warning when DNS has no record for the domain.
# DNS can change after the install, so a missing record does not stop it.
check_dns() {
    command -v getent >/dev/null 2>&1 || return 0

    if ! getent ahosts "$DOMAIN" >/dev/null 2>&1; then
        warn "DNS has no record for $DOMAIN. Add an A record that points to this server."
    fi
}

pull_image() {
    step "Download the image $BM_IMAGE:$BM_VERSION"

    if run docker pull "$BM_IMAGE:$BM_VERSION"; then
        step_done "Downloaded"
        return
    fi

    if probe docker image inspect "$BM_IMAGE:$BM_VERSION" >/dev/null 2>&1; then
        warn "The download failed. The script uses the local copy of the image."
        step_done "Local image"
        return
    fi

    die "Docker cannot download $BM_IMAGE:$BM_VERSION." "Make sure that the server can reach ghcr.io. Then run the script again."
}

start_container() {
    step "Start the container"

    if [ "$EXISTING_CONTAINER" = true ]; then
        run docker rm -f "$BM_CONTAINER" || die "Docker cannot remove the old container \"$BM_CONTAINER\"."
    fi

    run docker volume create "$BM_VOLUME" || die "Docker cannot create the volume \"$BM_VOLUME\"."

    local args=(docker run -d --name "$BM_CONTAINER" --restart unless-stopped)
    local port
    for port in "${PORTS[@]}"; do
        args+=(-p "$port")
    done
    args+=(-v "$BM_VOLUME:/data")
    if [ -n "$BM_ENV_FILE" ]; then
        args+=(--env-file "$BM_ENV_FILE")
    fi
    if [ "$DOMAIN" = "localhost" ]; then
        if [ "$BM_HTTP_PORT" != "8080" ]; then
            args+=(-e "APP_URL=$APP_URL")
        fi
    else
        args+=(-e "APP_DOMAIN=$DOMAIN")
    fi
    args+=("$BM_IMAGE:$BM_VERSION")

    run "${args[@]}" || die "Docker cannot start the container." "Read the log. Then run the script again."
    step_done "Started \"$BM_CONTAINER\""
}

wait_until_ready() {
    step "Wait until the app is ready"

    if [ "$DRY_RUN" = true ]; then
        printf '      %s$ docker exec %s curl -fsS http://127.0.0.1:8080/up%s\n' "$DIM" "$BM_CONTAINER" "$RESET"
        step_done "Skipped in a dry run"
        return
    fi

    if [ "$EXISTING_CONTAINER" = false ]; then
        info "The first start creates the database and the secrets."
    fi
    local deadline=$((SECONDS + BM_WAIT_SECONDS))
    while [ "$SECONDS" -lt "$deadline" ]; do
        if [ "$(docker inspect -f '{{.State.Running}}' "$BM_CONTAINER" 2>/dev/null)" != "true" ]; then
            docker logs --tail 40 "$BM_CONTAINER" >>"$BM_LOG_FILE" 2>&1 || true
            die "The container stopped." "Read the log of the container: docker logs $BM_CONTAINER"
        fi
        if docker exec "$BM_CONTAINER" curl -fsS -o /dev/null http://127.0.0.1:8080/up >/dev/null 2>&1; then
            step_done "The app answers"
            return
        fi
        sleep 1
    done

    docker logs --tail 40 "$BM_CONTAINER" >>"$BM_LOG_FILE" 2>&1 || true
    die "The app did not answer in $BM_WAIT_SECONDS seconds." "Read the log of the container: docker logs $BM_CONTAINER"
}

read_setup_url() {
    step "Read the setup URL"

    if [ "$DRY_RUN" = true ]; then
        printf '      %s$ docker exec %s php artisan byomailer:setup-url%s\n' "$DIM" "$BM_CONTAINER" "$RESET"
        SETUP_URL="$APP_URL/setup?token=<token>"
        step_done "Skipped in a dry run"
        return
    fi

    local output
    output="$(docker exec "$BM_CONTAINER" php artisan byomailer:setup-url --no-ansi 2>>"$BM_LOG_FILE" || true)"
    log "byomailer:setup-url: $output"
    SETUP_URL="$(printf '%s\n' "$output" | grep -Eo 'https?://[^[:space:]"]+/setup\?token=[A-Za-z0-9]+' | head -n 1 || true)"

    if [ -n "$SETUP_URL" ]; then
        # The app builds the URL from its own APP_URL. Show the URL that the
        # operator can open from the browser.
        SETUP_URL="$APP_URL/setup?${SETUP_URL#*\?}"
        step_done "Found"
    else
        step_done "An owner account exists"
    fi
}

summary() {
    local seconds=$((($(now_ms) - INSTALL_STARTED) / 1000))
    local lines=()

    if [ "$DRY_RUN" = true ]; then
        lines+=("Dry run complete. The script changed nothing.")
    else
        lines+=("BYOMailer is ready. Installed in ${seconds}s.")
    fi
    lines+=("")
    lines+=("App:        $APP_URL")
    if [ -n "$SETUP_URL" ]; then
        lines+=("Setup URL:  $SETUP_URL")
    else
        lines+=("Log in:     $APP_URL/login")
    fi
    lines+=("")
    lines+=("Back up the /data volume (\"$BM_VOLUME\").")
    lines+=("It holds the database, the uploads and APP_KEY.")
    lines+=("APP_KEY encrypts the AWS keys of each account.")
    lines+=("")
    lines+=("Next: log in. The dashboard shows the Amazon SES steps.")
    lines+=("The first step is the SQS queue for the SES events.")
    if [ "$DOMAIN" != "localhost" ]; then
        lines+=("")
        lines+=("DNS: point $DOMAIN to this server.")
    fi
    lines+=("")
    lines+=("Connect an AI agent:")
    lines+=("  docker exec $BM_CONTAINER php artisan byomailer:mcp:connect --client=claude")

    local width=0 line
    for line in "${lines[@]}"; do
        if [ "${#line}" -gt "$width" ]; then
            width=${#line}
        fi
    done

    local border
    border="$(printf '%*s' $((width + 4)) '' | sed 's/ /─/g')"
    printf '\n┌%s┐\n' "$border"
    for line in "${lines[@]}"; do
        printf '│  %-*s  │\n' "$width" "$line"
    done
    printf '└%s┘\n' "$border"
    printf '%sInstall log: %s%s\n\n' "$DIM" "$BM_LOG_FILE" "$RESET"
}

main() {
    # File descriptor 3 is the terminal. probe prints to it, also when the
    # caller captures the output of the command.
    exec 3>&1
    parse_args "$@"
    if ! { : >"$BM_LOG_FILE"; } 2>/dev/null; then
        BM_LOG_FILE="$(mktemp)"
    fi
    INSTALL_STARTED=$(now_ms)
    log "START byomailer install, dry run: $DRY_RUN"

    printf '%sBYOMailer installer%s\n' "$BOLD" "$RESET"
    if [ "$DRY_RUN" = true ]; then
        printf '%sDry run: the script prints the commands and changes nothing.%s\n' "$YELLOW" "$RESET"
    fi

    ask_domain
    log "DOMAIN $DOMAIN"

    check_system
    check_ports
    pull_image
    start_container
    wait_until_ready
    read_setup_url
    summary
    log "DONE"
}

main "$@"
