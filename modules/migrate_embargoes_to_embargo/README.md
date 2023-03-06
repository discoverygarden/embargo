# Migrate "embargoes" to "embargo"

Couple of migrations which should handle migrating entities from the "embargoes"
module to our "embargo" equivalents.

Should be executable with something like:

```bash
drush migrate:import --tag=migrate_embargoes_to_embargo
```

Or, more completely, a script such as:

```bash
#!/bin/bash
URI=http://$(hostname)
WEB_USER=www-data
function wwwdrush() {
  sudo -u $WEB_USER -- $(which drush) --uri=$URI $@
}

# Assuming the "embargo" module code is present.
wwwdrush en embargo migrate_embargoes_to_embargo
# XXX: It is necessary to run the migrations as a user with sufficient
# permissions, so using a version of `migrate:import` with something equivalent
# to the old `--user` option is necessary.
wwwdrush migrate:import --userid=1 --tag=migrate_embargoes_to_embargo

# Should probably check that the entities migrated appropriately before proceeding to
# delete the content entities and uninstalling the module... so:
wwwdrush migrate:message embargoes_content
while true; do
  read -p "Were are all embargoes migrated successfully (Y/N)?: "
  echo
  case "$REPLY" in
    y|Y)
      wwwdrush entity:delete embargoes_ip_range_entity
      wwwdrush entity:delete embargoes_content_entity
      wwwdrush pmu embargoes migrate_embargoes_to_embargo
      break
      ;;
    n|N)
      echo "The messages should be reviewed, and the migrations rolledback (possibly their status reset, as necessary)."
      break
      ;;
    *)
      echo "Bad option, try again."
      ;;
  esac
done
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
