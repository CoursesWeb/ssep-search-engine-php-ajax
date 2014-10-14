<h1>SSEP - Site Search Engine PHP-Ajax</h1>
This is a completly Free and Open Source Site Search engine script that uses MySQL to store your website's indexed pages, to add Search Functionality to Your Web Site. It is build with PHP and JavaScript (search results are loaded via Ajax).<br>
The search system combine MySQL full text with SQL regexp, and words weight according to their location in HTML elements, to determine the relevance of the search results.<br>

 &bull; Download Page: http://coursesweb.net/php-mysql/ssep-site-search-engine-php-ajax_s2<br>

 &bull; Demo Page: http://coursesweb.net/scripts/ssep/admin.php<br>
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
<h3>Other Specifications</h3>
&bull; The SSEP script uses by default PDO for connecting to MySQL database. If your server not support PDO, the script will use MySQLi.<br>
&bull; You can Add to Crawl and Index multiple domains, BUT the Search Page can be used to search in a single domain.<br>
- The SSEP script crawls and indexes only the local links, that points to pages of the current selected domain in Admin Panel.<br><hr>
http://coursesweb.net/
