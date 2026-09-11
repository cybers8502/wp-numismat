#!/bin/bash
cd /home/cybers/cybers.pro/coins || exit 1
/usr/local/bin/wp nbu parse-souvenir --pages=all >> cron-import.log 2>&1 && /usr/local/bin/wp eval-file convert-to-webp.php >> cron-webp.log 2>&1
