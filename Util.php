<?php
/**
 * 便利工具
 *
 * @package comm
 * @author  6naffan9@gmail.com
 */
class Comm_Util {

    /**
     * 控制台颜色值
     */
    const CONSOLE_COLOR_NORMAL = '30';
    const CONSOLE_COLOR_BLACK = '30';
    const CONSOLE_COLOR_ERROR = '31';
    const CONSOLE_COLOR_OK = '32';
    const CONSOLE_COLOR_WARNING = '33';
    const CONSOLE_COLOR_INFO = '34';

    /**
     * 判断php宿主环境是否是64bit
     *
     * ps: 在64bit下，php有诸多行为与32bit不一致，诸如mod、integer、json_encode/decode等，具体请自行google。
     *
     * @return bool
     */
    public static function is64bit() {
        return ( int ) 0xFFFFFFFF !== -1;
    }

    /**
     * 修正过的ip2long
     *
     * 可去除ip地址中的前导0。32位php兼容，若超出127.255.255.255，则会返回一个float
     *
     * for example: 02.168.010.010 => 2.168.10.10
     *
     * 处理方法有很多种，目前先采用这种分段取绝对值取整的方法吧……
     * @param string $ip
     * @return float 使用unsigned int表示的ip。如果ip地址转换失败，则会返回0
     */
    public static function ip2long($ip) {
        $ip_chunks = explode('.', $ip, 4);
        foreach ($ip_chunks as $i => $v) {
            $ip_chunks[$i] = abs(intval($v));
        }
        return sprintf('%u', ip2long(implode('.', $ip_chunks)));
    }

    /**
     * 判断是否是内网ip
     * @param string $ip
     * @return boolean
     */
    public static function isPrivateIp($ip) {
        $ip_value = self::ip2long($ip);
        return ($ip_value & 0xFF000000) === 0x0A000000 ||         //10.0.0.0-10.255.255.255
        ($ip_value & 0xFFF00000) === 0xAC100000 ||         //172.16.0.0-172.31.255.255
        ($ip_value & 0xFFFF0000) === 0xC0A80000;        //192.168.0.0-192.168.255.255

    }



    /**
     * 使json_decode能处理32bit机器上溢出的数值类型
     *
     * @param string $response
     * @param string $field_name
     * @param boolean $assoc
     * @return array|object
     */
    public static function jsonDecode($value, $assoc = true) {
        //PHP5.3以下版本不支持
        //TODO 获取机器CPU位数
        if (version_compare(PHP_VERSION, '5.3.0', '>') && defined('JSON_BIGINT_AS_STRING')) {
            return json_decode($value, $assoc, 512, JSON_BIGINT_AS_STRING);
        } else {
            $value = preg_replace("/\"(\w+)\":(\d+[\.\d+[e\+\d+]*]*)/", "\"\$1\":\"\$2\"", $value);
            return json_decode($value, $assoc);
        }
    }

    /**
     * To get ip belonged region according to ip
     * @param <string> $ip ip address, heard that can be ip strings, split by "," ,but i found it not used
     * @param <int> $type 地域名及ISP的显示格式  0 默认文本格式；
    1 regions.xml中的id；
    2 regions.xml中的code，即ISO-3166的地区代码；
    3 regions.xml中的fips，即FIPS的地区代码。
     * @param <string> $encoding  编码类, gbk或utf-8, 默认为gbk
     * @return <int or array>
     */
    static function getIpSource($ip, $type = 1, $encoding = 'utf-8') {
        if (!function_exists('lookup_ip_source'))
            return 0;
        $code = lookup_ip_source($ip, $type, $encoding);
        switch ($code) {
            case "-1" :
            case "-2" :
            case "-3" :
                return 0;
                break;
            default :
                return $code;
                break;
        }

    }

    /**
     * 获取真实的客户端ip地址
     *
     * This function is copied from login.sina.com.cn/module/libmisc.php/get_ip()
     *
     * @param boolean $to_long	可选。是否返回一个unsigned int表示的ip地址
     * @return string|float		客户端ip。如果to_long为真，则返回一个unsigned int表示的ip地址；否则，返回字符串表示。
     */
    public static function getRealClientIp($to_long = false) {
        $forwarded = self::getServer('HTTP_X_FORWARDED_FOR');
        if ($forwarded) {
            $ip_chains = explode(',', $forwarded);
            $proxied_client_ip = $ip_chains ? trim(array_pop($ip_chains)) : '';
        }

        if (Comm_Util::isPrivateIp(self::getServer('REMOTE_ADDR')) && isset($proxied_client_ip)) {
            $real_ip = $proxied_client_ip;
        } else {
            $real_ip = self::getServer('REMOTE_ADDR');
        }

        return $to_long ? self::ip2long($real_ip) : $real_ip;
    }

