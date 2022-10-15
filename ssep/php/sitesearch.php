<?php
// Clasa SiteSearch to get search results of page data indexed by Crawl in database
class SiteSearch {
  public $search = 'index';                        // search phrase
  private $alchr ='A-z0-9ŔÁÂĂÄĹĆŕáâăäĺćŇÓÔŐŐÖŘňóôőöřČÉĘËčéęëđÇçĐĚÍÎĎěíîďŮÚŰÜůúűüŃńŢßýŞş'; //allowed characters in RegExp for search
  public $ssep_words = [];        // array with valid words to search in database
  public $pg_data = ['title'=>'', 'description'=>'', 'keywords'=>''];          // datele paginii (titl, desc, keys)
  private $cache_file = '';      // the name of cache-file with current search result (valid_words sort by name)
  public $ssep_pg;    // search page name used in links
  public $use_ajax = 1;    // 1 build menu and pagination buttons with <span> (to load via ajax), 0 build with <a>

  private $counter = 'counter.json';      // file with search list counter
  private $srclist = [];           // store the array items from $counter
  private $nr_srclist = 0;              // number of items in $srclist
  private $ext = '.htm';
  private $obsql = false;    // object with connection to mysql, from mysqli_pdo class
  public $tables = ['dom'=>'', 'url'=>'', 'pgd'=>''];    // mysql tables (url stores crawled links, 'pgd'- pages data)
  public $stop_words = [];    // words which to remove from search (in stop_words.txt)
  private $nr_results = 0;    // number of results
  public $src_suggest = 10;    // number of rows with search suggestions

  // for pagination
  public $rowsperpage = 20;    // number of rows displayed in the page
  public $range = 3;           // range number of links around the current
  private $totalpages = 0;   // number of total pages
  private $pgi = 0;        // the index of the current page in pagination

  public $score1 = ['title'=>30, 'description'=>10, 'url'=>15, 'content'=>1];    // value-weight of items for results-score
  // tags to keep in content, [ta:[max_nr-of-this-tag, value]] with value for the words inside them
  public $score2 = ['b'=>['n'=>12, 'v'=>2], 'em'=>['n'=>12, 'v'=>2], 'u'=>['n'=>12, 'v'=>2], 'strong'=>['n'=>12, 'v'=>4], 'h5'=>['n'=>12, 'v'=>3], 'h4'=>['n'=>10, 'v'=>4], 'h3'=>['n'=>7, 'v'=>9], 'h2'=>['n'=>7, 'v'=>10], 'h1'=>['n'=>2, 'v'=>18]];

  // $obsql = object with connection to mysql; $start and $end for Select Limit
  public function __construct($obsql) {
    $this->ssep_pg = $_SERVER['PHP_SELF'];
    if(isset($_REQUEST['pgi'])) $this->pgi = intval(abs($_REQUEST['pgi'])) - 1;
    $this->obsql = $obsql;

    if(isset($_SESSION['src_dom_id'])) {
      $this->tables['dom'] = SSEP_PREFIX .'domain';
      $this->tables['url'] = SSEP_PREFIX .'url_'. $_SESSION['src_dom_id'];
      $this->tables['pgd'] = SSEP_PREFIX .'pgd_'. $_SESSION['src_dom_id'];
    }

    // if $counter is less than 200 KB, store its data in $srclist
    if(is_file(SSEP_CACHE . $this->counter) && (filesize(SSEP_CACHE . $this->counter) / 1024) < 200) {
      $this->srclist = json_decode(file_get_contents(SSEP_CACHE . $this->counter), true);
      $this->nr_srclist = count($this->srclist);
    }
  }

