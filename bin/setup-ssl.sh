#!/bin/bash
#
# phpBorg SSL/TLS Certificate Setup Script
# Supports: Self-signed, Let's Encrypt (HTTP-01, DNS-01/Cloudflare), Custom certificates
#

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

CERT_DIR="/etc/phpborg/ssl"
NGINX_CONF="/etc/nginx/sites-available/phpborg"
NGINX_ENABLED="/etc/nginx/sites-enabled/phpborg"
PHPBORG_ROOT="/opt/newphpborg/phpBorg"

# ============================================
# Helper functions
# ============================================

print_header() {
    echo -e "\n${BLUE}╔══════════════════════════════════════════════════════╗${NC}"
    echo -e "${BLUE}║${NC}     ${GREEN}phpBorg SSL/TLS Certificate Setup${NC}               ${BLUE}║${NC}"
    echo -e "${BLUE}╚══════════════════════════════════════════════════════╝${NC}\n"
}

print_success() {
    echo -e "${GREEN}✓${NC} $1"
}

print_error() {
    echo -e "${RED}✗${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}!${NC} $1"
}

print_info() {
    echo -e "${BLUE}ℹ${NC} $1"
}

check_root() {
    if [ "$EUID" -ne 0 ]; then
        print_error "This script must be run as root"
        exit 1
    fi
}

ensure_cert_dir() {
    mkdir -p "$CERT_DIR"
    chmod 755 "$CERT_DIR"
}

install_certbot() {
    if ! command -v certbot &> /dev/null; then
        print_info "Installing certbot..."
        apt-get update -qq
        apt-get install -y certbot python3-certbot-nginx
        print_success "certbot installed"
    fi
}

install_cloudflare_plugin() {
    if ! dpkg -l | grep -q python3-certbot-dns-cloudflare; then
        print_info "Installing Cloudflare DNS plugin..."
        apt-get install -y python3-certbot-dns-cloudflare
        print_success "Cloudflare plugin installed"
    fi
}

# ============================================
# SSL Status
# ============================================

show_status() {
    echo -e "\n${BLUE}Current SSL Status:${NC}"
    echo "═══════════════════════════════════════"

    if [ -f "$CERT_DIR/cert.pem" ]; then
        cert_info=$(openssl x509 -in "$CERT_DIR/cert.pem" -noout -subject -issuer -dates 2>/dev/null)

        if [ -n "$cert_info" ]; then
            domain=$(openssl x509 -in "$CERT_DIR/cert.pem" -noout -subject 2>/dev/null | sed 's/.*CN = //')
            issuer=$(openssl x509 -in "$CERT_DIR/cert.pem" -noout -issuer 2>/dev/null | sed 's/.*CN = //' | sed 's/.*O = //')
            valid_from=$(openssl x509 -in "$CERT_DIR/cert.pem" -noout -startdate 2>/dev/null | sed 's/notBefore=//')
            valid_to=$(openssl x509 -in "$CERT_DIR/cert.pem" -noout -enddate 2>/dev/null | sed 's/notAfter=//')

            # Calculate days remaining
            end_date=$(date -d "$valid_to" +%s 2>/dev/null || date +%s)
            now=$(date +%s)
            days_remaining=$(( (end_date - now) / 86400 ))

            print_success "SSL is enabled"
            echo "  Domain:     $domain"
            echo "  Issuer:     $issuer"
            echo "  Valid from: $valid_from"
            echo "  Valid to:   $valid_to"

            if [ "$days_remaining" -le 7 ]; then
                print_error "Days remaining: $days_remaining (CRITICAL!)"
            elif [ "$days_remaining" -le 30 ]; then
                print_warning "Days remaining: $days_remaining (renew soon)"
            else
                print_success "Days remaining: $days_remaining"
            fi

            # Check auto-renewal
            if [ -f "/etc/cron.d/phpborg-ssl-renew" ]; then
                print_success "Auto-renewal: configured"
            else
                print_warning "Auto-renewal: not configured"
            fi
        fi
    else
        print_warning "SSL is not configured"
        echo "  Run this script to enable SSL"
    fi

    echo ""
}

# ============================================
# Self-signed certificate
# ============================================