    /**
     * 根据实际场景，获取客户端IP
     * @param	boolean		$to_long	是否变为整型IP
     * @return	string
     */
    public static function getClientIp($to_long = false) {
        static $ip = null;
        if ($ip === null) {
            $module = Yaf_Dispatcher::getInstance()->getRequest()->getModuleName();
            switch ($module) {
                case 'Internal' :
                    isset($_GET['cip']) && $ip = $_GET['cip'];
                    break;
                case 'Openapi' :
                    $headers = array();
                    if(function_exists('getallheaders')) {
                        foreach( getallheaders() as $name => $value ) {
                            $headers[strtolower($name)] = $value;
                        }
                    } else {
                        foreach($_SERVER as $name => $value) {
                            if(substr($name, 0, 5) == 'HTTP_') {
                                $headers[strtolower(str_replace(' ', '-', str_replace('_', ' ', substr($name, 5))))] = $value;
                            }
                        }
                    }
                    isset($headers['cip']) && $ip = $headers['cip'];
                    break;
                case 'Cli' :
                    $ip = '0.0.0.0';
                    //					$ip = `/sbin/ifconfig | grep 'inet addr' | awk '{ print $2 }' | awk -F ':' '{ print $2}' | head -1`;
                    break;
            }
            empty($ip) && $ip = self::getRealClientIp();
        }

        return $to_long ? self::ip2long($ip) : $ip;
    }

    /**
     * 获取当前Referer
     *
     * @return string
     */
    public static function getReferer() {
        return self::getServer('HTTP_REFERER');
    }

    /**
     * 获取当前域名
     *
     * @return string
     */
    public static function getDomain() {
        return self::getServer('SERVER_NAME');
    }

    /**
     * 得到当前请求的环境变量
     *
     * @param string $name
     * @return string|null 当$name指定的环境变量不存在时，返回null
     */
    public static function getServer($name) {
        return isset($_SERVER[$name]) ? $_SERVER[$name] : null;
    }

    /**
     * 返回当前url
     *
     * @param bool $urlencode 是否urlencode后返回，默认true
     */
    public static function getCurrentUrl($urlencode = true) {
        $req_uri = self::getServer('REQUEST_URI');
        if (null === $req_uri) {
            $req_uri = self::getServer('PHP_SELF');
        }

        $https = self::getServer('HTTPS');
        $s = null === $https ? '' : ('on' == $https ? 's' : '');

        $protocol = self::getServer('SERVER_PROTOCOL');
        $protocol = strtolower(substr($protocol, 0, strpos($protocol, '/'))) . $s;

        $port = self::getServer('SERVER_PORT');
        $port = ($port == '80') ? '' : (':' . $port);

        $server_name = self::getServer('SERVER_NAME');
        $current_url = $protocol . '://' . $server_name . $port . $req_uri;

        return $urlencode ? rawurlencode($current_url) : $current_url;
    }


    /**
     * 执行系统shell脚本，并返回输出
     * @param $cmd
     * @return string
     */
    static public function execute($cmd) {
        return shell_exec($cmd);
    }

    /**
     * 循环写入网络包
     *
     * @param resource $fp      网络资源
     * @param string   $content 内容
     *
     * @return int
     *
     * @author chengxuan <chengxuan@staff.sina.com.cn>
     */
    static public function netWrite($fp, $content) {
        $length = strlen($content);
        $write_length = fwrite($fp, $content);
        if($write_length < $length) {
            for($i = 0; $write_length < $length && $i < 10; ++$i) {
                $write_length += fwrite($fp, substr($content, $write_length));
            }
        }
        return $write_length;
    }

    /**
     * 判断是否为cli方式运行
     * @return bool
     */
    static public function isCli() {
        return php_sapi_name()=='cli';
    }

    /**
     * 返回控制台输出时高亮颜色
     * @param  string      $txt
     * @param  string $color CONSOLE_COLOR
     * @return string
     */
    static public function colorText($txt, $color=self::CONSOLE_COLOR_INFO) {
        if(!self::isCli() || $color==self::CONSOLE_COLOR_NORMAL)
            return $txt;
        return "\033[0;31;".$color."m".$txt."\033[0m";
    }

    /**
     * 返回控制台输出时错误字符串颜色
     * @param  string      $txt
     * @param  string $color CONSOLE_COLOR
     * @return string
     */
    static public function errorText($txt) {
        return self::colorText($txt, self::CONSOLE_COLOR_ERROR);
    }

    /**
     * 返回控制台输出时错误字符串颜色
     * @param  string      $txt
     * @param  string $color CONSOLE_COLOR
     * @return string
     */
    static public function infoText($txt) {
        return self::colorText($txt, self::CONSOLE_COLOR_INFO);
    }

    /**
     * 返回控制台输出时错误字符串颜色
     * @param  string      $txt
     * @param  string $color CONSOLE_COLOR
     * @return string
     */
    static public function warningText($txt) {
        return self::colorText($txt, self::CONSOLE_COLOR_WARNING);
    }

    /**
     * 判断一个给定的UA是否为主流的搜索引擎 http://www.useragentstring.com/pages/Crawlerlist/
     * @param $ua user agent
     * @return bool
     */
    static  function isSpiderUA($ua='') {
        if(!$ua) {
            $ua =  $_SERVER["HTTP_USER_AGENT"];
        }
        //搜狗： 'Sogou web spider'
        //搜搜； 'Sosospider'
        $ua_list = array('Baiduspider', 'bingbot', 'Googlebot', 'msnbot', 'YoudaoBot', 'spider', 'Sosospider', 'Yahoo! Slurp');
        foreach ($ua_list as $item) {
            if(strpos($ua,  $item) !== false)	 {
                return true;
            }
        }
        return false;
    }
}