  // returns html search from cache, or from setSearchData() and setHtmlSrc()
  public function getSearch($search) {
    $this->defineSearch($search);      // set $search, $ssep_words and $cache_file
    $this->setPgData();      // set pages data in $pg_data

    $cache_file = SSEP_CACHE . $this->cache_file .(($this->pgi > 0) ? '__'. $this->pgi : ''). $this->ext;   // Adresa si numele fisierului cache

    // if $cache_file exists, has data and newer than 12 days get its content, else gets data from database
    if(file_exists($cache_file) && filesize($cache_file) > 8 && (time()-filemtime($cache_file) < 1200000)) $re = file_get_contents($cache_file);
    else {
      // To avoid search spamm
      if(isset($_SESSION['ssep_src']) && !isset($_POST['isajax']) && !isset($_GET['pgi']) && (time()-$_SESSION['ssep_src']) < 10) return '<h3>'. getTL('er_ssep_time') .'</h3>';
      else {
        $_SESSION['ssep_src'] = time();

        // 3-dimensional array with search-result rows [score:[ [row], [row] ], get its html in $re
        $re = $this->setSearchData($this->ssep_words);
        $re = (count($re) > 0) ? $this->setHtmlSrc($re) : '';

        // if $re TRUE, else, error message
        if($re != '') {
          if(!is_dir(SSEP_CACHE)) @mkdir(SSEP_CACHE, 0755);    // create folder-cache for current domain
          $this->addSearch();    // to add number of searches
          if(!file_put_contents($cache_file, $re)) echo sprintf(getTL('er_save_file'), $this->cache_file);
        }
        else {
          $re = '<h4>'. getTL('er_ssep_results') .': <em>'. implode(' ', $this->ssep_words) .'</em>';
          $_SESSION['ssep_src'] = 8;
        }
      }
    }

    return $re;
  }

