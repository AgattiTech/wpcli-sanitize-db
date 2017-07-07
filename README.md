# WPCLI Sanitize DB #

Removes sensitive data from your local database replacing it with random data.

WARNING: This will overwrite data in your local database.


### Installation ###

To install as a wp-cli package:

```
#!bash

wp package install git@bitbucket.org:freshconsulting/wpcli-sanitize-db.git
```

Or to install manually clone this repo and add the path to the `sanitize-db.php` file to the `require` section of your `~/.wp-cli/config.yml` (see https://make.wordpress.org/cli/handbook/config/#config-files for details).


### To use ###

```
#!bash

wp sanitize db
```
