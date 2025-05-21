#!/bin/bash

input_file="endpoint-reports/all-endpoint-reports.txt"
output_file="endpoint-reports/all-endpoint-reports.json"

if [[ ! -f "$input_file" ]]; then
    echo "Error: File '$input_file' not found."
    exit 1
fi

awk '
# Function to escape strings for JSON
function escape_json(str) {
    # Basic escapes: backslash, double quote, newline, carriage return, tab
    gsub(/\\/, "\\\\", str)
    gsub(/"/, "\\\"", str)
    gsub(/\n/, "\\n", str)
    gsub(/\r/, "\\r", str)
    gsub(/\t/, "\\t", str)
    # Note: forward slash escape (\/) is optional unless it creates </script> etc.
    return str
}

# Function to output the collected data for a record as a JSON object
function output_record() {
    if (action_val != "") { # Only output if we have an action (i.e., a valid record was started)
        # Trim leading/trailing newlines from code blocks if any were accumulated
        gsub(/^\n+|\n+$/, "", reg_code_val)
        gsub(/^\n+|\n+$/, "", impl_code_val)

        printf "{\"ajaxEndpoint\":{\"action\":\"%s\",\"access\":\"%s\",\"callback\":\"%s\",\"file\":\"%s\"},\"registrationCode\":\"%s\",\"callbackImplementation\":\"%s\"}\n", \
            escape_json(action_val), \
            escape_json(access_val), \
            escape_json(callback_val), \
            escape_json(file_val), \
            escape_json(reg_code_val), \
            escape_json(impl_code_val)
    }
    # Reset variables for the next record
    action_val = ""; access_val = ""; callback_val = ""; file_val = ""
    reg_code_val = ""; impl_code_val = ""
    current_section = ""
    # Flags to indicate if we are on the first content line of a multi-line section
    # This helps handle potential empty lines between section header and actual content
    first_reg_line = 1
    first_impl_line = 1
}

# Main processing logic
/^=== AJAX Endpoint ===/ {
    output_record() # Output the previously collected record, if any
    current_section = "ajax_details"
    next # Skip processing this line further
}

/^=== Registration Code ===/ {
    current_section = "registration_code"
    first_reg_line = 1 # Reset for this section
    next
}

/^=== Callback Implementation ===/ {
    current_section = "callback_implementation"
    first_impl_line = 1 # Reset for this section
    next
}

# Process content lines based on the current section
current_section != "" {
    if (current_section == "ajax_details") {
        if (sub(/^Action: /, ""))                { action_val = $0; }
        else if (sub(/^Access: /, ""))           { access_val = $0; }
        else if (sub(/^Callback: /, ""))         { callback_val = $0; }
        else if (sub(/^File: /, ""))             {
            # Remove the " (Line: ...)" part
            sub(/ \(Line: [0-9]+\)$/, "", $0)
            file_val = $0
        }
    } else if (current_section == "registration_code") {
        # Skip empty lines if we have not captured the first actual code line yet
        if (first_reg_line && $0 ~ /^[ \t]*$/) { next }
        
        if (first_reg_line) {
            reg_code_val = $0
            first_reg_line = 0
        } else {
            reg_code_val = reg_code_val "\n" $0
        }
    } else if (current_section == "callback_implementation") {
        # Skip empty lines if we have not captured the first actual code line yet
        if (first_impl_line && $0 ~ /^[ \t]*$/) { next }

        if (first_impl_line) {
            impl_code_val = $0
            first_impl_line = 0
        } else {
            impl_code_val = impl_code_val "\n" $0
        }
    }
}

END {
    output_record() # Output the last collected record
}
' "$input_file" | jq --slurp '.' > "$output_file"