    /* START SQL */
  // return array with search result from database, or string with message. Receives array with $words
  private function getSearchSql($words) {
    $re = getTL('er_ssep_results') .': '. implode(' ', $words);
    $nr_w = count($words);
    $min_val = [['tdc'=>0.01, 't'=>0.01, 'd'=>0.01], ['tdc'=>0.03, 't'=>0.15, 'd'=>0.1], ['tdc'=>0.05, 't'=>0.4, 'd'=>0.3]];  // minim value of row results to Boolean-Mode
    $i_mv = 0;

    if($nr_w > 0) {
      if($this->obsql) {
        $start = $this->pgi * $this->rowsperpage;           // the row from which start to select the content
        $against = '+'. implode(' +', $words);   // make a string with + in front of each word, separated by space

        // traverses by number of words, and removes '+' to each iteration, from the ending, till row with result
        for($i=0; $i<$nr_w; $i++) {
          if($i > 0) $against = preg_replace('/\+([^\+]+)$/i', '$1', $against);    // removes last +

          // make SELECT for each $min_val, to set the total number of pages ($totalpages)
          for($i2=0; $i2<3; $i2++) {
            $sql[$i2] = 'SELECT COUNT(idurl) AS nr FROM '. $this->tables['pgd'] .' WHERE
MATCH(title,description,content) AGAINST ("'. $against .'" IN BOOLEAN MODE) > '. $min_val[$i2]['tdc'] .' OR
(
  MATCH (title) AGAINST ("'. $against .'" IN BOOLEAN MODE) > '. $min_val[$i2]['t'] .'  AND
  MATCH (description) AGAINST ("'. $against .'" IN BOOLEAN MODE) > '. $min_val[$i2]['d'] .'
)';
          }

          $resql[0] = $this->obsql->sqlExec($sql[0]);  $num_rows[0] = $this->obsql->num_rows;  $i_mv = 0;
          // if more than 80 results in $min_val[0], get with $min_val[1] (if higher than 3)
          if(isset($resql[0][0]['nr']) && $resql[0][0]['nr'] > 80) {
            $resql[1] = $this->obsql->sqlExec($sql[1]);  $num_rows[1] = $this->obsql->num_rows;
            if(isset($resql[1][0]['nr'])) {
              if($resql[1][0]['nr'] > 3) $i_mv = 1;
              if($resql[1][0]['nr'] > 80) {
                $resql[2] = $this->obsql->sqlExec($sql[2]);  $num_rows[2] = $this->obsql->num_rows;
                if(isset($resql[2][0]['nr']) && $resql[2][0]['nr'] > 3) $i_mv = 2;  // index of accepted $min_val
              }
            }
          }

          if($resql[$i_mv] && $num_rows[$i_mv] > 0) {
            if($i < ($nr_w - 1) && $resql[$i_mv][0]['nr'] < 7) continue;   // pass over less than 7 results, if not last iteration
            else if($resql[$i_mv][0]['nr'] > 0) {
              $this->nr_results = $resql[$i_mv][0]['nr'];
              $this->totalpages = ceil($this->nr_results / $this->rowsperpage);
              break;
            }
          }
        }

        if($this->nr_results > 0) {
          $min_val = $min_val[$i_mv];
          // CASE for SQL, title, description applyed to each word
          $sql_case = "CASE WHEN %s REGEXP '(^[^".$this->alchr." ]*%s[^".$this->alchr." ]* | [^".$this->alchr."]*%s[^".$this->alchr."]* | [^".$this->alchr."]*%s[^".$this->alchr." ]*$){%s}' THEN %d ELSE 0 END";

          // traverses by number of words, store in $sql_case_words each $sql_cases to each word
          // Score for relevance (Description start: 10+$nr_w ; Title start: description_start+$nr_w)
          // Decrease $i from $start_ in next iteration, to have more relevance for first words
          $sql_case_words = [];
          $start_d = 10 + $nr_w;
          $start_t = $start_d + $nr_w;
          for($i=0; $i<$nr_w; $i++) {
            $sql_case_words[] = sprintf($sql_case, 'title', $words[$i], $words[$i], $words[$i], 1, $start_t);
            $sql_case_words[] = sprintf($sql_case, 'description', $words[$i], $words[$i], $words[$i], '1,2', $start_d);
            $start_t--;  $start_d--;
          }

/* SQL FORMULA TO RESULT:
  SELECT title, ...,
  (
    (
      CASE WHEN title REGEXP '(^php | php | php$){1}' THEN 11 ELSE 0 END +
      CASE WHEN title REGEXP '(^html | html | html$){1}' THEN 10 ELSE 0 END
      ...
      CASE WHEN description REGEXP '(^php | php | php$){1,2}' THEN 11 ELSE 0 END +
      CASE WHEN description REGEXP '(^html | html | html$){1,2}' THEN 10 ELSE 0 END
      ...
    )
    + MATCH(title,description,content) AGAINST ('+php +html') / 2.5
  ) / 3 AS score
  FROM ssep_pgd_36 WHERE
    MATCH(title,description,content) AGAINST ('+php +html') > 0.9
    ...
  ORDER BY score DESC
*/

          // select to get the results
          $sql = 'SELECT domain, protocol, url, title, description, content, size,
( '. implode(' + ', $sql_case_words) .'
  + MATCH (title,description,content) AGAINST ("'. $against .'" IN BOOLEAN MODE) / 2
) / '. (($nr_w * 2) + 1) .' AS score
FROM '. $this->tables['pgd'] .'
LEFT JOIN '. $this->tables['url'] .' ON '. $this->tables['pgd'] .'.idurl = '. $this->tables['url'] .'.id
LEFT JOIN '. $this->tables['dom'] .' ON '. $this->tables['dom'] .'.id = '. $_SESSION['src_dom_id'] .'
WHERE
MATCH(title,description,content) AGAINST ("'. $against .'" IN BOOLEAN MODE) > '. $min_val['tdc'] .' OR
(
  MATCH (title) AGAINST ("'. $against .'" IN BOOLEAN MODE) > '. $min_val['t'] .' AND
  MATCH (description) AGAINST ("'. $against .'" IN BOOLEAN MODE) > '. $min_val['d'] .'
)
ORDER BY score DESC LIMIT '. $start .', '. $this->rowsperpage;

          $resql = $this->obsql->sqlExec($sql);
          if($resql) {
            // If returned rows, removes those url from $urls
            if($this->obsql->num_rows > 0) $re = $resql;
          }
          else $re = $this->obsql->error;
        }
      }
      else $re = $this->obsql->error;
    }

    return $re;
  }

