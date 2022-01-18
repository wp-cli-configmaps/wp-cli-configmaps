# ConfigMaps configuration management for WordPress WP-CLI - Tame your wp_options using WP-CLI and git

This is a [CLI](https://wp-cli.org/)-based tool for managing
[WordPress](https://wordpress.org/) settings defined in the
[wp_options](https://codex.wordpress.org/Database_Description#Table:_wp_options)
database table.

TL;DR:
- This is a WP-CLI package
- It manages options in your `wp_options` table
- Source of truth are PHP files (called "config maps")
- Multiple config maps can be merged into the final desired configuration (think per-environment overrides)
- You can dump database values back into the config maps

In short, if your answer to the question "Do I want to track my WordPress configuration that is stored in the `wp_options` table by storing it in a git repository?" is "Yes, definitely!", then this tool is what you're looking for.



## Installation

Prerequisites:
- [WordPress](https://wordpress.org/)
- [WP-CLI](https://wp-cli.org/)
- Shell access to your WP instance(s)

Install the `wp-cli-configmaps` package:
```
wp package install wp-cli-configmaps/wp-cli-configmaps
```



## Initial (basic) configuration

To start using this tool, at least one config map needs to be created.
The simplest way to get started is to generate a config map from your current `wp_options` content:
```
wp configmaps generate --from-db
```
This will dump out your first config map, in a form of PHP code.

Store this generated config map in some file (i.e. `../conf/maps/common.php`).
The `generate` command we used above can help you with that too:
```
wp configmaps generate --from-db --output=../conf/maps/common.php
```

Now define your config map set:
```php
define('WP_CLI_CONFIGMAPS', [
    'common' => ABSPATH . '../conf/maps/common.php',
//  WP_ENV   => ABSPATH . '../conf/maps/'. WP_ENV .'.php',   // This one is for later, when you'll have a per-environment config map overlays
]);
```
You can add this^ to any suitable WordPress source file, however there is a dilemma:
- `wp-config.php` file, as intended by WordPress, should not be replicated between environments, but
- Changes to other WP source files will be overwritten by WordPress updates.

To help you with the decision where to put this code, see [a saner WordPress directory structure](doc/saner-wp-directory-structure.md) for a better how to structure your WordPress directory hierarchy.



## Usage

To verify if `wp_options` content still matches what your config map(s) definitions say, use the, well, `verify` command:
```
wp configmaps verify
```

To apply all options defined in your config map(s) to the database:
```
wp configmaps apply --commit
```
This command transfers all defined option values (defined in one or multiple
[config map files](doc/terminology.md)) into the `wp_options` table.
The transfer is performed according to the individual value specification (literal copy, merged, etc.).

Alternatively, if you've been tweaking your WordPress configuration in the admin section of a particular instance (i.e. your local development instance),
and now you want to transfer the new configuration to your other environments (i.e. staging and later production),
you can update your config maps with your current database values:
```
wp configmaps update
```
This will update all defined config maps in-place.
Now `git commit -av`, `git push`, `git pull` and `wp configmaps apply` are all that you need to reliably transfer this new configuration to all the other environments.



## Advanced configuration - environment-specific value overrides

You've probably noticed the `WP_ENV` above.
This is one way how you can define per-environment overrides:
- Make sure each environment defines a correct `WP_ENV` constant (i.e. with `dev`, `stg` or `prod` values)
- Besides `common.php`, create config maps called `dev.php`, `stg.php` and `prod.php`

Here is the [directory structure you should end up with](doc/saner-wp-directory-structure.md):
```
./
./.git
./public                     # Here is the original WordPress code, and vhost root actually points to this location
./public/index.php
./public/wp-config.php       # This file is actually committed to the git repository, see it's content below

./conf
./conf/wp-config-local.php   # The actual local configuration (containing instance URLs, DB access credentials and salts, and WP_ENV definition)

./conf/maps                  # Location of our config maps
./conf/maps/common.php
./conf/maps/dev.php
./conf/maps/stg.php
./conf/maps/prod.php
./conf/maps/local.php        # For fun, let's make this one optional
```

Now, in your config map _set_ definition,  include a second config map.
But make the choice dynamic, based on your WordPress instance's configured environment.
Additionally, let's include a `local.php` config map too, if it's found:
```php
$configMaps = [
    'common' => ABSPATH . '../conf/maps/common.php',
    WP_ENV   => ABSPATH . '../conf/maps/' . WP_ENV . '.php',
];

$localConfigMapPath = ABSPATH . '../conf/maps/local.php';
if (file_exists($localConfigMapPath)) {
    $configMaps['local'] = $localConfigMapPath;
}

define('WP_CLI_CONFIGMAPS', $configMaps);
unset($configMaps);
```

Now, in a `dev` environment, when running `wp configmaps verify` or `wp configmaps apply`:
- All the option values specified in the `dev.php` file will override matching definitions from the `common.php` file
- All the option values specified in the `local.php` file will override matching definitions from both `common.php` and `dev.php` file

But in a `stg` environment:
- All the option values specified in the `stg.php` file will override matching definitions from the `common.php` file
- All the option values specified in the `local.php` file will override matching definitions from both `common.php` and `stg.php` file

And in a `prod` environment:
- All the option values specified in the `prod.php` file will override matching definitions from the `common.php` file
- All the option values specified in the `local.php` file will override matching definitions from both `common.php` and `prod.php` file



## License

```
/*
 * ConfigMaps configuration management for WordPress WP-CLI - Tame your wp_options using WP-CLI and git
 *
 * Copyright (C) 2022 Bostjan Skufca Jese
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * version 2 as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, see <https://www.gnu.org/licenses/gpl-2.0.html>.
 */
```



## Author

Created by [Bostjan Skufca Jese](https://github.com/bostjan).
