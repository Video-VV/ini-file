<?php
/*
 * Simple PHP INI Class

 * @author Video VV
 * @license MIT
 * @version v1.0.2 (PHP 8.4 ready)

 * @description Simple PHP Class to manage INI files (read and write). Adapted for PHP 8.4 with PSR-4 namespace and exceptions.
 *              Added: comments BEFORE sections (including empty), AFTER_EOL and AFTER_EMPTY comments.
 *              JSON values: stdClass objects are marked with a unique key ("\x00obj"),
 *              so they are read back as stdClass, while associative arrays stay arrays.
 *              The section name "@" is reserved for top-level variables and is rejected.
*/

namespace videovv\IniFile;

class IniFile
{
    /** Ini file path
     * @public String
     */
    public $file = NULL;

    /** Ini file data
     * @public Array
     */
    public $data = array();

    /** Process sections
     * @public Boolean
     */
    public $sections = TRUE;

    /** Ini file top data (no section)
     * @public Array['@']
     */
    public $topData = array( '@'=>array() );

    /** Ini file comments
     * @public Array
     */
    public $comments = array();

    /** Временное хранилище данных при чтении (используется в read)
     * @public Array
     */
    public $check = array();

    /** Ini file constant
     * @const BEFORE
     * @const BEHIND
     * @const AFTER
     * @const AFTER_EOL
     * @const AFTER_EMPTY
     * @const _OBJ_MARK
     * @const _ERR_SECTION_TOP
     */
    const BEFORE      = 'before';
    const BEHIND      = 'behind';
    const AFTER       = 'after';
    const AFTER_EOL   = 'after_eol';
    const AFTER_EMPTY = 'after_empty';
    const _TOP        = '@';
    const _LINE       = 'line';
    const _VAR        = 'var';
    const _SEP        = ';';
    const _SEP_BASE64 = 'Ow==';
    /** Уникальный маркер объекта stdClass в JSON */
    const _OBJ_MARK   = "\x00obj";
    /** Сообщение об ошибке: имя секции "@" зарезервировано */
    const _ERR_SECTION_TOP = 'Section name "@" is reserved for top-level variables and cannot be used as a section name.';

    /** Parse ini file
     * @param  String      $file       - Ini file path
     * @param  Boolean     $sections   - Process sections
     */
    public function __construct() {
        if (func_num_args()) {
            $args = func_get_args();
            call_user_func_array(array($this, 'read'), $args);
        }
    }

    /** Check variable in ini file on JSON encoding
     * @param  String      $string         - value in ini variable
     */
    private function isJSON($string = NULL) {
        $string = ($string) ? $string : '';
        try {
            json_decode( $string, true, 512, JSON_THROW_ON_ERROR | JSON_HEX_TAG |
                                            JSON_UNESCAPED_SLASHES |  JSON_UNESCAPED_UNICODE |
                                            JSON_HEX_QUOT | JSON_HEX_AMP );
        }
        catch (\JsonException $exception) {
            return false;
        }
        return true;
    }

    /** Save array in ini file on JSON encoding
     * @param  Array       $array      - value in ini variable
     */
    private function toJSON( $array = [] ) {
        try {
            return json_encode( $array, JSON_THROW_ON_ERROR | JSON_HEX_TAG |
                                        JSON_UNESCAPED_SLASHES |
                                        JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE |
                                        JSON_PRESERVE_ZERO_FRACTION );
        }
        catch (\JsonException $exception) {
            return 'Error JSON ('.$exception->getCode().') encoding: '.$exception->getMessage();
        }
    }

    /** Экранирование спецсимволов в строковом значении в ini file
     * @param  String  $value
     * @return String
     */
    private function escape_value( $value ) {
        return str_replace(
            array( '\\', '"' ),
            array( '\\\\', '\\"' ),
            (string)$value
        );
    }

    /** Разэкранирование спецсимволов в строковом значении в ini file
     * @param  String  $value
     * @return String
     */
    private function unescape_value( $value ) {
        return str_replace(
            array( '\\"', '\\\\' ),
            array( '"',   '\\' ),
            (string)$value
        );
    }