generate_self_signed() {
    local domain="$1"
    local days="${2:-365}"

    print_info "Generating self-signed certificate for $domain (valid for $days days)..."

    ensure_cert_dir

    openssl req -x509 -nodes -days "$days" -newkey rsa:2048 \
        -keyout "$CERT_DIR/privkey.pem" \
        -out "$CERT_DIR/cert.pem" \
        -subj "/CN=$domain/O=phpBorg/OU=Self-Signed Certificate" \
        2>/dev/null

    # Create fullchain (same as cert for self-signed)
    cp "$CERT_DIR/cert.pem" "$CERT_DIR/fullchain.pem"

    # Set permissions
    chmod 600 "$CERT_DIR/privkey.pem"
    chmod 644 "$CERT_DIR/cert.pem"
    chmod 644 "$CERT_DIR/fullchain.pem"

    print_success "Self-signed certificate generated"

    update_nginx_ssl "$domain"
}

# ============================================
# Let's Encrypt HTTP-01
# ============================================

request_letsencrypt_http() {
    local domain="$1"
    local email="$2"

    install_certbot

    print_info "Requesting Let's Encrypt certificate for $domain via HTTP-01..."
    print_warning "Make sure port 80 is accessible from the internet!"

    # Create webroot
    mkdir -p /var/www/html/.well-known/acme-challenge

    # Run certbot
    if certbot certonly --webroot -w /var/www/html \
        -d "$domain" \
        --email "$email" \
        --agree-tos \
        --non-interactive \
        --cert-name phpborg; then

        print_success "Certificate obtained!"
        copy_letsencrypt_certs
        setup_auto_renewal
        update_nginx_ssl "$domain"
    else
        print_error "Failed to obtain certificate"
        exit 1
    fi
}

# ============================================
# Let's Encrypt DNS-01 (Cloudflare)
# ============================================

request_letsencrypt_cloudflare() {
    local domain="$1"
    local email="$2"
    local cf_token="$3"

    install_certbot
    install_cloudflare_plugin

    print_info "Requesting Let's Encrypt certificate for $domain via DNS-01 (Cloudflare)..."

    ensure_cert_dir

    # Create credentials file
    local cf_creds="$CERT_DIR/cloudflare.ini"
    echo "dns_cloudflare_api_token = $cf_token" > "$cf_creds"
    chmod 600 "$cf_creds"

    # Run certbot
    if certbot certonly --dns-cloudflare \
        --dns-cloudflare-credentials "$cf_creds" \
        -d "$domain" \
        --email "$email" \
        --agree-tos \
        --non-interactive \
        --cert-name phpborg; then

        print_success "Certificate obtained!"
        copy_letsencrypt_certs
        setup_auto_renewal
        update_nginx_ssl "$domain"
    else
        print_error "Failed to obtain certificate"
        exit 1
    fi
}

# ============================================
# Copy Let's Encrypt certificates
# ============================================

copy_letsencrypt_certs() {
    local le_dir="/etc/letsencrypt/live/phpborg"

    if [ -d "$le_dir" ]; then
        ensure_cert_dir
        cp -L "$le_dir/privkey.pem" "$CERT_DIR/privkey.pem"
        cp -L "$le_dir/cert.pem" "$CERT_DIR/cert.pem"
        cp -L "$le_dir/chain.pem" "$CERT_DIR/chain.pem"
        cp -L "$le_dir/fullchain.pem" "$CERT_DIR/fullchain.pem"

        chmod 600 "$CERT_DIR/privkey.pem"
        chmod 644 "$CERT_DIR/cert.pem"
        chmod 644 "$CERT_DIR/chain.pem"
        chmod 644 "$CERT_DIR/fullchain.pem"

        print_success "Certificates copied to $CERT_DIR"
    else
        print_error "Let's Encrypt certificates not found at $le_dir"
        exit 1
    fi
}

# ============================================
# Setup auto-renewal cron
# ============================================

setup_auto_renewal() {
    cat > /etc/cron.d/phpborg-ssl-renew << 'EOF'
# phpBorg SSL certificate auto-renewal
0 3 * * * root certbot renew --cert-name phpborg --quiet --deploy-hook "systemctl reload nginx"
EOF
    chmod 644 /etc/cron.d/phpborg-ssl-renew
    print_success "Auto-renewal cron configured (runs daily at 3:00 AM)"
}