  // returns first $src_suggest rows with titles that contains the words in $src
  public function srcSugest($src) {
    $re = '';    // returned data
    $src = array_map('trim', explode(' ', trim($src)));    // gets array with the words in $src
    $nr_src = count($src);

    if($nr_src > 0) {
      // CASE for SQL REGEXP to each word (receives REGEXP, and value for score)
      $sql_case = "CASE WHEN %s THEN %d ELSE 0 END";
      $sql_regexp = "title REGEXP '(%s)'";

      // traverses by number of words, store in $sql_case_words each $sql_cases to each word
      // Score for relevance (start: $nr_src). Decrease $i from $nr_src in next iteration, to have more relevance for first words
      $sql_case_words = [];  $sql_where = [];
      for($i=0; $i<$nr_src; $i++) {
        $sql_where[$i] = sprintf($sql_regexp, $src[$i]);
        $sql_case_words[] = sprintf($sql_case, $sql_where[$i], ($nr_src - $i));
      }

      // select to get the results
      $sql = 'SELECT title, ( '. implode(' + ', $sql_case_words) .' ) AS score
FROM '. $this->tables['pgd'] .'
WHERE '. implode(' OR ', $sql_where) .'
ORDER BY score DESC LIMIT '. $this->src_suggest;
      $resql = $this->obsql->sqlExec($sql);
      if($resql) {
        // If returned rows, removes those url from $urls
        if($this->obsql->num_rows > 0) {
          $num_rows = $this->obsql->num_rows;
          for($i=0; $i<$num_rows; $i++) $re .= '<h4 onclick="getSugest(this)">'. highlightWords($resql[$i]['title'], $src) .'</h4>';
        }
      }
      else $re = $this->obsql->error;
    }

    return $re;
  }
    /* END SQL */

  // returns array with correct order of search-results (by words-weight), or string, from getSearchSql(). Receives array with $words
  private function setSearchData($words) {
    $resql = $this->getSearchSql($words);
    $num_rows = is_array($resql) ? count($resql) : 0;
    $re = [];    // multi-dimensional array with [score[rows-with-this-score]]

    // return score of all the $words in $str, according to $val and $max_words
    function setScore($str, $words, $val, $max_words = 2) {
      $score = 0;
      $word_repet = [];    // stores words=>repetition
      $str = explode(' ', mb_strtolower($str, 'utf-8'));    // make it array of lowercase-words
      $nr_as = count($str);
      for($i=0; $i<$nr_as; $i++) {
        if(in_array($str[$i], $words)) {
          if(!isset($word_repet[$str[$i]]) || $word_repet[$str[$i]] < $max_words) $score += $val;
          else if(isset($word_repet[$str[$i]])) $word_repet[$str[$i]]++;
          else $word_repet[$str[$i]] = 0;
        }
      }
      return $score;
    }

    if(is_array($resql) && $num_rows > 0) {
      // traverse the rows, and sets the score of each row accordings to words value from tags in $score2
      $score = 0;    // score of current row
      for($i=0; $i<$num_rows; $i++) {
        $title = $resql[$i]['title'];
        $description = $resql[$i]['description'];
        $content = $resql[$i]['content'];
        $size = $resql[$i]['size'];
        $url = preg_replace(['/\.(php|html)$/i', '/[^a-z0-9]+/i', '/ [a-z0-9]{1,2} /i', '/\s+/i'], ' ', $resql[$i]['url']);   // separates url pieces by single-space
        $score = setScore(cleanStr($title), $words, $this->score1['title']) + setScore(cleanStr($description), $words, $this->score1['description'], 3) + setScore(trim($url), $words, $this->score1['url']);

        // get data of $score2 in $content to score, remove them from $content to score it without those tags
        foreach($this->score2 AS $tag => $arr) {
          if(preg_match_all('@\<'. $tag .'[^\>]*\>(.*?)\</'. $tag .'\>@si', $content, $mt)) {
            $mt[1] = array_slice($mt[1], 0, $arr['n']);   // keep only speciffied nr of tag-repetition
            $nr_mt = count($mt[1]);
            for($i2=0; $i2<$nr_mt; $i2++) {
              $score += setScore(cleanStr($mt[1][$i2]), $words, $arr['v']);
            }
            $content = preg_replace('@\<'. $tag .'[^\>]*\>(.*?)\</'. $tag .'\>@si', '', $content);
          }
        }
        $score += setScore(cleanStr($content), $words, $this->score1['content'], 5) + (ceil($resql[$i]['score']) * 2);    // add score of content and 2* score from database

        $resql[$i]['content'] = $this->partOfContent($resql[$i]['content'], $words);   // keep only sub-string of content around $words

        // set /add key-score and traversed row data in $re
        if(isset($re[$score])) $re[$score][] = $resql[$i];
        else $re[$score] = [$resql[$i]];
      }
      krsort($re);   // sorts descententing, by keys
    }

    return $re;
  }

