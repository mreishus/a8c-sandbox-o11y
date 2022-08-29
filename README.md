# a8c-sandbox-o11y

## Installation Instructions (0-sandbox-o11y.php)

`cp 0-sandbox-o11y.php ~/public_html/wp-content/mu-plugins/`

Edit `~/public_html/wp-content/mu-plugins/0-sandbox.php` and add a `sandbox_log_request( 1 );` statement.

## Installation Instructions (hook11.diff)

```
cp hook11.diff /tmp/
cd ~/public_html
patch -p1 < ~/debug-perf/hook11.diff
```

Be careful not to commit changes to `wp-includes/class-wp-hook.php`! You can
reverse them with `git checkout HEAD wp-includes/class-wp-hook.php`.