# ============================================
# Upload custom certificate
# ============================================

upload_custom_cert() {
    local cert_file="$1"
    local key_file="$2"
    local chain_file="$3"

    print_info "Installing custom certificate..."

    # Validate certificate
    if ! openssl x509 -in "$cert_file" -noout 2>/dev/null; then
        print_error "Invalid certificate file"
        exit 1
    fi

    # Validate key
    if ! openssl rsa -in "$key_file" -check -noout 2>/dev/null; then
        print_error "Invalid private key file"
        exit 1
    fi

    # Verify key matches certificate
    cert_mod=$(openssl x509 -noout -modulus -in "$cert_file" 2>/dev/null | md5sum)
    key_mod=$(openssl rsa -noout -modulus -in "$key_file" 2>/dev/null | md5sum)

    if [ "$cert_mod" != "$key_mod" ]; then
        print_error "Private key does not match certificate"
        exit 1
    fi

    ensure_cert_dir

    # Copy files
    cp "$cert_file" "$CERT_DIR/cert.pem"
    cp "$key_file" "$CERT_DIR/privkey.pem"

    if [ -n "$chain_file" ] && [ -f "$chain_file" ]; then
        cp "$chain_file" "$CERT_DIR/chain.pem"
        cat "$cert_file" "$chain_file" > "$CERT_DIR/fullchain.pem"
    else
        cp "$cert_file" "$CERT_DIR/fullchain.pem"
    fi

    chmod 600 "$CERT_DIR/privkey.pem"
    chmod 644 "$CERT_DIR/cert.pem"
    chmod 644 "$CERT_DIR/fullchain.pem"

    # Get domain from certificate
    domain=$(openssl x509 -in "$cert_file" -noout -subject 2>/dev/null | sed 's/.*CN = //')

    print_success "Custom certificate installed"
    update_nginx_ssl "$domain"
}

# ============================================
# Update Nginx configuration
# ============================================

detect_php_fpm_socket() {
    # Check for phpborg-specific socket first
    for sock in /run/php/phpborg-8.3-fpm.sock /run/php/phpborg-8.2-fpm.sock /run/php/phpborg-fpm.sock; do
        if [ -S "$sock" ]; then
            echo "$sock"
            return
        fi
    done
    # Fall back to standard PHP-FPM sockets
    for sock in /run/php/php8.3-fpm.sock /run/php/php8.2-fpm.sock /var/run/php/php-fpm.sock /run/php-fpm/php-fpm.sock; do
        if [ -S "$sock" ]; then
            echo "$sock"
            return
        fi
    done
    # Default
    echo "/run/php/php8.3-fpm.sock"
}

