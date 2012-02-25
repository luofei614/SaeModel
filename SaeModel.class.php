<?php
/**
 * 简单的数据库模型
 * 具有连贯操作，自动验证等功能。
 * 实现了ORM和ActiveRecords模式
 * @author luofei614<www.3g4k.com>
 */
function M($name = '') {
    static $_model = array();
    if (isset($_model[$name])){
        $_model[$name]->flushOptions(); //清空之前的表达式
        return $_model[$name];
     }
    $_model[$name] = new SaeModel($name);
    return $_model[$name];
}

//sql语句安全过滤
function es($val) {
    return SaeModel::$db->escape($val);
}

class SaeModel {
    //调试模式,关闭字段缓存

    static public $debug = false;
    //表前缀
    static public $tablePrefix = '';
    //记录sql
    static public $sqlLog = array();
    //数据库对象
    public static $db;
    //验证对象
    static public $_validate;
    //默认缓存时间,单位秒，0为永久
    static public $cache_expire=0;
    //带前缀表名
    protected $tableName;
    //不带前缀表名
    protected $name;
    //表达式
    protected $options = array();
    //存表字段
    protected $fields = array();
    //记录错误
    protected $error;
    //数据
    public $data;
    //是否验证字段（使用D函数验证字段，M函数不验证）
    public $ifValidate = false;
    //操作类型
    public $type;

    public function __construct($name = '') {
        if (!is_object(self::$db))
            self::$db = new SaeMysql ();
        if (!empty($name)) {
            $this->name = self::parse_name($name);
            $this->tableName = $this->parseKey(self::$tablePrefix . $this->name);
        }
    }

    public function table($name) {
        $this->tableName = $this->addTablePrefix($name);
        return $this;
    }

    //给表名或字段加上`
    protected function parseKey($key) {
        $key = trim($key);
        if (false !== strpos($key, ' ') || false !== strpos($key, '*') || false != strpos($key, '.') || false !== strpos($key, '(') || false !== strpos($key, '`')) {
            //如果包含* 或者 使用了sql方法 则不作处理
        } elseif (false != strpos($key, ',')) {
            //多个字段处理
            $key = implode(',', array_map(array($this, 'parseKey'), explode(',', $key)));
        } else {
            $key = '`' . $key . '`';
        }
        return $key;
    }

    //安全过滤
    protected function parseValue($value) {
        if (strpos($value, ',') != 0) {
            $value = implode(',', $this->parseValue(explode(',', $value)));
        } elseif (is_string($value)) {
            $value = '\'' . self::$db->escape($value) . '\'';
        } elseif (is_array($value)) {
            $value = array_map(array($this, 'parseValue'), $value);
        } elseif (is_null($value)) {
            $value = 'null';
        }
        return $value;
    }

    protected function getFieldInfo() {
        //读取字段缓存
        if (!isset($this->fields[$this->tableName])) {
            $fields = self::fc($this->tableName);
            if (is_null($fields) || self::$debug) {
                $this->flush();
            } else {
                $this->fields[$this->tableName] = $fields;
            }
        }
    }

