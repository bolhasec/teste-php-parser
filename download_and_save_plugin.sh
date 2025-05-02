#!/bin/bash

# Check if an URL was provided
if [ "$#" -ne 1 ]; then
    echo "Usage: $0 <URL>"
    exit 1
fi

# Assign the first argument to a variable
URL="$1"

# Extract the filename from the URL
FILENAME=$(basename "$URL")

# Define the output directory
OUTPUT_DIR="./plugin"

# Create the output directory if it doesn't exist
mkdir -p "$OUTPUT_DIR"

# Download the file using wget
wget "$URL" -O "$FILENAME"

# Check if the download was successful
if [ $? -eq 0 ]; then
    # Unzip the file to the specified output directory
    unzip -o "$FILENAME" -d "$OUTPUT_DIR"
    echo "Extraction completed successfully."
    rm "$FILENAME" # Remove the downloaded zip file
else
    echo "Failed to download the file."
    exit 1
fi