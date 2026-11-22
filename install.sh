#!/usr/bin/env bash
# ==============================================================================
# Fandejo Automated Server Installer & Release Updater
# Copyright (c) 2026 Toondout (https://toondout.com)
# Canonical Repository: https://github.com/Toondout-Development/Fandejo
#
# Deploys Fandejo to /usr/share/fandejo and exposes it at /fandejo on the active
# web server (Apache, Nginx, or a custom webroot symlink).
#
# Usage:
#   sudo ./install.sh                         # Auto-detect web server & install
#   curl -fsSL https://raw.githubusercontent.com/Toondout-Development/Fandejo/main/install.sh | sudo bash
#   sudo /usr/share/fandejo/install.sh --check-update
#   sudo /usr/share/fandejo/install.sh --update
#   sudo /usr/share/fandejo/install.sh --update --version=v1.0.0
#   sudo /usr/share/fandejo/install.sh --uninstall
#   sudo ./install.sh --url-path=/fandejo     # Custom URL alias (default: /fandejo)
#   sudo ./install.sh --dir=/usr/share/fandejo
#   sudo ./install.sh --server=apache|nginx|webroot
#   sudo ./install.sh --webroot=/var/www/html
#   sudo ./install.sh --sqlite-user=admin --sqlite-pass=secret
# ==============================================================================
set -euo pipefail

GITHUB_REPO="Toondout-Development/Fandejo"
GITHUB_API_LATEST="https://api.github.com/repos/${GITHUB_REPO}/releases/latest"
GITHUB_TARBALL_BASE="https://github.com/${GITHUB_REPO}/archive/refs"
FORGEJO_API_LATEST="https://forgejo.toondout.com/api/v1/repos/bholland/Fandejo/releases/latest"
FORGEJO_TARBALL_BASE="https://forgejo.toondout.com/bholland/Fandejo/archive"

INSTALL_DIR="/usr/share/fandejo"
URL_PATH="/fandejo"
SERVER_MODE="auto"
WEBROOT=""
ACTION="install"
TARGET_VERSION=""
FORCE_UPDATE=0
SQLITE_USER=""
SQLITE_PASS=""
NON_INTERACTIVE=0

for arg in "$@"; do
    case "$arg" in
        --update)             ACTION="update" ;;
        --check-update)       ACTION="check-update" ;;
        --uninstall)          ACTION="uninstall" ;;
        --force)              FORCE_UPDATE=1 ;;
        --version=*)          TARGET_VERSION="${arg#*=}" ;;
        --repo=*)             GITHUB_REPO="${arg#*=}"; GITHUB_API_LATEST="https://api.github.com/repos/${GITHUB_REPO}/releases/latest"; GITHUB_TARBALL_BASE="https://github.com/${GITHUB_REPO}/archive/refs" ;;
        --non-interactive|-y) NON_INTERACTIVE=1 ;;
        --dir=*)              INSTALL_DIR="${arg#*=}" ;;
        --url-path=*)         URL_PATH="/${arg#*=}"; URL_PATH="/${URL_PATH#/}"; URL_PATH="${URL_PATH%/}" ;;
        --server=*)           SERVER_MODE="${arg#*=}" ;;
        --webroot=*)          WEBROOT="${arg#*=}"; SERVER_MODE="webroot" ;;
        --sqlite-user=*)      SQLITE_USER="${arg#*=}" ;;
        --sqlite-pass=*)      SQLITE_PASS="${arg#*=}" ;;
        --help|-h)
            sed -n '2,22p' "$0" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *)
            echo "Unknown option: $arg (run with --help for usage)" >&2
            exit 1
            ;;
    esac
done

SCRIPT_SOURCE="${BASH_SOURCE[0]:-}"
if [[ -n "$SCRIPT_SOURCE" && -f "$SCRIPT_SOURCE" ]]; then
    SRC_DIR="$(cd "$(dirname "$SCRIPT_SOURCE")" && pwd)"
else
    SRC_DIR=""