    //支持简单的连贯操作。 不支持数组传参，不支持 union。
    public function select() {
        $sqlTpl = 'SELECT%DISTINCT% %FIELD% FROM %TABLE%%JOIN%%WHERE%%GROUP%%HAVING%%ORDER%%LIMIT%';
        //支持page方法
        if (isset($this->options['page'])) {
            // 根据页数计算limit
            if (strpos($this->options['page'], ',')) {
                list($page, $listRows) = explode(',', $this->options['page']);
            } else {
                $page = $this->options['page'];
            }
            $page = $page ? $page : 1;
            $listRows = isset($listRows) ? $listRows : (isset($this->options['limit']) && is_numeric($this->options['limit']) ? $this->options['limit'] : 20);
            $offset = $listRows * ((int) $page - 1);
            $this->options['limit'] = $offset . ',' . $listRows;
        }
        //解析sql语句
        $sql = str_replace(array('%TABLE%', '%WHERE%', '%DISTINCT%', '%FIELD%', '%JOIN%', '%GROUP%', '%HAVING%', '%ORDER%', '%LIMIT%'), array(
            $this->tableName,
            $this->getOption('where','','WHERE __OPTION__ ',null,false),
            $this->getOption('distinct', '', ' DISTINCT '),
            $this->getOption('field', '*', null, 'parseKey'),
            $this->getOption('join', '', ' LEFT JOIN __OPTION__ ', 'addTablePrefix'),
            $this->getOption('group', '', ' GROUP BY __OPTION__ '),
            $this->getOption('having', '', ' HAVING __OPTION__ '),
            $this->getOption('order', '', ' ORDER BY __OPTION__ ',null,false),
            $this->getOption('limit', '', ' LIMIT __OPTION__ ')
                ), $sqlTpl);
        //缓存判断
        if (isset($this->options['cache'])) {
            $cache = $this->options['cache'];
            $key = is_string($cache[0]) ? $cache[0] : md5($sql);
            if(!$mc = @memcache_init()){
                header("Content-Type:text/html; charset=utf-8");
                exit('您还没有初始化Memecache，请先初始化');
            }
            $ret = $mc->get($key);
            if ($ret) {
                return $ret;
            } else {
                $ret = $this->query($sql);
                if ($ret)
                    $mc->set($key, $ret, MEMCACHE_COMPRESSED, $cache[1]);
                return $ret;
            }
        }
        return $this->query($sql); //执行sql语句
    }
    //查询缓存
    public function cache($key, $expire = '') {
        if ($key)
            $this->options['cache'] = array($key, empty($expire)?self::$cache_expire:$expire);
        return $this;
    }

    //查找一条数据
    public function find($options = null) {
        if (!is_null($options)) {
            $this->options['where'] = $this->parseKey($this->getPk()) . '=' . $this->parseValue($options);
        }
        $this->options['limit'] = 1;
        $data = $this->select();
        if (is_array($data))
            $data = reset($data);
        return $data;
    }

    //清空以前记录的表达式
    public function flushOptions() {
        $this->options = array();
    }

    //创建数据,自动获取数据
    public function create($data = array()) {
        if (empty($data)) {
            $data = $_POST;
        }
        $pk=$this->getPk();
        if (!isset($this->fields[$this->tableName])) {
            $this->error = '数据库表' . $this->tableName . '不存在';
            return false;
        }
        //自动验证
        $this->data = $data;
        $this->type = isset($data[$pk]) ? Validate::MODEL_UPDATE : Validate::MODEL_INSERT;
        $verifyFields = array();
        $tableName = $this->name;
        $verifyFields = is_object(self::$_validate) && isset(self::$_validate->$tableName) ? self::$_validate->$tableName : array();
        if (!empty($verifyFields))
            self::$_validate->_model = $this;
        foreach ($verifyFields as $key => $val) {
            if(!isset($val[1]))
                continue;
            //判断是多个条件函数但个条件 
            if(is_array($val[1])){
                //多个条件
                //定义逻辑关系，默认为and
                $logic=isset($val['_logic'])?$val['_logic']:'and';
                //循环验证
                foreach($val as $arr){
                    if(!is_array($arr))
                        continue;
                    $ret=$this->validateOneRule($key, $arr, $data[$key]);
                    if($logic=='and'){
                        //判断and关系
                        if(!$ret){
                            $flag=false;
                            break;
                        }else{
                            $flag=true;
                        }
                    }else{
                        //判断or关系
                        if($ret){
                            $flag=true;
                            break;
                        }else{
                            $flag=false;
                        }
                    }
                }
                
                //是否定义了_error错误下标
                if(isset($val['_error']))
                    $this->error=$val['_error'];
                if(!$flag)
                    return false;
            }else {
                //单个条件
                array_shift($val);
                if (!$this->validateOneRule($key, $val, $data[$key]))
                    return false;
            }
        }
        //自动验证end
        if (!$this->_facade($data))
            return false;
        $this->data = $data;
        return true;
    }
    //判断单个条件
    protected function validateOneRule($key,$val,$data){
            if (!isset($val[1]))
                $val[1] = '';
            //条件默认值
            if (!isset($val[2]))
                $val[2] = Validate::MODEL_BOTH;
            if (!isset($val[3]))
                $val[3] = Validate::MUST_VALIDATE;
            //判断验证条件
            if ($val[2] == $this->type || $val[2] == Validate::MODEL_BOTH) {
                $ifValidate = false;
                if ($val[3] == Validate::MUST_VALIDATE) {
                    $ifValidate = true;
                } elseif ($val[3] == Validate::EXISTS_VAILIDATE && isset($data)) {
                    $ifValidate = true;
                } elseif ($val[3] == Validate::VALUE_VAILIDATE && isset($data) && '' !== trim($data)) {
                    $ifValidate = true;
                }
                if ($ifValidate) {
                    $method = $val[0];
                    if (method_exists(self::$_validate, $method)) {
                        $args = array_slice($val, 4);
                        array_unshift($args, $key);
                        array_unshift($args, isset($data) ? $data : '');
                        if (!call_user_func_array(array(self::$_validate, $method), $args)) {
                            $error = self::$_validate->_getError(); //获得动态定义的错误信息
                            $this->error = is_null($error) ? $val[1] : $error;
                            return false;
                        }
                    } else {
                        $this->error = '验证规则不存在';
                        return false;
                    }
                }
            }
            return true;
    }

