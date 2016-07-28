<?php
/**
 * Created by PhpStorm.
 * User: dpopov
 * Date: 26.07.2016
 * Time: 10:28
 */

namespace platform\mail;

cload("mail.phpmailerSmtp");

define('APACHE_MIME_TYPES_URL','http://svn.apache.org/repos/asf/httpd/httpd/trunk/docs/conf/mime.types');

class MIMELite  {
    public static $AUTO_CONTENT_TYPE = 0;
    public static $AUTO_VERIFY = 1;
    public static $AUTO_CC = 1;

    private static $KnownField = array('bcc', 'cc','comments', 'date', 'encrypted',
                        'from', 'keywords', 'message-id', 'mime-version', 'organization',
                        'received', 'references', 'reply-to', 'return-path', 'sender',
                        'subject', 'to', 'approved');
    private $BCount;
    private $FH;
    private $Path;

    private $Header;
    private $Attrs;
    private $Parts;

    private $Data;

    private $Preamble;
    private $Binmode;

    //----------------------- STATIC FUNCTIONS ----------------------------
    protected static $__chars = array();


    /**
     * Генерирование Message-Id для письма
     *
     * @param $domain string Домен почтового сервера
     * @return string сгенерированное message-id для параметра Message-Id заголовка письма
     */
    public static function messageId($domain) {
        self::$__chars = str_split('abcdefABCDEF0123456789');
        $noise = array_map(function($e) {
            return self::$__chars[array_rand(self::$__chars)];
        }, array_fill(0, rand(1, 3+rand(0, 6)), 0));
        $t = time();

        if(!preg_match('/^@/', $domain)) $domain = '@'.$domain;
        return sprintf("<%s.%s.%s%s>", $t, implode($noise), getmypid(), $domain);
    }
    public static function prepareSubject($subject, $charset) {
        $subject = iconv($charset, 'utf-8', $subject);
        return sprintf('=?utf-8?B?%s?=', base64_encode($subject));
    }


    //----------------------- STATIC FUNCTIONS ----------------------------

    function ___update_private($var, $val) {
        switch($var) {
            case 'Attrs': $this->Attrs = $val; break;
            case 'Data': $this->Data = $val; break;
            case 'Path': $this->Path = $val; break;
            case 'FH': $this->FH = $val; break;
        }
    }

    function __construct($params=null) {
        $this->BCount = 0;
        $this->Header = array();
        $this->Attrs = array();
        $this->Parts = array();
        $this->Data = null;
        $this->Preamble = null;
        $this->Binmode = null;

        if(is_array($params)) $this->build($params);
    }

    private function fold($str) {
        $str = trim($str);
        $str = str_replace("\n", "\n ", $str);
        return $str;
    }

    private function gen_boundary() {
        return sprintf("_----------=_%d%d%d", time(), getmypid(), $this->BCount++);
    }

    private function known_field($field) {
        return in_array(strtolower($field), self::$KnownField);
    }

    private function is_mime_field($field) {
        return preg_match('/^(mime\-|content\-)/i', trim($field));
    }

    private function encode_8bit($str) {
        return preg_replace('/^(.{990})/', '$1'."\n", $str);
    }
    private function encode_7bit($str) {
        return $this->encode_8bit(preg_replace('/[\x80-\xFF]/', '', $str));
    }
    private function encode_base64($str) {
        $eol = "\n";
        $res = base64_encode($str);
        return preg_replace('/(.{1,76})/', '$1'.$eol, $res);
    }

    static function extract_addrs($str) {
        $addrs = array();
        $str = preg_replace('/\s+/', ' ', $str);

        $ATOM      = '[^ \000-\037()<>@,;:\134"\056\133\135]+';
        $QSTR      = '".*?"';
        $WORD      = '(?:' . $QSTR . '|' . $ATOM . ')';
        $DOMAIN    = '(?:' . $ATOM . '(?:' . '\\.' . $ATOM . ')*' . ')';
        $LOCALPART = '(?:' . $WORD . '(?:' . '\\.' . $WORD . ')*' . ')';
        $ADDR      = '(?:' . $LOCALPART . '@' . $DOMAIN . ')';
        $PHRASE    = '(?:' . $WORD . ')+';
        $SEP       = "(?:^\\s*|\\s*,\\s*)";     ### before elems in a list

        if(preg_match_all('/'.$SEP.$PHRASE.'\s*<\s*('.$ADDR.')\s*>/', $str, $arr)) $addrs = array_merge($addrs, $arr[1]);
        if(preg_match_all('/'.$SEP.'('.$ADDR.')/', $str, $arr)) $addrs = array_merge($addrs, $arr[1]);

        return $addrs;
    }

