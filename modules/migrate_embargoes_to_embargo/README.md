# Migrate "embargoes" to "embargo"

Couple of migrations which should handle migrating entities from the "embargoes"
module to our "embargo" equivalents.

Should be executable with something like:

```bash
drush migrate:import --tags=migrate_embargoes_to_embargo
```

NOTE: It may be necessary to handle changing the user in some manner (such as
via [`islandora`'s (re)introduction of `--userid` to the `migrate:import`
command](https://github.com/Islandora/islandora/blob/2.x/src/Commands/IslandoraCommands.php),
or `dgi-migrate:import`'s `--user` flag), in order to be able to be able to
refer to the nodes and users as expected, on the embargo entities.

Otherwise, you may see errors in the messages for the `embargoes_content`
migration such as:

*
  > [embargo]: embargoed_node.0.target_id=This entity (<em class="placeholder">node</em>: <em class="placeholder">5</em>) cannot be referenced.

*
  > [embargo]: exempt_users.0.target_id=This entity (<em class="placeholder">user</em>: <em class="placeholder">2</em>) cannot be referenced.
