# Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 0.01.x  | :white_check_mark: |

## Reporting a Vulnerability

If you discover a security vulnerability in RHADEV Server Monitor, please report it responsibly.

**Do not open a public GitHub issue for security-related reports.**

Instead, contact the maintainers privately with:

- A clear description of the vulnerability
- Steps to reproduce
- Potential impact
- Any suggested fixes (optional)

We aim to respond within 7 days and will work with you to address the issue promptly.

## Security Considerations

RHADEV Server Monitor is a lightweight PHP dashboard that reads system metrics from `/proc` and `/sys` on Linux.

### Recommendations

- Run this script only on trusted servers under your control.
- Restrict access to the monitoring page (e.g. via web server authentication, VPN, or firewall rules).
- Do not expose the endpoint publicly without proper access controls.
- Keep your PHP runtime and web server up to date.
- The script does not store credentials or perform privileged operations beyond reading system statistics.

### Known Limitations

- Requires read access to `/proc/stat`, `/proc/meminfo`, disk space APIs, and network interface statistics.
- Optional root network counters rely on an external `status.json` file – ensure this file is generated securely if used.
- No authentication is built into the script itself; protection must be handled at the web server or network level.

Thank you for helping keep RHADEV secure.