fi
URL_SLUG="${URL_PATH#/}"
TMP_EXTRACT_DIR=""
cleanup_tmp() { [[ -n "$TMP_EXTRACT_DIR" && -d "$TMP_EXTRACT_DIR" ]] && rm -rf "$TMP_EXTRACT_DIR" || true; }
trap cleanup_tmp EXIT

info()  { printf "\033[1;34m[fandejo]\033[0m %s\n" "$*"; }
ok()    { printf "\033[1;32m[  ok   ]\033[0m %s\n" "$*"; }
warn()  { printf "\033[1;33m[ warn  ]\033[0m %s\n" "$*"; }
err()   { printf "\033[1;31m[ error ]\033[0m %s\n" "$*" >&2; exit 1; }

installed_version() {
    if [[ -f "$INSTALL_DIR/VERSION" ]]; then
        tr -d '[:space:]' < "$INSTALL_DIR/VERSION"
    else
        echo "unknown"
    fi
}

# Query GitHub Releases API (with fallback to Forgejo Releases API)
# Outputs two lines: Line 1 = tag_name, Line 2 = tarball_url
query_latest_release() {
    if ! command -v curl >/dev/null 2>&1; then
        err "curl is required to check or download online releases."
    fi
    local json=""
    json="$(curl -fsSL -H "Accept: application/vnd.github+json" -H "User-Agent: Fandejo-Installer" "$GITHUB_API_LATEST" 2>/dev/null || true)"
    if [[ -z "$json" ]]; then
        json="$(curl -fsSL -H "Accept: application/json" -H "User-Agent: Fandejo-Installer" "$FORGEJO_API_LATEST" 2>/dev/null || true)"
    fi
    if [[ -n "$json" ]] && command -v php >/dev/null 2>&1; then
        JSON_PAYLOAD="$json" php -r '
            $d = json_decode((string)getenv("JSON_PAYLOAD"), true);
            if (is_array($d) && !empty($d["tag_name"])) {
                echo trim($d["tag_name"]) . "\n";
                echo trim($d["tarball_url"] ?? "") . "\n";
            }
        '
        return
    elif [[ -n "$json" ]]; then
        local tag tarball
        tag="$(printf '%s' "$json" | grep -oE '"tag_name"[[:space:]]*:[[:space:]]*"[^"]+"' | head -n1 | sed -E 's/.*"([^"]+)"$/\1/')"
        tarball="$(printf '%s' "$json" | grep -oE '"tarball_url"[[:space:]]*:[[:space:]]*"[^"]+"' | head -n1 | sed -E 's/.*"([^"]+)"$/\1/')"
        if [[ -n "$tag" ]]; then
            printf "%s\n%s\n" "$tag" "$tarball"
        fi
    fi
}

# Download a specific release tag (or latest release / main branch fallback) into TMP_EXTRACT_DIR and set SRC_DIR
fetch_remote_release() {
    local want_tag="$1"
    local tag="" tarball=""
    if [[ -n "$want_tag" ]]; then
        tag="$want_tag"
        tarball="${GITHUB_TARBALL_BASE}/tags/${want_tag}.tar.gz"
    else
        local rel_info
        rel_info="$(query_latest_release || true)"
        if [[ -n "$rel_info" ]]; then
            tag="$(printf '%s\n' "$rel_info" | sed -n '1p')"
            tarball="$(printf '%s\n' "$rel_info" | sed -n '2p')"
        fi
        if [[ -z "$tarball" ]]; then
            tag="main"
            tarball="${GITHUB_TARBALL_BASE}/heads/main.tar.gz"
        fi
    fi

    info "Downloading Fandejo release ($tag) from $tarball..."
    TMP_EXTRACT_DIR="$(mktemp -d /tmp/fandejo-release.XXXXXX)"
    if ! curl -fsSL -H "User-Agent: Fandejo-Installer" "$tarball" -o "$TMP_EXTRACT_DIR/release.tar.gz"; then
        # Fallback to Forgejo archive if GitHub archive fails
        local fj_url="${FORGEJO_TARBALL_BASE}/${tag}.tar.gz"
        warn "GitHub download failed; trying Toondout Forgejo mirror ($fj_url)..."
        curl -fsSL "$fj_url" -o "$TMP_EXTRACT_DIR/release.tar.gz" || err "Failed to download Fandejo release '$tag'."
    fi
    tar -xzf "$TMP_EXTRACT_DIR/release.tar.gz" -C "$TMP_EXTRACT_DIR"
    local extracted
    extracted="$(find "$TMP_EXTRACT_DIR" -mindepth 1 -maxdepth 1 -type d | head -n 1 || true)"
    if [[ -z "$extracted" || ! -f "$extracted/public/api.php" ]]; then
        err "Downloaded archive did not contain expected Fandejo public/api.php structure."
    fi
    if [[ -n "$tag" && "$tag" != "main" && ! -f "$extracted/VERSION" ]]; then
        printf "%s\n" "$tag" > "$extracted/VERSION"
    fi
    SRC_DIR="$extracted"
    ok "Downloaded and verified Fandejo ($tag)"
}

