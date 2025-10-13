#!/bin/sh
#
mydir=`dirname $0`

test -f "$mydir/composer.phar" || {
	php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
	php composer-setup.php
	rm composer-setup.php
}
php $mydir/composer.phar $@
