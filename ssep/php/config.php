<?php
// SSEP - Site Search Engine PHP-Ajax - http://coursesweb.net/

// Name and Password to logg in the Admin Panel
$admin_name = 'admin';
$admin_pass = 'pass';

// Data for connecting to MySQL database (MySQL server, user, password, database name)
$mysql['host'] = 'localhost';
$mysql['user'] = 'root';
$mysql['pass'] = 'passdb';
$mysql['bdname'] = 'dbname';

$lang = 'en';      // sufix of the 'lang_...txt' file with texts, in 'templ/' folder ('en' for english, 'ro' - rumano)
$search_domain = 'auto';    // in which registered domain to search ('auto' = current domain where the script is installed)

        /* FROM HERE NO NEED TO MODIFY */

$cache_dir = 'cache/';    // folder to store search cache files
define('SSEP_PREFIX', 'ssep_');     // prefix of the tables in database
define('SSEP_TEMPL', 'templ/');    // folder with html /css template

include 'common.php';