  // return sub-string around search-words from $content. Receives string $content, and array with words
  private function partOfContent($content, $words) {
    $before_after = [5, 9];    // numbers of keys /words [before, after] to get around found key /word

    // clean $content and make it array
    $content = trim(preg_replace(['/ [0-9;"_\-=\'\/\.]+ /i', '/[^'.$this->alchr.' ]/i', '/\s+/i'], ' ', strip_tags($content)));
    $content = explode(' ', $content);
    $max_key = count($content) - 1;
    $key_cnt = [];    // stores keys from $content with $words found

    // set maximum numbers of same word
    $nr_w = count($words);
    if($nr_w == 1) $first_nr = 4;
    else if($nr_w == 2) $first_nr = 3;
    else if($nr_w < 5) $first_nr = 2;
    else $first_nr = 1;

    // get array with keys of $words in $content
    for($i=0; $i<$nr_w; $i++) {
      $keys_f = array_keys(array_map('strtolower',$content), mb_strtolower($words[$i], 'utf-8'));    // all keys found for current word
      $keys_f = array_slice($keys_f, 0, $first_nr);    // keep the first $first_nr
      $nr_kf = count($keys_f);

      // get the keys that form the phrase around each found word /key
      for($i2=0; $i2<$nr_kf; $i2++) {
        $start = max(0, ($keys_f[$i2] - $before_after[0]));
        $end = min(($keys_f[$i2] + $before_after[1]), $max_key);
        for($i3=$start; $i3<=$end; $i3++) $key_cnt[$i3] = 1;
        if(count($key_cnt) > 43) break(2);
      }
    }
    ksort($key_cnt);

    // if less than 20 words for phrase, increments the area around found word (2 from start, 5 from end)
    if(count($key_cnt) < 20) {
      $start = max(0, (key($key_cnt) - 2));
      end($key_cnt);    // move the pointer to last array item
      $end = min((key($key_cnt) + 5), $max_key);
      $key_cnt = [];
      for($i=$start; $i<=$end; $i++) $key_cnt[$i] = 1;
    }

    // build the phrase with rhe words from $content associated to keys in $key_cnt
    $re = '';
    foreach($key_cnt AS $k => $v) $re .= ' '. $content[$k];

    return trim($re);
  }

  // set $search, $ssep_words and $cache_file, called from $getSearch. Receives search-phrase $search
  private function defineSearch($search) {
    $this->search = trim(mb_strtolower(preg_replace('/[^'.$this->alchr.' _-]+/i', '', $search), 'utf-8'));     // sets page name

    // sets $ssep_words and $cache_file lowercase and space instead of '-'
    if(strlen($this->search)>2) {
      $search = $this->ssep_words = $this->setSrcWords($this->search);
      sort($search);
      $this->cache_file = implode('_', $search);
    }
    else $this->cache_file = str_replace('.', '_', preg_replace("#^www\.#is", '', DOMAIN));
  }

  // return array with valid words to serch. Receives search-phrase from user
  protected function setSrcWords($str) {
    // replace '-' with space. Delete non alfa-numeric-space characters. Replace 2+ spaces with single space
    $str = str_replace('-', ' ', $str);
    $str = preg_replace('/[^'.$this->alchr.'_ ]+/i', '', $str);
    $str = preg_replace('/\s+/i', ' ', trim($str));

    // separates the words, removes $stop_words
    $words = explode(' ', $str);
    $words = array_diff($words, $this->stop_words);

    // stem words
    $re = [];
    foreach($words AS $word) {
      if(strlen($word) <= 2) continue;      // ignore 1 and 2 letter words
      $re[] = $word;
    }

    return $re;
  }

  // sets $pg_data with data for Title, Description, Keywords
  protected function setPgData() {
    $search = str_replace('-', ' ', $this->search);
    $ar_sir = explode(' ', $search);
    $ar_sir = array_values(array_unique($ar_sir));     // Removes duplicate values, reindex keys

    // keep words with 3+ characters
    $ar_meta_tag = array();
    for ($i=0; $i<count($ar_sir); $i++) {
      if (strlen($ar_sir[$i])>2) $ar_meta_tag[] = $ar_sir[$i];
    }
    $meta_tag = implode(', ', $ar_meta_tag);         // adauga cuvintele intr-un sir

    // store data
    $this->pg_data['title'] = ucfirst($search);
    $this->pg_data['description'] = ucwords($meta_tag). getTL('ssep_results_for') .': '. $meta_tag;
    $this->pg_data['keywords'] = $meta_tag;
  }

