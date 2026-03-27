# Bulk Plugin Installer

*Use at your own risk.
I do not offer any support or accept any issues you might have as a result of using this code*

Bulk install WordPress plugins from:
- WordPress.org repository slugs
- Direct ZIP URLs
- Local ZIP files uploaded from your computer

## Manifest format
Use a plain text file (`.txt`) with one plugin source per line.

Supported line formats:
- `slug`
- `repo:slug`
- `url:https://example.com/plugin.zip`
- `zip:my-plugin.zip` (must match an uploaded ZIP filename in the same request)

Notes:
- Empty lines are ignored.
- Lines beginning with `#` are treated as comments.
- Repository slugs only allow lowercase letters, numbers, and dashes.

Example:

```txt
# Repository plugins
akismet
repo:classic-editor

# External ZIP URL
url:https://downloads.wordpress.org/plugin/query-monitor.3.16.0.zip

# Local ZIP files uploaded in the form
zip:my-custom-plugin.zip
zip:team-toolkit.zip
```

## Installation
1.A Copy the `bulk-plugin-installer` folder to `wp-content/plugins/`.
1.B Or upload the ziped plugin in the WordPress dashboard.
2. Activate **Bulk Plugin Installer** in WordPress.
3. Go to **Tools > Bulk Plugin Installer**.
4. Upload your manifest file and (optionally) local ZIP files.
5. Click **Install Plugins**.

## Requirements
- WordPress admin account with `install_plugins` capability.
- Server able to install plugins (filesystem credentials if required).

## Security
- Uses WordPress nonces and capability checks.
- Uses WordPress core upgrader APIs.
