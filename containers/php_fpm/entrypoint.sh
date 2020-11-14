
set -e
set -x


/usr/sbin/php-fpm7.4 \
  --nodaemonize \
  --fpm-config=/var/app/containers/php_fpm/config/fpm.conf \
  -c /var/app/containers/php_fpm/config/php.ini