# ==============================================================================
# CHECK FOR UPDATES (--check-update)
# ==============================================================================
if [[ "$ACTION" == "check-update" ]]; then
    CUR_VER="$(installed_version)"
    info "Installed Fandejo version in $INSTALL_DIR: $CUR_VER"
    info "Checking latest release at https://github.com/${GITHUB_REPO}/releases ..."
    REL_INFO="$(query_latest_release || true)"
    if [[ -z "$REL_INFO" ]]; then
        warn "No published release found via API (or repository has no tagged releases yet)."
        exit 0
    fi
    LATEST_TAG="$(printf '%s\n' "$REL_INFO" | sed -n '1p')"
    if [[ "$CUR_VER" == "$LATEST_TAG" ]]; then
        ok "Fandejo is up to date ($CUR_VER)."
    else
        ok "New Fandejo release available: $LATEST_TAG (installed: $CUR_VER)"
        info "Run 'sudo $INSTALL_DIR/install.sh --update' to upgrade."
    fi
    exit 0
fi

if [[ $EUID -ne 0 ]]; then
    err "Please run this installer as root (e.g., sudo ./install.sh)"
fi

# Detect web server system user (www-data, nginx, apache, http)
detect_web_user() {
    for u in www-data nginx apache http _www; do
        if id -u "$u" >/dev/null 2>&1; then
            echo "$u"
            return
        fi
    done
    echo "root"
}

# Detect PHP-FPM socket or TCP upstream for Nginx
detect_php_fpm() {
    local sock
    for sock in /run/php/php8.5-fpm.sock /run/php/php8.4-fpm.sock /run/php/php8.3-fpm.sock /run/php/php8.2-fpm.sock /run/php/php8.1-fpm.sock /run/php/php-fpm.sock /var/run/php-fpm/www.sock /run/php-fpm/www.sock; do
        if [[ -S "$sock" ]]; then
            echo "unix:$sock"
            return
        fi
    done
    sock="$(find /run/php /var/run/php-fpm /run/php-fpm -maxdepth 1 -name '*.sock' 2>/dev/null | head -n 1 || true)"
    if [[ -n "$sock" && -S "$sock" ]]; then
        echo "unix:$sock"
        return
    fi
    echo "127.0.0.1:9000"
}

# Detect active web server when --server=auto
detect_server_mode() {
    if [[ "$SERVER_MODE" != "auto" ]]; then
        echo "$SERVER_MODE"
        return
    fi
    if command -v systemctl >/dev/null 2>&1; then
        if systemctl is-active --quiet apache2 2>/dev/null || systemctl is-active --quiet httpd 2>/dev/null; then
            echo "apache"
            return
        fi
        if systemctl is-active --quiet nginx 2>/dev/null; then
            echo "nginx"
            return
        fi
    fi
    if command -v apache2ctl >/dev/null 2>&1 || command -v httpd >/dev/null 2>&1; then
        echo "apache"
        return
    fi
    if command -v nginx >/dev/null 2>&1; then
        echo "nginx"
        return
    fi
    echo "webroot"
}

