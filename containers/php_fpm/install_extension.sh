

phpize
./configure
make install

echo "extension=fiber.so" > /usr/local/etc/php/conf.d/fiber.ini
echo "" >> /usr/local/etc/php/conf.d/fiber.ini