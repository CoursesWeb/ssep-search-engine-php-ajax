<?php
// SSEP - Site Search Engine PHP-Ajax - http://coursesweb.net/
include('php/config.php');
include('php/sitesearch.php');

// set domain in which to search (set in config.php)
if($search_domain == 'auto') $search_domain = isset($_SESSION['ssep_domain']) ? $_SESSION['ssep_domain'] : $_SERVER['SERVER_NAME'];
define('DOMAIN', $search_domain);
define('SSEP_CACHE', $cache_dir. preg_replace(['/^www\./i', '/[^a-z0-9_]+/i'], ['', '_'], DOMAIN) .'/');    // folder with cache files of current domain

unset($_SESSION['src_dom_id']);
// gets $_SESSION['src_dom_id'] from database, and settings from session
if(!isset($_SESSION['src_dom_id'])) $_SESSION['src_dom_id'] = getDomainId($obsql, DOMAIN);
if(!isset($_SESSION['ssep_sets'])) $_SESSION['ssep_sets'] = getSettings($obsql, $_SESSION['src_dom_id']);
$sets = array_merge($sets0, $_SESSION['ssep_sets']);    // get settings from database

$obsrc = new SiteSearch($obsql);
$obsrc->use_ajax = $sets['use_ajax'];

// template items
$tpl = [
 'lang'=> $lang,
 'base'=> $_SERVER['SERVER_NAME'] . rtrim(dirname(preg_replace('@/[^/]*$@i', '/', $_SERVER['REQUEST_URI']) .'index.php'), '/'). '/',
 'title'=> getTL('ssep_title'). DOMAIN,
 'description'=> getTL('ssep_description'),
 'keywords'=> '',
 'ssep_pg'=>$obsrc->ssep_pg,
 'nr_suggest'=>$sets['src_suggest'],
 'home_page'=> $_SERVER['SERVER_NAME'],
 'search'=>getTL('search'),
 'msg_ssep_inp'=>getTL('msg_ssep_inp'),
 'nav_menu'=> getTL('nav_menu'),
 'last_searches'=> getTL('last_searches'),
 'last_src'=> $obsrc->getListSrc($sets['last_src']),
 'top_searches'=> getTL('top_searches'),
 'top_src' => $obsrc->getListSrc($sets['top_src'], 'top'),
 'search_results'=> getTL('ssep_results'),
 'ssep_results_for'=> getTL('ssep_results_for'),
 'pgi_type'=> $sets['pgi_type']
];

// if $_POST['sugest'] from ajax, or _REQUEST['sr'] - search-page
if(isset($_POST['sugest']) || (isset($_REQUEST['sr']) && strlen(trim($_REQUEST['sr'])) > 2)) {
  // define properties
  $obsrc->score1 = $sets['score1'];
  $obsrc->score2 = $sets['score2'];
  $obsrc->rowsperpage = intval($sets['rowsperpage']);
  $obsrc->src_suggest = intval($sets['src_suggest']);

  // if request for search results
  if(isset($_REQUEST['sr'])) {
    // get array with the words added in text file (separated by comma), to be removed from search
    if(file_exists('php/stop_words.txt')) {
      $stop_words = trim(file_get_contents('php/stop_words.txt'));
      $obsrc->stop_words = array_map('trim', explode(',', $stop_words));
    }

    $_REQUEST['sr'] = preg_replace('/[!@#$%^&*\(\)\+\=,\.?\/\|\[\]\{\}`~)]+/i', '', $_REQUEST['sr']);
    $tpl['search_results'] = $obsrc->getSearch($_REQUEST['sr']);    // get search results
  }

  // if Ajax request return search_results only, else entry html page
  if(isset($_POST['isajax']) && $_POST['isajax'] == 1) {
    if(isset($_POST['sugest'])){
      $_POST['sugest'] = preg_replace('/[!@#$%^&*\(\)\+\=,\.?\/\|\[\]\{\}`~)]+/i', '', $_POST['sugest']);
      echo ($sets['src_suggest'] > 0) ? $obsrc->srcSugest(strip_tags($_POST['sugest'])) : '';   // when requests to sugest searches, from JS keyup
    }
    else echo $tpl['search_results'];
    exit;
  }
  else {
    // data to be added in $tl for template
    $tpl['title'] = $obsrc->pg_data['title'];
    $tpl['description'] = $obsrc->pg_data['description'];
    $tpl['keywords'] = $obsrc->pg_data['keywords'];
  }
}

if(isset($_POST['isajax']) && $_POST['isajax'] == 1) echo $tpl['search_results'];
else echo template(file_get_contents(SSEP_TEMPL .'search.htm'), $tpl);