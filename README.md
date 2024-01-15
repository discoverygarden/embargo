# Embargo

Embargo content indefinitely or till specified date, limiting access to specific users and/or IP addresses.

> This module is intended to be a successor to <https://github.com/discoverygarden/embargoes>

## Installation

Install as
[usual](https://www.drupal.org/docs/extending-drupal/installing-modules).

### Migrating from `embargoes`

Migrations exist in the `migrate_embargoes_to_embargo` module for migrating from `embargoes`'s entities onto `embargo`'s.
See [the module's docs for more info](modules/migrate_embargoes_to_embargo/README.md).

## Configuration

Configuration options can be set at `admin/config/content/embargo`,
including a contact email and notification message.

Embargoes can be managed at `admin/content/embargo`. 

To add an IP range for use on embargoes, navigate to
`admin/content/embargo/range` and click 'Add IP range'. Ranges
created via this method can then be used as IP address whitelists when creating
embargoes. This [CIDR to IPv4 Conversion utility](https://www.ipaddressguide.com/cidr) 
can be helpful in creating valid CIDR IP ranges.

## Usage

### Applying an embargo

An embargo can be applied to an existing node by clicking the 
"Embargoes" tab on a node, or navigating to 
`embargoes/node/{node_id}`. From here, an embargo can be applied if it doesn't
already exist, and existing embargoes can be modified or removed.

## Known Issues
Embargoed items may show up in search results. To work around this at a cost to performance you can enable access checking in your search views.

## Troubleshooting/Issues

Having problems or solved a problem? Contact
[discoverygarden](http://www.discoverygarden.ca/).

## Maintainers/Sponsors

* [discoverygarden](http://www.discoverygarden.ca/)

## Attribution
This module is heavily based on and includes contributions from [discoverygarden/embargoes](https://github.com/discoverygarden/embargoes) which was forked from [fsulib](https://github.com/fsulib/embargoes) authored by [Bryan J. Brown](https://github.com/bryjbrown).

## License
[GPLv2](http://www.gnu.org/licenses/gpl-2.0.txt)