    /** Detect type variable
     * @param  Mixed       $val        - value in ini file variable
     */
    private function setValue( $var = NULL, $val = '') {
        $content = '';
        if( $var ) {
            if ( is_object( $val ) || is_array( $val ) ) {
                $wrapped = $this->wrapObjects( $val );
                $content = $var.' = ' . $this->toJSON( $this->replace_com_data( $wrapped ) ) . PHP_EOL;
            } elseif ( is_string($val)) {
                if( empty( $val )) {
                    $content = $var . ' = ' . PHP_EOL;
                } else {
                    $content = $var . ' = "' . $this->escape_value( $this->replace_com_data( $val ) ) . '"' . PHP_EOL;
                }
            } elseif ( is_int( $val ) || is_float( $val )) {
                $content = $var . ' = ' . (string)$val . PHP_EOL;
            } elseif ( is_bool( $val )) {
                $content = $var . ' = ' . ($val ? 'true' : 'false' ) . PHP_EOL;
            } elseif ( is_null( $val )) {
                $content = $var . ' = ' . PHP_EOL;
            } else {
                $content = $var . ' = "' . $this->escape_value( $this->replace_com_data( $val ) ) . '"' . PHP_EOL;
            }
        }
        return $content;
    }

    /** Ключ комментария для переменной: '[section]var' или '@var' для верхнего уровня */
    private function varKey( $section, $var ) {
        return ( $section === self::_TOP ) ? self::_TOP . $var
                                           : '[' . trim($section) . ']' . trim($var);
    }

    /** Ключ комментария для секции: '[section]' или '@' для верхнего уровня */
    private function sectionKey( $section ) {
        return ( $section === self::_TOP ) ? self::_TOP
                                           : '[' . trim($section) . ']';
    }

    /** Извлечение BEHIND-комментария из строки значения (с учётом кавычек).
     *  Возвращает [часть до комментария (trim), сам комментарий с ';' в начале или NULL].
     */
    private function splitInlineComment( $line ) {
        $len = strlen($line);
        $inQuote = false;
        $escaped = false;
        for( $i = 0; $i < $len; $i++ ) {
            $ch = $line[$i];
            if( $escaped ) { $escaped = false; continue; }
            if( $ch === '\\' ) { $escaped = true; continue; }
            if( $ch === '"' ) { $inQuote = !$inQuote; continue; }
            if( !$inQuote && $ch === self::_SEP ) {
                $before  = rtrim( substr( $line, 0, $i ) );
                $comment = trim( substr( $line, $i ) );
                return array( $before, $comment );
            }
        }
        return array( rtrim( $line ), NULL );
    }