update_nginx_ssl() {
    local domain="$1"

    print_info "Updating nginx configuration..."

    # Backup current config
    if [ -f "$NGINX_CONF" ]; then
        cp "$NGINX_CONF" "$NGINX_CONF.bak"
    fi

    # Detect PHP-FPM socket
    local php_socket
    php_socket=$(detect_php_fpm_socket)

    # Use template file if it exists
    local template_file="${PHPBORG_ROOT}/install/templates/nginx-ssl.conf.template"

    if [ -f "$template_file" ]; then
        print_info "Using template: $template_file"
        sed -e "s|__DOMAIN__|${domain}|g" \
            -e "s|__PHPBORG_ROOT__|${PHPBORG_ROOT}|g" \
            -e "s|__CERT_DIR__|${CERT_DIR}|g" \
            -e "s|__PHP_FPM_SOCK__|${php_socket}|g" \
            "$template_file" > "$NGINX_CONF"
    else
        print_warning "Template not found, using inline config"
        # Fallback inline config (simplified)
        cat > "$NGINX_CONF" << EOF
# phpBorg Nginx SSL Configuration
# Generated by setup-ssl.sh on $(date)

server {
    listen 80;
    listen [::]:80;
    server_name $domain;
    location /.well-known/acme-challenge/ { root /var/www/html; }
    location / { return 301 https://\$host\$request_uri; }
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name $domain;

    ssl_certificate $CERT_DIR/fullchain.pem;
    ssl_certificate_key $CERT_DIR/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;

    root $PHPBORG_ROOT/frontend/dist;
    index index.html;
    client_max_body_size 100M;

    location / { try_files \$uri \$uri/ /index.html; }

    location /api/sse/stream {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Connection "";
        proxy_buffering off;
        proxy_read_timeout 86400s;
    }

    location ~ ^/api/ {
        fastcgi_pass unix:$php_socket;
        fastcgi_param SCRIPT_FILENAME $PHPBORG_ROOT/api/public/index.php;
        include fastcgi_params;
        fastcgi_read_timeout 300s;
    }

    location ~ ^/downloads/ {
        fastcgi_pass unix:$php_socket;
        fastcgi_param SCRIPT_FILENAME $PHPBORG_ROOT/api/public/index.php;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
EOF
    fi

    # Create symlink if needed
    if [ ! -L "$NGINX_ENABLED" ]; then
        ln -sf "$NGINX_CONF" "$NGINX_ENABLED"
    fi

    # Test config
    if nginx -t 2>/dev/null; then
        systemctl reload nginx
        print_success "Nginx configured and reloaded"
    else
        print_error "Nginx config test failed, restoring backup"
        if [ -f "$NGINX_CONF.bak" ]; then
            mv "$NGINX_CONF.bak" "$NGINX_CONF"
        fi
        exit 1
    fi
}

# ============================================
# Disable SSL
# ============================================

disable_ssl() {
    print_info "Disabling SSL..."

    # Backup current config
    if [ -f "$NGINX_CONF" ]; then
        cp "$NGINX_CONF" "$NGINX_CONF.bak"
    fi

    # Detect PHP-FPM socket
    local php_socket
    php_socket=$(detect_php_fpm_socket)

    # Use template file if it exists
    local template_file="${PHPBORG_ROOT}/install/templates/nginx.conf.template"

    if [ -f "$template_file" ]; then
        print_info "Using template: $template_file"
        local php_version="8.3"
        sed -e "s|__DOMAIN__|_|g" \
            -e "s|__PHPBORG_ROOT__|${PHPBORG_ROOT}|g" \
            -e "s|__PHP_VERSION__|${php_version}|g" \
            "$template_file" > "$NGINX_CONF"
    else
        print_warning "Template not found, using inline config"
        # Fallback inline config
        cat > "$NGINX_CONF" << EOF
# phpBorg Nginx Configuration (HTTP)
# Generated by setup-ssl.sh on $(date)

server {
    listen 80;
    listen [::]:80;
    server_name _;

    root $PHPBORG_ROOT/frontend/dist;
    index index.html;
    client_max_body_size 100M;

    location / { try_files \$uri \$uri/ /index.html; }
    location /.well-known/acme-challenge/ { root /var/www/html; }

    location /api/sse/stream {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Connection "";
        proxy_buffering off;
        proxy_read_timeout 86400s;
    }

    location ~ ^/api/ {
        fastcgi_pass unix:$php_socket;
        fastcgi_param SCRIPT_FILENAME $PHPBORG_ROOT/api/public/index.php;
        include fastcgi_params;
        fastcgi_read_timeout 300s;
    }

    location ~ ^/downloads/ {
        fastcgi_pass unix:$php_socket;
        fastcgi_param SCRIPT_FILENAME $PHPBORG_ROOT/api/public/index.php;
        include fastcgi_params;
    }

    location ~ /\. { deny all; }
}
EOF
    fi

    # Remove auto-renewal cron
    rm -f /etc/cron.d/phpborg-ssl-renew

    # Test and reload nginx
    if nginx -t 2>/dev/null; then
        systemctl reload nginx
        print_success "SSL disabled, now using HTTP"
    else
        print_error "Nginx config test failed"
        if [ -f "$NGINX_CONF.bak" ]; then
            mv "$NGINX_CONF.bak" "$NGINX_CONF"
        fi
        exit 1
    fi
}

# ============================================
# Interactive mode
# ============================================

interactive_setup() {
    print_header
    show_status

    echo "Choose certificate type:"
    echo "  1) Self-signed (quick, browser warnings)"
    echo "  2) Let's Encrypt HTTP-01 (requires port 80 open)"
    echo "  3) Let's Encrypt DNS-01 via Cloudflare"
    echo "  4) Upload custom certificate"
    echo "  5) Disable SSL (HTTP only)"
    echo "  6) Show status and exit"
    echo ""
    read -p "Enter choice [1-6]: " choice

    case $choice in
        1)
            read -p "Enter domain name: " domain
            read -p "Validity in days [365]: " days
            days=${days:-365}
            generate_self_signed "$domain" "$days"
            ;;
        2)
            read -p "Enter domain name: " domain
            read -p "Enter email address: " email
            request_letsencrypt_http "$domain" "$email"
            ;;
        3)
            read -p "Enter domain name: " domain
            read -p "Enter email address: " email
            read -p "Enter Cloudflare API token: " cf_token
            request_letsencrypt_cloudflare "$domain" "$email" "$cf_token"
            ;;
        4)
            read -p "Path to certificate file: " cert_file
            read -p "Path to private key file: " key_file
            read -p "Path to chain file (optional): " chain_file
            upload_custom_cert "$cert_file" "$key_file" "$chain_file"
            ;;
        5)
            disable_ssl
            ;;
        6)
            exit 0
            ;;
        *)
            print_error "Invalid choice"
            exit 1
            ;;
    esac

    echo ""
    show_status
}

