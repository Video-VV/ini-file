<?php
/*
 * Simple PHP INI Class

 * @author Video VV
 * @license MIT
 * @version v1.1.0 (PHP 8.4 ready, flat mode support, multiline strings, JSON comments)

 * @description Simple PHP Class to manage INI files (read and write). Adapted for PHP 8.4 with PSR-4 namespace and exceptions.
 *              Added: flat mode support (sections = false) with correct read/write.
 *              Added: comments BEFORE sections (including empty), AFTER_EOL and AFTER_EMPTY comments.
 *              JSON values: stdClass objects are marked with a unique key ("\x00obj"),
 *              so they are read back as stdClass, while associative arrays stay arrays.
 *              The section name "@" is reserved for top-level variables and is rejected in file text.
 *              Added: multiline string and JSON values support, optional line width wrapping.
 *              Added: comments inside JSON are removed only when outside string literals.
 *              Fixed: inline BEHIND comments are collected only in parseIniLines (no duplication).
 *              Added: '#' is supported as a comment marker alongside ';'.
 *              Fixed: read() and write() no longer override $this->sections when the argument is omitted.
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

    /** Максимальная ширина строки при записи (0 - без ограничений)
     * @public Int
     */
    public $maxWidth = 0;

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
    const _SEP_HASH   = '#';
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

    /** Перенос длинного значения по ширине (для строк и JSON)
     * @param  String  $value     - значение для переноса
     * @param  Int     $maxWidth  - максимальная ширина (0 - без ограничений)
     * @param  Bool    $isJson    - является ли значение JSON
     * @return String
     */
    private function wrapValue( $value, $maxWidth = 0, $isJson = false ) {
        if ( $maxWidth <= 0 || strlen( $value ) <= $maxWidth ) {
            return $value;
        }

        if ( $isJson ) {
            $decoded = json_decode( $value, true );
            if ( $decoded !== null ) {
                $pretty = json_encode(
                    $decoded,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );
                if ( $pretty !== false ) {
                    return $pretty;
                }
            }
            return $value;
        }

        $words = preg_split( '/\s+/', $value );
        $lines = array();
        $currentLine = '';
        foreach ( $words as $word ) {
            if ( $currentLine !== '' && strlen( $currentLine ) + 1 + strlen( $word ) > $maxWidth ) {
                $lines[] = $currentLine;
                $currentLine = $word;
            } else {
                $currentLine .= ( $currentLine === '' ? '' : ' ' ) . $word;
            }
        }
        if ( $currentLine !== '' ) {
            $lines[] = $currentLine;
        }
        return implode( PHP_EOL, $lines );
    }

    /** Detect type variable
     * @param  Mixed       $val        - value in ini file variable
     */
    private function setValue( $var = NULL, $val = '') {
        $content = '';
        if( $var ) {
            if ( is_object( $val ) || is_array( $val ) ) {
                $wrapped = $this->wrapObjects( $val );
                $json = $this->toJSON( $this->replace_com_data( $wrapped ) );
                $json = $this->wrapValue( $json, $this->maxWidth, true );
                $content = $var.' = ' . $json . PHP_EOL;
            } elseif ( is_string($val)) {
                if( empty( $val )) {
                    $content = $var . ' = ' . PHP_EOL;
                } else {
                    $escaped = $this->escape_value( $this->replace_com_data( $val ) );
                    $wrapped = $this->wrapValue( $escaped, $this->maxWidth, false );
                    $content = $var . ' = "' . $wrapped . '"' . PHP_EOL;
                }
            } elseif ( is_int( $val ) || is_float( $val )) {
                $content = $var . ' = ' . (string)$val . PHP_EOL;
            } elseif ( is_bool( $val )) {
                $content = $var . ' = ' . ($val ? 'true' : 'false' ) . PHP_EOL;
            } elseif ( is_null( $val )) {
                $content = $var . ' = ' . PHP_EOL;
            } else {
                $escaped = $this->escape_value( $this->replace_com_data( $val ) );
                $wrapped = $this->wrapValue( $escaped, $this->maxWidth, false );
                $content = $var . ' = "' . $wrapped . '"' . PHP_EOL;
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
     *  Возвращает [часть до комментария (trim), сам комментарий с ';' или '#' в начале или NULL].
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
            if( !$inQuote && ( $ch === self::_SEP || $ch === self::_SEP_HASH ) ) {
                $before  = rtrim( substr( $line, 0, $i ) );
                $comment = trim( substr( $line, $i ) );
                return array( $before, $comment );
            }
        }
        return array( rtrim( $line ), NULL );
    }

    /** Parse ini file (comments) — только BEFORE, AFTER_EOL, AFTER_EMPTY.
     *  BEHIND-комментарии собираются в parseIniLines(), чтобы избежать дублирования.
     */
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

            if( $line[0] === self::_SEP || $line[0] === self::_SEP_HASH ) {
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

                    $section  = $newSection;
                    $lastVar  = '';
                    $lastKind = 'section';
                }
                continue;
            }

            if( preg_match('/^([^=]+)=(.*)$/', $line, $m) ) {
                $var = trim( $m[1] );
                $val = trim( $m[2] );

                $varKey  = $this->varKey( $section, $var );
                $prevKey = ( $lastKind === 'var' ) ? $this->varKey( $section, $lastVar )
                           : ( $lastKind === 'section' ? $this->sectionKey( $section ) : '' );

                $flush( $varKey, $prevKey, $lastKind, 'var' );

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

    /** Построчный парсер INI-файла с поддержкой многострочных строк и JSON.
     *  Собирает данные, "сырые" значения и inline BEHIND-комментарии.
     */
    private function parseIniLines( $content, $sections = TRUE ) {
        $lines = explode( PHP_EOL, $content );
        $data = array();
        $raw = array();
        $inlineComments = array();
        $currentSection = $sections ? self::_TOP : null; // <-- ВАЖНО: null для плоского режима
        $i = 0;
        $lineCount = count( $lines );

        while ( $i < $lineCount ) {
            $line = $lines[$i];
            $trimmed = trim( $line );

            if ( $trimmed === '' || $trimmed[0] === self::_SEP || $trimmed[0] === self::_SEP_HASH ) {
                $i++;
                continue;
            }

            if ( $trimmed[0] === '[' ) {
                if ( preg_match( '/^\[(.*?)\](.*)$/', $trimmed, $m ) ) {
                    $currentSection = trim( $m[1] );
                    if ( $currentSection === self::_TOP ) {
                        throw new \Exception(self::_ERR_SECTION_TOP);
                    }
                    if ( !isset( $data[ $currentSection ] ) ) {
                        $data[ $currentSection ] = array();
                        $raw[ $currentSection ] = array();
                    }
                    // BEHIND-комментарий для секции
                    $rest = trim( $m[2] );
                    if ( $rest !== '' && ( $rest[0] === self::_SEP || $rest[0] === self::_SEP_HASH ) ) {
                        $secKey = $this->sectionKey( $currentSection );
                        $inlineComments[ $secKey ][] = array( self::BEHIND => $rest . PHP_EOL );
                    }
                }
                $i++;
                continue;
            }

            if ( preg_match( '/^([^=]+)=(.*)$/', $trimmed, $m ) ) {
                $key = trim( $m[1] );
                $valuePart = trim( $m[2] );

                $isQuoted = ( isset( $valuePart[0] ) && $valuePart[0] === '"' );
                $isJson = ( isset( $valuePart[0] ) && ( $valuePart[0] === '{' || $valuePart[0] === '[' ) );

                if ( $isQuoted || $isJson ) {
                    $accum = $valuePart;
                    $inQuote = false;
                    $balance = 0;
                    $escaped = false;
                    $closed = false;

                    $len = strlen( $accum );
                    for ( $j = 0; $j < $len; $j++ ) {
                        $ch = $accum[$j];
                        if ( $escaped ) { $escaped = false; continue; }
                        if ( $ch === '\\' ) { $escaped = true; continue; }
                        if ( $ch === '"' ) { $inQuote = !$inQuote; continue; }
                        if ( !$inQuote ) {
                            if ( $ch === '{' || $ch === '[' ) $balance++;
                            elseif ( $ch === '}' || $ch === ']' ) $balance--;
                        }
                    }
                    if ( !$inQuote && $balance <= 0 ) {
                        $closed = true;
                    }

                    if ( !$closed ) {
                        $i++;
                        while ( $i < $lineCount ) {
                            $nextLine = $lines[$i];
                            $trimmedNext = trim( $nextLine );

                            $insideString = $inQuote;

                            if ( $isJson && !$insideString && $trimmedNext !== ''
                                 && ( $trimmedNext[0] === self::_SEP || $trimmedNext[0] === self::_SEP_HASH ) ) {
                                $i++;
                                continue;
                            }

                            $accum .= PHP_EOL . $nextLine;

                            $inQuote = false; $balance = 0; $escaped = false;
                            $len = strlen( $accum );
                            for ( $j = 0; $j < $len; $j++ ) {
                                $ch = $accum[$j];
                                if ( $escaped ) { $escaped = false; continue; }
                                if ( $ch === '\\' ) { $escaped = true; continue; }
                                if ( $ch === '"' ) { $inQuote = !$inQuote; continue; }
                                if ( !$inQuote ) {
                                    if ( $ch === '{' || $ch === '[' ) $balance++;
                                    elseif ( $ch === '}' || $ch === ']' ) $balance--;
                                }
                            }
                            if ( !$inQuote && $balance <= 0 ) {
                                break;
                            }
                            $i++;
                        }
                    }

                    list( $accumClean, $inlineCom ) = $this->splitInlineComment( $accum );
                    $value = $accumClean;
                    if ( $inlineCom !== NULL && $inlineCom !== '' ) {
                        $varKey = $this->varKey( $currentSection, $key );
                        $inlineComments[ $varKey ][] = array( self::BEHIND => $inlineCom . PHP_EOL );
                    }
                } else {
                    list( $valPart, $inlineCom ) = $this->splitInlineComment( $valuePart );
                    $value = $valPart;
                    if ( $inlineCom !== NULL && $inlineCom !== '' ) {
                        $varKey = $this->varKey( $currentSection, $key );
                        $inlineComments[ $varKey ][] = array( self::BEHIND => $inlineCom . PHP_EOL );
                    }
                }

                $rawValue = $value;
                if ( strlen( $rawValue ) >= 2 && $rawValue[0] === '"' && substr( $rawValue, -1 ) === '"' ) {
                    $rawValue = substr( $rawValue, 1, -1 );
                }

                // =========================================================
                // ВАЖНО: Если $currentSection === null, значит плоский режим
                // =========================================================
                if ( $currentSection === null ) {
                    $data[ $key ] = $value;
                    $raw[ $key ] = $rawValue;
                } else {
                    $data[ $currentSection ][ $key ] = $value;
                    $raw[ $currentSection ][ $key ] = $rawValue;
                }
                $i++;
                continue;
            }

            $i++;
        }

        return array( 'data' => $data, 'raw' => $raw, 'comments' => $inlineComments );
    }

    /** Parse ini file read() */
    function read( $file = NULL, $sections = NULL ) {
        if ( $sections !== NULL ) {
            $this->sections = $sections;
        }
        if( empty( $file ) ) { throw new \Exception('ini file path is not specified.'); }
        $this->file = ($file) ? $file : $this->file;
        if( !file_exists( $this->file )) { throw new \Exception('ini file not found: ' . $this->file); }
        if( is_dir( $this->file ) ) { throw new \Exception('The specified path is a directory, not a file: ' . $this->file); }
        if( !is_readable( $this->file ) ) { throw new \Exception('Permission denied. Cannot read file: ' . $this->file); }

        $fileContent = file_get_contents( realpath( $this->file ) );

        $parsed = $this->parseIniLines( $fileContent, $this->sections );

        // =========================================================
        // ИСПРАВЛЕНИЕ ДЛЯ ПЛОСКОГО РЕЖИМА (без секций)
        // =========================================================
        if ( !$this->sections ) {
            $lines = explode( PHP_EOL, $fileContent );
            $result = array();
            foreach ( $lines as $line ) {
                $line = trim( $line );
                if ( $line === '' || $line[0] === ';' || $line[0] === '#' ) continue;
                if ( preg_match( '/^([^=]+)=(.*)$/', $line, $m ) ) {
                    $key = trim( $m[1] );
                    $val = trim( $m[2] );
                    if ( strlen( $val ) >= 2 && $val[0] === '"' && substr( $val, -1 ) === '"' ) {
                        $val = substr( $val, 1, -1 );
                    }
                    if ( is_string( $val ) && ( strpos( $val, '{' ) === 0 || strpos( $val, '[' ) === 0 ) ) {
                        $decoded = json_decode( $val, true );
                        if ( $decoded !== null ) $val = $decoded;
                    } else {
                        if ( $val === 'true' ) $val = true;
                        elseif ( $val === 'false' ) $val = false;
                        elseif ( is_numeric( $val ) ) $val = ( strpos( $val, '.' ) !== false ) ? (float) $val : (int) $val;
                    }
                    $result[ $key ] = $val;
                }
            }
            $this->data = $result;
            return $this->data;
        }

        // =========================================================
        // ОБЫЧНЫЙ РЕЖИМ (с секциями)
        // =========================================================
        $this->data = $parsed['data'];

        $commentsFromSelect = $this->selectComm( explode( PHP_EOL, $fileContent ) );
        $commentsFromParse = $parsed['comments'];

        $this->comments = $commentsFromSelect;
        foreach ( $commentsFromParse as $varKey => $comList ) {
            if ( !isset( $this->comments[ $varKey ] ) ) {
                $this->comments[ $varKey ] = array();
            }
            foreach ( $comList as $com ) {
                $this->comments[ $varKey ][] = $com;
            }
        }

        $rawValues = $parsed['raw'];
        $this->check = $this->data;

        foreach( $this->check as $section_var => $section_val ) {
            if( is_array( $section_val ) ) {
                // Обрабатываем все секции (включая '@' для верхнего уровня)
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
                // Обработка плоских переменных (на случай, если они попали в корень)
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
    function write( $file = NULL, $data = array(), $sections = NULL, $comments = array(), $maxWidth = NULL ) {

        $this->data     = (!empty($data))     ? $data     : $this->data;
        $this->file     = ($file)             ? $file     : $this->file;
        if ( $sections !== NULL ) { 
           $this->sections = $sections; 
        }
        $this->comments = (!empty($comments)) ? $comments : $this->comments;
        if ( $maxWidth !== NULL ) {
            $this->maxWidth = (int)$maxWidth;
        }
        $content = '';

        if ($this->sections) {
            foreach ($this->data as $section => $data) {
                // Ключ '@' — это переменные верхнего уровня, выводим их без заголовка секции
                $isTop = ( $section === self::_TOP );
                
                // Если случайно создали секцию с именем '@' (не массив), это ошибка
                if ( $isTop && !is_array( $data ) ) {
                    throw new \Exception(self::_ERR_SECTION_TOP);
                }

                $line   = ( !$isTop ? '[' . $section . ']' : '' );
                $keyCom = ( !$isTop ? $this->sectionKey( $section ) : self::_TOP );

                if( isset( $this->comments[ $keyCom ] ) ) {
                    $content = $this->before( $this->comments[ $keyCom ], $content );
                }

                // Если это не верхний уровень, и предыдущий контент не закончился переводом строки
                if( !$isTop && $content !== '' && !$this->endsWithEol( $content ) ) {
                    $content .= PHP_EOL;
                }

                // Выводим заголовок секции только если это не верхний уровень
                if( !$isTop ) {
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
            // Режим без секций — просто выводим все данные, игнорируя структуру
            foreach ($this->data as $key => $val) {
                // Если данные пришли из массива '@', разворачиваем их
                if ( $key === self::_TOP && is_array( $val ) ) {
                    foreach ($val as $topKey => $topVal) {
                        $keyCom = $this->varKey( self::_TOP, $topKey );
                        if( isset( $this->comments[ $keyCom ] ) ) {
                            $content = $this->before( $this->comments[ $keyCom ], $content );
                            if( !$this->endsWithEol( $content ) ) $content .= PHP_EOL;
                        }
                        $content .= $this->setValue( $topKey, $topVal );
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
                        if( !$this->endsWithEol( $content ) ) $content .= PHP_EOL;
                    }
                    continue;
                }

                // Обычные плоские ключи
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