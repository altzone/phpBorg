FROM adminer:latest

# Copy custom phpBorg authentication plugin with correct ownership
COPY --chown=www-data:www-data --chmod=644 phpborg-auth-plugin.php /var/www/html/plugins-enabled/

# Set Adminer design
# Options: pepa-linha-dark, hydra, pappu687, price, mvt, nette, lucas-sandery
ENV ADMINER_DESIGN=hydra

# Expose port (will be overridden by docker run -p)
EXPOSE 8080
