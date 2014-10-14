<h1>SSEP - Site Search Engine PHP-Ajax</h1>
This is a completly Free and Open Source Site Search engine script that uses MySQL to store your website's indexed pages, to add Search Functionality to Your Web Site. It is build with PHP and JavaScript (search results are loaded via Ajax).<br>
The search system combine MySQL full text with SQL regexp, and words weight according to their location in HTML elements, to determine the relevance of the search results.
<h3>Features</h3>
- Intuitive and easy to use Admin Panel, with a simple adminstration interface, and info mark description to each function.<br>
- Suports both PDO and MySQLi for accessing MySQL databases in PHP.<br>
- Crawl and index web site pages automatically (can follow redirects).<br>
- Options to control indexed URLs: by link's Depth, by Maximum number of URLs to crawl, by URL Must-Include, or Must-Exclude "strings".<br>
- Crawl and index the links in the XML Sitemap.<br>
- You can register to Crawl and Index multiple domains.<br>
- Stop words excluded from searches.<br>
- Option to remove parts of the page / HTML elements from being indexed.<br>
- Keeps in the indexed content the text added in the "alt" attribute of the &lt;img&gt; tags (which are outside the removed parts).<br>
- Option to Build XML Sitemap with the indexed pages.<br>
- Easy to translate in other languages.<br>
- The Search results are loaded via Ajax (without refreshing the search page). This option can also be disabled.<br>
- Paginated serch results.<br>
- Option to choose Infinite or Standard pagination.<br>
- List with last and top searches.<br>
- The search results are ordered by a Score calculated according to the HTML elements in which the searched word is located (Title, Description, H1, Strong, ... and other tags, eaven the URL page address).<br>
- Cache files system for the search results.<br>
- Search Page with valid HTML5 format, and Responsive design (working on Mobile Device too).<br>
- CSS and HTML template easy to customize it, to add new elements in search page, and to change the design.
<h4>Requirements</h4>
- PHP 5.4+<br>
- MySQL 5.2+<br>
- Modern Browser with JavaScript enabled (Mozilla-Firefox, Google-Chrome, Opera, Internet-Explorer 9+).
<h3>Installation</h3>
<ol>
 <li>Open the "<span class="sb">config.php</span>" file to edit it (in "<span class="sbi">ssep/php/</span>" folder), and add your data for Name and Password to <span class="sbi">$admin_name</span> and <span class="sbi">$admin_pass</span> variables. They are used to logg in the SSEP Admin Panel.</li>
 <li>Edit the following data, for connecting to MySQL.
<pre>
<span class="sb">$mysql['host'] = 'localhost';</span>            - replace <span class="sbi">localhost</span> with your MySQL server address.
<span class="sb">$mysql['user'] = 'root';</span>                 - replace <span class="sbi">root</span> with your database user name.
<span class="sb">$mysql['pass'] = 'passdb';</span>               - replace <span class="sbi">passdb</span> with your password for MySQL database.
<span class="sb">$mysql['bdname'] = 'dbname';</span>             - replace <span class="sbi">dbname</span> with the name of your MySQL database.
</pre></li>
 <li>Copy the "<span class="sb">ssep/</span>" directory on your server, <span class="sb">in the Root</span> folder of your website ("<span class="sbi">www/</span>", "<span class="sbi">htdocs/</span>" or "<span class="sbi">public_html/</span>").</li>
 <li>Set CHMOD 0755 (or 0777) to "<span class="sb">cache/</span>" folder on your server (it is used to store cache files with the search results).</li>
 <li>Access the "<span class="sb">ssep/admin.php</span>" file in your browser, with the address from server; for example; <span class="sbi">http://localhost/ssep/admin.php</span></li>
 <li>Logg-in with your Name and Password set in "config.php" (in $admin_name and $admin_pass variables), and see the description from the info-mark <img src="templ/info_mark.png" alt="Info Mark" width="18" height="18" /> associated to each option in Admin Panel.</li>
 <li>Add the following HTML code in the pages of your web site in which you want to include the search form.