# ==============================================================================
# UNINSTALL
# ==============================================================================
if [[ "$ACTION" == "uninstall" ]]; then
    info "Uninstalling Fandejo from $INSTALL_DIR and removing web server configuration..."
    if [[ -f /etc/apache2/conf-enabled/fandejo.conf ]] || [[ -f /etc/apache2/conf-available/fandejo.conf ]]; then
        a2disconf fandejo >/dev/null 2>&1 || true
        rm -f /etc/apache2/conf-available/fandejo.conf /etc/apache2/conf-enabled/fandejo.conf
        systemctl reload apache2 >/dev/null 2>&1 || true
        ok "Removed Apache configuration"
    fi
    if [[ -f /etc/httpd/conf.d/fandejo.conf ]]; then
        rm -f /etc/httpd/conf.d/fandejo.conf
        systemctl reload httpd >/dev/null 2>&1 || true
        ok "Removed httpd configuration"
    fi
    if [[ -f /etc/nginx/snippets/fandejo.conf ]]; then
        rm -f /etc/nginx/snippets/fandejo.conf
        grep -rl "snippets/fandejo.conf" /etc/nginx/ 2>/dev/null | while read -r nf; do
            sed -i '/snippets\/fandejo\.conf/d' "$nf"
        done
        nginx -t >/dev/null 2>&1 && systemctl reload nginx >/dev/null 2>&1 || true
        ok "Removed Nginx configuration"
    fi
    if [[ -n "$WEBROOT" && -L "$WEBROOT/$URL_SLUG" ]]; then
        rm -f "$WEBROOT/$URL_SLUG"
        ok "Removed symlink $WEBROOT/$URL_SLUG"
    elif [[ -L "/var/www/html/$URL_SLUG" ]]; then
        rm -f "/var/www/html/$URL_SLUG"
        ok "Removed symlink /var/www/html/$URL_SLUG"
    fi

    if [[ -d "$INSTALL_DIR" ]]; then
        if [[ $NON_INTERACTIVE -eq 0 && -d "$INSTALL_DIR/data" ]] && [[ -n "$(ls -A "$INSTALL_DIR/data" 2>/dev/null || true)" ]]; then
            read -r -p "Delete $INSTALL_DIR (including SQLite databases in $INSTALL_DIR/data)? [y/N] " ans
            if [[ "${ans,,}" == "y" ]]; then
                rm -rf "$INSTALL_DIR"
                ok "Deleted $INSTALL_DIR"
            else
                rm -rf "$INSTALL_DIR/public"
                warn "Kept $INSTALL_DIR/data and config.php"
            fi
        else
            rm -rf "$INSTALL_DIR"
            ok "Removed $INSTALL_DIR"
        fi
    fi
    ok "Fandejo uninstall complete."
    exit 0
fi

# ==============================================================================
# STEP 1: Verify PHP & Required Extensions
# ==============================================================================
info "Checking PHP runtime and required extensions..."
if ! command -v php >/dev/null 2>&1; then
    err "PHP CLI is not installed. Please install PHP 8.1+ (e.g., apt install php-cli php-fpm php-mysql php-sqlite3)"
fi

PHP_VER="$(php -r 'echo PHP_VERSION;')"
PHP_OK="$(php -r 'echo version_compare(PHP_VERSION, "8.1.0", ">=") ? "1" : "0";')"
if [[ "$PHP_OK" != "1" ]]; then
    err "PHP 8.1+ is required (detected PHP $PHP_VER)"
fi
ok "Detected PHP $PHP_VER"

MISSING_EXTS=()
for ext in PDO sodium zlib; do
    if ! php -m | grep -qi "^${ext}$"; then
        MISSING_EXTS+=("$ext")
    fi
done
if ! php -m | grep -qi "^pdo_mysql$" && ! php -m | grep -qi "^pdo_sqlite$"; then
    MISSING_EXTS+=("pdo_mysql or pdo_sqlite")