  // sets html with data from database. Receives array with rows: [score:[ [row], [row] ]
  private function setHtmlSrc($ssep_result) {
    if(is_array($ssep_result)) {
      if(count($ssep_result) > 0) {
        $re = '';
        $pgi_links = $this->pgiLinks();    // pagination links

        $it = 0;    // to set Hx tags
        $pgi = $this->pgi * $this->rowsperpage;    // to number results starting from current page number
        foreach($ssep_result AS $score => $rows) {
          $nr_sr = count($rows);
          for($i=0; $i<$nr_sr; $i++) {
            // Definire tip tag Hx pt. titlu
            if($it<4) $hx = 'h2';
            else if($it<15) $hx = 'h3';
            else if($it<36) $hx = 'h4';
            else $hx = 'h5';
            $it++;
            $pgi++;

            if(strlen($rows[$i]['title']) < 1) $rows[$i]['title'] = $rows[$i]['url'];   // show url if title

            // Define $url, and $link of search result
            if(!preg_match('#^http[s]{0,1}://#i', $rows[$i]['url'])) $url = $rows[$i]['protocol'] .'://'. $rows[$i]['domain'] .'/'. ltrim(rawurldecode($rows[$i]['url']) , '/');    // make full url if not already
            else $url = rawurldecode($rows[$i]['url']);

///         $url = '../'. ltrim(rawurldecode($rows[$i]['url']) , '/');   // relative url
            $link = '. <a href="'. $url .'" title="'. $rows[$i]['title']. '" target="_blank">'. highlightWords($rows[$i]['title'], $this->ssep_words, 1). '</a>';

            $re .= '<'.$hx.'>'. $pgi . $link. '</'.$hx.'><em>'. highlightWords($rows[$i]['description'], $this->ssep_words, 1) .'.</em><br>'. highlightWords($rows[$i]['content'], $this->ssep_words, 1) .'<div class="ci"> - '. getTL('score') .': '. $score .' - '. getTL('size') .': '. $rows[$i]['size'] .'</div>';
          }
        }

        // add pagination links, if more pages
        $re = '<div id="nr_results">'. sprintf(getTL('nr_results'), ($this->pgi + 1), $this->totalpages, $this->nr_results) .'</div>'. $re .(($this->totalpages > 1) ? '<div class="pgi_pages">'. $pgi_links .'</div>' : '');
      }
      else $re = '<h4>'. getTL('er_ssep_results') .'<em>'. implode(' ', $this->ssep_words) .'</em></h4>';
    }
    else $re = $ssep_result;

    return $re;
  }