    //设置数据
    public function data($data) {
        $this->_facade($data);
        $this->data = $data;
    }

    //数据处理
    protected function _facade(&$data) {
        $this->getFieldInfo();
        $tableName = $this->name;
        $verifyFields = isset(self::$_validate->$tableName) ? self::$_validate->$tableName : array();
        foreach ($data as $key => $val) {
            if (!in_array($key, $this->fields[$this->tableName], true)) {
                //清除多余数据
                unset($data[$key]);
                continue;
            }
            //验证字段
            $fieldType = strtolower($this->fields[$this->tableName]['_type'][$key]);
            //验证长度
            if (preg_match('/[a-zA-Z]+\((\d+)\)/', $fieldType, $arr) && strlen($data[$key]) > $arr[1]) {
                $filedName = isset($verifyFields[$key]) ? reset($verifyFields[$key]) : $key;
                $this->error = "{$filedName}超出长度,不得超过{$arr[1]}字节";
                return false;
            }
            //类型转换
            if (false === strpos($fieldType, 'bigint') && false !== strpos($fieldType, 'int')) {
                $data[$key] = intval($data[$key]);
            } elseif (false !== strpos($fieldType, 'float') || false!==strpos($fieldType,'decimal') || false !== strpos($fieldType, 'double')) {
                $data[$key] = floatval($data[$key]);
            } elseif (false !== strpos($fieldType, 'bool')) {
                $data[$key] = (bool) $data[$key];
            }
        }
        return true;
    }

    //返回错误
    public function getError() {
        return $this->error;
    }

    //添加数据
    public function add($data = array(), $replace = false) {
        if (!empty($data)) {
            if (!$this->_facade($data))
                return false;
        }else {
            $data = $this->data;
        }
        $keys = array();
        $values = array();
        foreach ($data as $key => $value) {
            $keys[] = $this->parseKey($key);
            $values[] = $this->parseValue($value);
        }
        $sql = ($replace ? 'REPLACE' : 'INSERT') . ' INTO ' . $this->tableName . ' (' . implode(',', $keys) . ') VALUES (' . implode(',', $values) . ')';
        if ($this->execute($sql) !== false)
            return $this->getLastInsID(); //最后插入主键
        else
            return false;
    }

