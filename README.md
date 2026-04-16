# WordPress Plugin Endpoint Scanner

A static analysis tool for WordPress plugins that automatically discovers and documents all exposed REST API and AJAX endpoints. Built for security researchers and developers who need to quickly map a plugin's attack surface.

## How It Works

The tool follows a 4-stage pipeline:

1. **Download** — fetches and extracts a plugin ZIP from a URL
2. **Scan** — parses all PHP files using an AST parser to find endpoint registrations and their callback implementations
3. **Consolidate** — merges individual reports into a single file
4. **Convert** — transforms the text report into structured JSON

## Usage

### Full pipeline (recommended)

```bash
./execute-all.sh <plugin-zip-url>
```

### Individual steps

```bash
# 1. Download and extract plugin
./download_and_save_plugin.sh <plugin-zip-url>

# 2. Scan for endpoints (outputs to ./endpoint-reports/)
php endpoint-scanner.php

# 3. Merge reports into a single file
./concatenate-all-reports.sh

# 4. Convert to JSON
./convert-all-endpoint-reports-to-json.sh
```

### Clean up

```bash
./clean.sh
```

## Output

Reports are saved to `./endpoint-reports/`:

- `all-endpoint-reports.txt` — human-readable consolidated report
- `all-endpoint-reports.json` — structured JSON for programmatic use

Each endpoint entry includes:
- Endpoint metadata (namespace, route, HTTP method, action name, access level)
- Registration code (the actual PHP that registers the endpoint)
- Callback implementation (the function/method body that handles requests)

### JSON structure

```json
{
  "restEndpoint": {
    "namespace": "myplugin/v1",
    "route": "/resource",
    "methods": "GET",
    "callback": "MyClass::handle_request",
    "file": "includes/api.php",
    "line": 42
  },
  "registrationCode": "...",
  "callbackImplementation": "..."
}
```

AJAX endpoint entries use `ajaxEndpoint` with `action` and `access` (`private` or `public`) fields instead.

## Requirements

- PHP >= 7.1 with `ext-tokenizer`
- Composer
- `jq`, `wget`, `unzip` (for shell scripts)

## Installation

```bash
composer install
```

## How the Scanner Works

`endpoint-scanner.php` uses [nikic/php-parser](https://github.com/nikic/PHP-Parser) to build an AST for each PHP file in `./plugin/`. It then:

- Detects `register_rest_route()` calls to find REST endpoints
- Detects `add_action('wp_ajax_*')` and `add_action('wp_ajax_nopriv_*')` calls to find AJAX endpoints
- Resolves callback references to their implementations (supports class methods and standalone functions)
- Extracts the raw source code for both registration and callback

Memory limit is set to 1256 MB to handle large plugins.
