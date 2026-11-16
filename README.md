# videovv/IniFile

Simple PHP class to read and write INI files with full support for comments, JSON values, and flat mode.

[![Latest Release](https://img.shields.io/github/v/release/Video-VV/ini-file)](https://github.com/Video-VV/ini-file/releases)

**English** | [Русский](README.ru.md)

- Ready for PHP 8.4.
- PSR-4 namespace: `videovv\IniFile`
- Error handling: exceptions instead of warnings.
- Support for comments associated with INI entries: `BEFORE`, `BEHIND`, `AFTER`, `AFTER_EOL`, `AFTER_EMPTY`.
- Support for JSON values: associative arrays are represented as arrays, while `stdClass` objects are represented as objects prefixed with `"\u0000obj"`.
- Escaping of `"` and `\` characters within string values.
- Safe handling of the `;` character inside JSON values.
- Support for `#` as a comment marker alongside `;`.
- Support for multiline string and JSON values.
- Flat mode support (`sections = false`) for reading and writing without sections.

## Installation

**Option 1 — Composer (recommended):**

```bash
composer require videovv/ini-file
```

Then just use the class:

```php
use videovv\IniFile\IniFile;
```

**Option 2 — without Composer:**

If you don't use Composer, copy `IniFile.php` into your project and connect it manually:

```php
require __DIR__ . '/path/to/IniFile.php';

use videovv\IniFile\IniFile;
```

Or register your own PSR-4 autoloader for the `videovv\IniFile` namespace:

```php
spl_autoload_register(function ($class) {
    $prefix = 'videovv\\IniFile\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/path/to/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
```

## Quick start

```php
use videovv\IniFile\IniFile;

$ini = new IniFile();
$ini->data = [
    'database' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'debug'   => true,
        'options' => ['charset' => 'utf8mb4', 'persistent' => false],
    ],
];

$ini->addComment('Database settings', 'database', IniFile::BEFORE);
$ini->addComment('Default port', 'database:port', IniFile::BEHIND);
$ini->write('config.ini');
```

Result:

```ini
; Database settings
[database]
host = "localhost"
port = 3306 ; Default port
debug = true
options = {"charset":"utf8mb4","persistent":false}
```

## Reading

```php
$ini = new IniFile('config.ini');

// or
$ini = new IniFile();
$ini->read('config.ini');

print_r($ini->data);
print_r($ini->comments);
```

Constructor accepts the same arguments as `read()`:

```php
$ini = new IniFile($file, $sections = true);
```

## Mixed format: top-level variables and sections

An INI file can contain variables **before** the first section (top-level) and variables **inside** sections. Both are read and written correctly.

```ini
; top-level variables
debug = true
version = "1.0.2"

[first_section]
animal = "cat"
port = 3306

[second_section]
user = "admin"
```

After `read()`, top-level variables are stored under the special key `@`:

```php
$ini = new IniFile('mixed.ini');

// top-level variables
$ini->data['@']['debug'];   // true
$ini->data['@']['version']; // "1.0.2"

// section variables
$ini->data['first_section']['animal']; // "cat"
$ini->data['second_section']['user'];  // "admin"
```

When writing, top-level variables are output first, without a section header:

```php
$ini->data['@']['new_top'] = 'value';
$ini->write('mixed_new.ini');
```

Result:

```ini
debug = true
version = "1.0.2"
new_top = "value"

[first_section]
animal = "cat"
port = 3306

[second_section]
user = "admin"
```

Comments for top-level variables use the key **without** a colon:

```php
$ini->addComment('Before debug', 'debug', IniFile::BEFORE);
$ini->addComment('Top-level comment', 'debug', IniFile::BEHIND);
```

For section variables, use `'section:var'`:

```php
$ini->addComment('Before animal', 'first_section:animal', IniFile::BEFORE);
```

If you want to read the file **without** sections (flat format), pass `false` as the second argument:

```php
$ini->read('mixed.ini', false); // sections are ignored, all keys are flat
```

## Data types

| PHP value                | Written as              | Read back as           |
|--------------------------|-------------------------|------------------------|
| `'string'`               | `"string"`              | `string`               |
| `123`, `1.5`             | `123`, `1.5`            | `int` / `float`        |
| `true` / `false`         | `true` / `false`        | `bool`                 |
| `null`                   | (empty)                 | `''`                   |
| `['a'=>1,'b'=>2]`        | `{"a":1,"b":2}`         | `array`                |
| `[1,2,3]`                | `[1,2,3]`               | `array`                |
| `new stdClass`           | `{"\u0000obj":{...}}`   | `stdClass`             |

`stdClass` objects are marked with a unique key `"\x00obj"` so that they can be distinguished from associative arrays after reading.

## Booleans

```php
$ini->data['section']['enabled']  = true;
$ini->data['section']['disabled'] = false;
```

Written as:

```ini
enabled = true
disabled = false
```

After `read()` both are real PHP booleans:

```php
var_dump($ini->data['section']['enabled']);  // bool(true)
var_dump($ini->data['section']['disabled']); // bool(false)
```

## Objects

```php
$user = new stdClass();
$user->name = 'John';
$user->age  = 25;

$ini->data['section']['user'] = $user;
```

Written as (the object is wrapped in a marker `"\u0000obj"`):

```ini
user = {"\u0000obj":{"name":"John","age":25}}
```

After `read()` the value is `stdClass` again:

```php
$ini2 = new IniFile('test.ini');
echo $ini2->data['section']['user']->name; // John
echo $ini2->data['section']['user']->age;  // 25
```

Nested objects and objects inside arrays are preserved too:

```php
$address = new stdClass();
$address->city = 'Moscow';

$user = new stdClass();
$user->name    = 'John';
$user->address = $address;          // nested object
$user->roles   = ['admin', 'user']; // array with strings

$ini->data['section']['user'] = $user;
$ini->data['section']['users'] = [
    'first'  => $user,                // object inside associative array
    'second' => $address,             // another object
];
```

After `read()`:

```php
$ini2->data['section']['user']->address->city;           // 'Moscow'
$ini2->data['section']['user']->roles[0];                // 'admin'
$ini2->data['section']['users']['first']->address->city; // 'Moscow'
$ini2->data['section']['users']['second']->city;         // 'Moscow'
```

### Associative array vs object

```php
$ini->data['section']['as_array']  = ['a' => 1, 'b' => 2]; // stays array
$ini->data['section']['as_object'] = (object)['a' => 1, 'b' => 2]; // stays stdClass

$ini->write('test.ini');
$ini2 = new IniFile('test.ini');

is_array($ini2->data['section']['as_array']);   // true
is_object($ini2->data['section']['as_object']); // true

$ini2->data['section']['as_array']['a'];  // 1
$ini2->data['section']['as_object']->a;   // 1
```

## Comments

Five comment types are supported:

| Constant                | Position                                          |
|-------------------------|---------------------------------------------------|
| `IniFile::BEFORE`       | On a separate line before section/variable        |
| `IniFile::BEHIND`       | On the same line, right after the value           |
| `IniFile::AFTER`        | On the same line, after `BEHIND`                  |
| `IniFile::AFTER_EOL`    | On the next line after section/variable           |
| `IniFile::AFTER_EMPTY`  | Empty line after section/variable                 |

### All comment types for one variable

```php
$ini->addComment('before',     'section:var', IniFile::BEFORE);
$ini->addComment('behind',     'section:var', IniFile::BEHIND);
$ini->addComment('after',      'section:var', IniFile::AFTER);
$ini->addComment('after_eol',  'section:var', IniFile::AFTER_EOL);
$ini->addComment('',           'section:var', IniFile::AFTER_EMPTY);
```

Result:

```ini
; before
var = "value" ; behind ; after
; after_eol

```

### Comments for a section

```php
$ini->addComment('',            'section', IniFile::BEFORE); // empty line before section
$ini->addComment('Section one', 'section', IniFile::BEFORE);
$ini->addComment('inline',      'section', IniFile::BEHIND);
```

Result:

```ini

; Section one
[section] ; inline
```

### Comments for a top-level variable (no section)

```php
$ini->addComment('top before', 'key', IniFile::BEFORE);
$ini->addComment('top behind', 'key', IniFile::BEHIND);
```

Result:

```ini
; top before
key = "value" ; top behind
```

### delComment

```php
$ini->delComment('section:var');                  // remove all comments of the variable
$ini->delComment('section:var', IniFile::BEHIND); // remove only BEHIND comments
$ini->delComment('section');                      // remove all comments of the section
$ini->delComment();                               // remove all comments
```

## Escaping

String values are escaped for `"` and `\`:

```php
$ini->data['section']['path']  = 'C:\temp\file.txt';
$ini->data['section']['quote'] = 'a "b" c';
```

Written as:

```ini
path = "C:\\temp\\file.txt"
quote = "a \"b\" c"
```

Semicolons inside JSON values are replaced with `Ow==` (base64 of `;`) during writing and restored during reading, so that `;` inside a JSON string does not break parsing.

## Multiline strings

You can write string values that span multiple lines. The parser preserves line breaks inside the string.

```php
$ini = new IniFile();
$ini->data = [
    'section' => [
        'description' => "This is a long description
that spans multiple lines
and should be preserved.",
    ],
];

$ini->write('multiline.ini');
```

Result:

```ini
[section]
description = "This is a long description
that spans multiple lines
and should be preserved."
```

After `read()` the value is a single string with `\n` line breaks:

```php
$ini2 = new IniFile('multiline.ini');
echo $ini2->data['section']['description'];
// Output:
// This is a long description
// that spans multiple lines
// and should be preserved.
```

**Note:** If a string contains `#` or `;` inside a multiline value, they are treated as part of the string (not as comment markers), because the parser knows the string is not closed yet.

### Multiline JSON values

JSON values can also span multiple lines:

```php
$ini = new IniFile();
$ini->data = [
    'section' => [
        'config' => [
            'name' => 'example',
            'values' => [1, 2, 3],
        ],
    ],
];

$ini->write('json_multiline.ini');
```

Result (pretty-printed):

```ini
[section]
config = {
    "name": "example",
    "values": [1, 2, 3]
}
```

After `read()` the value is the original array.

**Note:** To enable pretty-printing (multiline JSON), set `$ini->maxWidth` to a positive value, or pass it as the last argument to `write()`:

```php
$ini->write('json_multiline.ini', [], true, [], 80);
```

## Writing without sections

```php
$ini = new IniFile();
$ini->sections = false;
$ini->data = [
    'key1' => 'value1',
    'key2' => 42,
    'key3' => true,
];
$ini->write('flat.ini');
```

Result:

```ini
key1 = "value1"
key2 = 42
key3 = true
```

## Exceptions

`read()` throws `\Exception` in the following cases:

- file path is not specified;
- file does not exist;
- path is a directory;
- file is not readable.

`write()` throws `\Exception` if the file cannot be opened for writing or if writing fails.

```php
try {
    $ini = new IniFile('missing.ini');
} catch (\Exception $e) {
    echo $e->getMessage();
}
```

## Full example

```php
use videovv\IniFile\IniFile;

$obj = new stdClass();
$obj->name = 'John';
$obj->age  = 25;

$ini = new IniFile();
$ini->data = [
    'first_section' => [
        'animal'    => 'HE SAID "WOOF"',
        'path'      => 'C:\temp\file.txt',
        'quote'     => 'a "b" c \\ d',
        'note'      => '\'hello\'; world "x"',
        'semicolon' => 'a; b; c',
        'enabled'   => true,
        'disabled'  => false,
    ],
    'second_section' => [
        'array_json' => ['word' => 'hello', 'number' => 200, 'boolean' => true, 'object' => $obj],
        'user'       => $obj,
    ],
];

// All comment types for a variable
$ini->addComment('Comment before animal', 'first_section:animal', IniFile::BEFORE);
$ini->addComment('Comment behind animal', 'first_section:animal', IniFile::BEHIND);
$ini->addComment('Comment after animal',  'first_section:animal', IniFile::AFTER);
$ini->addComment('Comment after_eol',     'first_section:animal', IniFile::AFTER_EOL);
$ini->addComment('',                      'first_section:animal', IniFile::AFTER_EMPTY);

// Comments for a section
$ini->addComment('',                'second_section', IniFile::BEFORE);
$ini->addComment('SECOND SECTION',  'second_section', IniFile::BEFORE);

$ini->write('test.ini');
```

Result:

```ini
[first_section]
; Comment before animal
animal = "HE SAID \"WOOF\"" ; Comment behind animal ; Comment after animal
; Comment after_eol

path = "C:\\temp\\file.txt"
quote = "a \"b\" c \\ d"
note = "'hello'Ow== world \"x\""
semicolon = "aOw== bOw== c"
enabled = true
disabled = false

; SECOND SECTION
[second_section]
array_json = {"word":"hello","number":200,"boolean":true,"object":{"\u0000obj":{"name":"John","age":25}}}
user = {"\u0000obj":{"name":"John","age":25}}
```

After `read()`:

```php
$ini2 = new IniFile('test.ini');

// booleans
var_dump($ini2->data['first_section']['enabled']);  // bool(true)
var_dump($ini2->data['first_section']['disabled']); // bool(false)

// associative array stays array
is_array($ini2->data['second_section']['array_json']); // true
$ini2->data['second_section']['array_json']['word'];    // 'hello'

// stdClass stays object
is_object($ini2->data['second_section']['user']);       // true
$ini2->data['second_section']['user']->name;            // 'John'

// nested object inside array
is_object($ini2->data['second_section']['array_json']['object']); // true
$ini2->data['second_section']['array_json']['object']->age;        // 25
```

## License

MIT