    //修改数据
    public function save($data = array()) {
        if (!empty($data)) {
            if (!$this->_facade($data))
                return false;
        }else {
            $data = $this->data;
        }
        //条件生成
        if (!isset($this->options['where'])) {
            $pk = $this->getPk();
            if (isset($data[$pk])) {
                $this->options['where'] = $this->parseKey($pk) . '=' . $this->parseValue($data[$pk]);
                unset($data[$pk]);
            } else {
                $this->error = "没有任何修改条件";
                return false;
            }
        }
        $sets = array();
        foreach ($data as $key => $val) {
            $sets[] = $this->parseKey($key) . '=' . $this->parseValue($val);
        }
        //生成sql语句
        $sql = "UPDATE " . $this->tableName . " SET " . implode(',', $sets) . $this->getOption('where', '', ' WHERE __OPTION__ ',null,false) . $this->getOption('order', '', ' ORDER BY __OPTION__ ',null,false) . $this->getOption('limit', '', ' LIMIT __OPTION__ ');
        return $this->execute($sql);
    }

    //单独设置字段
    public function setField($field, $value = '') {
        if (is_array($field)) {
            $data = $field;
        } else {
            $data[$field] = $value;
        }
        return $this->save($data);
    }

    //删除数据
    public function delete($pk = null) {
        if (!is_null($pk)) {
            if (strpos($pk, ','))
                $this->options['where'] = $this->getPk() . " in(" . $this->parseValue($pk) . ") ";
            else
                $this->options['where'] = $this->getPk() . '=' . $this->parseValue($pk);
        }
        if (!isset($this->options['where']) && is_null($pk)) {
            $this->error = "没有任何删除条件";
            return false;
        }
        $sql = 'DELETE FROM ' . $this->tableName . $this->getOption('where', '', ' WHERE __OPTION__ ',null,false) . $this->getOption('order', '', ' ORDER BY __OPTION__ ',null,false) . $this->getOption('limit', '', ' LIMIT __OPTION__ ');
        return $this->execute($sql);
    }

    //执行查询
    public function query($sql) {
        self::$sqlLog[] = $sql;
        return self::$db->getData($sql);
    }

    //执行sql语句
    public function execute($sql) {
        self::$sqlLog[] = $sql;
        return self::$db->runSql($sql);
    }

    //解析表前缀，
    // __Table_Name__ 形式会自动加上表前缀
    protected function addTablePrefix($str) {
        return preg_replace("/__([A-Z_-]+)__/esU", "\$this->parseKey('" . self::$tablePrefix . "'.strtolower('$1'))", $str);
    }

    //获得最后插入的主键
    public function getLastInsID() {
        return self::$db->lastId();
    }

    public function getDbError() {
        return self::$db->error();
    }

    //获得主键
    public function getPk() {
        $this->getFieldInfo();
        return $this->fields[$this->tableName]['_pk'];
    }

    //获得表达式参数
    protected function getOption($name, $default = '', $return = null, $filter = null,$allowEmpty=true) {
        if (!is_null($filter) && isset($this->options[$name]))
            $this->options[$name] = $this->$filter($this->options[$name]); //过滤函数
        if(isset($this->options[$name])){
            if(!$allowEmpty && empty($this->options[$name]))//为空不处理
                return '';
            return is_null($return) ? $this->options[$name] : str_replace('__OPTION__', $this->options[$name], $return);
        }else{
            return $default;
        }
    }

    //获得最后执行的sql语句
    public function _sql() {
        return $this->getLastSql();
    }

    public function getLastSql() {
        $count = count(self::$sqlLog);
        if ($count != 0) {
            return self::$sqlLog[$count - 1];
        }
        return null;
    }

    public function getField($field, $sepa = null) {
        $this->options['field'] = $field;
        if (strpos($field, ',')) { // 多字段
            $resultSet = $this->select();
            if (!empty($resultSet)) {
                $_field = explode(',', $field);
                $field = array_keys($resultSet[0]);
                $move = $_field[0] == $_field[1] ? false : true;
                $key = array_shift($field);
                $key2 = array_shift($field);
                $cols = array();
                $count = count($_field);
                foreach ($resultSet as $result) {
                    $name = $result[$key];
                    if ($move) { // 删除键值记录
                        unset($result[$key]);
                    }
                    if (2 == $count) {
                        $cols[$name] = $result[$key2];
                    } else {
                        $cols[$name] = is_null($sepa) ? $result : implode($sepa, $result);
                    }
                }
                return $cols;
            }
        } else {   // 查找一条记录
            $this->options['limit'] = 1;
            $result = $this->select();
            if (!empty($result)) {
                return reset($result[0]);
            }
        }
        return false;
    }