    function add($field, $value) {
        $field = strtolower($field);
        array_push($this->Header, array("field"=>$field, "value"=>$this->fold($value)));
    }
    function get($field, $index=null, $asArray=false) {
        $tag = strtolower($field);
        if($this->is_mime_field($tag)) die("get: can't be used with MIME fields\n");

        $all = array();
        foreach($this->Header as $head) {
            if($head['field'] == $tag) {
                array_push($all, $asArray?$head:$head['value']);
            }
        }

        return $asArray?$index==null?$all:$all[$index]:implode(", ", $all);
    }
    function delete($field) {
        $field = strtolower($field);
        $newHeader = array();
        foreach($this->Header as $head) {
            if($head["field"] != $field) array_push($newHeader, $head);
        }
        $this->Header = $newHeader;
    }
    function replace($field, $value) {
        $this->delete($field);
        $this->add($field, $value);
    }
    function fields() {
        $fields = array();

        $explicit = array();
        foreach($this->Header as $head) {
            $explicit[$head["field"]] = 1;
            array_push($fields, array($head['field'], $head['value']));
        }

        foreach($this->Attrs as $tag=>$attrObj) {
            if($explicit[$tag]) continue;

            $subtags = array_keys($attrObj);
            if(!$subtags) continue;

            $value = $attrObj[''];
            if(!$value) continue;

            foreach($attrObj as $subtag=>$subvalue) {
                if($subtag=='') continue;
                $value .= sprintf('; %s="%s"', $subtag, $subvalue);
            }

            array_push($fields, array($tag, $value));
        }

        return $fields;
    }

    function attr($attr, $value=null) {
        $attr = strtolower($attr);
        list($tag, $subtag) = explode(".", $attr);
        if(!$subtag) $subtag='';

        if($value!==null) {
            if(!is_array($this->Attrs[$tag])) $this->Attrs[$tag] = array();
            $value = preg_replace('/[\r\n]+/', '', $value);
            $this->Attrs[$tag][$subtag] = $value;
        }

        return $this->Attrs[$tag][$subtag];
    }
    function _safe_attr($attr) {
        $v = $this->attr($attr);
        return $v?$v:'';
    }
    function top_level($onoff) {
        if($onoff) {
            $this->attr('MIME-Version', '1.0');
            $this->replace('X-Mailer', "Perl's MIME::Lite ported to PHP by Di_Moon.");
        } else {
            $this->attr('MIME-Version', 0);
            $this->delete('X-Mailer');
        }
    }

    function build($params) {
        $type = $params["Type"]?$params["Type"]:(self::$AUTO_CONTENT_TYPE?'AUTO':'TEXT');

        switch($type) {
            case 'TEXT': $type = 'plain/text'; break;
            case 'BINARY': $type = 'application/octet-stream'; break;
            case 'AUTO': $type = $this->suggest_type($params["Path"]); break;

        }

        $type = strtolower($type);
        $this->attr('content-type', $type);

        $is_multipart = preg_match('/^(multipart)/i', $type);

        if($is_multipart) {
            $this->attr('content-type.boundary', $this->gen_boundary());
        }

        if(array_key_exists('Data', $params)) {
            $this->data($params['Data']);
        } elseif(array_key_exists('Path', $params)) {
            $this->path($params['Path']);
            if($params['ReadNow']) $this->read_now();
        } elseif(array_key_exists('FH', $params)) {
            $this->fh($params['FH']);
            if($params['ReadNow']) $this->read_now();
        }

        if(array_key_exists('Filename', $params)) {
            $this->filename($params['Filename']);
        }

        $enc = $params['Encoding']?$params['Encoding']:(self::$AUTO_CONTENT_TYPE?$this->suggest_encoding($type):'binary');
        $this->attr('content-transfer-encoding', strtolower($enc));

        if(preg_match('/^(multipart|message)/', $type)) {
            if(!preg_match('/^(7bit|8bit|binary)/', $enc)) {
                die("can't have encoding $enc with type $type");
            }
        }

        $disp = $params['Disposition']?$params['Disposition']:($is_multipart?null:'inline');
        $this->attr('content-disposition', $disp);
        $length = 0;
        if(array_key_exists('Length', $params)) {
            $this->attr('content-length', $params['Length']);
        } else {
            $this->get_length();
        }

        $isTop = array_key_exists('Top', $params)?$params['Top']:1;
        $this->top_level($isTop);

        $ds_wanted = $params['Datestamp'];
        $ds_defaulted = ($isTop && !array_key_exists('Datestamp', $params));
        if(($ds_wanted || $ds_defaulted) && !array_key_exists('Date', $params)) {
            $date = gmstrftime('%a, %d %b %Y %H:%M:%S UT');
            $this->add('date', $date);
        }

        foreach($params as $tag=>$value) {
            $field = "";
            if(preg_match('/^-(.*)/', $tag, $arr)) {
                $field = strtolower($arr[1]);
            } else if(preg_match('/(.*):/', $tag, $arr)) {
                $field = strtolower($arr[1]);
            } else if($this->known_field($tag)) {
                $field = strtolower($tag);
            } else {
                continue;
            }

            $this->add($field, $value);
        }

        return $this;
    }

