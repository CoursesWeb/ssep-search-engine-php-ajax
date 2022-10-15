<?php
libxml_use_internal_errors(true);    // to not generate invalid html-format error from DOMDocument

// PHP Class to crawl website local URLs, and store the URLs and their content in MySQL database - http://coursesweb.net/
class crawlIndex {
  private $obsql = false;    // object with connection to mysql, from mysqli_pdo class
  private $ix = 1;    // to show number when index pages
  private $start_url = '';
  private $add_subdomain = 1;    // 1 crawl and indexes subdomains of current domain; 0 to not include subdomain urls
  private $index_pending = 0;   // is set 1 if request to index pending urls (to not check $depth in allowAcc())
  private $error_url = [];      // stores not alloved urls
  private $seen_url = [0=>''];    // array with crawled urls [url=>1] (0->first inserted url)
  private $nr_urls = 0;     // number of crawled Urls
  public $reindex = 0;     // if 1, delete pages before insert
  public $max_depth = 999;    // maximum depth to crawl
  public $max_urls = 0;     // maximum urls to crawl, 0 - no limit
  public $url_include = [];  // array with strings that must be in $url to access
  public $url_exclude = [];  // array with strings that must not be in $url to access
  public $tables = ['dom'=>'', 'sets'=>'', 'url'=>'', 'pgd'=>''];    // mysql tables (url stores crawled links, 'pgd'- pages data)
  public $domain;    // domain name
  public $dom_id;    // id of $domain in database (for table name)
  // extensions of files excluded from crawling
  private $exclude_files = '3g2|3gp|7z|a52|aac|ace|amv|ar|arc|arj|as|asc|asf|avi|bin|bmp|bz2|bzip|bzip2|css|csv|divx|dll|drc|dv|f4v|exe|fla|flv|gif|gvi|gxf|gz|gzip|ice|ico|inf|ini|iso|jar|jpg|jpe|jpeg|js|jsfl|json|log|kar|m1v|m2v|m4a|m4v|midi|mkv|mp1|mp2|mp3|mp4|mtv|mxf|odb|odf|odp|ogg|ogm|ogv|ogx|ott|pcd|pdf|pic|pgm|png|pps|ppt|psd|ram|rar|rle|rm|sgv|sql|swf|tar|tga|tif|tiff|ttf|vlc|wmf|wmv|wvx|xlt|xar|xfl|zip|zipx';
  public $deltags = [['a'=>[]], ['form'=>[]], ['select'=>[]]];    // array with tags to complete delete [ [tag=>[attr=>[values]]], ... ]

  // $obsql = object with connection to mysql
  public function __construct($obsql) {
    $this->obsql = $obsql;
    $this->add_subdomain = isset($_POST['add_subdomain']) ? 1 : 0;

    // set session and tables data for current domain
    $this->domain = isset($_SESSION['ssep_domain']) ? trim(trim($_SESSION['ssep_domain'], '/')) : $_SERVER['SERVER_NAME'];
    if(isset($_SESSION['adminlogg'])) {
      if(!isset($_SESSION['ssep_dom_id'])) $this->createIndexTables(getDomainId($obsql, $this->domain));
      else {
        $this->dom_id = $_SESSION['ssep_dom_id'];
        $this->tables['url'] = SSEP_PREFIX .'url_'. $this->dom_id;
        $this->tables['pgd'] = SSEP_PREFIX .'pgd_'. $this->dom_id;
      }
    }
    $this->tables['dom'] = SSEP_PREFIX .'domain';
    $this->tables['sets'] = SSEP_PREFIX .'sets';
  }

    /* START SQL */

  // create mysql tables for domains and settings (if $add_dom is 1, register current domain)
  public function createMainTables($add_dom = 1) {
    $re = '';

    // creates table for domain, insert current domain, insert tablse for settings, and for pages with domain ID
    if($this->obsql) {
      $sql = "SHOW TABLES LIKE '". $this->tables['dom'] ."'";    // check if table exists
      $resql = $this->obsql->sqlExec($sql);
      if($resql && $this->obsql->num_rows > 0) return $re;
      else {
        $sql = "CREATE TABLE IF NOT EXISTS ". $this->tables['dom'] ." (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, domain VARCHAR(80) NOT NULL UNIQUE, protocol VARCHAR(5) NOT NULL DEFAULT 'http') CHARACTER SET utf8 COLLATE utf8_general_ci";
        if($this->obsql->sqlExec($sql)) {
          $sql = "CREATE TABLE IF NOT EXISTS ". $this->tables['sets'] ." (id INT UNSIGNED PRIMARY KEY NOT NULL DEFAULT 1, sets VARCHAR(2500) NOT NULL DEFAULT '{}') CHARACTER SET utf8 COLLATE utf8_general_ci";
          if($this->obsql->sqlExec($sql)) {
            $protocol = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
            if($add_dom == 1) $re = $this->addDomain($this->domain, $protocol);    // register $domain
          }
          else $re = $this->obsql->error;
        }
        else $re = $this->obsql->error;
      }
    }
    else $re = $this->obsql->error;

    return $re;
  }