    /** Parse ini file (comments) */
    private function selectComm( $aLine = NULL ) {
        $aComment = array( self::_TOP => array() );
        $section  = self::_TOP;
        $lastVar  = '';
        $lastKind = '';
        $pending  = array();

        $flush = function( $targetKey, $prevKey, $prevKind, $targetKind ) use ( &$aComment, &$pending ) {
            foreach( $pending as $p ) {
                if( $p['type'] === 'empty' ) {
                    if( $prevKey !== '' ) {
                        $aComment[ $prevKey ][] = array( self::AFTER_EMPTY => PHP_EOL );
                    } else {
                        $aComment[ $targetKey ][] = array( self::BEFORE => PHP_EOL );
                    }
                } else {
                    if( $targetKind === 'section' ) {
                        $aComment[ $targetKey ][] = array( self::BEFORE => $p['text'] );
                    } else {
                        if( $prevKind === 'section' || $prevKey === '' || $prevKey === $targetKey ) {
                            $aComment[ $targetKey ][] = array( self::BEFORE => $p['text'] );
                        } else {
                            $aComment[ $prevKey ][] = array( self::AFTER_EOL => $p['text'] );
                        }
                    }
                }
            }
            $pending = array();
        };

        for( $l = 0; $l < count($aLine); $l++ ) {
            $line = trim( $aLine[$l] );

            if( $line === '' ) {
                $pending[] = array( 'type' => 'empty', 'text' => PHP_EOL );
                continue;
            }

            if( $line[0] === self::_SEP ) {
                $pending[] = array( 'type' => 'comment', 'text' => $line . PHP_EOL );
                continue;
            }

            if( $line[0] === '[' ) {
                if( preg_match('/^\[(.*?)\]/', $line, $m) ) {
                    $newSection = trim( $m[1] );
                    if( $newSection === self::_TOP ) {
                        throw new \Exception(self::_ERR_SECTION_TOP);
                    }
                    $secKey  = $this->sectionKey( $newSection );
                    $prevKey = ( $lastKind === 'var' ) ? $this->varKey( $section, $lastVar )
                               : ( $lastKind === 'section' ? $this->sectionKey( $section ) : '' );

                    $flush( $secKey, $prevKey, $lastKind, 'section' );

                    $rest = trim( substr( $line, strlen( '[' . $m[1] . ']' ) ) );
                    if( $rest !== '' && $rest[0] === self::_SEP ) {
                        $aComment[ $secKey ][] = array( self::BEHIND => $rest . PHP_EOL );
                    }

                    $section  = $newSection;
                    $lastVar  = '';
                    $lastKind = 'section';
                }
                continue;
            }

            if( preg_match('/^([^=]+)=(.*)$/', $line, $m) ) {
                $var = trim( $m[1] );
                $val = trim( $m[2] );

                list( $valPart, $inlineCom ) = $this->splitInlineComment( $val );
                $varKey  = $this->varKey( $section, $var );
                $prevKey = ( $lastKind === 'var' ) ? $this->varKey( $section, $lastVar )
                           : ( $lastKind === 'section' ? $this->sectionKey( $section ) : '' );

                $flush( $varKey, $prevKey, $lastKind, 'var' );

                if( $inlineCom !== NULL && $inlineCom !== '' ) {
                    $aComment[ $varKey ][] = array( self::BEHIND => $inlineCom . PHP_EOL );
                }

                $lastVar  = $var;
                $lastKind = 'var';
                continue;
            }
        }

        if( !empty( $pending ) ) {
            if( $lastKind === '' ) {
                foreach( $pending as $p ) {
                    if( $p['type'] === 'comment' ) {
                        $aComment[ self::_TOP ][] = array( self::BEFORE => $p['text'] );
                    } else {
                        $aComment[ self::_TOP ][] = array( self::AFTER_EMPTY => PHP_EOL );
                    }
                }
            } else {
                $prevKey = ( $lastKind === 'var' ) ? $this->varKey( $section, $lastVar )
                           : $this->sectionKey( $section );
                foreach( $pending as $p ) {
                    if( $p['type'] === 'comment' ) {
                        $aComment[ $prevKey ][] = array( self::AFTER_EOL => $p['text'] );
                    } else {
                        $aComment[ $prevKey ][] = array( self::AFTER_EMPTY => PHP_EOL );
                    }
                }
            }
        }

        return $aComment;
    }

