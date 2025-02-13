<?php
class Config {

  public static function displayError($b = true){
    if($b){
      ini_set('display_errors', 1);
      ini_set('display_startup_errors', 1);
      error_reporting(E_ALL);
    }else{
      ini_set('display_errors', 0);
    }
  }
  public static function mysqlServer($s){
    define('MYSQL_SERVER',$s);
  }
  public static function mysqlDatabase($s){
    define('MYSQL_DB',$s);
  }
  public static function mysqlUsername($s){
    define('MYSQL_USER',$s);
  }
  public static function mysqlPassword($s){
    define('MYSQL_PASS',$s);
  }
  public static function mysqlConnection($b = true){
    if(!defined('MYSQL_CONNECT')){
      define('MYSQL_CONNECT',$b);
    }
  }
  public static function language($b  = 'en'){
    return self::languageDetect($b);
  }
  public static function languageDetect($b  = 'en'){
    $lang = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2) : $b;
    $GLOBALS['_candy']['language']['default'] = $b;
    Lang::set($lang);
  }

  public static function cron($b = true){
    return self::cronJobs($b);
  }

  public static function cronJobs($b = true){
    define('CRON_JOBS',$b);
    $arr_subs = explode('.',($_SERVER['HTTP_HOST'] ?? 'www'));
    $domain = '';
    $route = 'www';
    foreach ($arr_subs as $key){
      $domain .= $key.'.';
      if(file_exists('route/'.substr($domain,0,-1).'.php')) $route = substr($domain,0,-1);
    }
    if($b === 'cli'){
      $command_add = "* * * * * php ".BASE_PATH."/index.php candy cron $route";
      $command_delete = '* * * * * curl -L -A candyPHP-cron '.str_replace('www.','',($_SERVER['SERVER_NAME'] ?? '')).'/?_candy=cron';
    } else {
      if(!isset($_SERVER['SERVER_NAME'])) return false;
      $command_add = '* * * * * curl -L -A candyPHP-cron '.str_replace('www.','',($_SERVER['SERVER_NAME'] ?? '')).'/?_candy=cron';
      $command_delete = "* * * * * php ".BASE_PATH."/index.php candy cron $route";
    }
    exec('crontab -l', $crontab);
    $append = true;
    $is_override = false;
    $delete = false;
    if(isset($crontab) && is_array($crontab)){
      foreach ($crontab as $key) {
        $command = str_replace(' >/dev/null 2>&1','',$key);
        if($command == $key && $command == $command_add){
          $delete = true;
        }elseif($command==$command_add){
          $is_override = !$append;
          $append = false;
        } else if($command==$command_delete){
          $delete = true;
        }
      }
      if($append || $is_override || $delete){
        if($is_override || $delete){
          exec('crontab -r ');
          foreach ($crontab as $key) {
            $command = str_replace(' >/dev/null 2>&1','',$key);
            if($command!='' && $command!=$command_add && $command!=$command_delete){
              exec('echo -e "`crontab -l`\n'.$key.'" | crontab -', $output);
            }
          }
        }
        exec('echo -e "`crontab -l`\n'.$command_add.' >/dev/null 2>&1" | crontab -', $output);
      }
    }
  }

  public static function autoBackup($b = true,$directory = '../backup/'){
    define('AUTO_BACKUP',$b);
    define('BACKUP_DIRECTORY',$directory);
  }

  public static function backup($b = true,$directory = '../backup/'){
    return self::autoBackup($b,$directory);
  }

  public static function runBackup(){
    global $backupdirectory;
    $conns = [];
    foreach(($GLOBALS['candy_mysql'] ?? []) as $key => $val) if($val['backup']) $conns[$key] = Mysql::connect($key);
    if(!defined('AUTO_BACKUP') || !AUTO_BACKUP) return false;
    $check = isset($_SERVER['SERVER_ADDR']) && isset($_SERVER['REMOTE_ADDR']) && (substr($_SERVER['SERVER_ADDR'],0,8)=='192.168.' || $_SERVER['SERVER_ADDR']==$_SERVER['REMOTE_ADDR']) && isset($_GET['_candy']) && $_GET['_candy']=='cron';
    $check = $check || in_array(php_sapi_name(),['cli','cgi-fcgi']) && $_SERVER['argv'][1] == 'candy' && $_SERVER['argv'][2] == 'cron';
    $check = $check && AUTO_BACKUP /*&& intval(date("Hi"))==1*/;
    if(!$check) return false;
    $storage = Candy::config('backup','date')->get() ?? 0;
    $date = intval(date('Ymd'));
    if(intval($storage) >= $date) return false;
    Candy::config('backup','date')->save($date);
    set_time_limit(0);
    ini_set('memory_limit', '-1');
    $directory = BASE_PATH."/".BACKUP_DIRECTORY;
    $directory = explode('/',$directory);
    $directory = array_reverse($directory);
    $backupdirectory = [];
    $last = "";
    foreach ($directory as $val) {
      if($last == '..' || $val == '..' || empty($val)){
        $last = $val;
        continue;
      }
      $last = $val;
      $backupdirectory[] = $val;
    }
    $backupdirectory = "/".implode('/',array_reverse($backupdirectory));
    if(!file_exists("$backupdirectory/mysql/")) mkdir("$backupdirectory/mysql/", 0777, true);
    if(!file_exists("$backupdirectory/www/")) mkdir("$backupdirectory/www/", 0777, true);
    $file_date = date("Y-m-d");
    foreach($conns as $key => $conn){
      if(file_exists("$backupdirectory/mysql/$file_date-$key.sql") || file_exists("$backupdirectory/mysql/$file_date-$key.sql.gz")) continue;
      $tables = [];
      $result = mysqli_query($conn,"SHOW TABLES");
      while($row = mysqli_fetch_row($result)) $tables[] = $row[0];
      $return = '';
      foreach($tables as $table){
        $result = mysqli_query($conn, "SELECT * FROM ".$table);
        $num_fields = mysqli_num_fields($result);
        $return .= 'DROP TABLE '.$table.';';
        $row2 = mysqli_fetch_row(mysqli_query($conn, 'SHOW CREATE TABLE '.$table));
        $return .= "\n\n".$row2[1].";\n\n";
        for($i=0; $i < $num_fields; $i++){
          while($row = mysqli_fetch_row($result)){
            $return .= 'INSERT INTO '.$table.' VALUES(';
            for($j=0; $j < $num_fields; $j++){
              $row[$j] = addslashes($row[$j]);
              $return .= isset($row[$j]) ? '"'.$row[$j].'"' : ',';
              if($j<$num_fields-1) $return .= ',';
            }
            $return .= ");\n";
          }
        }
        $return .= "\n\n\n";
      }
      $handle = fopen("$backupdirectory/mysql/$file_date-$key.sql", 'w+');
      fwrite($handle, $return);
      fclose($handle);
    }
    exec("(gzip $backupdirectory/mysql/$file_date-*.sql; sleep 10; rm $backupdirectory/mysql/$file_date-*.sql;) > /dev/null 2>&1 &");
    if(!file_exists("$backupdirectory/www/$file_date-backup.zip")){
      $zip = new ZipArchive();
      $zip->open("$backupdirectory/www/$file_date-backup.zip", ZipArchive::CREATE | ZipArchive::OVERWRITE);
      $files = Candy::dirContents(BASE_PATH);
      foreach($files as $file){
        if(is_dir($file) || Candy::var($file)->contains(BASE_PATH."/storage/cache")) continue;
        $relativePath = substr($file, strlen(BASE_PATH) + 1);
        $zip->addFile($file,$relativePath);
      }
      $zip->close();
      exec("(sleep 60; rm $backupdirectory/www/$file_date-*.zip.*;) > /dev/null 2>&1 &");
    }
  }

  public static function autoUpdate($b = true){
    define('CANDY_UPDATE',$b);
  }

  public static function runUpdate(){
    if(!defined('CANDY_UPDATE') || !CANDY_UPDATE) return false;
    $check = isset($_SERVER['REMOTE_ADDR']) && (substr($_SERVER['SERVER_ADDR'],0,8)=='192.168.' || $_SERVER['SERVER_ADDR']==$_SERVER['REMOTE_ADDR']) && isset($_GET['_candy']) && $_GET['_candy']=='cron';
    $check = $check || in_array(php_sapi_name(),['cli','cgi-fcgi']) && $_SERVER['argv'][1] == 'candy' && $_SERVER['argv'][2] == 'cron';
    $check = $check && CANDY_UPDATE && intval(date("Hi"))==10;
    if($check){
      set_time_limit(0);
      ini_set('memory_limit', '4G');
      $base = 'https://raw.githubusercontent.com/CandyPack/CandyPHP/master/';
      $get = Candy::curl($base.'update.txt');
      $arr_get = explode("\n",$get);
      $now = getdate();
      $params = array();
      $update = false;
      if(file_exists('update.txt')){
        $current = file_get_contents(BASE_PATH.'/update.txt', FILE_USE_INCLUDE_PATH);
        $arr_current = explode("\n",$current);
        foreach($arr_current as $current){
          if(substr($current,0,1)=='#'){
            $params_current = explode(':',str_replace('#','',$current));
            switch ($params_current[0]) {
              case 'version':
              $version_current = $params_current[1];
              break;
            }
          }
        }
      }else $version_current = 0;
      foreach($arr_get as $new){
        if(substr($new,0,1)=='#'){
          $params_new = explode(':',str_replace('#','',$new));
          switch ($params_new[0]) {
            case 'version':
              if($params_new[1]>$version_current) $update = true;
              else return false;
              break;
            case 'delete':
              if(file_exists($params_new[1])) unlink($params_new[1]);
              break;
          }
        }elseif(trim($new)!='') $arr_update[] = trim($new);
      }
      if($update){
        foreach ($arr_update as $key){
          if(strpos($key, '/') !== false){
            $arr_dir = explode('/',$key);
            $makedir = '';
            for ($i=0; $i < count($arr_dir) - 1; $i++) $makedir .= $arr_dir[$i].'/';
            $makedir = substr($makedir,0,-1);
            if(!file_exists(BASE_PATH."/$makedir")) mkdir(BASE_PATH."/$makedir", 0777, true);
          }
          $content = '';
          $content = Candy::curl($base.$key);
          if(trim($content)!=''){
            $file = fopen(BASE_PATH."/$key", "w") or die("Unable to open file!");
            fwrite($file, $content);
            fclose($file);
          }
        }
      }
    }
  }
  public static function update($b = true){
    return self::autoUpdate($b);
  }

  public static function masterMail($s=''){
    if(!defined('MASTER_MAIL') && $s!='') define('MASTER_MAIL',$s);
  }

  public static function composer($b=true){
    if(!defined('CANDY_COMPOSER')){
      if(is_bool($b)){
        define('CANDY_COMPOSER',$b);
      }else{
        define('CANDY_COMPOSER', true);
        define('CANDY_COMPOSER_DIRECTORY', $b);
      }
    }
  }

  public static function check($v){
    $return = true;
    $arr_var = explode(',',$v);
    foreach ($arr_var as $key){
      if($key!=''){
        if(!defined($key)){
          $return = false;
        }else{
          if(is_bool(constant($key)) && !constant($key)){
            $return = false;
          }elseif(!is_bool(constant($key)) && (is_numeric(constant($key)) || constant($key)!='')){

          }
        }
      }
    }
    return $return;
  }

  public static function backupClear(){
    if(!defined('BACKUP_DIRECTORY') || intval(date('Hi')) != 10) return false;
    $arr = ['www','mysql'];
    foreach ($arr as $key){
      $dir = substr(BACKUP_DIRECTORY,-1)=='/' ? BACKUP_DIRECTORY.$key.'/' : BACKUP_DIRECTORY.'/'.$key.'/';
      if(file_exists($dir)){
        $dh  = opendir($dir);
        while(false !== ($filename = readdir($dh))){
          if($filename=='.' || $filename=='..') continue;
          if(strpos($dir.$filename, '.zip.') !== false){
            unlink($dir.$filename);
            continue;
          }
          $filemtime = filemtime($dir.$filename);
          $diff = time()-$filemtime;
          $days = round($diff/86400);
          $dayofweek = date('w', $filemtime);
          $dayofmonth = date('d', $filemtime);
          $dayofyear = date('md', $filemtime);
          if($days<=7) continue;
          if($dayofweek==1 && $days<=30) continue;
          if($dayofmonth==1 && $days<=365) continue;
          if($dayofyear=='0101') continue;
          unlink($dir.$filename);
        }
      }
    }
  }

  public static function mysql($name='default'){
    $class = new class {
      private static $_arr = ['name' => 'default', 'host' => '127.0.0.1', 'database' => '', 'user' => '', 'password' => '', 'backup' => null, 'default' => null];
      public static function name($v){
        self::$_arr['name'] = $v;
        self::$_arr['default'] = $v=='default' && self::$_arr['default']==null;
        self::$_arr['backup'] = $v=='default' && self::$_arr['backup']==null;
        $GLOBALS['candy_mysql'] = !isset($GLOBALS['candy_mysql']) || !is_array($GLOBALS['candy_mysql']) ? [] : $GLOBALS['candy_mysql'];
        $GLOBALS['candy_mysql'][self::$_arr['name']] = self::$_arr;
        return new static();
      }
      public static function host($v){
        self::$_arr['host'] = $v;
        $GLOBALS['candy_mysql'] = !is_array($GLOBALS['candy_mysql']) ? [] : $GLOBALS['candy_mysql'];
        $GLOBALS['candy_mysql'][self::$_arr['name']] = self::$_arr;
        return new static();
      }
      public static function database($v){
        self::$_arr['database'] = $v;
        $GLOBALS['candy_mysql'] = !is_array($GLOBALS['candy_mysql']) ? [] : $GLOBALS['candy_mysql'];
        $GLOBALS['candy_mysql'][self::$_arr['name']] = self::$_arr;
        return new static();
      }
      public static function user($v){
        self::$_arr['user'] = $v;
        $GLOBALS['candy_mysql'] = !is_array($GLOBALS['candy_mysql']) ? [] : $GLOBALS['candy_mysql'];
        $GLOBALS['candy_mysql'][self::$_arr['name']] = self::$_arr;
        return new static();
      }
      public static function password($v){
        self::$_arr['password'] = $v;
        $GLOBALS['candy_mysql'] = !is_array($GLOBALS['candy_mysql']) ? [] : $GLOBALS['candy_mysql'];
        $GLOBALS['candy_mysql'][self::$_arr['name']] = self::$_arr;
        return new static();
      }
      public static function backup($v=true){
        self::$_arr['backup'] = $v;
        $GLOBALS['candy_mysql'] = !is_array($GLOBALS['candy_mysql']) ? [] : $GLOBALS['candy_mysql'];
        $GLOBALS['candy_mysql'][self::$_arr['name']] = self::$_arr;
        return new static();
      }
      public static function default($v=true){
        self::$_arr['default'] = $v;
        $GLOBALS['candy_mysql'] = !is_array($GLOBALS['candy_mysql']) ? [] : $GLOBALS['candy_mysql'];
        $GLOBALS['candy_mysql'][self::$_arr['name']] = self::$_arr;
        Config::mysqlConnection();
        return new static();
      }
      public static function abort($v=500){
        self::$_arr['abort'] = $v;
        $GLOBALS['candy_mysql'] = !is_array($GLOBALS['candy_mysql']) ? [] : $GLOBALS['candy_mysql'];
        $GLOBALS['candy_mysql'][self::$_arr['name']] = self::$_arr;
        return new static();
      }
    };
    return $class->name($name);
  }

  public static function dev($b){
    return self::devmode($b);
  }

  public static function devmode($b){
    if(is_bool($b) && $b) $devmode = !defined('CANDY_DEVMODE') ? define('CANDY_DEVMODE', $b) : false;
    return new class {
      public static function version($v){
        if(defined('CANDY_DEVMODE') && CANDY_DEVMODE) $GLOBALS['DEV_VERSION'] = $v;
        return new static();
      }
      public static function errors(){
        if(defined('CANDY_DEVMODE') && CANDY_DEVMODE) Config::displayError(true);
        if(!defined('DEV_ERRORS')) define('DEV_ERRORS',(defined('CANDY_DEVMODE') && CANDY_DEVMODE));
        return new static();
      }
      public static function mail($m){
        Config::masterMail($m);
        return new static();
      }
    };
  }

  public static function key($k='candy', $stage=5){
    $define = !defined('ENCRYPT_KEY') ? define('ENCRYPT_KEY', md5($k)) : false;
    $define = !defined('ENCRYPT_STAGE') ? define('ENCRYPT_STAGE', $stage) : false;
  }

  public static function devmodeVersion(){
    if(defined('CANDY_DEVMODE') && defined('BACKUP_DIRECTORY') &&   isset($GLOBALS['DEV_VERSION'])){
      $bkdir = substr(BACKUP_DIRECTORY,-1)=='/' ? BACKUP_DIRECTORY.'www/' : BACKUP_DIRECTORY.'/www/';
      if(defined('BACKUP_DIRECTORY')){
        $bks = array_diff(scandir($bkdir), ['.','..']);
        $cbk = '';
        $dver = Candy::dateFormatter($GLOBALS['DEV_VERSION'],'Ymd');
        $difbk = null;
        foreach($bks as $key){
          $kbk = intval(str_replace(['-','backup'],['',''],$key));
          $kdiff = $kbk - $dver;
          if($kdiff==0 || ($kdiff<0 && ($difbk===null || $kdiff>$difbk))){
            $cbk = $key;
            $difbk = $kdiff;
          }
        }
        $define = !defined('DEV_VERSION') && $cbk!='' ? define('DEV_VERSION', $bkdir.$cbk) : false;
      }
    }
  }

  public static function brute($try=250){
    if(!isset($GLOBALS['_candy'])) $GLOBALS['_candy'] = [];
    if(!isset($GLOBALS['_candy']['settings'])) $GLOBALS['_candy']['settings'] = [];
    if(!isset($GLOBALS['_candy']['settings']['bruteforce'])) $GLOBALS['_candy']['settings']['bruteforce'] = ['try' => $try];
  }

  public static function checkBruteForce($c = 1){
    if(!isset($GLOBALS['_candy']['settings']['bruteforce'])) return false;
    if(!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') return false;
    $try = $GLOBALS['_candy']['settings']['bruteforce']['try'];
    $ip = $_SERVER['REMOTE_ADDR'];
    $now = date('YmdH');
    $storage = Candy::config('brute',$now,$ip)->get() ?? 0;
    $storage = $storage + $c;
    if($storage >= $try) Candy::abort(403);
    Candy::config('brute',$now,$ip)->save($storage);
  }

  public static function errorReport($type,$mssg=null,$file=null,$line=null){
    if(Candy::isDev()) return true;
    $now = date('YmdH');
    $log = "";
    $open = file_exists(BASE_PATH.'/candy.log') && filesize(BASE_PATH.'/candy.log') <= 128000000 ? file_get_contents(BASE_PATH.'/candy.log', FILE_USE_INCLUDE_PATH) : "";
    if(empty(trim($open))) $log = "\n--- <b>CANDY PHP ERRORS</b> ---\n";
    $log .= "\n--- ".date('Y/m/d H:i:s')." ---\n";
    if(!empty($type)) $log .= "<b>Type:</b>    ".$type." Error\n";
    if(!empty($mssg)) $log .= "<b>Message:</b> ".$mssg."\n";
    if(!empty($file)) $log .= "<b>File:</b>    ".(isset($GLOBALS['_candy']['cached'][$file]['file']) ? $GLOBALS['_candy']['cached'][$file]['file'] : $file)."\n";
    if(isset($line) && !empty($line)) $log .= "<b>Line:</b>    ".(isset($GLOBALS['_candy']['cached'][$file]['line']) ? ($GLOBALS['_candy']['cached'][$file]['line'] + $line) : $line)."\n";
    $log .= "-------\n";
    file_put_contents(BASE_PATH.'/candy.log',strip_tags($open.$log));
    $storage = Candy::config('error','notification','date')->get();
    if(defined('MASTER_MAIL') && ($storage != date('Ymd'))) Candy::quickMail(MASTER_MAIL,nl2br($log."<br><br><pre>".print_r($GLOBALS,true)."</pre>"),$_SERVER['HTTP_HOST']." - Candy PHP ERROR","candyphp@".$_SERVER['HTTP_HOST']);
    Candy::config('error','notification','date')->set(date('Ymd'));
  }

  public static function auth($b = true){
    $GLOBALS['_candy']['auth']['status'] = $b;
    $GLOBALS['_candy']['auth']['storage'] = 'mysql';
    $GLOBALS['_candy']['auth']['db'] = null;
    $GLOBALS['_candy']['auth']['table'] = 'tb_user';
    $GLOBALS['_candy']['auth']['key'] = 'id';
    $GLOBALS['_candy']['auth']['token'] = null;
    return new class {
      public static function db($storage='mysql', $db=null){
        $GLOBALS['_candy']['auth']['storage'] = $storage;
        $GLOBALS['_candy']['auth']['db'] = $db;
        return new static();
      }
      public static function table($user = 'tb_user'){
        $GLOBALS['_candy']['auth']['table'] = $user;
        return new static();
      }
      public static function key($key = 'id'){
        $GLOBALS['_candy']['auth']['key'] = $key;
        return new static();
      }
      public static function token($table = 'candy_token'){
        $GLOBALS['_candy']['auth']['token'] = $table;
        return new static();
      }
    };
  }

  public static function locale($v = 'en'){
    \Candy::config('locale')->set($v);
  }

}