  // create tables to index url and page-data, and session 'ssep_dom_id'. Rreceives  domain ID
  private function createIndexTables($dom_id) {
    $re = '';
    if($this->obsql && $dom_id > 0) {
      $sql = "CREATE TABLE IF NOT EXISTS ". SSEP_PREFIX .'url_'. $dom_id ." (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, url VARCHAR(255) NOT NULL UNIQUE, indexed INT(1) NOT NULL DEFAULT 0, depth INT(2) NOT NULL DEFAULT 0) CHARACTER SET utf8 COLLATE utf8_general_ci";
      if($this->obsql->sqlExec($sql)) {
        $engine = (substr($this->obsql->mysql_version, 0, 2) >= 56) ? 'InnoDB' : 'MyISAM';   // set ENGINE for mysql dable according to mysql server version

        $sql = "CREATE TABLE IF NOT EXISTS ". SSEP_PREFIX .'pgd_'. $dom_id ." (idurl INT UNSIGNED UNIQUE PRIMARY KEY, title VARCHAR(120), description VARCHAR(190), content TEXT NOT NULL, size VARCHAR(15) NOT NULL DEFAULT '', FULLTEXT(title), FULLTEXT(description), FULLTEXT(content)) ENGINE=". $engine ." CHARACTER SET utf8 COLLATE utf8_general_ci";
        if($this->obsql->sqlExec($sql)) {
          // change session and tables value
          $_SESSION['ssep_dom_id'] = $this->dom_id = $dom_id;
          $this->tables['url'] = SSEP_PREFIX .'url_'. $dom_id;
          $this->tables['pgd'] = SSEP_PREFIX .'pgd_'. $dom_id;
        }
        else $re = $this->obsql->error;
      }
      else $re = $this->obsql->error;
    }

    return $re;
  }

  // add domain in database, sets $domain
  public function addDomain($domain, $protocol) {
    $re = '';

    if($this->obsql) {
      $domain = trim(trim(strip_tags(strtolower($domain)), '/'));
      $sql = "INSERT INTO ". $this->tables['dom'] ." (domain, protocol) VALUES (:domain, :protocol)";
      if($this->obsql->sqlExec($sql, ['domain'=>$domain, 'protocol'=>$protocol])) {
        $_SESSION['ssep_domain'] = $this->domain = $domain;
        $re = $this->createIndexTables($this->obsql->last_insertid);    // create indexing tables
      }
      else $re = $this->obsql->error;
    }
    else $re = $this->obsql->error;

    if($re != '') echo $re;
    return $re;
  }

  // returns <option>s with registered domains (value="id-domain")
  public function getOpDomains() {
    $re = '';

    if($this->obsql) {
      $sql = "SELECT id, domain FROM ". $this->tables['dom'];
      $resql = $this->obsql->sqlExec($sql);
      if($resql && $this->obsql->num_rows > 0) {
          $num_rows = $this->obsql->num_rows;
          for($i=0; $i<$num_rows; $i++) {
            $selected = ($resql[$i]['id'] == $this->dom_id) ? ' selected' : '';
            $re .= '<option value="'. $resql[$i]['id'] .'-'. $resql[$i]['domain'] .'"'. $selected .'>'. $resql[$i]['domain'] .'</option>';
          }
        }
    }

    return $re;
  }

  // return array with number of indexed pages [-1:unable-to-read, 0:pending-to-index, 1:without-indexed-content, 2:succesfully-indexed]
  public function getNrPgs() {
      $re = ['nr_pgs'=>0, 'nr_1'=>0, 'nr0'=>0, 'nr1'=>0, 'nr2'=>0];
      $sql = "SELECT indexed, COUNT(id) AS nr FROM ". $this->tables['url'] ." GROUP BY indexed";
      $resql = $this->obsql->sqlExec($sql);
      // If returned rows, sets $re array with results, else, $re with error message
      if($resql) {
        if($this->obsql->num_rows > 0) {
          $num_rows = $this->obsql->num_rows;
          for($i=0; $i<$num_rows; $i++) {
            if($resql[$i]['indexed'] == -1) $re['nr_1'] = $resql[$i]['nr'];
            else $re['nr'. $resql[$i]['indexed']] = $resql[$i]['nr'];
          }
          $re['nr_pgs'] = array_sum($re);
        }
        else $re['nr_pgs'] = getTL('not_reg_pgs');
      }

      return $re;
  }

  // delete rows with content which not has 'idurl' associated in 'ssep_url_'
  public function cleanContent() {
    $re = '';

    if($this->obsql) {
      $sql = 'DELETE '. $this->tables['pgd'] .' FROM '. $this->tables['pgd'] .'
      LEFT JOIN '. $this->tables['url'] .' ON '. $this->tables['pgd'] .'.idurl = '. $this->tables['url'] .'.id
      WHERE  '. $this->tables['url'] .'.id IS NULL';
      if($this->obsql->sqlExec($sql)) $re = sprintf(getTL('ok_del_rows'), $this->obsql->affected_rows);
      else $re .= $this->obsql->error;
    }
    else $re .= $this->obsql->error;

    return $re;
  }

  // if $urls 'all' delete all rows from 'ssep_url_', 'ssep_pgd_' tables, if 0, delete indexed 0 from 'ssep_url_', if array, passed $urls
  public function deletePages($urls = 'all') {
    $re = '';
    if(is_array($urls)) {
      // to Fix Bug, not delete the first iserted url 2nd time, when reindex
      if($this->seen_url[0] != '' && in_array($this->seen_url[0], $urls)) {
        $del_key = array_search($this->seen_url[0], $urls);
        unset($urls[$del_key]);
        sort($urls);
      }

      if(count($urls) > 0) {
        $urls = "'". implode("','", $urls) ."'";
        // get IDs of $urls, then delete by id
        $sql = "DELETE FROM ". $this->tables['pgd'] ." WHERE idurl IN(
        SELECT GROUP_CONCAT(DISTINCT id) FROM ". $this->tables['url'] ." WHERE url IN (". $urls .")
        )";
        if($this->obsql->sqlExec($sql)) {
          if(!$this->obsql->sqlExec("DELETE FROM ". $this->tables['url'] ." WHERE url IN (". $urls .")")) $re .=  $this->obsql->error;
        }
        else $re .= $this->obsql->error;
      }
    }
    else if($urls === 0) {
      if(!$this->obsql->sqlExec('DELETE FROM '. $this->tables['url'] .' WHERE indexed = 0')) $re .=  $this->obsql->error;
    }
    else if($urls == 'all') {
      if($this->obsql->sqlExec('TRUNCATE TABLE '. $this->tables['pgd'])) {
        if(!$this->obsql->sqlExec('TRUNCATE TABLE '. $this->tables['url'])) $re .=  $this->obsql->error;
      }
      else $re .=  $this->obsql->error;
      if($re == '') $re = getTL('ok_del_pgs');
    }

