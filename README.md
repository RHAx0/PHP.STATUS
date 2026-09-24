```
  ____  _   _    _      ____  _______     __
 |  _ \| | | |  / \    |  _ \| ____\ \   / /
 | |_) | |_| | / _ \   | | | |  _|  \ \ / / 
 |  _ <|  _  |/ ___ \ _| |_| | |___  \ V /  
 |_| \_\_| |_/_/   \_(_)____/|_____|  \_/   
```

# RHADEV Server Monitor

**Version:** v0.01  

Lightweight, real-time Linux server monitoring dashboard written in pure PHP.  
No databases, no frameworks, no external dependencies – just drop the file and go.

## Features

- **CPU** usage + logical thread count  
- **RAM** usage (used / total)  
- **HDD** usage for any mount point  
- **Network** traffic (RX / TX) with live speed  
- Optional split view: WWW Network + Root Network  
- Clean dark UI with live updates (~1 s)  
- JSON API endpoint (`?api=1`)  
- Zero configuration for basic use  

## Screenshots

The dashboard shows live metrics for CPU, RAM, disk and network interfaces.
![alt text](https://github.com/[RHAx0]/[PHP.STATUS]/blob/[branch]/demo.png?raw=true)

## Quick Start

```bash
# Place status.php on your web server
cp status.php /var/www/html/

# Open in browser
http://your-server/status.php
```

See **[INSTALL.md](INSTALL.md)** for detailed installation instructions and optional root-network setup.

## Requirements

- Linux host
- PHP 7.4+
- Web server with PHP support

## Project Structure

```
rhadev/
├── status.php      # Main monitoring script
├── LICENSE         # MIT License
├── README.md       # This file
├── INSTALL.md      # Installation guide
└── SECURITY.md     # Security policy
```

## API

Append `?api=1` to get a JSON response with current metrics:

```
GET /status.php?api=1
```

Example fields: `cpu`, `ram_percent`, `disk_percent`, `www_rx`, `www_tx`, `root_rx`, `root_tx`, etc.

## License

Released under the [MIT License](LICENSE).

## Security

Please read [SECURITY.md](SECURITY.md) before deploying publicly.

---

**RHADEV** – simple server monitoring, nothing more, nothing less.  
v0.01