<pre>
&lt;form action=&quot;/ssep/index.php&quot; method=&quot;post&quot;&gt;
  &lt;input type=&quot;text&quot; name=&quot;sr&quot; maxlength=&quot;45&quot; /&gt;
  &lt;input type=&quot;submit&quot; value=&quot;Search&quot; /&gt;
&lt;/form&gt;
</pre>
 - <span class="sbi">The address from "action" must open the "ssep/index.php" file.</span></li>
</ol>
 &bull; The SSEP script will register automatically the current domain in database, and creates the needed tables.
<h4>Info for the oher configurations in "config.php"</h4>
<ul>
 <li><span class="sb">$lang</span> - Sufix for the "<span class="sb">lang_...txt</span>" file with texts and messages used in script (in JSON format), in "<span class="sbi">ssep/templ/</span>" folder.<br>
 - For example, if you want to use the script in Espanol (Spanish), translate the "<span class="sb">lang_en.txt</span>" file into a similar text file, named "<span class="sbi">lang_es.txt</span>" (with valid JSON format), and set:<br> &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; <span class="sb cb">$lang = 'es';</span><br>
 - <span class="si">If this file is not properly edited with JSON format, the script will use the "lang_en.txt" file.</span></li>
 <li><span class="sb">$search_domain</span> - The registered domain in which to search (in Search-Page). If the value is "<span class="sbi">auto</span>" the script will search in the indexed pages of the local domain (where the script is installed,) or, if you open the Search-Page from Admin Panel, in the current selected domain in Admin page. Otherwise, the script will search in the indexed pages of the specified domain, MUST BE REGISTERED AND INDEXED IN DATABASE (default "auto").</li>
 <li><span class="sb">$cache_dir</span> - The name of the directory for search cache files. In this directory the script creates a folder for each registered domain. In that folder the SSEP script saves cache files with the search results, and counter for searched phrases. Next time when the same search phrase is seek, the script gets the results from the cache file, and increments the counter of that search.</li>
 <li><span class="sb">SSEP_PREFIX</span> - Prefix of the tables in database used by this search engine script (default "ssep_").</li>
 <li><span class="sb">SSEP_TEMPL</span> - Folder with html /css template, and language files.</li>
</ul>
<h3>Other Specifications</h3>
&bull; The SSEP script uses by default PDO for connecting to MySQL database. If your server not support PDO, the script will use MySQLi.<br><br>
&bull; The "<span class="sb">stop_words.txt</span>" file (in the "<span class="sbi">ssep/php/</span>" folder) contains stop word which will be excluded from searches. You can add other stop words too, <span class="sb">separated by comma</span>.<br><br>
&bull; You can Add to Crawl and Index multiple domains, BUT the Search Page can be used to search in a single domain.<br>
- The SSEP script crawls and indexes only the local links, that points to pages of the current selected domain in Admin Panel.
- If you want to Not use Ajax, check the <span class="sb">Disable</span> button in "Advanced Settings".<br><br>
&bull; <span class="si">For other details, see the description from info-mark <img src="templ/info_mark.png" alt="Info Mark" width="18" height="18" /> associated to each option</span>.<br><br>
&bull; To make changes in the HTML of the Search Page, edit the "<span class="sb">search.htm</span>" file, in the "<span class="sbi">ssep/templ/</span>" folder.<br>
&bull; To change the style of the Search Page, edit the "<span class="sb">search_style.css</span>" and "<span class="sb">search_style_mobile.css</span>" files, in the "<span class="sbi">ssep/templ/</span>" folder ("search_style_mobile.css" is for browsers with the width less than 400 pixels, for mobile devices).<br><hr>

 &bull; To see online Demo of this File Manager, visit: <a href="http://coursesweb.net/scripts/ssep/admin.php" title="Demo SSEP - Site Search Engine PHP-Ajax">http://coursesweb.net/scripts/ssep/admin.php</a><br><br>
 &bull; Home Page: <a href="http://coursesweb.net/php-mysql/ssep-site-search-engine-php-ajax_s2" title="SSEP - Site Search Engine PHP-Ajax">http://coursesweb.net/php-mysql/ssep-site-search-engine-php-ajax_s2</a><br>
