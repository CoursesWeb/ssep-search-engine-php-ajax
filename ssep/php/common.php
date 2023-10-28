<?php
if(!isset($_SESSION)) session_start();
if(!headers_sent()) {
  header('Content-type: text/html; charset=utf-8');
  header('Expires: 0');
  header('Cache-Control: must-revalidate');
  header('Pragma: public');
}

// return text language associated to $tab
function getTL($tab) {
  GLOBAL $tl;
  return isset($tl[$tab]) ? $tl[$tab] : $tab;
}

// return ID of $domain from database. Receives object with mysql connection, and $domain name
function getDomainId($obsql, $domain) {
  $re = 0;
  if($obsql) {
    $resql = $obsql->sqlExec("SELECT id, domain FROM ". SSEP_PREFIX ."domain WHERE replace(domain, 'www.', '') = '". preg_replace('/^www\./i', '', $domain) ."' LIMIT 1");

    // if passed $domain not found, gets ID and Domain in 1st row, sets session with domain name
    if($resql !== false && $obsql->num_rows < 1) $resql = $obsql->sqlExec("SELECT id, domain FROM ". SSEP_PREFIX ."domain ORDER BY id LIMIT 1");

    if(isset($resql[0]['domain'])) {
      $_SESSION['ssep_domain'] = $resql[0]['domain'];
      $re = $resql[0]['id'];
    }
  }

  return intval($re);
}

// replace in $tpl the strings equal with keys from $arr, with associateed values
function template($tpl, $tplv) {
  // changes "key" to "{$key}"
  foreach($tplv as $k => $v) {
    $tplv['{$'. $k .'}'] = $v;
    unset($tplv[$k]);
  }
  return strtr($tpl, $tplv);
}
// gets and returns array with settings from ssep_sets table. Receives object with mysql connection
function getSettings($obsql, $id) {
  $re = [];
  if($obsql) {
    $sql = "SELECT sets FROM ". SSEP_PREFIX ."sets WHERE id = ". intval($id) ." LIMIT 1";
    $resql = $obsql->sqlExec($sql);
    if($resql && $obsql->num_rows > 0) $re = json_decode($resql[0]['sets'], true);
    if($re === null) $re = [];
  }

  return $re;
}

// to formt $size
function formatSize($size){
  $unit = 'B';
  if($size > 1024){
    $unit = 'KB';
    $size = $size / 1024;
  }
  if($size > 1024){
    $unit = 'MB';
    $size = $size / 1024;
  }
  if($size > 1024){
    $unit = 'GB';
    $size = $size / 1024;
  }

  return round($size, 2).' '.$unit;
}

  // clean $str from non-alfa-numeric-space characters, 1-2 words, and multiple spaces
function cleanStr($str) {
  $str = preg_replace('/[^a-z0-9 ŔÁÂĂÄĹĆŕáâăäĺćŇÓÔŐŐÖŘňóôőöřČÉĘËčéęëđÇçĐĚÍÎĎěíîďŮÚŰÜůúűüŃńŢßýŞş]/i', ' ', $str); // non-alfa_numeric, space
  $str = preg_replace(['/^[^ ]{1,2} /i', '/ [^ ]{1,2} /i', '/ [^ ]{1,2}$/i'], '', $str);   // 1-2 characters
  $str = preg_replace('/\s+/i', ' ', $str);
  return trim($str);
}

// return $str with highlighted $words (array). If $whole_word is 0 match sub-strings too, else, match whole words only
function highlightWords($str, $words, $whole_word = 0) {
  $words = implode('|', $words);
  if($whole_word == 0) $str = preg_replace('/('. $words .')/i', '<span>$1</span>', $str);
  else $str = preg_replace(['/^('. $words .') /i', '/ ('. $words .') /i', '/ ('. $words .')$/i'], ' <span>$1</span> ', $str);

  return str_replace('<span>', '<span class="hglw">', $str);
}

function sortMultiArray($arr, $k, $sort) {
/*
 Sorts and Return an entire multi-dimensional array by one element of it
 - $arr = the multi-dimensional array
 - $k = a string with the name of the index-key by which the entire array will be sorted
 - $sort = one of these sorting type flags:

    SORT_ASC - sort items ascendingly.
    SORT_DESC - sort items descendingly.
    SORT_REGULAR - compare items normally (don't change types)
    SORT_NUMERIC - compare items numerically
    SORT_STRING - compare items as strings
    SORT_LOCALE_STRING - compare items as strings, based on the current locale. It uses the locale, which can be changed using setlocale()
    SORT_NATURAL - compare items as strings using "natural ordering" like natsort()
*/
  $tmp = [];
  foreach($arr as &$ma)  $tmp[] = &$ma[$k];
  $tmp = array_map('strtolower', $tmp);      // to sort case-insensitive
  array_multisort($tmp, $sort, $arr);
  return $arr;
}


    /* These values ar used only if the "ssep_sets.json" is not properly defined */
$sets0 = [
  // tags to complete delete [ [tag=>[attr=>[values]]], ... ]
  'deltags'=>[['a'=>[]], ['form'=>[]], ['select'=>[]]],

  // value for score of words in these elements ('content' is for general content in html <body>)
  'score1'=>['title'=>30, 'description'=>10, 'url'=>15, 'content'=>1],

  /* html tags used to calculate score for search results:
   'n' = maximum number of this tag to take for calculating score
   'v' = value of each word in this tag
  */
  'score2'=>['b'=>['n'=>12, 'v'=>2], 'em'=>['n'=>12, 'v'=>2], 'u'=>['n'=>12, 'v'=>2], 'strong'=>['n'=>12, 'v'=>4], 'h5'=>['n'=>12, 'v'=>3], 'h4'=>['n'=>10, 'v'=>4], 'h3'=>['n'=>7, 'v'=>9], 'h2'=>['n'=>7, 'v'=>10], 'h1'=>['n'=>2, 'v'=>18]],

  'rowsperpage'=> 20,  // number of rows for search results pagination
  'src_suggest'=> 10,  // number of rows for search suggestions
  'last_src'=> 10,     // Number of lists in menu with Last Searches
  'top_src'=> 10,      // Number of lists in menu with Top Searches
  'pgi_type'=> 'infinite',   // type of pagination for search results: 'infinite', or 'standard'
  'use_ajax'=> 1       // 1 = enable to load search results via Ajax (without refreshing page), 0 = disable Ajax (load pages with standard links)
];

// get json file with messages, errors, template
$tl = json_decode(file_get_contents(SSEP_TEMPL .'lang_'. $lang .'.txt'), true);
if($tl == null) {
  $er_lang = 'Error in "lang_'. $lang .'.txt" file with texts. Not valid JSON Format.';    // if invalid JSON format
  if($lang != 'en') $tl = json_decode(file_get_contents(SSEP_TEMPL .'lang_en.txt'), true);    // try to use 'lang_en'
}

// set object with connection to mysql
include 'mysqli_pdo.php';
$obsql = new mysqli_pdo($mysql);