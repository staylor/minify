=== Minify ===
Contributors: wonderboymusic
Tags: assets, js, css, minify, performance
Requires at least: 3.0
Tested up to: 3.4
Stable Tag: 0.2

Automagically concatenates JS and CSS files that are output in wp_head() and wp_footer()

== Description ==

Similar to what we use on eMusic - this software is still experimental, but take a look!

Automagically concatenates JS and CSS files that are output in wp_head() and wp_footer() - stores / serves them from Memcached (if installed) or Database. In a load-balanced environment, the generation of flat files can be expensive and hard to distribute. Minify takes advantage of Memcached and Site Options / Transients to do the work once and share it among all servers in your cluster. File names are dynamically-generated to allow cache-busting of a CDN like Akamai that doesn't always cache-bust by query string.

New .htaccess rule!
<code>RewriteRule ^([_0-9a-zA-Z-]+)?/?wp-content/cache/minify-(.+)-(.*).(css|js)$ /wp-content/plugins/minify/make.php?hash=$2&type=$4&incr=$3&site=$1 [L]</code>

== Installation ==

You MUST add this rewrite rule to your .htaccess file or httpd.conf file and then restart your server:
<code>RewriteRule ^([_0-9a-zA-Z-]+)?/?wp-content/cache/minify-(.+)-(.*).(css|js)$ /wp-content/plugins/minify/make.php?hash=$2&type=$4&incr=$3&site=$1 [L]</code>

You need to install Memcached on your servers and use Ryan's WP Object Cache backend in WordPress:
http://wordpress.org/extend/plugins/memcached/

If you don't want to use Memcached: 
1) you're weird 
2) all of the action will happen in the database

== Changelog ==

= 0.1 =
* Initial release

= 0.1.1 =
* Added a missing semicolon, props Robert

= 0.2 =
* change your .htaccess rule to: RewriteRule ^([_0-9a-zA-Z-]+)?/?wp-content/cache/minify-(.+)-(.*).(css|js)$ /wp-content/plugins/minify/make.php?hash=$2&type=$4&incr=$3&site=$1 [L]


== Upgrade Notice ==

= 0.2 =
Upgrade your .htaccess: RewriteRule ^([_0-9a-zA-Z-]+)?/?wp-content/cache/minify-(.+)-(.*).(css|js)$ /wp-content/plugins/minify/make.php?hash=$2&type=$4&incr=$3&site=$1 [L]