    //查询连贯操作
    public function __call($method, $args) {
        if (in_array(strtolower($method), array('where', 'join', 'page', 'limit', 'distinct', 'field', 'group', 'order', 'having'), true)) {
            //记录表达式
            $this->options[$method] = reset($args);
            return $this;
        } elseif (in_array(strtolower($method), array('count', 'sum', 'min', 'max', 'avg'), true)) {
            // 统计查询的实现
            $field = isset($args[0]) ? $args[0] : '*';
            return $this->getField(strtoupper($method) . '(' . $field . ') AS sae_' . $method);
        } elseif (strtolower(substr($method, 0, 5)) == 'getby') {
            // 根据某个字段获取记录
            return $this->where($this->parseKey(self::parse_name(substr($method, 5))) . '=' . $this->parseValue(reset($args)))->find();
        } elseif (strtolower(substr($method, 0, 10)) == 'getfieldby') {
            // 根据某个字段获取记录的某个值
            return $this->where($this->parseKey(self::parse_name(substr($method, 10))) . '=' . $this->parseValue($args[0]))->getField($args[1]);
        } else {
            //抛出错误
            exit('SaeModel not has Method:'.$method);
        }
    }

    //获得数据库字段
    protected function _getDbFields() {
        $result = $this->query('SHOW COLUMNS FROM ' . $this->tableName);
        $info = array();
        if ($result) {
            foreach ($result as $key => $val) {
                $info[$val['Field']] = array(
                    'name' => $val['Field'],
                    'type' => $val['Type'],
                    'notnull' => (bool) ($val['Null'] === ''), // not null is empty, null is yes
                    'default' => $val['Default'],
                    'primary' => (strtolower($val['Key']) == 'pri'),
                    'autoinc' => (strtolower($val['Extra']) == 'auto_increment'),
                );
            }
        }
        return $info;
    }

    //刷新缓存字段
    public function flush() {
        $fields = $this->_getDbFields();
        if (!$fields) {
            return false;
        }
        $_fields = array_keys($fields);
        foreach ($fields as $key => $val) {
            //记录字段类型
            $_fields['_type'][$key] = $val['type'];
            //记录主键
            if ($val['primary']) {
                $_fields['_pk'] = $key;
                if ($val['autoinc'])
                    $_fields['_autoinc'] = true;
            }
        }
        $this->fields[$this->tableName] = $_fields;
        //生成缓存
        self::fc($this->tableName, $fields);

        return true;
    }

    //驼峰命名
    protected static function parse_name($name, $type = 0) {
        if ($type) {
            return ucfirst(preg_replace("/_([a-zA-Z])/e", "strtoupper('\\1')", $name));
        } else {
            return strtolower(trim(preg_replace("/[A-Z]/", "_\\0", $name), "_"));
        }
    }

    //字段缓存存储控制：fields-cache
    protected static function fc($name, $value = '') {
        static $kv;
        if (!is_object($kv)) {
            $kv = new SaeKVClient();
            header("Content-Type:text/html; charset=utf-8");
            if (!$kv->init())
                exit('~你没有初始化KVDB,请先初始化！');
        }
        if ($value !== '') {
            if (is_null($value))
                return $kv->delete($_SERVER['HTTP_APPVERSION'] . '/' . $name);
            else
                return $kv->set($_SERVER['HTTP_APPVERSION'] . '/' . $name, $value);
        }
        return $_SERVER['HTTP_APPVERSION'] . '/' . $name;
    }

    //实现ActiveRecords模式
    public function __set($name, $value) {
        $this->data[$name] = $value;
    }

