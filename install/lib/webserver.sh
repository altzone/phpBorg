#!/bin/bash
#
# phpBorg Installation - Web Server Configuration
# Configures Nginx or Apache for phpBorg
#

# Domain configuration
DOMAIN="${DOMAIN:-localhost}"

#
# Detect PHP version for FPM socket
#

get_php_fpm_socket() {
    local php_version=$(${PHP_BINARY} -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')

    # Check if custom versioned phpborg pool exists
    if [ -S "/run/php/phpborg-${php_version}-fpm.sock" ]; then
        echo "/run/php/phpborg-${php_version}-fpm.sock"
    # Check if old non-versioned phpborg pool exists (backward compatibility)
    elif [ -S "/run/php/phpborg-fpm.sock" ]; then
        echo "/run/php/phpborg-fpm.sock"
    # Otherwise use default PHP-FPM socket
    elif [ -S "/run/php/php${php_version}-fpm.sock" ]; then
        echo "/run/php/php${php_version}-fpm.sock"
    elif [ -S "/run/php-fpm/php-fpm.sock" ]; then
        echo "/run/php-fpm/php-fpm.sock"
    elif [ -S "/var/run/php/php${php_version}-fpm.sock" ]; then
        echo "/var/run/php/php${php_version}-fpm.sock"
    else
        echo "/run/php/php-fpm.sock"
    fi
}

#
# Nginx configuration
#

configure_nginx() {
    print_section "Configuring Nginx"

    local template_file="${PHPBORG_ROOT}/install/templates/nginx.conf.template"
    local config_file="/etc/nginx/sites-available/phpborg"
    local enabled_file="/etc/nginx/sites-enabled/phpborg"

    # Check if template exists
    if [ ! -f "${template_file}" ]; then
        log_error "Nginx template not found: ${template_file}"
        return 1
    fi

    # Prompt for domain in interactive mode
    if [ "${INSTALL_MODE}" = "interactive" ]; then
        prompt "Server domain/IP" "localhost" DOMAIN
    fi

    # Get PHP version
    local php_version=$(${PHP_BINARY} -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')

    # Backup existing config
    backup_file "${config_file}"

    # Generate config from template
    log_info "Creating Nginx configuration: ${config_file}"

    sed -e "s|__DOMAIN__|${DOMAIN}|g" \
        -e "s|__PHPBORG_ROOT__|${PHPBORG_ROOT}|g" \
        -e "s|__PHP_VERSION__|${php_version}|g" \
        "${template_file}" > "${config_file}"

    if [ $? -ne 0 ]; then
        log_error "Failed to create Nginx configuration"
        return 1
    fi

    # Enable site
    log_info "Enabling phpBorg site"
    ln -sf "${config_file}" "${enabled_file}"

    # Disable default site if it exists
    if [ -L "/etc/nginx/sites-enabled/default" ]; then
        log_info "Disabling default Nginx site"
        rm -f "/etc/nginx/sites-enabled/default"
    fi

    # Test Nginx configuration
    log_info "Testing Nginx configuration"
    if nginx -t >> "${INSTALL_LOG}" 2>&1; then
        log_success "Nginx configuration is valid"
    else
        log_error "Nginx configuration test failed"
        return 1
    fi

    # Reload Nginx
    log_info "Reloading Nginx"
    if systemctl reload nginx; then
        log_success "Nginx reloaded"
        save_state "configure_nginx" "completed" "{\"domain\":\"${DOMAIN}\"}"
        return 0
    else
        log_error "Failed to reload Nginx"
        return 1
    fi
}

#
# Apache configuration
#

configure_apache() {
    print_section "Configuring Apache"

    local template_file="${PHPBORG_ROOT}/install/templates/apache.conf.template"
    local config_file="/etc/apache2/sites-available/phpborg.conf"
    local enabled_file="/etc/apache2/sites-enabled/phpborg.conf"

    # Check if template exists
    if [ ! -f "${template_file}" ]; then
        log_error "Apache template not found: ${template_file}"
        return 1
    fi

    # Prompt for domain in interactive mode
    if [ "${INSTALL_MODE}" = "interactive" ]; then
        prompt "Server domain/IP" "localhost" DOMAIN
    fi

    # Get PHP version
    local php_version=$(${PHP_BINARY} -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')

    # Backup existing config
    backup_file "${config_file}"

    # Generate config from template
    log_info "Creating Apache configuration: ${config_file}"

    sed -e "s|__DOMAIN__|${DOMAIN}|g" \
        -e "s|__PHPBORG_ROOT__|${PHPBORG_ROOT}|g" \
        -e "s|__PHP_VERSION__|${php_version}|g" \
        "${template_file}" > "${config_file}"

    if [ $? -ne 0 ]; then
        log_error "Failed to create Apache configuration"
        return 1
    fi

    # Enable required modules
    log_info "Enabling Apache modules"
    a2enmod rewrite >> "${INSTALL_LOG}" 2>&1
    a2enmod headers >> "${INSTALL_LOG}" 2>&1
    a2enmod proxy >> "${INSTALL_LOG}" 2>&1
    a2enmod proxy_http >> "${INSTALL_LOG}" 2>&1
    a2enmod proxy_fcgi >> "${INSTALL_LOG}" 2>&1

    # Enable site
    log_info "Enabling phpBorg site"
    ln -sf "${config_file}" "${enabled_file}"

    # Disable default site if it exists
    if [ -L "/etc/apache2/sites-enabled/000-default.conf" ]; then
        log_info "Disabling default Apache site"
        a2dissite 000-default >> "${INSTALL_LOG}" 2>&1
    fi

    # Test Apache configuration
    log_info "Testing Apache configuration"
    if apachectl configtest >> "${INSTALL_LOG}" 2>&1; then
        log_success "Apache configuration is valid"
    else
        log_error "Apache configuration test failed"
        return 1
    fi

    # Reload Apache
    log_info "Reloading Apache"
    if systemctl reload apache2; then
        log_success "Apache reloaded"
        save_state "configure_apache" "completed" "{\"domain\":\"${DOMAIN}\"}"
        return 0
    else
        log_error "Failed to reload Apache"
        return 1
    fi
}

#
# Enable and start web server
#

enable_webserver() {
    print_section "Enabling Web Server"

    local service_name
    if [ "${WEBSERVER}" = "nginx" ]; then
        service_name="nginx"
    else
        service_name="apache2"
    fi

    # Enable at boot
    log_info "Enabling ${service_name} at boot"
    if systemctl enable "${service_name}"; then
        log_success "${service_name} enabled at boot"
    else
        log_warn "Failed to enable ${service_name} at boot"
    fi

    # Start if not running
    if ! systemctl is-active --quiet "${service_name}"; then
        log_info "Starting ${service_name}"
        if systemctl start "${service_name}"; then
            log_success "${service_name} started"
        else
            log_error "Failed to start ${service_name}"
            return 1
        fi
    else
        log_info "${service_name} is already running"
    fi

    save_state "enable_webserver" "completed"
    return 0
}

#
# Configure firewall (if UFW is available)
#

configure_firewall() {
    print_section "Configuring Firewall"

    # Check if UFW is installed
    if ! command -v ufw &> /dev/null; then
        log_info "UFW not installed, skipping firewall configuration"
        save_state "configure_firewall" "completed"
        return 0
    fi

    # Check if UFW is enabled
    if ! ufw status | grep -q "Status: active"; then
        log_info "UFW is not active, skipping firewall configuration"
        save_state "configure_firewall" "completed"
        return 0
    fi

    log_info "Configuring UFW firewall rules"

    # Allow SSH (if not already allowed)
    if ! ufw status | grep -q "22/tcp.*ALLOW"; then
        log_info "Allowing SSH (port 22)"
        ufw allow 22/tcp >> "${INSTALL_LOG}" 2>&1
    fi

    # Allow HTTP
    log_info "Allowing HTTP (port 80)"
    ufw allow 80/tcp >> "${INSTALL_LOG}" 2>&1

    # Allow HTTPS
    log_info "Allowing HTTPS (port 443)"
    ufw allow 443/tcp >> "${INSTALL_LOG}" 2>&1

    # Reload UFW
    if ufw reload >> "${INSTALL_LOG}" 2>&1; then
        log_success "Firewall rules configured"
        save_state "configure_firewall" "completed"
        return 0
    else
        log_warn "Failed to reload firewall"
        save_state "configure_firewall" "completed"
        return 0
    fi
}

#
# Test web server accessibility
#

test_webserver() {
    print_section "Testing Web Server"

    local test_url="http://localhost"

    log_info "Testing web server accessibility: ${test_url}"

    # Wait for web server to be ready
    sleep 2

    # Test with curl
    if curl -s -o /dev/null -w "%{http_code}" "${test_url}" | grep -q "200\|301\|302"; then
        log_success "Web server is accessible"
        save_state "test_webserver" "completed"
        return 0
    else
        log_warn "Web server test failed (may require frontend build)"
        save_state "test_webserver" "completed"
        return 0
    fi
}

#
# Display access information
#

display_access_info() {
    print_section "Access Information"

    echo ""
    echo -e "${GREEN}════════════════════════════════════════════════════════${NC}"
    echo -e "${GREEN}phpBorg Web Interface${NC}"
    echo -e "${GREEN}════════════════════════════════════════════════════════${NC}"
    echo ""

    if [ "${DOMAIN}" = "localhost" ]; then
        echo -e "  ${CYAN}Local Access:${NC}"
        echo -e "    http://localhost"
        echo -e "    http://127.0.0.1"
        echo ""
        echo -e "  ${YELLOW}Note: Configure a domain name for external access${NC}"
    else
        echo -e "  ${CYAN}URL:${NC} http://${DOMAIN}"
        echo ""
        echo -e "  ${YELLOW}Note: Configure SSL/TLS for production use${NC}"
    fi

    echo ""
    echo -e "${GREEN}════════════════════════════════════════════════════════${NC}"
    echo ""
}

#
# Main web server setup orchestrator
#

setup_webserver() {
    print_header "Web Server Setup"

    local errors=0

    # Configure web server based on selection
    if [ "${WEBSERVER}" = "nginx" ]; then
        if ! is_step_completed "configure_nginx"; then
            configure_nginx || errors=$((errors + 1))
        else
            log_info "Nginx already configured (skipped)"
        fi
    else
        if ! is_step_completed "configure_apache"; then
            configure_apache || errors=$((errors + 1))
        else
            log_info "Apache already configured (skipped)"
        fi
    fi

    # Enable and start web server
    if ! is_step_completed "enable_webserver"; then
        enable_webserver || errors=$((errors + 1))
    else
        log_info "Web server already enabled (skipped)"
    fi

    # Configure firewall
    if ! is_step_completed "configure_firewall"; then
        configure_firewall || errors=$((errors + 1))
    else
        log_info "Firewall already configured (skipped)"
    fi

    # Test web server
    if ! is_step_completed "test_webserver"; then
        test_webserver || errors=$((errors + 1))
    else
        log_info "Web server already tested (skipped)"
    fi

    # Display access info
    display_access_info

    if [ ${errors} -eq 0 ]; then
        log_success "Web server setup completed successfully"
        save_state "webserver_setup" "completed"
        return 0
    else
        log_error "Web server setup completed with ${errors} error(s)"
        return 1
    fi
}