    /** Parse ini file */
    function read( $file = NULL, $sections = TRUE ) {
        if( empty( $file ) ) { throw new \Exception('ini file path is not specified.'); }
        $this->file = ($file) ? $file : $this->file;
        if( !file_exists( $this->file )) { throw new \Exception('ini file not found: ' . $this->file); }
        if( is_dir( $this->file ) ) { throw new \Exception('The specified path is a directory, not a file: ' . $this->file); }
        if( !is_readable( $this->file ) ) { throw new \Exception('Permission denied. Cannot read file: ' . $this->file); }

        $this->sections = $sections;
        $this->data = parse_ini_file( realpath($this->file), $this->sections, INI_SCANNER_RAW );
        $fileContent = file_get_contents( realpath($this->file) );
        $this->comments = $this->selectComm( explode( PHP_EOL, $fileContent ) );

        $rawLines = explode( PHP_EOL, $fileContent );
        $rawValues = array();
        $currentSection = self::_TOP;

        foreach ($rawLines as $line) {
            $line = trim($line);
            if (empty($line) || $line[0] === ';') continue;

            if ($line[0] === '[') {
                if (preg_match('/^\[(.*?)\]/', $line, $m)) {
                    $currentSection = trim($m[1]);
                    if ($currentSection === self::_TOP) {
                        throw new \Exception(self::_ERR_SECTION_TOP);
                    }
                }
            } elseif (preg_match('/^([^=]+)=(.*)$/', $line, $m)) {
                $key = trim($m[1]);
                $rawVal = trim($m[2]);

                if (strpos($rawVal, ';') !== false) {
                    if (preg_match('/^"(?:[^"\\\\]|\\\\.)*"/u', $rawVal, $matchQuote)) {
                        $rawVal = $matchQuote[0];
                    } else {
                        $pos = strpos($rawVal, ';');
                        $rawVal = trim(substr($rawVal, 0, $pos));
                    }
                }

                if (strlen($rawVal) >= 2 && $rawVal[0] === '"' && substr($rawVal, -1) === '"') {
                    $rawVal = substr($rawVal, 1, -1);
                }

                $rawValues[$currentSection][$key] = $rawVal;
            }
        }

        $this->check = $this->data;

        foreach( $this->check as $section_var => $section_val ) {
            if( is_array( $section_val ) ) {
                if( $section_var === self::_TOP ) {
                    throw new \Exception(self::_ERR_SECTION_TOP);
                }
                foreach( $section_val as $var => $val) {
                    if (isset($rawValues[$section_var][$var])) {
                        $val = $rawValues[$section_var][$var];
                    }

                    if( $this->isJSON( $val ) ) {
                        try {
                            $decoded = json_decode(
                                str_replace( self::_SEP_BASE64, self::_SEP, $val ),
                                true, 512,
                                JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP
                            );
                            $this->data[$section_var][$var] = $this->unwrapObjects( $decoded );
                        } catch (\JsonException $exception) {
                            $this->data[$section_var][$var] = 'Error JSON ('.$exception->getCode().') encoding: '.$exception->getMessage();
                        }
                    } else {
                        $this->data[$section_var][$var] = $this->unescape_value( str_replace( self::_SEP_BASE64, self::_SEP, $val ) );
                    }
                }
            } else {
                if (isset($rawValues[self::_TOP][$section_var])) {
                    $section_val = $rawValues[self::_TOP][$section_var];
                }

                if( $this->isJSON( $section_val ) ) {
                    try {
                        $decoded = json_decode(
                            $section_val,
                            true, 512,
                            JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP
                        );
                        $this->topData[self::_TOP][$section_var] = $this->unwrapObjects( $decoded );
                    } catch (\JsonException $exception) {
                        $this->topData[self::_TOP][$section_var] = 'Error JSON ('.$exception->getCode().') encoding: '.$exception->getMessage();
                    }
                } else {
                    $this->topData[self::_TOP][$section_var] = $this->unescape_value( $section_val );
                }
                unset( $this->data[$section_var] );
            }
        }
        if( count( $this->topData[self::_TOP] ) > 0 ) {
            $this->data = array_merge( $this->topData, $this->data );
        }
        return $this->data;
    }

    /** Рекурсивно оборачивает объекты в маркер. */
    private function wrapObjects( $value ) {
        if ( is_object( $value ) ) {
            $inner = array();
            foreach ( get_object_vars( $value ) as $k => $v ) {
                $inner[$k] = $this->wrapObjects( $v );
            }
            return array( self::_OBJ_MARK => $inner );
        }
        if ( is_array( $value ) ) {
            $result = array();
            foreach ( $value as $k => $v ) {
                $result[$k] = $this->wrapObjects( $v );
            }
            return $result;
        }
        return $value;
    }

    /** Рекурсивно разворачивает маркеры обратно в stdClass. */
    private function unwrapObjects( $value ) {
        if ( is_array( $value ) ) {
            if ( array_key_exists( self::_OBJ_MARK, $value ) && count( $value ) === 1 ) {
                $inner = $value[ self::_OBJ_MARK ];
                $obj = new \stdClass();
                if ( is_array( $inner ) ) {
                    foreach ( $inner as $k => $v ) {
                        $obj->$k = $this->unwrapObjects( $v );
                    }
                } elseif ( is_object( $inner ) ) {
                    foreach ( get_object_vars( $inner ) as $k => $v ) {
                        $obj->$k = $this->unwrapObjects( $v );
                    }
                }
                return $obj;
            }
            $result = array();
            foreach ( $value as $k => $v ) {
                $result[$k] = $this->unwrapObjects( $v );
            }
            return $result;
        }
        return $value;
    }