    function data($data=null) {
        if($data !== null) {
            $this->Data = is_array($data)?implode('', $data):$data;
            $this->get_length();
        }
        return $this->Data;
    }
    function parts() {
        return $this->Parts;
    }
    function get_length() {
        $length = 0;
        $enc = $this->attr('content-transfer-encoding')?$this->attr('content-transfer-encoding'):'binary';
        $is_multipart = preg_match('/^multipart/i', $this->attr('content-type'));
        if(!$is_multipart && $enc == 'binary') {
            if($this->Data) {                       // text
                $length = strlen($this->Data);
            } else if($this->FH) {                  // filehandle

            } else if($this->Path && file_exists($this->Path)) {
                $length = filesize($this->Path);
            }
        }
        $this->attr("content-length", $length);
        return $length;
    }
    function path($path=null) {
        if(!$path) return $this->Path;

        $this->Path = $path;
        $filename = "";
        if($this->Path && !preg_match('/\|$/', $this->Path)) {
            $filename = preg_replace('/^</', '', $this->Path);
            if(preg_match('/([^\/\\]+)$/', $filename, $arr)) {
                $filename = $arr[1];
            }
        }
        $this->filename($filename);
        $this->get_length();
        return $this->Path;
    }
    function filename($filename=null) {
        if($filename) {
            $this->attr('content-type.name', $filename);
            $this->attr('content-disposition.filename', $filename);
        }
        return $this->attr('content-disposition.filename');
    }
    function fh($fh=null) {
        if($fh) $this->FH = $fh;
        return $this->FH;
    }
    function read_now() {
        if($this->FH) {
            $this->Data = stream_get_contents($this->FH);
        } else if($this->Path) {
            $this->Data = file_get_contents($this->Path);
        }
    }
    function resetfh() {
        if($this->FH) fseek($this->FH, 0);
    }

    function suggest_encoding($ctype) {
        list($type) = explode('/', $ctype);
        if($type == 'text' || $type == 'message') {
            return 'binary';
        } else {
            return $type == 'multipart'?'binary':'base64';
        }
    }

    function attach($param) {
        $part = @get_class($param)==get_class($this)?$param:new MIMELite(array_merge($param, array("Top"=>0)));

        if(!preg_match('/^(multipart|message)/i', $this->attr('content-type'))) {
            $part0 = new MIMELite(array());
            $part0->___update_private("Attrs", $this->Attrs);   $this->Attrs = array();
            $part0->___update_private("Data", $this->Data);     $this->Data = null;
            $part0->___update_private("Path", $this->Path);     $this->Path = null;
            $part0->___update_private("FH", $this->FH);         $this->FH = null;

            $part0->top_level(0);

            $this->attr('content-type', 'multipart/mixed');
            $this->attr('content-type.boundary', $this->gen_boundary());
            $this->attr('content-transfer-encoding', 'binary');
            $this->top_level(1);

            array_push($this->Parts, $part0);
        }

        //$part->attr('content-type.boundary', $this->gen_boundary());

        array_push($this->Parts, $part);
        return $part;
    }
    function suggest_type($path) {
        if(!$path) return 'application/octet-stream';

        if(function_exists('mime_content_type')) return mime_content_type($path);
        if(function_exists('finfo_file')) return finfo_file(finfo_open(FILEINFO_MIME_TYPE), $path);

        $ext = strtolower(array_pop(explode('.',$path)));
        $mime_types = $this->mimeArray();
        if (array_key_exists($ext, $mime_types)) return $mime_types[$ext];


        return 'application/octet-stream';
    }
    function preamble($preamble=null) {
        if($preamble!==null) $this->Preamble = $preamble;
        return $this->Preamble;
    }
    function binmode($mode=null) {
        if($mode!==null) $this->Binmode = $mode;
        return $this->Binmode!==null?$this->Binmode:!preg_match('/^(text|message)/i', $this->attr('content-type'));
    }

    function verify_data() {
        $path = $this->Path;
        if($path && !preg_match('/\|$/', $path)) {
            $path = preg_replace('/^</', '', $path);
            if(!is_readable($path)) die('$path: not readable\n');
        }

        foreach($this->Parts as $part) {
            $part->verify_data();
        }

        return true;
    }

