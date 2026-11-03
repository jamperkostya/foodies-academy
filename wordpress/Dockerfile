FROM wordpress:7.0.2-php8.3-apache

# WP-CLI installs and activates the site's plugins on start (see install-plugins.sh).
ARG WP_CLI_VERSION=2.12.0
RUN curl -fsSL -o /usr/local/bin/wp \
		"https://github.com/wp-cli/wp-cli/releases/download/v${WP_CLI_VERSION}/wp-cli-${WP_CLI_VERSION}.phar" \
	&& chmod +x /usr/local/bin/wp

COPY install-plugins.sh foodies-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/install-plugins.sh /usr/local/bin/foodies-entrypoint.sh

ENTRYPOINT ["foodies-entrypoint.sh"]
CMD ["apache2-foreground"]
