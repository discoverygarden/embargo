# Migrate "embargoes" to "embargo"

Couple of migrations which should handle migrating entities from the "embargoes"
module to our "embargo" equivalents.

Should be executable with something like:

```bash
drush migrate:import --tags=migrate_embargoes_to_embargo
```