fi
if [[ ${#MISSING_EXTS[@]} -gt 0 ]]; then
    err "Missing required PHP extensions: ${MISSING_EXTS[*]}. Install them and re-run."
fi
ok "All required PHP extensions are loaded (PDO, sodium, zlib, pdo_mysql/pdo_sqlite)"

# ==============================================================================
# STEP 2: Resolve Source Files (Local Checkout or Remote GitHub Release)
# ==============================================================================
if [[ "$ACTION" == "update" ]]; then
    CUR_VER="$(installed_version)"
    # If running from the installed copy (/usr/share/fandejo/install.sh) or with --version or piped via curl, fetch from remote release
    if [[ -n "$TARGET_VERSION" || -z "$SRC_DIR" || "$SRC_DIR" == "$INSTALL_DIR" || ! -f "$SRC_DIR/public/api.php" ]]; then
        if [[ -z "$TARGET_VERSION" ]]; then
            REL_INFO="$(query_latest_release || true)"
            LATEST_TAG="$(printf '%s\n' "$REL_INFO" | sed -n '1p')"
            if [[ -n "$LATEST_TAG" && "$CUR_VER" == "$LATEST_TAG" && $FORCE_UPDATE -eq 0 ]]; then
                ok "Fandejo is already on the latest release ($CUR_VER). Use --force to reinstall."
                exit 0
            fi
        fi
        fetch_remote_release "$TARGET_VERSION"
    fi
elif [[ -n "$TARGET_VERSION" || -z "$SRC_DIR" || ! -f "$SRC_DIR/public/api.php" ]]; then
    fetch_remote_release "$TARGET_VERSION"
fi

if [[ ! -f "$SRC_DIR/public/api.php" || ! -f "$SRC_DIR/public/app.js" ]]; then
    err "Could not find Fandejo source files in $SRC_DIR/public"
fi

NEW_VER="v1.0.0"
[[ -f "$SRC_DIR/VERSION" ]] && NEW_VER="$(tr -d '[:space:]' < "$SRC_DIR/VERSION")"

WEB_USER="$(detect_web_user)"
info "Deploying Fandejo ($NEW_VER) to $INSTALL_DIR (web user: $WEB_USER)..."

mkdir -p "$INSTALL_DIR/public" "$INSTALL_DIR/data"
if [[ "$SRC_DIR" != "$INSTALL_DIR" ]]; then
    cp -f "$SRC_DIR/public/index.html" "$SRC_DIR/public/app.js" "$SRC_DIR/public/style.css" "$SRC_DIR/public/api.php" "$INSTALL_DIR/public/"
    cp -f "$SRC_DIR/config.example.php" "$INSTALL_DIR/config.example.php"
    [[ -f "$SRC_DIR/VERSION" ]] && cp -f "$SRC_DIR/VERSION" "$INSTALL_DIR/VERSION"
    [[ -f "$SRC_DIR/LICENSE" ]] && cp -f "$SRC_DIR/LICENSE" "$INSTALL_DIR/LICENSE"
    [[ -f "$SRC_DIR/README.md" ]] && cp -f "$SRC_DIR/README.md" "$INSTALL_DIR/README.md"
    [[ -f "$SRC_DIR/install.sh" ]] && cp -f "$SRC_DIR/install.sh" "$INSTALL_DIR/install.sh" && chmod 0755 "$INSTALL_DIR/install.sh"
fi

# Set secure ownership and permissions:
# - public/ is world-readable (0755 dirs, 0644 files) so the web server can serve static assets
# - data/ is owned by $WEB_USER (0750) so SQLite can create WAL/SHM journal files
# - config.php & .fandejo_locks.json are 0600 owned by $WEB_USER
chown -R root:"$WEB_USER" "$INSTALL_DIR"
chmod 0755 "$INSTALL_DIR" "$INSTALL_DIR/public"
chmod 0644 "$INSTALL_DIR/public/"*
[[ -f "$INSTALL_DIR/install.sh" ]] && chmod 0755 "$INSTALL_DIR/install.sh"
chown -R "$WEB_USER":"$WEB_USER" "$INSTALL_DIR/data"
chmod 0750 "$INSTALL_DIR/data"

if [[ "$ACTION" == "update" ]]; then
    [[ -f "$INSTALL_DIR/config.php" ]] && chown "$WEB_USER":"$WEB_USER" "$INSTALL_DIR/config.php" && chmod 0600 "$INSTALL_DIR/config.php"
    chmod 0775 "$INSTALL_DIR"
    ok "Updated Fandejo to $NEW_VER in $INSTALL_DIR/public (preserved config.php and data/)."
    exit 0
fi

# ==============================================================================
# STEP 3: Initialize config.php
# ==============================================================================
if [[ ! -f "$INSTALL_DIR/config.php" ]]; then
    if [[ -z "$SQLITE_USER" && $NON_INTERACTIVE -eq 0 && -t 0 ]]; then
        echo ""
        info "Optional: Pre-configure an SQLite administrator account now"
        info "(Press Enter to skip and initialize it through the web UI on first sign-in)"
        read -r -p "SQLite Admin Username [skip]: " SQLITE_USER
        if [[ -n "$SQLITE_USER" ]]; then
            read -r -s -p "SQLite Admin Password (min 6 chars): " SQLITE_PASS
            echo ""
        fi
    fi

    if [[ -n "$SQLITE_USER" && -n "$SQLITE_PASS" ]]; then
        FANDEJO_DIR="$INSTALL_DIR" SU="$SQLITE_USER" SP="$SQLITE_PASS" php -r '
            $dir = getenv("FANDEJO_DIR");
            $u = getenv("SU");
            $p = getenv("SP");
            if (strlen($p) < 6) { fwrite(STDERR, "Password must be at least 6 characters\n"); exit(1); }
            $hash = password_hash($p, defined("PASSWORD_ARGON2ID") ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
            $cfg = [
                "sqlite_dirs" => [$dir . "/data"],
                "sqlite_allow_any_dir" => false,
                "sqlite_users" => [$u => $hash],
                "max_login_attempts" => 5,
                "admin_lockout_seconds" => 900,
                "ip_max_attempts" => 12,
                "ip_lockout_seconds" => 900,
                "session_idle_ttl" => 3600,
            ];
            file_put_contents($dir . "/config.php", "<?php\n// Fandejo Configuration — generated by install.sh\nreturn " . var_export($cfg, true) . ";\n");
        '
        ok "Created $INSTALL_DIR/config.php with Argon2id hash for SQLite admin '$SQLITE_USER'"
    else
        cp "$INSTALL_DIR/config.example.php" "$INSTALL_DIR/config.php"
        ok "Created default $INSTALL_DIR/config.php (first-time SQLite setup enabled in web UI)"
    fi
fi

chown "$WEB_USER":"$WEB_USER" "$INSTALL_DIR/config.php"
chmod 0600 "$INSTALL_DIR/config.php"
# Ensure $INSTALL_DIR is group-writable by $WEB_USER so api.php can create/update config.php and .fandejo_locks.json
chmod 0775 "$INSTALL_DIR"

# ==============================================================================
# STEP 4: Configure Web Server for $URL_PATH (/fandejo)
# ==============================================================================
DETECTED_SERVER="$(detect_server_mode)"
info "Configuring web server ($DETECTED_SERVER) to serve Fandejo at $URL_PATH..."

configure_apache() {
    local conf_file=""
    if [[ -d /etc/apache2/conf-available ]]; then
        conf_file="/etc/apache2/conf-available/fandejo.conf"
    elif [[ -d /etc/httpd/conf.d ]]; then
        conf_file="/etc/httpd/conf.d/fandejo.conf"
    else
        return 1
    fi

    cat > "$conf_file" <<EOF
# Fandejo Database Foundry — Web Workbench ($URL_PATH)
Alias $URL_PATH $INSTALL_DIR/public

<Directory $INSTALL_DIR/public>
    Options FollowSymLinks
    DirectoryIndex index.html index.php
    AllowOverride None
    Require all granted

    <IfModule mod_headers.c>
        Header always set X-Content-Type-Options "nosniff"
        Header always set X-Frame-Options "DENY"
        Header always set Referrer-Policy "no-referrer"
    </IfModule>
</Directory>
EOF

    if command -v a2enconf >/dev/null 2>&1; then
        a2enconf fandejo >/dev/null
    fi
    if systemctl is-active --quiet apache2 2>/dev/null; then
        systemctl reload apache2
        ok "Enabled Apache config ($conf_file) and reloaded apache2"
    elif systemctl is-active --quiet httpd 2>/dev/null; then
        systemctl reload httpd
        ok "Enabled Apache config ($conf_file) and reloaded httpd"
    else
        ok "Wrote Apache config to $conf_file (reload Apache to activate)"
    fi
}

configure_nginx() {
    local fpm_pass
    fpm_pass="$(detect_php_fpm)"
    mkdir -p /etc/nginx/snippets
    local snippet="/etc/nginx/snippets/fandejo.conf"

    cat > "$snippet" <<EOF
# Fandejo Database Foundry — Nginx location snippet ($URL_PATH)
# Include this file inside any server {} block:
#   include snippets/fandejo.conf;

location = $URL_PATH {
    return 301 $URL_PATH/;
}

location ^~ $URL_PATH/ {
    alias $INSTALL_DIR/public/;
    index index.html index.php;
    client_max_body_size 128M;

    location ~ \.php$ {
        fastcgi_split_path_info ^($URL_PATH)(/.+\.php)(.*)\$;
        fastcgi_param SCRIPT_FILENAME $INSTALL_DIR/public\$fastcgi_script_name;
        fastcgi_param SCRIPT_NAME $URL_PATH\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_pass $fpm_pass;
    }
}
EOF
    ok "Created Nginx snippet at $snippet (upstream: $fpm_pass)"

    local injected=0
    for site in /etc/nginx/sites-enabled/default /etc/nginx/conf.d/default.conf; do
        if [[ -f "$site" ]] && ! grep -q "snippets/fandejo.conf" "$site"; then
            cp "$site" "${site}.bak.fandejo"
            awk '
                /^[[:space:]]*}[[:space:]]*$/ && !done {
                    print "    include snippets/fandejo.conf;"
                    done=1
                }
                { print }
            ' "${site}.bak.fandejo" > "$site"
            if nginx -t >/dev/null 2>&1; then
                rm -f "${site}.bak.fandejo"
                injected=1
                ok "Included snippets/fandejo.conf in $site"
            else
                mv "${site}.bak.fandejo" "$site"
                warn "Could not auto-inject into $site; add 'include snippets/fandejo.conf;' to your server {} block manually."
            fi
        elif [[ -f "$site" ]] && grep -q "snippets/fandejo.conf" "$site"; then
            injected=1
        fi
    done

    if [[ $injected -eq 1 ]] && systemctl is-active --quiet nginx 2>/dev/null; then
        systemctl reload nginx
        ok "Reloaded Nginx"
    elif [[ $injected -eq 0 ]]; then
        info "To enable on a specific Nginx virtual host, add inside its server {} block:"
        info "    include snippets/fandejo.conf;"
        info "Then run: sudo nginx -t && sudo systemctl reload nginx"
    fi
}

configure_webroot() {
    local target_root="${WEBROOT:-/var/www/html}"
    mkdir -p "$target_root"
    ln -sfn "$INSTALL_DIR/public" "$target_root/$URL_SLUG"
    ok "Linked $target_root/$URL_SLUG -> $INSTALL_DIR/public"
}

case "$DETECTED_SERVER" in
    apache)
        configure_apache || configure_webroot
        ;;
    nginx)
        configure_nginx
        ;;
    webroot)
        configure_webroot
        ;;
    *)
        err "Unsupported --server mode '$DETECTED_SERVER' (use apache, nginx, or webroot)"
        ;;
esac

echo ""
ok "=================================================================="
ok "Fandejo ($NEW_VER) by Toondout is installed!"
ok "  URL:              http://<your-server>$URL_PATH/"
ok "  Install Path:     $INSTALL_DIR"
ok "  Config File:      $INSTALL_DIR/config.php"
ok "  SQLite Sandbox:   $INSTALL_DIR/data"
ok "  Emergency Unlock: sudo php $INSTALL_DIR/public/api.php --unlock"
ok "  Check Updates:    sudo $INSTALL_DIR/install.sh --check-update"
ok "  Apply Update:     sudo $INSTALL_DIR/install.sh --update"
ok "  Uninstall:        sudo $INSTALL_DIR/install.sh --uninstall"
ok "=================================================================="
