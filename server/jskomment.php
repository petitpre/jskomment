<? /* 
This is a PHP server side implementation of Jskomment. 
It is meant to be used with an appropriate .htaccess file
*/

@include('jskomment.local.php');

// all configuration variables may be overridden in jskomment.local.php
@define('DATADIR','./jskomment-data/');
@define('JSKOMMENT_EMAIL','jskomment@'.$_SERVER[HTTP_HOST]);
@define('JSKOMMENT_DATE', 'M d, Y - g:i a');
@define('JSKOMMENT_FORMAT_FUNCTION','markdown');
ob_start("ob_gzhandler");

if(!function_exists('add_comment_action')) {
  function add_comment_action($comment) {}// may be defined in jskomment.local.php for instance to send notification emails
}

/** returns a concatenation of jquery, json2 and jskomment, plus the initialization of jskomment */
function jskomment_js() {
  header('Content-type: text/javascript');

  echo " if (window.jQuery === undefined) { \n";
  readfile('jquery-1.5.1.min.js');
  echo "}// end jquery \n";

  echo "\n if (window.swfobject === undefined) { \n";
  @readfile("swfobject.js");
  echo "}// end swfobject \n";

  readfile('json2.js');

  readfile('jskomment-core.js');

  $base_url = './';
  if (isset($_GET['JSKOMMENT_url'])) {
    $base_url = $_GET['JSKOMMENT_url'];
    echo "JSKOMMENT.url = '{$base_url}';\n";
  }

  echo "JSKOMMENT.main();\n";
  exit;
}

/** checks whether the recaptcha is correct if a private key is defined, then returns the $comment data without recaptcha info */
function check_recaptcha($comment) {
  $recaptcha_response_field = @$comment['recaptcha_response_field'];
  $recaptcha_challenge_field = @$comment['recaptcha_challenge_field'];

  unset($comment['recaptcha_response_field']);
  unset($comment['recaptcha_challenge_field']);

  // silent if recaptchalib is not present 
  @include('recaptchalib.php');
  
  if (!defined('RECAPTCHA_PRIVATE_KEY')) { // should be defined in jskomment.local.php
    return $comment;
  }
  
  $resp = recaptcha_check_answer (RECAPTCHA_PRIVATE_KEY,
                                      $_SERVER["REMOTE_ADDR"],
                                      $recaptcha_challenge_field,
                                      $recaptcha_response_field);

  if ($resp->is_valid) {
    return $comment;
  } else {
    header('HTTP/1.0 403 Unauthorized');
    exit;
  }
}

/** sets HTTP response headers to support cross-domain calls */
function allow_cross_domain() {
  header("Access-Control-Allow-Origin: *");
  if (@isset($_SERVER['HTTP_CONTROL_REQUEST_METHOD'])) {
    header("Access-Control-Allow-Methods: ".$_SERVER['HTTP_CONTROL_REQUEST_METHOD']);
  }
  if (@isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
    header("Access-Control-Allow-Headers: ".$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']);
  }
}

/** returns all comments matching $query['title'] as a PHP array */
function get_comments($query) {
  $result = array();
  $fname=DATADIR.sha1($query['title']);
  //echo $fname;
  if (file_exists($fname)) {
    foreach(file($fname) as $comment) {
      if (strlen(trim($comment))==0) {continue; }
      $f = JSKOMMENT_FORMAT_FUNCTION;
      $comment = $f($comment);
      $commentobj = json_decode($comment, true);
      unset($commentobj['email']); //privacy
      $result[] = $commentobj;
    }
  } // end if
  return $result;
}

/** Implements some basic Markdown */
function markdown($mdinput) {
  $mdinput = preg_replace("/\*\*(.*?)\*\*/", "<strong>$1</strong>", $mdinput);
  $mdinput = preg_replace("/\*(.*?)\*/", "<strong>$1</strong>", $mdinput);
  $mdinput = preg_replace("/\_\_(.*?)\_\_/", "<em>$1</em>", $mdinput);
  $mdinput = preg_replace("/\_(.*?)\_/", "<em>$1</em>", $mdinput);
  $mdinput = preg_replace('/\[(.*?)\]\s?\((.*?)\)/i', '<a href=\"$2\" rel=\"nofollow\">$1</a>', $mdinput);
  return $mdinput;
}

/** gets the request data depending on HTTP method, content-type and accept headers */
function get_request_data() {
  if (is_jsonp()) { // must be first
    return json_decode($_GET['data'], true);
  } 
  if ($_SERVER['REQUEST_METHOD']=='GET') {
    if (@$_SERVER['CONTENT_TYPE']=='application/json') { 
      // we don't use $_SERVER['QUERY_STRING']
      // since it is set after .htaccess rewriting
      $query_string = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY));
      return (json_decode($query_string, true));
    } else {
      return (json_decode($_GET['data'], true));
    }
  }
  if ($_SERVER['REQUEST_METHOD']=='POST') {
    
    if ($_SERVER['CONTENT_TYPE']=='application/x-www-form-urlencoded') {
      //file_put_contents('/tmp/foo',var_export(json_decode($_POST['data'], true), true));
      return (json_decode($_POST['data'], true));
    } else {
      $data = file_get_contents("php://input");
      return (json_decode($data, true));
    }
    
  }
  die;
}