    return $re;
  }

  // delete domain, its settings and tables
  public function deleteDomain() {
    $re = '';
    if($this->obsql) {
      if($this->obsql->sqlExec('DELETE FROM '. $this->tables['dom'] .' WHERE id = '. $this->dom_id)) {
        if(!$this->obsql->sqlExec('DELETE FROM '. $this->tables['sets'] .' WHERE id = '. $this->dom_id)) $re .=  $this->obsql->error;
        if(!$this->obsql->sqlExec('DROP TABLE '. $this->tables['url'])) $re .=  $this->obsql->error;
        if(!$this->obsql->sqlExec('DROP TABLE '. $this->tables['pgd'])) $re .=  $this->obsql->error;

        // delete all cache files and their folder
        $this->deleteCache('*');
        @rmdir(SSEP_CACHE);
      }
      else $re .=  $this->obsql->error;
    }
    else $re .=  $this->obsql->error;
    if($re == '') $re = sprintf(getTL('ok_del_dom'), $this->domain);

    return $re;
  }

  // add the page-data in database. Receives array [idurl, title, description, content]. Returns true, or error message
  private function addPgd($pgd) {
    $re = true;

    if($this->obsql) {
      $sql = "INSERT INTO ". $this->tables['pgd'] ." (idurl, title, description, content, size) VALUES (:idurl, :title, :description, :content, :size) ON DUPLICATE KEY UPDATE idurl = :idurl, title = :title, description = :description, content = :content, size = :size";
      if(!$this->obsql->sqlExec($sql, $pgd)) $re = $this->obsql->error;
    }
    else $re = $this->obsql->error;

    return $re;
  }

  // add the $urls in database. Returns true, or error message
  private function addUrls($urls, $depth) {
    $re = '';
    // delete protocol and domain from $urls (if not to include subdomains)
    if($this->add_subdomain == 0) $urls = array_map(function($url){ return '/'. ltrim(preg_replace('@(http|https)://(www\.){0,1}'. $this->domain .'@i', '', $url), '/');}, $urls);

    if($this->obsql) {
        // create array with $urls with and without ending '/'
        $urls0 = array_map(function($url){return ($url != '/') ? rtrim($url, '/') : $url;}, $urls);    // removes ending '/'
        $urls1 = array_map(function($url){return ($url != '/') ? $url  .'/' : $url;}, $urls0);    // adds ending '/'
        $urls_double = array_unique(array_merge($urls0, $urls1));
        $urls0 = $urls1 = '';    // to free memory

      // if $reindex = 1, or Insert of 1st URL (to can actualize it), delete rows with $urls, else select to not add same url twice
      if($this->reindex == 1 || $this->seen_url[0] == '') $this->deletePages($urls_double);
      else {
        $sql = "SELECT url FROM ". $this->tables['url'] ." WHERE url IN('". implode("','", $urls_double) ."')";
        $resql = $this->obsql->sqlExec($sql);
        if($resql) {
          // If returned rows, removes those url from $urls
          if($this->obsql->num_rows > 0) {
            $num_rows = $this->obsql->num_rows;
            $urls = array_flip(array_filter($urls));    // exchange values and keys to can unset()

            for($i=0; $i<$num_rows; $i++) unset($urls[$resql[$i]['url']]);
            $urls = array_flip($urls);    // exchange back values and keys
          }
        }
        else $re .= $this->obsql->error;
      }

      $vals = [];
      foreach($urls AS $url) $vals[] = "('". str_replace("'", "\\'", $url) ."', ". $depth .")";

      // if $max_urls > 0, sets how many urls to add from $vals, till $max_urls
      $nr_v = count($vals);
      if($this->max_urls > 0) {
        $nr_add = $this->max_urls - $this->nr_urls;
        if($nr_v > $nr_add) {
          $vals = array_slice($vals, 0, $nr_add);
          $nr_v = count($vals);
        }
      }

      if($nr_v > 0) {
        $sql = "INSERT INTO ". $this->tables['url'] ." (url, depth) VALUES ". implode(',', $vals);
        if($this->obsql->sqlExec($sql)) {
          $this->nr_urls += $nr_v;
          if($this->seen_url[0] == '') {
            $this->seen_url[0] = $urls[0];    // memory 1st added url, to not delete 2nd time when reindex

            // to not crawl again the starting url (without, or with ending '/')
            $this->seen_url[rtrim($this->start_url, '/')] = 1;
            $this->seen_url[rtrim($this->start_url, '/') .'/'] = 1;
          }
          $re .= '<div class="cl_b">&bull; '. sprintf(getTL('ok_add_url'), $nr_v, $depth) .'</div>';
        }
        else $re .= '<div class="cl_r">'. sprintf(getTL('er_add_url'), $url, $this->obsql->error) .'</div>';
      }
    }

    return $re;
  }

  // gets the first 10 urls with 'indexed' 0 from database, and calls crawlPage() for each one
  private function crawlUrls() {
    if($this->obsql) {
      $sql = "SELECT ". $this->tables['url'] .".id, url, depth, protocol FROM ". $this->tables['url'] ." LEFT JOIN ". $this->tables['dom'] ." ON ". $this->tables['dom'] .".domain = '". $this->domain ."' WHERE indexed = 0 ORDER BY ". $this->tables['url'] .".id LIMIT 10";
      $resql = $this->obsql->sqlExec($sql);
      if($resql && $this->obsql->num_rows > 0) {
        // If returned rows, calls crawlPage()
        $num_rows = $this->obsql->num_rows;
        for($i=0; $i<$num_rows; $i++) {
          if(!preg_match('#^http[s]{0,1}://#i', $resql[$i]['url'])) $resql[$i]['url'] = $resql[$i]['protocol'] .'://'. $this->domain . trim($resql[$i]['url']);    // make full-url if not already
          $this->crawlPage($resql[$i]['url'], $resql[$i]['depth'], $resql[$i]['id'], ($num_rows - $i));
        }
      }
      else {
        $this->cleanContent();    // clean unassociated 'idurl' in ssep_pgd_
        return getTL('ok_end_indexing');
        if($this->obsql->error) return $this->obsql->error;
      }
    }
  }

  // update the 'indexed' column in "ssep_url_" table. $receives array with [id, indexed] values
  private function updateSrcUrl($vals) {
    $sql = "UPDATE ". $this->tables['url'] ." SET indexed = :indexed WHERE id = :id LIMIT 1";
    if($this->obsql->sqlExec($sql, $vals)) return true;
    else return false;
  }

  // returns sitemap with indexed URLs (indexed != -1)
  public function getSitemap() {
    $re = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    if($this->obsql) {
      $sql = "SELECT url, domain, protocol FROM ". $this->tables['url'] ." LEFT JOIN ". $this->tables['dom'] ." ON ". $this->tables['dom'] .".domain = '". $this->domain ."' WHERE indexed != -1";
      $resql = $this->obsql->sqlExec($sql);
      if($resql && $this->obsql->num_rows > 0) {
        $num_rows = $this->obsql->num_rows;
        for($i=0; $i<$num_rows; $i++) {
         if(!preg_match('#^http[s]{0,1}://#i', $resql[$i]['url']))  $resql[$i]['url'] = $resql[$i]['protocol'] .'://'. $this->domain . $resql[$i]['url'];    // make full-url if not already
          $re .= '<url><loc>'. $resql[$i]['url'] .'<changefreq>weekly</changefreq><priority>0.41</priority></url>'. PHP_EOL;
        }
      }
    }
    $re .= '</urlset>';

    // output sitemap content for download
    header('Content-Disposition: attachment; filename="sitemap.xml"');
    header('Content-Type: application/force-download');
    header('Content-Length: ' . strlen($re));
    echo $re;
    exit;
  }

  // save settings in json format, from Advanced Settings. Receives array with settings. Return true, or false
  public function saveSettings($arr) {
    $re = true;
    if($this->obsql) {
      $sql = "INSERT INTO ". $this->tables['sets'] ." (id, sets) VALUES (:id, :sets) ON DUPLICATE KEY UPDATE id = :id, sets = :sets";
      if($this->obsql->sqlExec($sql, ['id'=>$this->dom_id, 'sets'=>strtolower(json_encode($arr))])) $_SESSION['ssep_sets'] = $arr;    // store setting in session, for faster access
      else $re = false;
    }
    else $re = false;
    return $re;
  }

    /* END SQL */

  // set to save settings with data from $_POST
  public function setSettings($post) {
    $re = ['deltags'=>[], 'score1'=>[], 'score2'=>[], 'rowsperpage'=>20, 'src_suggest'=>10, 'last_src'=>10, 'top_src'=>10, 'pgi_type'=>'infinite', 'use_ajax'=>1];

    // html elements which to remove from content
    if(isset($post['tag_name']) && is_array($post['tag_name'])) {
      // get tags, attribute, values to add in $deltags [ [tag=>[attr=>[values]]], ... ]
      $nr_tgs = count($post['tag_name']);
      $ix = 0;    // to avoid uncontinuous indexing due of emty fields
      for($i=0; $i<$nr_tgs; $i++) {
        $tag_name = trim(strtolower(strip_tags($post['tag_name'][$i])));
        $tag_attr = trim(strtolower(strip_tags($post['tag_attr'][$i])));
        $attr_val = trim(strtolower(strip_tags($post['attr_val'][$i])));
        if(strlen($tag_name) > 0) {
          $re['deltags'][$ix] = [$tag_name=>[]];
          if(strlen($tag_attr) > 0&& strlen($attr_val) > 0) $re['deltags'][$ix][$tag_name] = [$tag_attr=>array_map('trim', explode(',', $attr_val))];
          $ix++;
        }
      }
    }

    // elements with values to set score of searcs results
    $re['score1'] = ['title'=>$post['score_title_v'], 'description'=>$post['score_description_v'], 'url'=>$post['score_url_v'], 'content'=>$post['score_content_v']];

    $re['score2'] = ['b'=>['n'=>trim(strip_tags($post['score_b_n'])), 'v'=>trim(strip_tags($post['score_b_v']))], 'em'=>['n'=>trim(strip_tags($post['score_em_n'])), 'v'=>trim(strip_tags($post['score_em_v']))], 'u'=>['n'=>trim(strip_tags($post['score_u_n'])), 'v'=>trim(strip_tags($post['score_u_v']))], 'strong'=>['n'=>trim(strip_tags($post['score_strong_n'])), 'v'=>trim(strip_tags($post['score_strong_v']))], 'h5'=>['n'=>trim(strip_tags($post['score_h5_n'])), 'v'=>trim(strip_tags($post['score_h5_v']))], 'h4'=>['n'=>trim(strip_tags($post['score_h4_n'])), 'v'=>trim(strip_tags($post['score_h4_v']))], 'h3'=>['n'=>trim(strip_tags($post['score_h3_n'])), 'v'=>trim(strip_tags($post['score_h3_v']))], 'h2'=>['n'=>trim(strip_tags($post['score_h2_n'])), 'v'=>trim(strip_tags($post['score_h2_v']))], 'h1'=>['n'=>trim(strip_tags($post['score_h1_n'])), 'v'=>trim(strip_tags($post['score_h1_v']))]];

    if(isset($post['rowsperpage'])) $re['rowsperpage'] = intval($post['rowsperpage']);  // number of rows displayed in search page
    if(isset($post['src_suggest'])) $re['src_suggest'] = intval($post['src_suggest']);  // number of rows for search suggestions
    if(isset($post['last_src'])) $re['last_src'] = intval($post['last_src']);  // number of lists for Menu with Last Searches
    if(isset($post['top_src'])) $re['top_src'] = intval($post['top_src']);  // number of lists for Menu with Top Searches
    if(isset($post['pgi_type'])) $re['pgi_type'] = $post['pgi_type'];  // pagination type
    if(isset($post['use_ajax'])) $re['use_ajax'] = $post['use_ajax'];  // enable /disable Ajax in page with sewarch results

    return $this->saveSettings($re) ? $re : [];    // save data
  }

  // delete all files in SSEP_CACHE. $type = extension of the files to delete, '*' for all files
  public function deleteCache($type = '*.htm') {
    if(is_dir(SSEP_CACHE)) {
      $files = glob(SSEP_CACHE .$type);
      $nrf = is_array($files) ? count($files) : 0;
      if($nrf > 0) array_map('unlink', $files);
      $files = glob(SSEP_CACHE .$type);
      $nrf = is_array($files) ? count($files) : 0;
      if($nrf == 0) return getTL('ok_del_cache');
      else return sprintf(getTL('er_del_cache'), $nrf, SSEP_CACHE);
    }
  }

  // replace hex-decimal-html-entities with their Ascii
  private function getAscii($str) {
    $str = preg_replace_callback('~&#x([0-9a-f]+);~i', function($m){ return chr(hexdec($m[1]));}, $str);
    $str = preg_replace_callback('~&#([0-9]+);~', function($m){ return mb_convert_encoding('&#' . intval($m[1]) . ';', 'UTF-8', 'HTML-ENTITIES');}, $str);
    $str = str_replace(['&amp','&THORN;','&szlig;','&agrave;','&aacute;','&acirc;','&atilde;','&auml;','&aring;','&aelig;','&ccedil;','&egrave;','&eacute;','&ecirc;','&euml;','&igrave;','&iacute;','&icirc;','&iuml;','&eth;','&ntilde;','&ograve;','&oacute;','&ocirc;','&otilde;','&ouml;','&oslash;','&ugrave;','&uacute;','&ucirc;','&uuml;','&yacute;','&thorn;','&yuml;','&Agrave;','&Aacute;','&Acirc;','&Atilde;','&Auml;','&Aring;','&Aelig;','&Ccedil;','&Egrave;','&Eacute;','&Ecirc;','&Euml;','&Igrave;','&Iacute;','&Icirc;','&Iuml;','&ETH;','&Ntilde;','&Ograve;','&Oacute;','&Ocirc;','&Otilde;','&Ouml;','&Oslash;','&Ugrave;','&Uacute;','&Ucirc;','&Uuml;','&Yacute;','&Yhorn;'], ['&','Ţ','ß','ŕ','á','â','ă','ä','ĺ','ć','ç','č','é','ę','ë','ě','í','î','ď','đ','ń','ň','ó','ô','ő','ö','ř','ů','ú','ű','ü','ý','ţ','˙','ŕ','á','â','ă','ä','ĺ','ć','ç','č','é','ę','ë','ě','í','î','ď','đ','ń','ň','ó','ô','ő','ö','ř','ů','ú','ű','ü','ý','ţ'], $str);

    return $str;
  }

  // sets page-data. Receives $content. Returns array with [url, title, description, content]
  private function setPgd($content) {
    $re = ['title'=>'', 'description'=>'', 'content'=>''];

    if(strlen($content) > 1) {
      // get title, description (replace html-entities, non-alfa-numeric, and multiple-spaces with single-space)
      if(preg_match("@\<title *\>(.*?)\</title*\>@si", $content, $m)){
        $m[1] =str_replace(['`',"’","'",'"','\\','<','>','/','?','!','@','#','$','&','+','=','|'],' ',$m[1]);
        $re['title'] = trim(preg_replace(['/&[a-z]{0,8};/i', '@[^a-z0-9ŔÁÂĂÄĹĆŕáâăäĺćŇÓÔŐŐÖŘňóôőöřČÉĘËčéęëđÇçĐĚÍÎĎěíîďŮÚŰÜůúűüŃńŢßýŞş ]@i', '/\s+/i'], ' ', $this->getAscii($m[1])));
        $re['title'] = mb_substr($re['title'], 0, 119);
      }
      if(preg_match("/\<meta +name *=[\"']?description[\"']? *content=[\"']?([^\<\>'\"]+)[\"']?/i", $content, $m)){
        $m[1] =str_replace(['`',"’","'",'"','\\','<','>','/','?','!','@','#','$','&','+','=','|'],' ',$m[1]);
        $re['description'] = trim(preg_replace(['/&[a-z]{0,8};/i', '@[^a-z0-9ŔÁÂĂÄĹĆŕáâăäĺćŇÓÔŐŐÖŘňóôőöřČÉĘËčéęëđÇçĐĚÍÎĎěíîďŮÚŰÜůúűüŃńŢßýŞş ]@i', '/\s+/i'], ' ', $this->getAscii($m[1])));
        $re['description'] = mb_substr($re['description'], 0, 189);
      }

      // create the DOMDocument object, and load HTML from a string
      $dochtml = new DOMDocument();
      $dochtml->loadHTML($content);

      $elm = $dochtml->getElementsByTagName('body')->item(0);      // get the <body> element

      if($elm != null) {
        $domElemsToRemove = [];

        // traverse $deltags and delete those tags and their content
        $nr_deltags = count($this->deltags);
        for($i=0; $i<$nr_deltags; $i++) {
          foreach($this->deltags[$i] AS $tag => $att_v) {
            $etag = $elm->getElementsByTagName($tag);
            $attr = key($att_v);
            $by_attr = count($att_v);    // if 0 removes al $etag items, else, only by speciffied attribute
            if($etag->length != 0) {
              foreach($etag as $domElement ) {
                if($by_attr == 0) $domElemsToRemove[] = $domElement;
                else if(in_array($domElement->getAttribute($attr), $att_v[$attr])) $domElemsToRemove[] = $domElement;
              }
            }
          }
        }
        
        if(count($domElemsToRemove) > 0){
          foreach($domElemsToRemove as $domElement) $domElement->parentNode->removeChild($domElement);
        }

        // create new DOM to add the $elm to can return with html tags
        $new_dom = new DOMDocument();
        $new_dom->appendChild($new_dom->importNode($elm, TRUE));
        $content = $new_dom->saveHTML();
      }
      else if(preg_match("@\<body *\>(.*?)\</body*\>@si", $content, $m)) $content = trim($m[1]);

      $content = str_ireplace(['&nbsp;', '&lt;', '&gt;'], ' ', $content);

      // create spaces between tags, so that removing tags doesnt concatenate strings
      $content = str_replace(['<', '>'], [' <', '> '], $content);

      $content = preg_replace_callback('@\<img [^\>]+alt="([^"]*)"[^\>]*>@i', function($m){ return isset($m[1]) ? $m[1] : '';}, $content);   // keeps the string from "alt" attribute of <img> tag

      // strip_tags, keep only needed tags for searching to determine words height
      $content = strip_tags($content, '<b><u><em><strong><h5><h4><h3><h2><h1>');

      $content = $this->getAscii($content);   // replace hex-decimal-html-entities with Ascii 

      // replace URLs and other non needed characters with space
      $content = preg_replace(['@http://[^ ]*@i', '@www\.[^ ]*@i', '@[\-_\.]{3,}@i', '@([^a-z0-9_\-&;\<\>="\'\./ŔÁÂĂÄĹĆŕáâăäĺćŇÓÔŐŐÖŘňóôőöřČÉĘËčéęëđÇçĐĚÍÎĎěíîďŮÚŰÜůúűüŃńŢßýŞş ]+)@i'], ' ', $content);

      $content = preg_replace('/\<([^ \>]+) ([^\>]+)\>/i', "<$1>", $content);   // removes attributes from needed tags

      $content = trim(preg_replace(['/&[a-z]{0,8};/i', '@[^a-z0-9\<\>/ŔÁÂĂÄĹĆŕáâăäĺćŇÓÔŐŐÖŘňóôőöřČÉĘËčéęëđÇçĐĚÍÎĎěíîďŮÚŰÜůúűüŃńŢßýŞş ]+@i'], ' ', $content));     // replace html-entities and non-alfa-numeric with single-space
      $content = preg_replace('@(.)\1{2,}@', '$1$1', $content);    // removes more than 2 consecutive characters
      $content = trim(str_replace(' ', '  ', $content));    // single-space with two-spaces, to can replace next all single-characters
      $re['content'] = trim(preg_replace(['/^[^ ] /i', '/ [^ ] /i', '/ [^ ]$/i', '@[0-9]{2,}@', '@[/]{2,}@', '/\s+/i'], ' ', $content));     // replace single-characters, multiple-numbers, multiple-// and multiple-spaces with single-space
    }

    return $re;
  }

  // return an absolute path from a base url and a relative path
	private function fullUrl($base, $href) {
    $url = false;
		if(empty($href)) $url = $base;
		else if($href_parts = @parse_url($href)) {
      $base_parts = parse_url($base);
      $domain = isset($base_parts['host']) ? $base_parts['host'] : $this->domain;

      if(isset($href_parts['path'])) {
        $path = $href_parts['path'];
        if(isset($href_parts['query'])) $path .= '?'. $href_parts['query'];    // add part with '?...' in url

        if($path[0] != '/') $path = $base_parts['path'] .'/'. $path;      // Replace initial / with full path

        $is_dir = (substr($path, -1) == '/');
        $path = explode('/', $path);
        $level = 0;
        $new = [];
        foreach($path as $part) {
          if($part == '.' || $part == '') continue;    // Ignore ./ and //
          if($part == '..') {
            // Go a level deeper 
            $level--;
            if($level < 0) break;
          }
          else {
            $new[$level] = $part;
            $level++;
          }
        }

        // define url to return
        if($level > 0) {
          $url = strtolower($base_parts['scheme']) .'://'. $domain;

          // add parts to http;//domain
          for($i=0; $i<$level; $i++) $url .= '/'.$new[$i];
          if($is_dir) $url .= '/';
        }
      }
		}

    return $url;
	}

  // return array with all local links in current page ($url)
  private function getAnchors($content, $url, $depth) {
    $re = [];    // stores the local full url in current page

    $dom = new DOMDocument('1.0');
    @$dom->loadHTML($content);
    $anchors = $dom->getElementsByTagName('a');

    // set Base url, from tag <base> if exists, else from $url (deleting end part which is not dir)
    $base = $dom->getElementsByTagName('base');
    $base = ($base->length > 0) ? $base->item(0)->getAttribute('href') : rtrim(preg_replace('@/([^/]+)$@i', '', $url), '/') .'/';
    $base = trim($base);

    foreach($anchors as $element) {
      $href = trim($element->getAttribute('href'));
      $full_url = false;
      if($href == '') $full_url = $base;
      else if(stripos($href, 'http') !== 0) $full_url = $this->fullUrl($base, $href);
      else {
        $url_info = parse_url($href);
        if(($this->add_subdomain == 1 && preg_match('#^([^\.]*\.){0,1}'. $this->domain .'$#i', $url_info['host'])) || $url_info['host'] == $this->domain) $full_url = $href;    // if subdomain /local full url
      }

      if($full_url !== false) {
        $full_url = trim(preg_replace('/#(.*?)$/i', '', $full_url));    // delete anchor for inside url

        // if file with extension in $exclude_files, pass
        // else if alllowed $url, add it in $seen_url and $re to insert it in database, else, add it in $error_url, and output message
        if(preg_match('/\.('. $this->exclude_files .')(\?(.*?))*$/i', $full_url)) continue;
        else if(!isset($this->seen_url[$full_url]) && !isset($this->error_url[$full_url])) {
          $error_url = $this->allowAcc($full_url, $depth);
          if(count($error_url) == 0) {
            $this->seen_url[$full_url] = 1;

            // url encode the part after last "/" from url.
///         if(preg_match('/\/([^\/]+)$/i', $full_url, $pm)) $full_url = str_ireplace($pm[1], rawurlencode($pm[1]), $full_url);
            $re[] = str_ireplace(' ', '%20', $full_url);    // add url to return, replacing space with '%20'
          }
          else {
            $this->error_url[$full_url] = 1;
            echo '<div class="re_crawl cl_r">'. implode('<br>', $error_url) .'</div>';
          }
        }
      }
    }

    return $re;
  }

  // used when "open_basedir" and "safe_mode" are set True in php.ini, to follow Redirects by Header-Response
  // Receives cUrl instance, optional: number of maximum redirects to follow,m and additional headers to pass
  private function curlFollowHeader(&$curl, $redirects = 8, $header = false) {
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($curl, CURLOPT_HEADER, $header);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_FORBID_REUSE, false);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

    do {
      $resp = curl_exec($curl);
      if(curl_errno($curl)) break;    // stop if curl-error

      $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
      if($code != 301 && $code != 302) break;    // stop if not redirect-status

      // get redirect address from header
      $header_start = strpos($resp, "\r\n") + 2;
      $headers = substr($resp, $header_start, strpos($resp, "\r\n\r\n", $header_start) + 2 - $header_start);
      if(!preg_match("!\r\n(?:Location|URI): *(.*?) *\r\n!", $headers, $m)) break;    // stop if not redirect URL

      curl_setopt($curl, CURLOPT_URL, $m[1]);    // get redirect address
    }
    while (--$redirects);

    if(!$redirects) trigger_error('Too many redirects. When following redirects, libcurl hit the maximum amount.', E_USER_WARNING);

    return $resp;
  }

  // get page from $url. Returns array with [response, http_status, time_geting_response, page_size]
  private function getPage($url) {
    $curl = curl_init($url);

    $header[0] = "Accept: text/xml,application/xml,application/xhtml+xml,";
    $header[0] .= "text/html;q=0.9,text/plain;q=0.8,image/png,*/*;q=0.5";
    $header[] = "Cache-Control: max-age=0";
    $header[] = "Connection: keep-alive";
    $header[] = "Keep-Alive: 300";
    $header[] = "Accept-Charset: ISO-8859-1,utf-8;q=0.7,*;q=0.7";
    $header[] = "Accept-Language: en-us,en;q=0.5";
    $header[] = "Pragma: ";

    // if allow CURLOPT_FOLLOWLOCATION ("open_basedir" false) and not safe_mode get data and follwo redirects directly
    // else follow redirects by status and header response with curlFollowHeader() method
    if(!ini_get('open_basedir') && !ini_get('safe_mode')) {
      curl_setopt($curl, CURLOPT_HEADER, $header);
      curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
      curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
      curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
      curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
      curl_setopt($curl, CURLOPT_MAXREDIRS, 8);
      $resp = curl_exec($curl);     // get response
    }
    else $resp = trim($this->curlFollowHeader($curl, 8, $header));     // get response from following function

    $resp = substr($resp, strpos($resp, '<'));     // remove Header data from response
    // get time and status to add in returned array
    $time = curl_getinfo($curl, CURLINFO_TOTAL_TIME);      // response total time
    $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);   // Status response code; 200 (ok), 404 (file not found)
    $redir_url = curl_getinfo($curl, CURLINFO_EFFECTIVE_URL); //redirected url, to detect if server-response url has ending '/' (domain/dir is redirected to domain/dir/)
    curl_close($curl);

    return [$resp, $http_status, $redir_url, $time, formatSize(strlen($resp))];
  }

  // return empty array, or with error messages
  private function allowAcc($url, $depth) {
    $re = [];  $url = trim($url);

    // check if url is from current domain (or subdomain if set to include subdomains)
    if($this->add_subdomain == 1) {
      if(!preg_match('#^http[s]{0,1}://(www\.){0,1}([^\.]*\.){0,1}'. $this->domain .'#i', $url)) $re[] = sprintf(getTL('er_local_url'), $url);
    }
    else if(!preg_match('#^http[s]{0,1}://(www\.){0,1}'. $this->domain .'#i', $url)) $re[] = sprintf(getTL('er_local_url'), $url);

    // check depth (if limit set)
    if($this->index_pending == 0 && $depth > $this->max_depth) $re[] = sprintf(getTL('er_depth'), $depth, $this->max_depth);

    // check is $url contains included string, added in $url_include
    $err = (count($this->url_include) > 0) ? 1 : 0;
    foreach($this->url_include as $str) {
      if($url == $this->start_url || stripos($url, $str) !== false) { $err = 0; break; }
    }
    if($err == 1) $re[] = sprintf(getTL('er_must_include'), $url);
    

    // check is $url contains excluded string, added in $url_exclude
    foreach($this->url_exclude as $str) {
      if(stripos($url, trim($str)) !== false) {
        $re[] = sprintf(getTL('er_exclude_url'), $url);  break;
      }
    }
    return $re;
  }

  // if allows to access the $url, get its data, print result, and get links in this page
  // id - id of $url in "ssep_url_" table, $last 1 - last page from current set
  private function crawlPage($url, $depth, $id = 1, $last = 1) {
    $re = $this->allowAcc($url, $depth);
    if(count($re) > 0) echo '<div class="re_crawl cl_r">'. implode('<br>', $re) .'</div>';
    else if($this->max_urls > 0 && $this->nr_urls > $this->max_urls) echo '<h4 class="cl_r">'. getTL('ok_end_indexing'). sprintf(getTL('er_max_urls'), $this->max_urls) .'</h4>';    // when with max-urls
    else {
      $re = '';
      list($content, $http_status, $redir_url, $time, $size) = $this->getPage($url);    // get Content and Return Code

      //set $url_crawl with ending '/' to current $url if server-response url contains it
      $url_crawl = preg_match('#(.*?)/$#i', $redir_url) ?rtrim($url, '/').'/' :$url;

      // if status 200, and if $depth > 0, and not exceds $max_urls gets all links in current page
      $urls = ($http_status == 200) ? ((($this->max_depth - $depth) > 0 && ($this->max_urls == 0 || $this->nr_urls < $this->max_urls)) ? $this->getAnchors($content, $url_crawl, $depth) : []) : 0;

      if(is_array($urls)) {
        // get array with page-data, and add it in "ssep_pgd_" table
        $pgd = $this->setPgd($content);
        $pgd['idurl'] = $id;
        $pgd['size'] = $size;
        $addpgd = $this->addPgd($pgd);

        // if succesfully insert, set "indexed" to 2, else, set to 1 (crawled, but not content registered)
        if($addpgd === true) $res = ($this->updateSrcUrl(['indexed'=>2, 'id'=>$id])) ? sprintf(getTL('ok_indexed'), $url, $depth, $time, $size) : sprintf(getTL('er_update_indexed'), $url);
        else $res = ($this->updateSrcUrl(['indexed'=>1, 'id'=>$id])) ? sprintf(getTL('er_add_pgd'), $url, $depth, $time, $size) : sprintf(getTL('er_update_indexed'), $url);
        $re .= $this->ix .') '. $res;
        $this->ix++;

        // add the local links from current $url
        if($depth < $this->max_depth && count($urls) > 0) $re .= $this->addUrls($urls, $depth + 1);
      }
      else $re .= '<div class="cl_r">'. (($this->updateSrcUrl(['indexed'=>-1, 'id'=>$id])) ? sprintf(getTL('er_get_page'), $url, $http_status) : sprintf(getTL('er_update_indexed'), $url)) .'</div>';

      // output results to browser
      @ob_end_flush();
      echo '<div class="re_crawl">'. $re .'</div>';
      ob_start();
      flush();

      if($last == 1) {
        $re = $this->crawlUrls();
        if(strlen($re) > 0) echo '<h3 class="cl_b">'. $re .'</h3>';      // get next urls to index (with 'indexed' 0)
      }
    }
  }

  // to index only the URLs passed in $urls array
  public function indexOnly($urls) {
    $this->reindex = 1;    // to delete if these $urls are indexed
    $this->max_depth = 0;
    $this->max_urls = count($urls);

    // check $urls, remove invalid URLs
    for($i=0; $i<$this->max_urls; $i++) {
      $error_url = $this->allowAcc($urls[$i], 0);
      if(count($error_url) > 0) {
        unset($urls[$i]);
        echo '<div class="re_crawl cl_r">'. implode('<br>', $error_url) .'</div>';
      }
    }
    sort($urls);    // sort to reorder index-keys
    $this->max_urls = count($urls);

    if($this->max_urls > 0) {
      $this->deletePages(0);    // delete pending URLs (indexed 0)
      echo $this->addUrls($urls, 0);
      $this->crawlUrls();    // start crawl inserted $urls
    }
  }

  // index pending URLs, with 'indexed' 0
  public function indexPending() {
    $this->index_pending = 1;    // to not check $depth in allowAcc()
    $this->max_depth = 0;    // to nor crawl links in pending urls
    $this->crawlUrls();    // start crawl inserted $urls
  }

  // start to crawl
  public function run($start_url) {
    $start_url = trim(preg_replace('/#(.*?)$/i', '', $start_url));  // delete ending #..;
    $this->start_url = $start_url;
    $this->deletePages(0);    // if Reindex, delete pending URLs (indexed 0)
    $this->addUrls([$start_url], 0);
    if($this->obsql->last_insertid > 0) $this->crawlPage($start_url, 0, $this->obsql->last_insertid);
  }
}