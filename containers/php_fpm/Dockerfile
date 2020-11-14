FROM phpsrc/php-nightly-master:latest

USER root

RUN apt-get update -qq \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y nginx \
    ca-certificates wget curl git \
    lsb-release apt-transport-https gnupg

## Install 3rd party PHP 7.4 packages
#RUN echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" | tee -a /etc/apt/sources.list.d/php.list
#
#RUN wget -O /etc/apt/trusted.gpg.d/php.gpg https://packages.sury.org/php/apt.gpg



# Make the default directory you
WORKDIR /var/app

CMD tail -f README.md

# CMD sh /var/app/containers/php_fpm/entrypoint.sh