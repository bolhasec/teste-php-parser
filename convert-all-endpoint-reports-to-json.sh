#!/bin/bash

input_file="endpoint-reports/all-endpoint-reports.txt"
output_file="endpoint-reports/all-endpoint-reports.json"

if [[ ! -f "$input_file" ]]; then
    echo "Error: File '$input_file' not found."
    exit 1
fi

awk '
function escape_json(str) {
    gsub(/\\/, "\\\\", str)
    gsub(/"/, "\\\"", str)
    gsub(/\n/, "\\n", str)
    gsub(/\r/, "\\r", str)
    gsub(/\t/, "\\t", str)
    return str
}

function output_record() {
    if (action_val != "") {
        gsub(/^\n+|\n+$/, "", reg_code_val)
        gsub(/^\n+|\n+$/, "", impl_code_val)

        printf "{\"ajaxEndpoint\":{\"action\":\"%s\",\"access\":\"%s\",\"callback\":\"%s\",\"file\":\"%s\",\"line\":%d},\"registrationCode\":\"%s\",\"callbackImplementation\":\"%s\"}\n", \
            escape_json(action_val), \
            escape_json(access_val), \
            escape_json(callback_val), \
            escape_json(file_val), \
            line_val, \
            escape_json(reg_code_val), \
            escape_json(impl_code_val)
    }
    action_val = ""; access_val = ""; callback_val = ""; file_val = ""; line_val = 0
    reg_code_val = ""; impl_code_val = ""
    current_section = ""
    first_reg_line = 1
    first_impl_line = 1
}

/^=== AJAX Endpoint ===/ {
    output_record()
    current_section = "ajax_details"
    next
}

/^=== Registration Code ===/ {
    current_section = "registration_code"
    first_reg_line = 1
    next
}

/^=== Callback Implementation ===/ {
    current_section = "callback_implementation"
    first_impl_line = 1
    next
}

current_section != "" {
    if (current_section == "ajax_details") {
        if (sub(/^Action: /, ""))                { action_val = $0 }
        else if (sub(/^Access: /, ""))           { access_val = $0 }
        else if (sub(/^Callback: /, ""))         { callback_val = $0 }
        else if (sub(/^File: /, "")) {
            # Extract line number in POSIX-compatible way
            line_val = 0
            if ($0 ~ /\(Line: [0-9]+\)/) {
                line_text = $0
                sub(/^.*\(Line: /, "", line_text)
                sub(/\)$/, "", line_text)
                line_val = line_text + 0
            }
            sub(/ \(Line: [0-9]+\)$/, "", $0)
            file_val = $0
        }
    } else if (current_section == "registration_code") {
        if (first_reg_line && $0 ~ /^[ \t]*$/) { next }
        if (first_reg_line) {
            reg_code_val = $0
            first_reg_line = 0
        } else {
            reg_code_val = reg_code_val "\n" $0
        }
    } else if (current_section == "callback_implementation") {
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
    output_record()
}
' "$input_file" | jq --slurp '.' > "$output_file"
