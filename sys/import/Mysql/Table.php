<?php
class Mysql_Table {
  protected $arr = [];
  protected $result = [];
  protected $table;
  protected $statements = ['=','>','>=','<','<=','!=','LIKE','NOT LIKE','IN','NOT IN','BETWEEN','NOT BETWEEN','IS','IS NOT'];
  // protected $val_statements = ['IS NULL','IS NOT NULL'];

 function __construct($table=null, $arr=[], $vals=[]){
   $this->table = $table;
   $this->arr = $arr;
   foreach($vals as $key => $val) $this->$key = $val;
  }

  function query($type = null){
    $arr_q = ['inner join', 'right join', 'left join', 'where','group by','having','order by','limit'];
    $query = "";
    foreach($arr_q as $key){
      if(isset($this->arr[$key])){
        if(is_array($this->arr[$key])){
          $query .= " ".strtoupper($key)." ".implode(" ".strtoupper($key)." ",$this->arr[$key]);
        }else{
          $query .= " ".strtoupper($key)." ";
          $query .= $this->arr[$key];
        }
      }
    }
    if($type == 'add') return "INSERT ".(isset($this->arr['ignore']) ? 'IGNORE' : '')." INTO ".$this->escape($this->arr['table'],'table').' '.$this->arr['into'].' VALUES '.$this->arr['values'].'';
    if($type == 'get') return "SELECT ".(isset($this->arr['select']) ? $this->arr['select'] : '*')." FROM ".$this->escape($this->arr['table'],'table')." ".$query;
    if($type == 'set') return "UPDATE ".$this->escape($this->arr['table'],'table')." SET ".$this->arr['set']." ".$query;
    if($type == 'delete') return "DELETE FROM ".$this->escape($this->arr['table'],'table')." ".$query;
    if($type == 'replace') return "REPLACE INTO ".$this->escape($this->arr['table'],'table').' '.$this->arr['into'].' VALUES '.$this->arr['values'].'';
    return $query;
  }
  function table($t){
    $this->arr['table'] = $t;
    if(!$this->table) $this->table = [];
    $this->define($t);
    return new static($this->table,$this->arr);
  }
  function where(){
    if(count(func_get_args()) == 1 && !is_array(func_get_args()[0])){
      // $this->arr['where'] = is_numeric(func_get_args()[0]) ? "id='".func_get_args()[0]."'" : "";
      $this->arr['where'] = $this->whereExtract([$this->table[$this->arr['table']]['primary'],func_get_args()[0]]);
    }elseif(count(func_get_args()) > 0){
      $this->arr['where'] = isset($this->arr['where']) && trim($this->arr['where'])!='' ? $this->arr['where'].' AND '.$this->whereExtract(func_get_args()) : $this->whereExtract(func_get_args());
    }
    return new static($this->table,$this->arr);
  }
  function having(){
    if(count(func_get_args()) == 1 && !is_array(func_get_args()[0])){
      $this->arr['having'] = is_numeric(func_get_args()[0]) ? "id='".func_get_args()[0]."'" : "";
    }elseif(count(func_get_args()) > 0){
      $this->arr['having'] = isset($this->arr['having']) && trim($this->arr['having'])!='' ? $this->arr['having'].' AND '.$this->whereExtract(func_get_args()) : $this->whereExtract(func_get_args());
    }
    return new static($this->table,$this->arr);
  }
  function orWhere(){
    if(count(func_get_args()) > 0){
      $this->arr['where'] = isset($this->arr['where']) && trim($this->arr['where'])!='' ? $this->arr['where'].' OR '.$this->whereExtract(func_get_args()) : $this->whereExtract(func_get_args());
    }
    return new static($this->table,$this->arr);
  }
  function whereJson($col,$val){
    //return 'JSON_SEARCH('.$col.', "one", "'.$val.'") IS NOT NULL';
    return new static($this->table,$this->arr);
  }
  function cache($t=3600){
    if(!is_numeric($t)){
      $exp = explode(' ',str_replace('  ',' ',$t));
      if($exp[1] == 'second') $t = intval(trim($exp[0]));
      if($exp[1] == 'minute') $t = intval(trim($exp[0])) * 60;
      if($exp[1] == 'hour')   $t = intval(trim($exp[0])) * 60 * 60;
      if($exp[1] == 'day')    $t = intval(trim($exp[0])) * 60 * 60 * 24;
      if($exp[1] == 'week')   $t = intval(trim($exp[0])) * 60 * 60 * 24 * 7;
      if($exp[1] == 'month')  $t = intval(trim($exp[0])) * 60 * 60 * 24 * 30;
      if($exp[1] == 'year')   $t = intval(trim($exp[0])) * 60 * 60 * 24 * 365;
    }
    $this->arr['cache'] = $t;
    return new static($this->table,$this->arr);
  }
  function get($b=false){
    $query = $this->query('get');
    $data = [];
    if(isset($this->arr['cache'])){
      $md5_query = md5($query);
      $md5_table = md5($this->arr['table']);
      $file = "cache/mysql/".md5(Mysql::$name)."/$md5_table"."_$md5_query";
      $cache = Candy::storage($file)->get('cache');
      if(isset($cache->date) && ($cache->date >= (time() - $this->arr['cache']))) return $cache->data;
    }
    $sql = mysqli_query(Mysql::$conn, $query);
    if($sql === false) return $this->error();
    while($row = mysqli_fetch_assoc($sql)){
      foreach($row as $key => $value) $row[$key] = $this->type($key, $value);
      $data[] = $b ? $row : (object)$row;
    }
    mysqli_free_result($sql);
    if(isset($cache)){
      $cache->data = $data;
      $cache->date = time();
      Candy::storage($file)->set('cache', $cache);
    }
    return $data;
  }
  function delete($b=false){
    $query = $this->query('delete');
    $sql = mysqli_query(Mysql::$conn, $query);
    $this->affected = mysqli_affected_rows(Mysql::$conn);
    if($this->affected > 0) self::clearcache();
    return new static($this->table,$this->arr, ['affected' => $this->affected]);
  }
  function rows($b=false){
    $query = $this->query('get');
    if(isset($this->arr['cache'])){
      $md5_query = md5($query);
      $md5_table = md5($this->arr['table']);
      $file = "cache/mysql/".md5(Mysql::$name)."/$md5_table"."_$md5_query"."_r";
      $cache = Candy::storage($file)->get('cache');
      if(isset($cache->date) && ($cache->date >= (time() - $this->arr['cache']))) return $cache->data;
    }
    $sql = mysqli_query(Mysql::$conn, $query);
    if($sql === false) return $this->error();
    $rows = mysqli_num_rows($sql);
    if(isset($cache)){
      $cache->data = $rows;
      $cache->date = time();
      Candy::storage($file)->set('cache', $cache);
    }
    return $sql===false ? false : $rows;
  }
  function set($arr,$val=null){
    $vars = "";
    if(!is_array($arr) && $val !== null) $vars .= $this->escape($arr,'col').' = '. $this->escape($this->type($arr, $val, 'encode')) .',';
    else foreach($arr as $key => $val)   $vars .= $this->escape($key,'col').' = '. $this->escape($this->type($key, $val, 'encode')) .',';
    $this->arr['set'] = substr($vars,0,-1);
    $query = $this->query('set');
    $sql = mysqli_query(Mysql::$conn, $query);
    if($sql === false) return $this->error();
    $this->affected = mysqli_affected_rows(Mysql::$conn);
    if($this->affected > 0) self::clearcache();
    return new static($this->table,$this->arr, ['affected' => $this->affected]);
  }
  function add($arr){
    $this->id = 1;
    $ext = $this->valuesExtract($arr);
    $this->arr['into'] = $ext['into'];
    $this->arr['values'] = $ext['values'];
    $query = $this->query('add');
    $sql = mysqli_query(Mysql::$conn, $query);
    if($sql === false) return $this->error();
    $this->success = $sql;
    $this->id = mysqli_insert_id(Mysql::$conn);
    $this->affected = mysqli_affected_rows(Mysql::$conn);
    if($this->affected > 0) self::clearcache();
    return new static($this->table,$this->arr, ['id' => $this->id,'affected' => $this->affected]);
  }
  function insertIgnore($arr){
    $this->arr['ignore'] = true;
    return $this->add($arr);
  }
  function replace($arr){
    $this->id = 1;
    $ext = $this->valuesExtract($arr);
    $this->arr['into'] = $ext['into'];
    $this->arr['values'] = $ext['values'];
    $query = $this->query('replace');
    $sql = mysqli_query(Mysql::$conn, $query);
    if($sql === false) return $this->error();
    $this->success = $sql;
    $this->id = mysqli_insert_id(Mysql::$conn);
    self::clearcache();
    return new static($this->table,$this->arr, ['id' => $this->id]);
  }
  function first($b=false){
    $this->arr['limit'] = 1;
    $sql = $this->get($b);
    if($sql === false || !isset($sql[0])) return false;
    return $sql[0];
  }
  function select(){
    $this->arr['select'] = isset($this->arr['select']) ? $this->arr['select'] : '';
    $select = array_filter(explode(',',$this->arr['select']));
    if(count(func_get_args())==1 && is_array(func_get_args()[0])){
      if(isset(func_get_args()[0]['ct']) && isset(func_get_args()[0]['v']) && func_get_args()[0]['ct'] == $GLOBALS['candy_token_mysql']){
        $select[] = func_get_args()[0]['v'];
      }else{
        foreach(func_get_args()[0] as $key => $value){
          if(!is_int($key)) $select[] = $this->escape($key,'col').' AS '.$this->escape($value);
          else $select[] = $this->escape($value,'col');
        }
      }
    }else{
      foreach(func_get_args() as $key){
        $select[] = $this->escape($key,'col');
      }
    }
    $this->arr['select'] = implode(', ',$select);
    return new static($this->table,$this->arr);
  }
  function orderBy($v1,$v2='asc'){
    if(is_array($v1) && (!isset($v1['ct']) || $v1['ct'] != $GLOBALS['candy_token_mysql'])){
      $order = [];
      foreach($v1 as $key => $val)
      if(!is_int($key)) $order[] = $this->escape($key,'col').(strtolower($val) == 'desc' ? ' DESC' : ' ASC');
      else $order[] = $this->escape($val,'col').' ASC';
      $this->arr['order by'] = implode(',',$order);
    }else $this->arr['order by'] = $this->escape($v1,'col').(strtolower($v2) == 'desc' ? ' DESC' : ' ASC');
    return new static($this->table,$this->arr);
  }
  function groupBy(){
    $this->arr['group by'] = isset($this->arr['group by']) ? $this->arr['group by'] : '';
    $select = array_filter(explode(',',$this->arr['group by']));
    if(count(func_get_args())==1 && is_array(func_get_args()[0])){
      if(isset(func_get_args()[0]['ct']) && isset(func_get_args()[0]['v']) && func_get_args()[0]['ct'] == $GLOBALS['candy_token_mysql']){
        $select[] = func_get_args()[0]['v'];
      }else{
        foreach(func_get_args()[0] as $key => $value){
          $select[] = $this->escape($value,'col');
        }
      }
    }else foreach(func_get_args() as $key) $select[] = $this->escape($key,'col');
    $this->arr['group by'] = implode(', ',$select);
    return new static($this->table,$this->arr);
  }
  function limit($v1,$v2=null){
    $this->arr['limit'] = $v2===null ? $v1 : "$v1, $v2";
    return new static($this->table,$this->arr);
  }
  function leftJoin($tb,$col1,$st=null,$col2=null){
    return $this->join($tb,$col1,$st,$col2,'left join');
  }
  function rightJoin($tb,$col1,$st=null,$col2=null){
    return $this->join($tb,$col1,$st,$col2,'right join');
  }
  function join($tb,$col1,$st=null,$col2=null,$type='inner join'){
    $this->arr[$type] = isset($this->arr[$type]) ? $this->arr[$type] : [];
    $this->define($tb);
    $tb = $this->escape($tb,'col');
    if($st===null && $col2===null){
      $col1 = self::whereExtract($col1);
      $col2 = '';
      $state = '';
    }else{
      $col1 = $this->escape($col1,'col');
      $col2 = $this->escape(($col2 !== null ? $col2 : $st),'col');
      $state = $this->escape(($col2 !== null ? $st : '='),'st');
    }
    $this->arr[$type][] = $tb . ' ON ' . $col1 . $state . $col2;
    return new static($this->table,$this->arr);
  }
  function login($tb_token = 'candy_token', $key = 'id'){
    $sql = $this->first();
    if($sql === false) return false;
    return new static($this->table,$this->arr);
  }
  private function whereExtract($arr){
    $q = "";
    $loop = 1;
    $in_arr = false;
    $state = '=';
    $last = 0;
    foreach ($arr as $key){
      if(is_array($key) && ($state != 'IN' && $state != 'NOT IN') && (!isset($key['ct']) || $key['ct']!=$GLOBALS['candy_token_mysql'])){
        $q .= $last == 1 ? ' AND '.$this->whereExtract($key) : $this->whereExtract($key);
        $in_arr = true;
        $last = 1;
      }elseif(count($arr)==2 && $loop==2){
        // if(!is_array($key) && in_array(strtoupper($key),$this->val_statements)){
          // $q .= " ".strtoupper($key);
        // }else{
          $q .= " =" . $this->escape($key);
        // }
      }elseif($in_arr){
        $q .= strtoupper($key)=='OR' ? " OR " : " AND ";
        $last = 2;
      }elseif(count($arr)==3 && $loop==2){
        $state = in_array(strtoupper($key),$this->statements) ? strtoupper($key) : "=";
        $q .= " ".$state;
        $last = 1;
      }elseif($key === null){
        $q .= " NULL ";
      }else{
        $q .= ' '.$this->escape($key,($loop==1 ? 'table' : 'value')).' ';
        $last = 1;
      }
        $loop++;
    }
    return '('.$q.')';
  }
  private function valuesExtract($arr){
    $query_key = [];
    $query_val = [];
    $multiple = false;
    foreach($arr as $key => $val){
      if(is_object($val)) $val = (array)$val;
      if(is_array($val) && !isset($this->table[$this->arr['table']]['columns'][$key]) && (!isset($val['ct']) || $val['ct']!=$GLOBALS['candy_token_mysql'])){
        $multiple = true;
        $ex = $this->valuesExtract($val);
        $query_key = $ex['into'];
        $query_val[] = $ex['values'];
      }elseif($val === null){
        $query_key[] = $this->escape($key,'col');
        $query_val[] = 'NULL';
      }else{
        $query_key[] = $this->escape($key,'col');
        $query_val[] = $this->escape($this->type($key,$val,'encode'));
      }
    }
    return [
      'into'   => !$multiple ? "(".implode(',',$query_key).")" : $query_key,
      'values' => !$multiple ? "(".implode(',',$query_val).")" : implode(',',$query_val)
    ];
  }
  private function escape($v,$type = 'value'){
    if(is_array($v) && isset($v['ct']) && isset($v['v']) && $v['ct']==$GLOBALS['candy_token_mysql']) return ' '.$v['v'].' ';
    if($type == 'value'){
      if($v === null) return 'NULL';
      if(is_array($v)) return ' ("'.implode('","',array_map(function($val){return(Mysql::escape($val));},$v)).'") ';
      return ' "'.Mysql::escape($v).'" ';
    }elseif($type == 'table' || $type == 'col'){
      $as = "";
      if(is_array($v)){
        $as = array_values($v)[0];
        $v = array_keys($v)[0];
        $as = "AS \"$as\" ";
      }
      if(strpos($v,'.') !== false) return ' `'.implode('`.`',array_map(function($val){return(Mysql::escape($val));},explode('.',$v))).'` '.$as;
      return ' `'.Mysql::escape($v).'` '.$as;
    }elseif($type == 'statement' || $type == 'st'){
      return in_array(strtoupper($v),$this->statements) ? strtoupper($v) : "=";
    }
  }
  private function clearcache(){
    if(!isset($this->arr['table'])) return false;
    $md5_table = md5($this->arr['table']);
    $file = "storage/cache/mysql/".md5(Mysql::$name)."/$md5_table*";
    foreach(glob($file) as $key) unlink($key);
    return true;
  }
  private function error($sql=null){
    $bt = debug_backtrace();
    $caller = $bt[1];
    Config::errorReport('MYSQL',mysqli_error(Mysql::$conn),$caller['file'],$caller['line']);
    if(Candy::isDev() && defined('DEV_ERRORS')) printf("Candy Mysql Error: %s\n<br />".$caller['file'].' : '.$caller['line'], mysqli_error(Mysql::$conn));
    return false;
  }
  private function define($t){
    $this->table[$t] = Candy::config('mysql','db',(Mysql::$name ?? 'default'),$t)->get();
    if(!($this->table[$t] ?? false)){
      $columns = [];
      $sql = mysqli_query(Mysql::$conn, 'SHOW COLUMNS FROM ' . $this->escape($t,'table'));
      $this->table[$t] = ['time' => time()];
      while($get = mysqli_fetch_object($sql)){
        $columns[$get->Field] = (array)$get;
        if($get->Key == 'PRI') $this->table[$t]['primary'] = $get->Field;
      }
      $this->table[$t]['columns'] = $columns;
      Candy::config('mysql','db',(Mysql::$name ?? 'default'),$t)->save($this->table[$t]);
    }
  }
  private function type($col, $value, $action = 'decode'){
    if($this->types ?? false) $this->types = [];
    if(!isset($this->types[$col])) {
      $this->types[$col] = 'string';
      foreach($this->table as $key => $table){
        if(!isset($this->arr['select']) && isset($this->table[$key]['columns'][$col]['Type'])){
          $this->types[$col] = $this->table[$key]['columns'][$col]['Type'] ?? $this->types[$col];
          break;
        } else if(!isset($this->arr['select'])){
          continue;
        } else if(Candy::var($this->arr['select'])->contains(" AS \"$col\"")){
          $exp = explode(' ,',explode(" AS \"$col\"",$this->arr['select'])[0]);
          $real_col = explode('.',Candy::var(trim(end($exp)))->clear('`'));
          $real_table = trim($real_col[0]);
          $real_col = trim($real_col[1]);
          $this->types[$col] = $this->types[$col] = $this->table[$real_table]['columns'][$real_col]['Type'] ?? $this->types[$col];
          break;
        } else if(Candy::var($this->arr['select'])->containsAny(" `$col`", " `".$key."`.`$col`")){
          $this->types[$col] = $table['columns'][$col]['Type'] ?? $this->types[$col];
        }
      }
    }
    if($action == 'decode'){
          if(Candy::var($this->types[$col])->isBegin('tinyint(1)')) $value = boolval($value);
      elseif(Candy::var($this->types[$col])->contains('int'))       $value = intval($value);
      elseif(Candy::var($this->types[$col])->isBegin('double'))     $value = doubleval($value);
      elseif(Candy::var($this->types[$col])->isBegin('float'))      $value = floatval($value);
      elseif(Candy::var($this->types[$col])->isBegin('boolean'))    $value = boolval($value);
      elseif(Candy::var($this->types[$col])->isBegin('json'))       $value = json_decode($value);
    } else if(!is_string($value)) {
          if(Candy::var($this->types[$col])->isBegin('tinyint(1)')) $value = intval($value);
      elseif(Candy::var($this->types[$col])->isBegin('boolean'))    $value = intval($value);
      elseif(Candy::var($this->types[$col])->isBegin('json'))       $value = json_encode($value);
    }
    return $value;
  }
}
