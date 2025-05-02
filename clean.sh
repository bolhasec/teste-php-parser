#!/bin/bash

# Define directories to be cleared
directories=("./plugin" "./endpoint-reports")

# Loop through each directory and remove its contents
for dir in "${directories[@]}"; do
    echo "Deleting all contents of: $dir"
    rm -rf "$dir"/*
done

echo "Deletion completed."