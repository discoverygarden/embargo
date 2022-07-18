# Embargo

Embargo content indefinitely or till specified date, limiting access to specific users and/or IP addresses.

> This module is intended to be a successor to <https://github.com/discoverygarden/embargoes>

## Installation

Install as
[usual](https://www.drupal.org/docs/8/extending-drupal-8/installing-drupal-8-modules).

@todo add notes about uninstalling `embargoes`.

## Configuration

Configuration options can be set at `admin/config/content/embargoes/settings`,
including notification options and IP range settings that can apply to
embargoes.

To add an IP range for use on embargoes, navigate to
`admin/config/content/embargoes/settings/ips` and click 'Add IP range'. Ranges
created via this method can then be used as IP address whitelists when creating
embargoes.

## Usage

### Applying an embargo

An embargo can be applied to an existing node by navigating to
`node/{node_id}/embargoes`. From here, an embargo can be applied if it doesn't
already exist, and existing embargoes can be modified or removed.

## Maintainers/Sponsors

* [discoverygarden](http://support.discoverygarden.ca)

## License
[GPLv2](http://www.gnu.org/licenses/gpl-2.0.txt)
