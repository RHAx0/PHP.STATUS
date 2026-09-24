# Installation Guide – RHADEV Server Monitor v0.01

## Requirements

- Linux server (reads from `/proc` and `/sys`)
- PHP 7.4 or newer (tested with modern PHP versions)
- Web server with PHP support (Apache, Nginx, Caddy, etc.)
- Read permissions for:
  - `/proc/stat`
  - `/proc/meminfo`
  - Disk usage of the monitored path (default: `/`)
  - Network interface statistics under `/sys/class/net/*/statistics/`

## Quick Install

1. Clone or download the repository:

   ```bash
   git clone https://github.com/YOUR_USERNAME/rhadev.git
   cd rhadev
   ```

2. Place `status.php` in a web-accessible directory, for example:

   ```bash
   # Apache / Nginx document root example
   cp status.php /var/www/html/status.php
   ```

3. Ensure the web server user can read system files (usually works out of the box on most distributions).

4. Open the page in your browser:

   ```
   http://your-server/status.php
   ```

5. (Optional) Enable the JSON API by appending `?api=1`:

   ```
   http://your-server/status.php?api=1
   ```

## Optional: Root Network Counters

If you want separate "ROOT NETWORK" statistics, create a file named `status.json` next to `status.php` with the following structure:

```json
{
  "rx": 123456789,
  "tx": 987654321,
  "timestamp": 1727000000
}
```

- `rx` / `tx` – cumulative bytes received / transmitted
- `timestamp` – Unix timestamp of the last update

The script considers the data stale if it is older than 5 seconds (configurable via `$root_max_age`).

You can update this file from a privileged process or cron job that aggregates traffic from additional interfaces or containers.

## Configuration

Open `status.php` and adjust the top variables if needed:

```php
$root_status_file = __DIR__ . '/status.json';
$disk_path = '/';          // change to monitor a different mount point
$root_max_age = 5;         // seconds before root network data is considered stale
```

## Permissions Checklist

- PHP process must be able to read `/proc` and `/sys`.
- No write permissions are required by the script itself.
- If using `status.json`, ensure the file is readable by the web server and written only by a trusted process.

## Troubleshooting

| Problem                        | Possible Cause / Fix                                      |
|--------------------------------|-----------------------------------------------------------|
| CPU / RAM shows null           | Missing read access to `/proc`                            |
| Disk usage empty               | Wrong `$disk_path` or insufficient permissions            |
| Network counters stuck at 0    | Interface names changed or missing `/sys` access          |
| "API offline"                  | Web server not serving PHP or script error                |
| Root network always stale      | `status.json` not updated or older than `$root_max_age`   |

## Uninstall

Simply delete `status.php` (and optionally `status.json`) from your web directory. No other system changes are made by the script.

---

RHADEV Server Monitor v0.01 – Keep it simple, keep it fast.
