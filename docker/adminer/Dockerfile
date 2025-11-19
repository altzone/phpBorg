FROM adminer:latest

# Copy custom phpBorg authentication plugin
COPY phpborg-auth-plugin.php /var/www/html/plugins-enabled/

# Set Adminer design (optional)
ENV ADMINER_DESIGN=pepa-linha-dark

# Expose port (will be overridden by docker run -p)
EXPOSE 8080
