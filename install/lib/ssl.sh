#!/bin/bash
#
# phpBorg Installer - SSL/TLS Module
#
# This module handles SSL/TLS certificate configuration
# Supports: Self-signed, Let's Encrypt (HTTP-01, DNS-01/Cloudflare), Custom certificates
#

# ============================================
# SSL Setup Functions
# ============================================

#
# Ask user if they want to configure SSL
#
ask_ssl_setup() {
    echo ""
    print_header "SSL/TLS Configuration"

    log_info "SSL/TLS is recommended for production environments"
    log_info "It encrypts all communication between browsers and phpBorg"
    echo ""

    if [ "${INSTALL_MODE}" = "auto" ]; then
        log_info "SSL setup skipped in automatic mode"
        log_info "Run 'setup-ssl.sh' later to configure SSL"
        return 1
    fi

    if ! confirm "Would you like to configure SSL/TLS now?" "n"; then
        log_info "SSL setup skipped. You can run '${PHPBORG_ROOT}/bin/setup-ssl.sh' later"
        return 1
    fi

    return 0
}

#
# Show SSL options and let user choose
#
select_ssl_type() {
    echo ""
    echo -e "${CYAN}Available SSL options:${NC}"
    echo ""
    echo "  1) Self-signed certificate"
    echo "     • Quick setup, works immediately"
    echo "     • Browsers will show security warnings"
    echo "     • Good for internal/testing use"
    echo ""
    echo "  2) Let's Encrypt (HTTP-01)"
    echo "     • Free trusted certificate"
    echo "     • Requires port 80 accessible from internet"
    echo "     • Automatic renewal"
    echo ""
    echo "  3) Let's Encrypt (DNS-01 via Cloudflare)"
    echo "     • Free trusted certificate"
    echo "     • No need for port 80"
    echo "     • Requires Cloudflare account"
    echo ""
    echo "  4) Skip - configure later"
    echo ""

    local choice
    read -p "Enter choice [1-4]: " choice

    case $choice in
        1) SSL_TYPE="self-signed" ;;
        2) SSL_TYPE="letsencrypt-http" ;;
        3) SSL_TYPE="letsencrypt-cloudflare" ;;
        4|*) SSL_TYPE="skip" ;;
    esac
}

#
# Configure self-signed certificate
#
setup_ssl_self_signed() {
    log_step "Setting up self-signed certificate"

    local domain
    read -p "Enter domain name (e.g., backup.example.com or IP): " domain

    if [ -z "$domain" ]; then
        domain=$(hostname -f 2>/dev/null || hostname)
    fi

    local days=365
    if [ "${INSTALL_MODE}" = "interactive" ]; then
        read -p "Certificate validity in days [365]: " input_days
        [ -n "$input_days" ] && days=$input_days
    fi

    log_info "Generating self-signed certificate for ${domain}..."

    if "${PHPBORG_ROOT}/bin/setup-ssl.sh" self-signed "$domain" "$days"; then
        log_success "Self-signed certificate created"
        mark_step_completed "ssl_setup"
        return 0
    else
        log_error "Failed to create self-signed certificate"
        return 1
    fi
}

#
# Configure Let's Encrypt via HTTP-01
#
setup_ssl_letsencrypt_http() {
    log_step "Setting up Let's Encrypt (HTTP-01)"

    log_warn "Make sure port 80 is accessible from the internet!"
    echo ""

    local domain
    read -p "Enter domain name (e.g., backup.example.com): " domain

    if [ -z "$domain" ]; then
        log_error "Domain is required for Let's Encrypt"
        return 1
    fi

    local email
    read -p "Enter email address for Let's Encrypt: " email

    if [ -z "$email" ]; then
        log_error "Email is required for Let's Encrypt"
        return 1
    fi

    log_info "Requesting Let's Encrypt certificate..."

    if "${PHPBORG_ROOT}/bin/setup-ssl.sh" letsencrypt-http "$domain" "$email"; then
        log_success "Let's Encrypt certificate obtained"
        mark_step_completed "ssl_setup"
        return 0
    else
        log_error "Failed to obtain Let's Encrypt certificate"
        log_info "You can retry later with: ${PHPBORG_ROOT}/bin/setup-ssl.sh"
        return 1
    fi
}

#
# Configure Let's Encrypt via Cloudflare DNS
#
setup_ssl_letsencrypt_cloudflare() {
    log_step "Setting up Let's Encrypt (DNS-01 via Cloudflare)"

    echo ""
    log_info "You need a Cloudflare API token with Zone:DNS:Edit permission"
    log_info "Create one at: https://dash.cloudflare.com/profile/api-tokens"
    echo ""

    local domain
    read -p "Enter domain name (e.g., backup.example.com): " domain

    if [ -z "$domain" ]; then
        log_error "Domain is required"
        return 1
    fi

    local email
    read -p "Enter email address for Let's Encrypt: " email

    if [ -z "$email" ]; then
        log_error "Email is required"
        return 1
    fi

    local cf_token
    read -p "Enter Cloudflare API token: " cf_token

    if [ -z "$cf_token" ]; then
        log_error "Cloudflare API token is required"
        return 1
    fi

    log_info "Requesting Let's Encrypt certificate via Cloudflare DNS..."

    if "${PHPBORG_ROOT}/bin/setup-ssl.sh" letsencrypt-cf "$domain" "$email" "$cf_token"; then
        log_success "Let's Encrypt certificate obtained"
        mark_step_completed "ssl_setup"
        return 0
    else
        log_error "Failed to obtain Let's Encrypt certificate"
        log_info "You can retry later with: ${PHPBORG_ROOT}/bin/setup-ssl.sh"
        return 1
    fi
}

#
# Main SSL setup function
#
setup_ssl() {
    # Check if already configured
    if is_step_completed "ssl_setup"; then
        log_info "SSL already configured (skipped)"
        return 0
    fi

    # Ask if user wants SSL
    if ! ask_ssl_setup; then
        return 0
    fi

    # Let user choose SSL type
    select_ssl_type

    case "$SSL_TYPE" in
        "self-signed")
            setup_ssl_self_signed
            ;;
        "letsencrypt-http")
            setup_ssl_letsencrypt_http
            ;;
        "letsencrypt-cloudflare")
            setup_ssl_letsencrypt_cloudflare
            ;;
        "skip"|*)
            log_info "SSL setup skipped"
            log_info "Run '${PHPBORG_ROOT}/bin/setup-ssl.sh' to configure SSL later"
            ;;
    esac

    return 0
}

#
# Check SSL status
#
check_ssl_status() {
    if [ -f "/etc/phpborg/ssl/cert.pem" ]; then
        local domain
        domain=$(openssl x509 -in /etc/phpborg/ssl/cert.pem -noout -subject 2>/dev/null | sed 's/.*CN = //')
        local expiry
        expiry=$(openssl x509 -in /etc/phpborg/ssl/cert.pem -noout -enddate 2>/dev/null | sed 's/notAfter=//')

        log_success "SSL is configured"
        log_info "Domain: ${domain}"
        log_info "Expires: ${expiry}"
        return 0
    else
        log_info "SSL is not configured"
        return 1
    fi
}
