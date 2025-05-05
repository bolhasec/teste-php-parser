#!/bin/bash

# Navigate to the directory containing the reports
cd endpoint-reports/

# Check if 'all-endpoint-reports.txt' exists and remove it to avoid duplicating content
if [ -f all-endpoint-reports.txt ]; then
    rm all-endpoint-reports.txt
fi

# Concatenate all txt files into one
for file in *.txt; do
    if [ -f $file ]; then
        cat "$file" >> all-endpoint-reports.txt
        rm "$file"
        # Optionally, add a newline or some separator between files
        echo "" >> all-endpoint-reports.txt
        echo "$file processed and removed"
    fi
done

echo "All files have been concatenated into all-endpoint-reports.txt"