    /** Count comments by process type */
    private function countCom( $aComment = NULL, $process = NULL) {
        $aComment = ( $aComment ) ? $aComment : array();
        $process = ( $process ) ? $process : self::BEHIND;
        $count = 0;
        foreach( $aComment as $commKey ) {
            if( isset( $commKey[ $process ] ) ) {
                $count++;
            }
        }
        return $count;
    }

    /** Проверка, что строка заканчивается переводом строки */
    private function endsWithEol( $content ) {
        $content = (string)$content;
        if( $content === '' ) {
            return false;
        }
        return ( mb_substr( $content, -1, 1, 'UTF-8' ) === "\n"
                 || mb_substr( $content, -2, 2, 'UTF-8' ) === "\r\n" );
    }

    /** Форматирование текста комментария для переменной/секции */
    private function formatCommentText( $comment, $insert, $varcom ) {
        if( $insert === self::AFTER_EMPTY ) {
            return PHP_EOL;
        }

        if( $comment === '' && ( $insert === self::BEFORE || $insert === self::AFTER_EOL ) ) {
            return PHP_EOL;
        }

        if( empty( $comment ) ) {
            return PHP_EOL;
        }

        $onebehind = !( isset( $this->comments[ $varcom ] ) && is_array( $this->comments[ $varcom ] )
                        && $this->countCom( $this->comments[ $varcom ] ) > 0 );

        switch ( $insert ) {
            case self::BEFORE:
            case self::AFTER_EOL:
                return self::_SEP . ' ' . $comment . PHP_EOL;
            case self::AFTER:
                return self::_SEP . ' ' . $comment;
            case self::BEHIND:
            default:
                return ( $onebehind ? self::_SEP . ' ' : '' ) . $comment;
        }
    }

    /** addComment in ini file */
    function addComment( $comment = '', $var = NULL, $insert = NULL ) {
        $this->comments = $this->comments;
        $this->data = $this->data;

        $varkey = ($var) ? explode(':', $var) : array( self::_TOP, '' );
        $insert = ($insert) ? $insert : self::BEHIND;

        if( !isset( $varkey[1] ) ) {
            $varkey[1] = trim( $varkey[0] );
            $varkey[0] = self::_TOP;
        }

        if( !isset( $this->comments[ self::_TOP ] ) ) {
            $this->comments[ self::_TOP ] = array();
        }

        $isSection = (
            ( $varkey[0] !== self::_TOP && $varkey[0] !== '' && $varkey[1] === '' )
            ||
            ( $varkey[0] === self::_TOP
              && $varkey[1] !== ''
              && isset( $this->data[ $varkey[1] ] )
              && is_array( $this->data[ $varkey[1] ] ) )
        );

        if( $isSection ) {
            $sectionName = ( $varkey[0] !== self::_TOP ) ? trim( $varkey[0] ) : $varkey[1];
            if( $sectionName === self::_TOP ) {
                throw new \Exception(self::_ERR_SECTION_TOP);
            }
            $varcom = $this->sectionKey( $sectionName );

            $this->comments[ $varcom ][] = array(
                $insert => $this->formatCommentText( $comment, $insert, $varcom )
            );
            return TRUE;
        }

        if( $varkey[0] === self::_TOP ) {
            if( $varkey[1] === '' ) {
                if( $insert === self::AFTER_EMPTY ) {
                    $this->comments[ self::_TOP ][] = array( self::AFTER_EMPTY => PHP_EOL );
                } elseif( empty( $comment ) ) {
                    $this->comments[ self::_TOP ][] = array( self::AFTER => PHP_EOL );
                } else {
                    $this->comments[ self::_TOP ][] = array(
                        self::AFTER => self::_SEP . ' ' . $comment
                    );
                }
                return TRUE;
            }

            if( isset( $this->data[ self::_TOP ][ $varkey[1] ] ) ) {
                $varcom = $this->varKey( self::_TOP, $varkey[1] );
                $this->comments[ $varcom ][] = array(
                    $insert => $this->formatCommentText( $comment, $insert, $varcom )
                );
                return TRUE;
            }
            return FALSE;
        }

        if( isset( $this->data[ $varkey[0] ][ $varkey[1] ] ) ) {
            $varcom = $this->varKey( $varkey[0], $varkey[1] );
            $this->comments[ $varcom ][] = array(
                $insert => $this->formatCommentText( $comment, $insert, $varcom )
            );
            return TRUE;
        }

        return FALSE;
    }

