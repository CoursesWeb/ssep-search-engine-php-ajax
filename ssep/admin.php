<?php
// SSEP - Site Search Engine PHP-Ajax - http://coursesweb.net/
include 'php/config.php';

// check PHP version
if(defined('PHP_VERSION_ID') && PHP_VERSION_ID < 50400) {
  echo sprintf(getTL('er_phpversion'), PHP_VERSION);
  exit;
}

$ssep_res = isset($er_lang) ? [$er_lang] : [];    // additional content with script response
$ssep_res = isset($_SESSION['ssep_res']) ? [$_SESSION['ssep_res']] : $ssep_res;    // additional content with script response
if(isset($_SESSION['ssep_res'])) unset($_SESSION['ssep_res']);    // delete session with message before refresh

// template items
$tpl = [
 'header'=> file_get_contents(SSEP_TEMPL .'admin_header.htm'),
 'p_content' => '',
 'footer'=> file_get_contents(SSEP_TEMPL .'admin_footer.htm')
];

// check if admin logged
if(isset($_SESSION['adminlogg'])){
  if($_SESSION['adminlogg'] == $admin_name .$admin_pass) {
    // folder with cache files of current domain
    define('SSEP_CACHE', $cache_dir. preg_replace(['/^www\./i', '/[^a-z0-9_]+/i'], ['', '_'], (isset($_SESSION['ssep_domain']) ? $_SESSION['ssep_domain'] : $_SERVER['SERVER_NAME']) ) .'/');

    include 'php/crawlindex.php';
    $objci = new crawlIndex($obsql);

    if(isset($_GET['mod'])){
      $_GET['mod'] = trim(strip_tags($_GET['mod']));

      //creates manually the main tables, when logged admin access "?mod=create_tables"
      if($_GET['mod']=='create_tables'){
        $resp = $objci->createMainTables(0);    // create tables for domain and settings, if not already
        $_SESSION['ssep_res'] = ($resp == '') ? sprintf(getTL('ok_maintables'), $objci->tables['dom'], $objci->tables['sets']) : $resp;
      }
      else if($_GET['mod']=='log_out'){
        unset($_SESSION['adminlogg']);
        if(isset($_SESSION['ssep_res'])) unset($_SESSION['ssep_res']);
        if(isset($_SESSION['ssep_sets'])) unset($_SESSION['ssep_sets']);
        if(isset($_SESSION['ssep_domain'])) unset($_SESSION['ssep_domain']);
        if(isset($_SESSION['ssep_dom_id'])) unset($_SESSION['ssep_dom_id']);
      }
      header('Location: '. $_SERVER['PHP_SELF']);
      exit;
    }

    // gets $_SESSION['ssep_dom_id'] from database, and settings from session
    if(!isset($_SESSION['ssep_dom_id'])) $_SESSION['ssep_dom_id'] = getDomainId($obsql, $objci->domain);
    if(!isset($_SESSION['ssep_sets'])) $_SESSION['ssep_sets'] = getSettings($obsql, $_SESSION['ssep_dom_id']);
    $sets = array_merge($sets0, $_SESSION['ssep_sets']);    // get settings from database

    // add new domain
    if(isset($_POST['add_domain']) && isset($_POST['protocol']) && in_array($_POST['protocol'], ['http', 'https']) && strlen(trim($_POST['add_domain'])) > 4) {
      $resp = $objci->addDomain(trim(trim(strip_tags($_POST['add_domain']), '/')), $_POST['protocol']);
      if($resp == '') {
        if(isset($_SESSION['ssep_sets'])) unset($_SESSION['ssep_sets']);    // session with settings of current domain
        header('Location: '. $_SERVER['PHP_SELF']); exit;
      }
      else $re_sets[] = getTL('er_add_domain') .': '. $_POST['add_domain'] .' - '. $resp;
    }
    else if(isset($_POST['sel_domain'])) {
      // change domain from <select> list. Change needed session, redirect
      $id_dom = explode('-', trim(strip_tags($_POST['sel_domain'])), 2);    // get id and domain
      $_SESSION['ssep_dom_id'] = intval($id_dom[0]);
      $_SESSION['ssep_domain'] = $id_dom[1];
      if(isset($_SESSION['ssep_sets'])) unset($_SESSION['ssep_sets']);    // session with settings of current domain
      header('Location: '. $_SERVER['PHP_SELF']); exit;
    }

    if(isset($_POST['act'])) {
      set_time_limit(0);
      if($_POST['act'] == 'index'){   // re-index all pages
        // if to reindex, if 'all' delete all rows, else set $reindex to 1
        if(isset($_POST['reindex'])) {
          if($_POST['reindex'] == 'all') $ssep_res[] = $objci->deletePages();
          else if($_POST['reindex'] == 1) $objci->reindex = 1;
        }
        $objci->deltags = $sets['deltags'];

        // sets properties with value from form
        if(isset($_POST['max_depth']) && $_POST['max_depth'] == 'to_depth' && isset($_POST['to_depth'])) $objci->max_depth = intval($_POST['to_depth']);
        if(isset($_POST['max_urls']) && $_POST['max_urls'] == 'to_max' && isset($_POST['to_max'])) $objci->max_urls = intval($_POST['to_max']);
        if(isset($_POST['url_include']) && trim($_POST['url_include']) != '') $objci->url_include = explode(PHP_EOL, trim($_POST['url_include']));
        if(isset($_POST['url_exclude']) && trim($_POST['url_exclude']) != '') $objci->url_exclude = explode(PHP_EOL, trim($_POST['url_exclude']));

        if(isset($_POST['start_url'])) $start_url = trim(strip_tags($_POST['start_url']));
        else $start_url = (!empty($_SERVER['HTTPS']) ? 'https://' : 'http://'). $objci->domain;

        // output header, empty it in $tpl, start indexing
        echo $tpl['header'];
        $tpl['header'] = '';
        $objci->run($start_url);
      }
      else if($_POST['act'] == 'index_only') {    // index only these urls (from sitemap, or /and speciffied urls)
        $urls = [];
        if(isset($_POST['from_sitemap']) && trim($_POST['from_sitemap']) != '') {  // from sitemap
          $sitemap = file_get_contents(trim(strip_tags($_POST['from_sitemap'])));
          if(preg_match_all('@\<loc[^\>]*\>([^\<]+)\</loc\>@i', $sitemap, $m)) {    // get all urls from sitemap (<loc>url</loc>)
            $m[1] = array_map('trim', array_map('trim', $m[1]));
            $urls = array_merge($urls, $m[1]);
          }
        }
        if(isset($_POST['only_urls']) && trim($_POST['only_urls']) != '') {  // from speciffied urls
          $urls = array_merge($urls, explode(PHP_EOL, trim(strip_tags($_POST['only_urls']))));
        }
        $urls = array_unique(array_map('trim', $urls));
        sort($urls);

        // output header, empty it in $tpl, start indexing
        echo $tpl['header'];
        $tpl['header'] = '';
        if(count($urls) > 0) $objci->indexOnly($urls);
      }
      else if($_POST['act'] == 'index_pending') {    // index pending URLs
        // output header, empty it in $tpl, start indexing
        echo $tpl['header'];
        $tpl['header'] = '';
        $objci->indexPending();
      }
      else if($_POST['act'] == 'ssep_sets') {
        $sets2 = $objci->setSettings($_POST);   // set settngs in "ssep_sets" table
        if(count($sets2) > 0) {
          $sets = $sets2;
          $ssep_res[] = getTL('ok_save_sets');
        }
        else $ssep_res[] = getTL('ok_save_sets');
      }
      else if($_POST['act'] == 'clean_cnt') $ssep_res[] = $objci->cleanContent();   // delete unassociated content
      else if($_POST['act'] == 'del_cache') $ssep_res[] = $objci->deleteCache();   // delete all files in SSEP_CACHE
      else if($_POST['act'] == 'get_sitemap') $objci->getSitemap();   // create sitemap
      else if($_POST['act'] == 'del_pgs') $ssep_res[] = $objci->deletePages();   // delete all rows from 'ssep_url_', 'ssep_pgd_' tables
      else if($_POST['act'] == 'del_dom') {
        // delete domain, its settings and tables, Store response in session to show it after refresh
        $_SESSION['ssep_res'] = $objci->deleteDomain();

        // delete sessions with settings of current domain, then refresh
        if(isset($_SESSION['ssep_dom_id'])) unset($_SESSION['ssep_dom_id']);
        if(isset($_SESSION['ssep_domain'])) unset($_SESSION['ssep_domain']);
        if(isset($_SESSION['ssep_sets'])) unset($_SESSION['ssep_sets']);
        header('Location: '. $_SERVER['PHP_SELF']); exit;
      }
      else if( $_POST['act'] == 're_sets') {    // restore default settings
        $sets = $sets0;
        if($objci->saveSettings($sets)) $ssep_res[] = getTL('ok_re_sets');
        else $ssep_res[] = getTL('er_save_sets');
      }
    }

    // texts added in template['p_content']
    $tl['current_dom'] = isset($_SESSION['ssep_domain']) ? $_SESSION['ssep_domain'] :'';
    $tl['deltags'] = $tl['score1'] = $tl['score2'] = $tl['rowsperpage'] = $tl['src_suggest'] = $tl['check_infinite'] = $tl['check_standard'] = $tl['check_ajax1'] = $tl['check_ajax0'] = '';    // for items in #ssep_sets

    // get number of indexed pages, array with: [nr_pgs, nr_1, nr0, nr1, nr2]
    $tl = array_merge($tl, $objci->getNrPgs());
    if(!is_dir(SSEP_CACHE)) {
      // create cache folder for current domain, if not exist
      if(!mkdir(SSEP_CACHE, 0755)) $ssep_res[] = getTL('er_dir_cache') .': '. SSEP_CACHE;
    }
    $tl['nr_chace_f'] = is_dir(SSEP_CACHE) ? count(glob(SSEP_CACHE .'*.htm')) : 0;

    // sets fields with html items removed from indexed content
    $nr_tgs = count($sets['deltags']);
    for($i=0; $i<$nr_tgs; $i++) {
      foreach($sets['deltags'][$i] AS $tag => $arr) {
        $tag_attr = (count($arr) > 0) ? key($arr) : '';
        $attr_val = ($tag_attr != '') ? $arr[$tag_attr] : [];

        $tl['deltags'] .= '<tr><td class="del_itms">[X]</td><td><input type="text" name="tag_name[]" size="6" value="'. $tag .'" /></td><td><input type="text" name="tag_attr[]" size="5" value="'. $tag_attr .'" /></td><td><input type="text" name="attr_val[]" size="28" value="'. implode(', ', $attr_val) .'" /></td></tr>';
      }
    }

    $tl['op_domain'] = $objci->getOpDomains();    // <options>s to select domain

    // set fields for items-score
    foreach($sets['score1'] AS $tag => $v) $tl['score1'] .= '<tr><td>'. strtoupper($tag) .'</td><td><input type="number" min="0" max="99" class="num2_input" name="score_'. $tag .'_v" value="'. $v .'" /></td></tr>';
    foreach($sets['score2'] AS $tag => $arr) $tl['score2'] .= '<tr><td>'. strtoupper($tag) .'</td><td><input type="number" min="0" max="99" class="num2_input" name="score_'. $tag .'_n" value="'. $arr['n'] .'" /></td><td><input type="number" min="0" max="99" class="num2_input" name="score_'. $tag .'_v" value="'. $arr['v'] .'" /></td></tr>';

    $tl['rowsperpage'] = intval($sets['rowsperpage']);    // nr. rows for pagination
    $tl['src_suggest'] = intval($sets['src_suggest']);    // nr. rows for search suggestions
    $tl['last_src'] = intval($sets['last_src']);    // nr. last searches lists
    $tl['top_src'] = intval($sets['top_src']);    // nr. top searches lists

    // sets which type of pagination to be checked
    if($sets['pgi_type'] == 'infinite') $tl['check_infinite'] = 'checked';
    else if($sets['pgi_type'] == 'standard') $tl['check_standard'] = 'checked';

    // sets which "use_ajax" item to be checked
    if($sets['use_ajax'] == 1) $tl['check_ajax1'] = 'checked';
    else $tl['check_ajax0'] = 'checked';

    $tpl['p_content'] = template(file_get_contents(SSEP_TEMPL .'admin_content.htm'), $tl);
  }
  else unset($_SESSION['adminlogg']);
}
else if(isset($_POST['name']) && isset($_POST['pass'])) {
  // check if data form to logg in
  if($_POST['name'] == $admin_name && $_POST['pass'] == $admin_pass) {
    include 'php/crawlindex.php';
    $objci = new crawlIndex($obsql);
    $resp = $objci->createMainTables();    // create tables for domain and settings, if not already

    $_SESSION['adminlogg'] = $admin_name . $admin_pass;

    if($resp == '') header('Location: '. $_SERVER['PHP_SELF']);
    else $re_sets[] = $resp;
  }
  else $ssep_res[] = getTL('er_inc_pass');
}

// if not logged, include and outputs loggin form
if(!isset($_SESSION['adminlogg'])) $tpl['p_content'] .= template(file_get_contents(SSEP_TEMPL .'admin_logg.htm'), $tl);
$ssep_res = (count($ssep_res) > 0) ? '<div id="ssep_res">'. implode('<br>', $ssep_res) .'<div class="cls_res">[X]</div></div>' : '';

echo $tpl['header']. $ssep_res . $tpl['p_content']. $tpl['footer'];