    public function __get($name) {
        return isset($this->data[$name]) ? $this->data[$name] : null;
    }

    public function __isset($name) {
        return isset($this->data[$name]);
    }

    public function __unset($name) {
        unset($this->data[$name]);
    }

    //debug
    function test() {
        self::fc($this->tableName, null);
        var_dump(self::fc($this->tableName));
    }

}

//验证基类,定义常用的验证规则
class Validate {
    
    const MODEL_INSERT = 1;      //  插入模型数据
    const MODEL_UPDATE = 2;      //  更新模型数据
    const MODEL_BOTH = 3;      //  包含上面两种方式
    const MUST_VALIDATE = 1; // 必须验证
    const EXISTS_VAILIDATE = 0; // 表单存在字段则验证
    const VALUE_VAILIDATE = 2; // 表单值不为空则验证

    //模型对象
    public $_model;
    //动态错误信息
    public $_error = null;

    public function notempty($fieldData) {
        return !empty($fieldData);
    }

    //唯一
    public function unique($fieldData, $fieldName) {
        $where = array("{$fieldName}='" . es($fieldData) . "'");
        if (isset($this->_model->data[$this->_model->getPk()])) {
            $where[] = '`' . $this->_model->getPk() . '`!=\'' . intval($this->_model->data[$this->_model->getPk()]) . '\'';
        }
        $whereStr = implode(' and ', $where);
        if ($this->_model->where($whereStr)->find()) {
            return false;
        } else {
            return true;
        }
    }

    //是否为邮件格式
    public function email($fieldData) {
        return filter_var($fieldData, FILTER_VALIDATE_EMAIL) === false ? false : true;
    }

    //是否为ip格式
    public function ip($fieldData) {
        return filter_var($fieldData, FILTER_VALIDATE_IP) === false ? false : true;
    }

    public function url($fieldData) {
        return filter_var($fieldData, FILTER_VALIDATE_URL) === false ? false : true;
    }

    //判断是否为整数，可为负数
    public function int($fieldData) {
        return filter_var($fieldData, FILTER_VALIDATE_INT) === false ? false : true;
    }

    public function float($fieldData) {
        return filter_var($fieldData, FILTER_VALIDATE_FLOAT) === false ? false : true;
    }

//身份证验证   
    public function idcard($fieldData) {

//加权因子
        $wi = array(7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2);
//校验码串
        $ai = array('1', '0', 'X', '9', '8', '7', '6', '5', '4', '3', '2');
//按顺序循环处理前17位
        $sigma = 0;
        for ($i = 0; $i < 17; $i++) {
//提取前17位的其中一位，并将变量类型转为实数
            $b = @(int) $fieldData{$i};

//提取相应的加权因子
            $w = $wi[$i];

//把从身份证号码中提取的一位数字和加权因子相乘，并累加
            $sigma+=$b * $w;
        }
//计算序号
        $snumber = $sigma % 11;

//按照序号从校验码串中提取相应的字符。
        $check_number = $ai[$snumber];

        if (@$fieldData{17} == $check_number) {
            return true;
        } else {
            return false;
        }
    }

    //是否为中文
    function chinese($fieldData) {
        return preg_match('/^([\xe4-\xe9][\x80-\xbf]{2})*$/', $fieldData);
    }
    //英文格式
    function english($fieldData){
        return preg_match('/^[A-Za-z]+$/', $fieldData);
    }

    //座机
    function phone($fieldData) {
        return (preg_match("/^(((\d{3}))|(\d{3}-))?((0\d{2,3})|0\d{2,3}-)?[1-9]\d{6,8}$/", $fieldData)) ? true : false;
    }

    //手机
    function tel($fieldData) {
        return (preg_match("/(?:13\d{1}|15[03689]|18\d{1})\d{8}$/", $fieldData)) ? true : false;
    }

    //获得动态错误信息
    function _getError() {
        $error = $this->_error;
        $this->_error = null;
        return $error;
    }

}
