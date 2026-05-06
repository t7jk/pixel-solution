#!/bin/bash
set -e

FTP_HOST="host884922.hostido.net.pl"
FTP_USER="plugins@mindcloudsiedlce.pl"
FTP_PASS="9WTMzZZEzTtbaVxJ4zhc"
LOCAL_DIR="$HOME/Pixel-solution/pixel-solution/"
REMOTE_DIR="/pixel-solution/"

echo "Deploying pixel-solution to production..."

lftp -e "
set ftp:ssl-allow no;
open ftp://$FTP_USER:$FTP_PASS@$FTP_HOST;
mirror --reverse --delete --verbose $LOCAL_DIR $REMOTE_DIR;
bye
"

echo "Deploy complete."
