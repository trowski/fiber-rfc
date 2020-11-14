FROM debian:9

USER root

RUN apt-get update -qq \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y nginx \
    ca-certificates wget curl \
    lsb-release apt-transport-https gnupg

# RUN wget -O /etc/apt/trusted.gpg.d/php.gpg https://packages.sury.org/php/apt.gpg

WORKDIR /var/app

# CMD ["/usr/sbin/nginx", "-c", "/var/app/containers/nginx/config/nginx.conf"]
CMD sh /var/app/containers/nginx/entrypoint.sh

# For debugging, rather than having the container exit immediately,
# being able to switch the command to be just 'follow that file' allows
# you to bash into the container to inspect the state after nginx has crashed.
# CMD ["tail", "-f", "/var/app/readme.MD"]