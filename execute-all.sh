#!/bin/bash

# Check if an URL was provided
if [ "$#" -ne 1 ]; then
    echo "Usage: $0 <URL>"
    exit 1
fi

# Assign the first argument to a variable
URL="$1"

./download_and_save_plugin.sh "$URL"
 php endpoint-scanner.php
 ./concatenate-all-reports.sh
./convert-all-endpoint-reports-to-json.sh