  // sets pagination links
  private function pgiLinks() {
  $re = '';         // the variable that will contein the links and will be returned
  $ssep_pg = $this->ssep_pg .'?sr='. urlencode($this->search);    // value used in "href"

  // if $totalpages>0 and totalpages higher then $this->pgi
  if($this->totalpages >= $this->pgi) {
    // links to first and back page, if it isn't the first page
    if ($this->pgi > ($this->range)) {
      // show << for link to 1st page
      if(($this->pgi + 1) > $this->range) {
        if($this->use_ajax == 1) $re .= '<span title="1">(1) &lt;&lt;</span> ';
        else $re .= '<a href="'. $ssep_pg .'" title="1">(1) &lt;&lt;</a> ';
      }

      // show < for link to back page
      if(($this->pgi - $this->range) > 1) {
        if($this->use_ajax == 1) $re .= '<span title="'. ($this->pgi - $this->range) .'">'. ($this->pgi - $this->range) .'&lt;</span>';
        else $re .= '<a href="'. $ssep_pg .'&amp;pgi='. ($this->pgi - $this->range) .'" title="'. ($this->pgi - $this->range) .'">'. ($this->pgi - $this->range) .'&lt;</a>';
      }
    }

    // sets the links in the range of the current page
    for($x = ($this->pgi - $this->range + 1); $x <= ($this->pgi + $this->range); $x++) {
      // if it's a number between 0 and last page
      if (($x > 0) && ($x <= $this->totalpages)) {
        // if it's the number of current page, show the number without link, otherwise add link
        if ($x == ($this->pgi + 1)) $re .= '<em>'. $x .'</em>';
        else {
          if($this->use_ajax == 1) $re .= '<span title="'. $x .'">'. $x .'</span>';
          else {
            $pgi = ($x > 1) ? '&amp;pgi='. $x : '';
            $re .= '<a href="'. $ssep_pg . $pgi .'" title="'. $x .'">'. $x .'</a>';
          }
        }
      }
    }
    // If the current page is not final, adds link to next and last page
    if ($this->pgi < $this->totalpages) {
      // show > for next page
      if(($this->pgi + $this->range) < ($this->totalpages - 3)) {
        if($this->use_ajax == 1) $re .= '<span title="'. ($this->pgi + $this->range + 2) .'">&gt;'. ($this->pgi + $this->range + 2) .'</span>';
        else $re .= '<a href="'. $ssep_pg .'&amp;pgi='. ($this->pgi + $this->range + 2) .'" title="'. ($this->pgi + $this->range + 2) .'">&gt;'. ($this->pgi + $this->range + 2) .'</a>';
      }
      //  show >> for last page
      if($this->totalpages > $this->range && $this->totalpages > ($this->pgi + 2)) {
        if($this->use_ajax == 1) $re .= ' <span title="'. $this->totalpages .'">&gt;&gt; ('. $this->totalpages. ')</span>';
        else $re .= ' <a href="'. $ssep_pg .'&amp;pgi='. $this->totalpages .'" title="'. $this->totalpages .'">&gt;&gt; ('. $this->totalpages .')</a>';
      }
    }
  }

    return $re;
  }

  // Adauga in fisierul din prop. 'counter' cautarea {cache_file:[nr, search, time]}
  private function addSearch() {
    // daca nr caractere cautare e intre 3 si 44
    $nrchr = strlen($this->cache_file);
    if($nrchr > 2 && $nrchr < 45) {
      $nr = isset($this->srclist[$this->cache_file]) ? ($this->srclist[$this->cache_file]['nr'] + 1) : 1;    // number of searches for current search
      $this->srclist[$this->cache_file] = ['nr'=>$nr, 'search'=>str_replace(['-', '_'], ' ', $this->search), 'time'=>time()];

      // save data, in json format
      if(!file_put_contents(SSEP_CACHE . $this->counter, json_encode($this->srclist))) echo sprintf(getTL('er_save_file'), $this->counter);
    }
  }

  // Metoda preia lista cu toate cautarile si returneaza ultimile $nr
  public function getListSrc($nr=10, $tip='last') {
    $re = '';

    // Daca nr. randuri din $srclist (retinut in $nr_srclist) e mai mare ca 0
    if($this->nr_srclist > 0) {
      $ar_list = [];      // first $nr items from $srclist
      $nr = min($this->nr_srclist, $nr);         // Nr. rows to return

      // if there are items
      if($nr > 0) {
        $re = '<ol>';        // OL list to return

        // if 'top' sorts $srclist descendingly by 'nr', else, sorts by 'time'
        if($tip == 'top') $this->srclist = sortMultiArray($this->srclist, 'nr', SORT_DESC);
        else $this->srclist =  sortMultiArray($this->srclist, 'time', SORT_DESC);

        $ar_list = array_slice($this->srclist, 0, $nr);    // keep the first $nr items

        // add lists in OL
        foreach($ar_list AS $k => $ar) {
          if($this->use_ajax == 1) $re .= '<li title="'. $ar['search'] .'">'. $ar['search'] .'&nbsp;<sup>('. $ar['nr'] .')</sup></li>';
          else $re .= '<li><a href="'. $this->ssep_pg .'?sr='. urlencode($ar['search']) .'" title="'. $ar['search'] .'">'. $ar['search'] .'</a>&nbsp;<sup>('. $ar['nr'] .')</sup></li>';
        }
        $re .= '</ol>';
      }
    }
    return $re;
  }
}