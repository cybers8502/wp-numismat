#!/bin/bash
cd /home/cybers/cybers.pro/coins || exit 1

# /usr/local/bin/wp's shebang is `#!/usr/bin/env php`, which resolves `php`
# via $PATH - cron's PATH puts /usr/local/bin first, where `php` is a
# symlink to PHP 5.6 (kept for old sites on this shared box). Interactive
# SSH sessions only avoid this because .bashrc/.bash_profile prepend a
# modern PHP dir to PATH, which cron never sources. PHP 5.6 can't parse
# WordPress 7.1's nullable-type syntax (wp-includes/compat-utf8.php), so
# every run was failing with a parse error before this fix - invoke the
# interpreter explicitly instead of relying on $PATH.
WP_PHP=/usr/local/php83/bin/php
WP_CLI=/usr/local/bin/wp

"$WP_PHP" "$WP_CLI" nbu parse-souvenir --pages=all >> cron-import.log 2>&1 && "$WP_PHP" "$WP_CLI" eval-file convert-to-webp.php >> cron-webp.log 2>&1