/** returns a JSON string of all comments matching the HTTP POST input as JSON */
function get_single_comment_as_json() {
  return json_encode(get_comments(get_request_data()));
}

/** returns a list of list of comments */
function get_comments_as_json() {
  $response = array();
  foreach(get_request_data() as $query) {
    $response[] = get_comments($query);
  } // end foreach
  return json_encode($response);
}

/** adds a $comment (assoc array) in the database */
function add_comment($comment) {
  $comment = check_recaptcha($comment);
  add_comment_action($comment);
  $fname=DATADIR.sha1(@$comment['title']);

  @$comment['date'] = date(JSKOMMENT_DATE);

  // Strip any tabs or spaces from the name and website fields.
  $namecheck = trim(@$comment['name'], " \t");
  $websitecheck = trim(@$comment['website'], " \t");

   // Replace a  blank user name with Anonymous. If a name is given sanitize it.
  if (empty($namecheck)) {
    @$comment['name'] = 'Anonymous';
  } else {
    @$comment['name'] = htmlspecialchars(strip_tags(@$comment['name']), ENT_NOQUOTES, 'utf-8');
  }

  // Attach the commenter's website to their name if included.
  if (!empty($websitecheck)) {
    @$comment['website'] = htmlspecialchars(strip_tags(@$comment['website']), ENT_NOQUOTES, 'utf-8');
    // Markdown. It will be converted to a proper link when displayed.
    @$comment['name'] = '['. @$comment['name'] . '](' . @$comment['website']. ')';
  }

  // Sanitize the remaining fields.
  @$comment['email'] = htmlspecialchars(strip_tags(@$comment['email']), ENT_NOQUOTES, 'utf-8');
  @$comment['comment'] = htmlspecialchars(strip_tags(@$comment['comment']), ENT_NOQUOTES, 'utf-8');

  $file  = fopen($fname, "a");
  fputs($file,json_encode($comment)."\n");
  fclose($file);

  // sending an email to the others
  $x=array();
  if (file_exists($fname)) {
    foreach(file($fname) as $line) {
      $result = json_decode($line, true);
      // /* for debug */ $x[] = var_export($result,true);
      if (isset($result['email']) && !in_array($result['email'], $x)&& $result['title']==$comment['title']) {
        $x[] = $result['email'];
        @mail($result['email'], '[comment] '.$result['title'],
          "Hi,<br/><br/>A comment has been posted on <a href=\"".$result['title']."\">".$result['title']."</a>:<br/><br/>".
          str_replace("\n","<br/>",htmlentities($comment['comment'])), 
               "From: ".JSKOMMENT_EMAIL."\r\n".
               "MIME-Version: 1.0" . "\r\n" .
               "Content-type: text/html; charset=UTF-8" . "\r\n"
        );
      }
    }
  } // end if
  return '{}'; // for json-p
}

function is_jsonp() {
  return (@$_GET['contentType']== 'application/jsonp') || preg_match('/text\/javascript|application\/javascript/', $_SERVER['HTTP_ACCEPT']);
}

/** outputs a JSON object in JSONP of pure JSON depending on the request */
function output($response) {
  if (is_jsonp()) {
    header('Content-type: text/javascript');
    echo $_GET['callback'].'('.$response.");\n";
  } else {
    header('Content-type: application/json');
    echo $response;
    //file_put_contents('/tmp/jskomment.log', $response);
  }
}

function main() {

  allow_cross_domain();
    
  if (@$_GET['file']==='jskomment.js') jskomment_js();
  if (@$_GET['action']==='p') output(add_comment(get_request_data()));
  if (@$_GET['action']==='sx') {
    output(get_comments_as_json());
  }
  if (@$_GET['action']==='s') {
    output(get_single_comment_as_json());
  }
  if (@$_GET['action']==='t') {
    output(json_encode(get_request_data()));
  }

}

main();

?>