# ============================================
# Usage
# ============================================

usage() {
    echo "Usage: $0 [COMMAND] [OPTIONS]"
    echo ""
    echo "Commands:"
    echo "  status                              Show current SSL status"
    echo "  self-signed DOMAIN [DAYS]           Generate self-signed certificate"
    echo "  letsencrypt-http DOMAIN EMAIL       Request Let's Encrypt via HTTP-01"
    echo "  letsencrypt-cf DOMAIN EMAIL TOKEN   Request Let's Encrypt via Cloudflare DNS"
    echo "  custom CERT KEY [CHAIN]             Install custom certificate"
    echo "  disable                             Disable SSL and use HTTP"
    echo "  renew                               Force certificate renewal"
    echo "  test-renewal                        Test renewal (dry-run)"
    echo ""
    echo "Run without arguments for interactive mode."
    echo ""
}

# ============================================
# Main
# ============================================

check_root

if [ $# -eq 0 ]; then
    interactive_setup
    exit 0
fi

case "$1" in
    status)
        show_status
        ;;
    self-signed)
        if [ -z "$2" ]; then
            echo "Usage: $0 self-signed DOMAIN [DAYS]"
            exit 1
        fi
        generate_self_signed "$2" "${3:-365}"
        ;;
    letsencrypt-http)
        if [ -z "$2" ] || [ -z "$3" ]; then
            echo "Usage: $0 letsencrypt-http DOMAIN EMAIL"
            exit 1
        fi
        request_letsencrypt_http "$2" "$3"
        ;;
    letsencrypt-cf)
        if [ -z "$2" ] || [ -z "$3" ] || [ -z "$4" ]; then
            echo "Usage: $0 letsencrypt-cf DOMAIN EMAIL CLOUDFLARE_TOKEN"
            exit 1
        fi
        request_letsencrypt_cloudflare "$2" "$3" "$4"
        ;;
    custom)
        if [ -z "$2" ] || [ -z "$3" ]; then
            echo "Usage: $0 custom CERT_FILE KEY_FILE [CHAIN_FILE]"
            exit 1
        fi
        upload_custom_cert "$2" "$3" "$4"
        ;;
    disable)
        disable_ssl
        ;;
    renew)
        print_info "Forcing certificate renewal..."
        certbot renew --cert-name phpborg --force-renewal
        copy_letsencrypt_certs
        systemctl reload nginx
        print_success "Certificate renewed"
        ;;
    test-renewal)
        print_info "Testing certificate renewal (dry-run)..."
        certbot renew --cert-name phpborg --dry-run
        ;;
    -h|--help|help)
        usage
        ;;
    *)
        echo "Unknown command: $1"
        usage
        exit 1
        ;;
esac