    /** delComment in ini file */
    function delComment( $var = NULL, $insert = NULL ) {
        $this->comments = $this->comments;
        if( $var ) {
            $varkey = explode(':', $var);
            if( !isset( $varkey[1] ) ) { $varkey[1] = trim( $varkey[0] ); $varkey[0] = self::_TOP; }

            if( $varkey[0] === self::_TOP) {
                if( empty( $varkey[1] ) ) {
                    $varcom = self::_TOP;
                } else {
                    $varcom = $this->varKey( self::_TOP, $varkey[1] );
                }
            } else {
                $varcom = $this->varKey( $varkey[0], $varkey[1] );
            }
            if( isset( $this->comments[ $varcom ] ) ) {
                if( $insert ) {
                    $nCnt = count( $this->comments[ $varcom ] );
                    for($c=0; $c<$nCnt; $c++) {
                        if( isset( $this->comments[ $varcom ][$c][ $insert ] ) ) {
                            unset( $this->comments[ $varcom ][$c] );
                        }
                    }
                    $this->comments[ $varcom ] = array_values( $this->comments[ $varcom ] );
                } else {
                    unset( $this->comments[ $varcom ] );
                    return TRUE;
                }
            }
        } else {
            $this->comments = array();
            return TRUE;
        }
        return FALSE;
    }

    /** before insert comments in ini file */
    private function before( $comments, $content = '') {
        foreach( $comments as $commKey ) {
            if( isset( $commKey[ self::BEFORE ] ) ) {
                $content .= $commKey[ self::BEFORE ];
            }
        }
        return $content;
    }

    /** behind insert comments in ini file */
    private function behind( $comments, $content = '') {
        foreach( $comments as $commKey ) {
            if( isset( $commKey[ self::BEHIND ] ) ) {
                $content = ( mb_substr( $content, -1, 1, 'UTF-8' ) == ']'
                             ? $content
                             : rtrim( $content, "\r\n" )
                           ).' '.$commKey[ self::BEHIND ];
            }
        }
        return $content;
    }

    /** after insert comments in ini file */
    private function after( $comments, $content = '') {
        foreach( $comments as $commKey ) {
            if( isset( $commKey[ self::AFTER ] ) ) {
                if( $this->endsWithEol( $content ) ) {
                    $content = rtrim( $content, "\r\n" );
                }
                $content .= ' ' . $commKey[ self::AFTER ];
            }
        }
        return $content;
    }

    /** after_eol insert comments in ini file */
    private function after_eol( $comments, $content = '') {
        foreach( $comments as $commKey ) {
            if( isset( $commKey[ self::AFTER_EOL ] ) ) {
                if( !$this->endsWithEol( $content ) ) {
                    $content .= PHP_EOL;
                }
                $content .= $commKey[ self::AFTER_EOL ];
            }
        }
        return $content;
    }

    /** after_empty insert (пустая строка после переменной/секции) */
    private function after_empty( $comments, $content = '') {
        foreach( $comments as $commKey ) {
            if( isset( $commKey[ self::AFTER_EMPTY ] ) ) {
                if( !$this->endsWithEol( $content ) ) {
                    $content .= PHP_EOL;
                }
                $content .= $commKey[ self::AFTER_EMPTY ];
            }
        }
        return $content;
    }

    /** Замена ';' на base64-представление внутри JSON-значений */
    private function replace_com_data( $json_array, $search = self::_SEP, $replace = self::_SEP_BASE64 ) {
        if( is_array( $json_array ) ) {
            foreach ($json_array as $key => $value) {
                if (is_array($value) || is_object($value)) {
                    $json_array[$key] = $this->replace_com_data($value, $search, $replace );
                } elseif (is_string( $value )) {
                    $json_array[$key] = str_replace( $search, $replace, $json_array[$key] );
                }
            }
            return $json_array;
        } elseif ( is_object( $json_array ) ) {
            $result = new \stdClass();
            foreach ( get_object_vars( $json_array ) as $key => $value ) {
                if ( is_array( $value ) || is_object( $value ) ) {
                    $result->$key = $this->replace_com_data( $value, $search, $replace );
                } elseif ( is_string( $value ) ) {
                    $result->$key = str_replace( $search, $replace, $value );
                } else {
                    $result->$key = $value;
                }
            }
            return $result;
        } elseif ( is_string( $json_array ) ) {
            return str_replace( $search, $replace, $json_array );
        } else {
            return $json_array;
        }
    }