    function _print() {
        if(self::$AUTO_VERIFY) $this->verify_data();

        $out = $this->header_as_string();
        $out .= "\n".$this->print_body();

        return $out;
    }

    function print_body() {
        $out = "";

        $type = $this->attr('content-type');
        if(preg_match('/^multipart/i', $type)) {
            $boundary = $this->attr('content-type.boundary');

            $out .= $this->Preamble?$this->Preamble:"This is a multi-part message in MIME format.\n";
            foreach($this->Parts as $part) {
                $out .= "\n--$boundary\n";
                if($part->attr('content-type.boundary') == $boundary) $part->attr('content-type.boundary', $this->gen_boundary());
                $out .= $part->_print();
            }
            $out .= "\n--$boundary--\n\n";
        } elseif (preg_match('/^message/i', $type)) {
            if(count($this->Parts)==0) $out = $this->print_simple_body();
            if(count($this->Parts)==1) $out = $this->Parts[0]->_print();
            else die("can't handle message with >1 part\n");
        } else {
            $out = $this->print_simple_body();
        }

        return $out;
    }

    function print_simple_body() {
        $out = "";
        $data = "";

        $encoding = strtoupper($this->attr('content-transfer-encoding'));

        if($this->Data!==null) {
            $data = $this->Data;
        } elseif($this->Path) {
            $data = file_get_contents($this->Path);
        }

        switch($encoding) {
            case (preg_match('/^BINARY/', $encoding)?true:false) : $out = $data; break;
            case (preg_match('/^8BIT/', $encoding)?true:false) : $out = $this->encode_8bit($data); break;
            case (preg_match('/^7BIT/', $encoding)?true:false) : $out = $this->encode_7bit($data); break;
            case (preg_match('/^BASE64/', $encoding)?true:false) : $out = $this->encode_base64($data); break;
            case (preg_match('/^QUOTED-PRINTABLE$/', $encoding)?true:false) : {
                die("qp unsupported");
            }; break;
            default: die("encoding $encoding unsupported");
        }

        return $out;
    }

    function print_header() {
        return $this->header_as_string();
    }

    function body_as_string() {
        return $this->print_body();
    }

    function fields_as_string($fields) {
        $out = array();
        foreach($fields as $field) {
            list($tag, $value) = $field;
            if(!$value) continue;

            $tag = preg_replace_callback('/\b([a-z])/', function($_m) { return strtoupper($_m[0]); }, trim($tag));
            $tag = preg_replace('/^mime-/i', 'MIME-', $tag);
            $tag = preg_replace('/:$/', '', $tag);
            array_push($out, sprintf("%s: %s\n", $tag, $value));
        }

        return implode("", $out);
    }

    function header_as_string() {
        return $this->fields_as_string($this->fields());
    }

    public static function mimeArray() {
        $mime = array();

        return $mime;
    }

    protected function extract_domain($server) {
        if(!preg_match_all('/([^ \000-\037()<>@,;:\134"\056\133\135]+)/', $server, $arr)) die("error to parse server $server");
        $domain = "";

        if(count($arr[1])==1) die("too few results on parse server $server");
        elseif(count($arr[1])>2) { unset($arr[1][0]); }

        $domain = implode('.', $arr[1]);

        return $domain;
    }

    protected function prepare_smtp($server, $domain="", $debug_lvl=0) {
        $smtp = new SMTP();
        $smtp->do_debug = 10;

        $smtp->setDebugLevel(10);
        $smtp->setDebugOutput();

        $smtp->connect($server);
        if(!$smtp->connected()) die($smtp->getError());
        $smtp->hello($domain);

        return $smtp;
    }

    public function send_by_smtp($server) {
        $header = $this->fields();
        $from = $this->get('Return-Path');
        if(!$from) $from = $this->get('From');
        $to = $this->get('To', null, false);

        $domain = $this->extract_domain($server);

        if(!$this->get('Message-Id')) $this->attr('Message-Id', $this->messageId($domain));

        if(!$to) die('missing To address\n');

        $addrs = $this->extract_addrs($to);
        if(self::$AUTO_CC) {
            foreach(["Cc", "Bcc"] as $field) {
                $_v = $this->get($field);
                _print_r($_v);
                if($_v) $addrs = array_merge($addrs, $this->extract_addrs($_v));
            }
        }

        $smtp = $this->prepare_smtp($server, $domain, 10);

        $smtp->mail($from);
        foreach($addrs as $recipient) { $smtp->recipient($recipient); };

        $smtp->data($this->_print());

        $smtp->close();

    }
}
