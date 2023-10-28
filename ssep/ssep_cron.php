#!/usr/local/bin/php
<?php
/*
Settings for Cron: ADMIN_NAME is the value of the $admin_name set in config.php

curl http://WEBSITE_NAME/spbm/pbm_cron.php?cron=ADMIN_NAME > /dev/null
OR:
lynx -source http://WEBSITE_NAME/spbm/pbm_cron.php?cron=ADMIN_NAME > /dev/null
OR:
/usr/local/bin/php /home/CPanelUSER/public_html/spmb/pbm_cron.php ADMIN_NAME > /dev/null
*/

include 'php/config.php';

//HERE add the domain you want to be indexed with CronJobs, ex.: 'domain.com' (The Domain Must be Already Registered in Database)
//By default it indexes the current domain (in which the SSEP script is running)
$_SESSION['ssep_domain'] ='localhost/coursesweb';

if(isset($argv) && isset($argv[1])) $_GET['cron'] = $argv[1];
if(isset($_GET['cron']) && $_GET['cron'] == $admin_name){
  if(isset($_SESSION['ssep_dom_id'])) unset($_SESSION['ssep_dom_id']);
  $_SESSION['adminlogg'] = $admin_name .$admin_pass;
  set_time_limit(0);
  include 'php/crawlindex.php';
  $objci = new crawlIndex($obsql);
  $objci->reindex =1;  //sets to re-index existing registered pages (0 to not re-index)
  $_SESSION['ssep_dom_id'] = getDomainId($obsql, $objci->domain);  //gets $_SESSION['ssep_dom_id'] from database
  $start_url ='https://'. $objci->domain;  //Replace https with http if it is needed
  $objci->run($start_url);  //starts indexing
}
else echo 'Invalid request';