    /** Write ini file */
    function write( $file = NULL, $data = array(), $sections = TRUE, $comments = array() ) {
        $this->data     = (!empty($data))     ? $data     : $this->data;
        $this->file     = ($file)             ? $file     : $this->file;
        $this->sections = $sections;
        $this->comments = (!empty($comments)) ? $comments : $this->comments;
        $content = '';

        if ($this->sections) {
            foreach ($this->data as $section => $data) {
                if( is_array( $data ) && $section === self::_TOP ) {
                    throw new \Exception(self::_ERR_SECTION_TOP);
                }
                $line   = ( $section != self::_TOP ? '[' . $section . ']' : '' );
                $keyCom = ( $section != self::_TOP ? $this->sectionKey( $section ) : self::_TOP );

                if( isset( $this->comments[ $keyCom ] ) ) {
                    $content = $this->before( $this->comments[ $keyCom ], $content );
                }

                if( $section != self::_TOP && $content !== '' && !$this->endsWithEol( $content ) ) {
                    $content .= PHP_EOL;
                }

                if( $section != self::_TOP ) {
                    $content .= $line;
                }

                if( isset( $this->comments[ $keyCom ] ) ) {
                    $content = $this->after_empty(
                        $this->comments[ $keyCom ],
                        $this->after_eol(
                            $this->comments[ $keyCom ],
                            $this->after(
                                $this->comments[ $keyCom ],
                                $this->behind( $this->comments[ $keyCom ], $content )
                            )
                        )
                    );
                }
                if( !$this->endsWithEol( $content ) ) {
                    $content .= PHP_EOL;
                }

                foreach ($data as $key => $val) {
                    $keyCom = $this->varKey( $section, $key );
                    if( isset( $this->comments[ $keyCom ] ) ) {
                        $content = $this->before( $this->comments[ $keyCom ], $content );
                        if( !$this->endsWithEol( $content ) ) {
                            $content .= PHP_EOL;
                        }
                    }
                    $content .= $this->setValue( $key, $val );
                    if( isset( $this->comments[ $keyCom ] ) ) {
                        $content = $this->after_empty(
                            $this->comments[ $keyCom ],
                            $this->after_eol(
                                $this->comments[ $keyCom ],
                                $this->after(
                                    $this->comments[ $keyCom ],
                                    $this->behind( $this->comments[ $keyCom ], $content )
                                )
                            )
                        );
                    }
                    if( !$this->endsWithEol( $content ) ) {
                        $content .= PHP_EOL;
                    }
                }
            }
        } else {
            foreach ($this->data as $key => $val) {
                $keyCom = $this->varKey( self::_TOP, $key );
                if( isset( $this->comments[ $keyCom ] ) ) {
                    $content = $this->before( $this->comments[ $keyCom ], $content );
                    if( !$this->endsWithEol( $content ) ) {
                        $content .= PHP_EOL;
                    }
                }
                $content .= $this->setValue( $key, $val );
                if( isset( $this->comments[ $keyCom ] ) ) {
                    $content = $this->after_empty(
                        $this->comments[ $keyCom ],
                        $this->after_eol(
                            $this->comments[ $keyCom ],
                            $this->after(
                                $this->comments[ $keyCom ],
                                $this->behind( $this->comments[ $keyCom ], $content )
                            )
                        )
                    );
                }
                if( !$this->endsWithEol( $content ) ) {
                    $content .= PHP_EOL;
                }
            }
        }

        if (!$handle = @fopen($this->file, 'w')) {
            throw new \Exception('Cannot open file for writing: ' . $this->file);
        }
        if (fwrite($handle, rtrim($content, "\r\n")) === false) {
            throw new \Exception('Cannot write data to file: ' . $this->file);
        }
        fclose($handle);
        return true;
    }
}