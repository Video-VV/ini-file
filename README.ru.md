# videovv/IniFile

Простой PHP-класс для чтения и записи INI-файлов с полной поддержкой комментариев и JSON-значений.

[English](README.md) | **Русский**

- Готов к PHP 8.4
- PSR-4 namespace: `videovv\IniFile`
- Обработка ошибок: исключения вместо предупреждений
- Комментарии для переменных в ini файле: `BEFORE`, `BEHIND`, `AFTER`, `AFTER_EOL`, `AFTER_EMPTY`
- Поддержка как JSON-значения: для ассоциативных массивов, они остаются массивами и для объектов `stdClass` — они объекты с начальной меткой "\u0000obj"
- Экранирование символов `"` и `\` в строковых значениях
- Безопасная обработка символа `;` внутри JSON-значений

## Установка

**Вариант 1 — Composer (рекомендуется):**

```bash
composer require videovv/ini-file
```

Дальше просто используйте класс:

```php
use videovv\IniFile\IniFile;
```

**Вариант 2 — без Composer:**

Если вы не используете Composer, скопируйте `IniFile.php` в проект и подключите вручную:

```php
require __DIR__ . '/path/to/IniFile.php';

use videovv\IniFile\IniFile;
```

Или зарегистрируйте свой PSR-4 автозагрузчик для namespace `videovv\IniFile`:

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

## Быстрый старт

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

$ini->addComment('Настройки базы данных', 'database', IniFile::BEFORE);
$ini->addComment('Порт по умолчанию', 'database:port', IniFile::BEHIND);
$ini->write('config.ini');
```

Результат:

```ini
; Настройки базы данных
[database]
host = "localhost"
port = 3306 ; Порт по умолчанию
debug = true
options = {"charset":"utf8mb4","persistent":false}
```

## Чтение

```php
$ini = new IniFile('config.ini');

// или
$ini = new IniFile();
$ini->read('config.ini');

print_r($ini->data);
print_r($ini->comments);
```

Конструктор принимает те же аргументы, что и `read()`:

```php
$ini = new IniFile($file, $sections = true);
```

## Смешанный формат: переменные верхнего уровня и секции

INI-файл может содержать переменные **до** первой секции (верхний уровень) и переменные **внутри** секций. И то, и другое читается и записывается корректно.

```ini
; переменные верхнего уровня
debug = true
version = "1.0.2"

[first_section]
animal = "cat"
port = 3306

[second_section]
user = "admin"
```

После чтения переменные верхнего уровня складываются под специальный ключ `@`:

```php
$ini = new IniFile('mixed.ini');

// переменные верхнего уровня
$ini->data['@']['debug'];   // true
$ini->data['@']['version']; // "1.0.2"

// переменные секций
$ini->data['first_section']['animal']; // "cat"
$ini->data['second_section']['user'];  // "admin"
```

При записи переменные верхнего уровня выводятся первыми, без заголовка секции:

```php
$ini->data['@']['new_top'] = 'value';
$ini->write('mixed_new.ini');
```

Результат:

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

Комментарии для переменных верхнего уровня используют ключ **без** двоеточия:

```php
$ini->addComment('Перед debug', 'debug', IniFile::BEFORE);
$ini->addComment('Комментарий в строке', 'debug', IniFile::BEHIND);
```

Для переменных секций используйте `'section:var'`:

```php
$ini->addComment('Перед animal', 'first_section:animal', IniFile::BEFORE);
```

Если нужно прочитать файл **без** секций (плоский формат), передайте `false` вторым аргументом:

```php
$ini->read('mixed.ini', false); // секции игнорируются, все ключи плоские
```

## Типы данных

| PHP-значение            | Записывается как        | Читается как           |
|-------------------------|-------------------------|------------------------|
| `'string'`              | `"string"`              | `string`               |
| `123`, `1.5`            | `123`, `1.5`            | `int` / `float`        |
| `true` / `false`        | `true` / `false`        | `bool`                 |
| `null`                  | (пусто)                 | `''`                   |
| `['a'=>1,'b'=>2]`       | `{"a":1,"b":2}`         | `array`                |
| `[1,2,3]`               | `[1,2,3]`               | `array`                |
| `new stdClass`          | `{"\u0000obj":{...}}`   | `stdClass`             |

Объекты `stdClass` помечаются уникальным ключом `"\x00obj"`, чтобы после чтения их можно было отличить от ассоциативных массивов.

## Boolean

```php
$ini->data['section']['enabled']  = true;
$ini->data['section']['disabled'] = false;
```

Записывается как:

```ini
enabled = true
disabled = false
```

После чтения — настоящие PHP-булевы значения:

```php
var_dump($ini->data['section']['enabled']);  // bool(true)
var_dump($ini->data['section']['disabled']); // bool(false)
```

## Объекты

```php
$user = new stdClass();
$user->name = 'Иван';
$user->age  = 25;

$ini->data['section']['user'] = $user;
```

Записывается как (объект оборачивается в маркер `"\u0000obj"`):

```ini
user = {"\u0000obj":{"name":"Иван","age":25}}
```

После чтения значение снова `stdClass`:

```php
$ini2 = new IniFile('test.ini');
echo $ini2->data['section']['user']->name; // Иван
echo $ini2->data['section']['user']->age;  // 25
```

Вложенные объекты и объекты внутри массивов тоже сохраняются:

```php
$address = new stdClass();
$address->city = 'Moscow';

$user = new stdClass();
$user->name    = 'Иван';
$user->address = $address;          // вложенный объект
$user->roles   = ['admin', 'user']; // массив строк

$ini->data['section']['user'] = $user;
$ini->data['section']['users'] = [
    'first'  => $user,                // объект внутри ассоциативного массива
    'second' => $address,             // ещё один объект
];
```

После чтения:

```php
$ini2->data['section']['user']->address->city;           // 'Moscow'
$ini2->data['section']['user']->roles[0];                // 'admin'
$ini2->data['section']['users']['first']->address->city; // 'Moscow'
$ini2->data['section']['users']['second']->city;         // 'Moscow'
```

### Ассоциативный массив vs объект

```php
$ini->data['section']['as_array']  = ['a' => 1, 'b' => 2]; // остаётся массивом
$ini->data['section']['as_object'] = (object)['a' => 1, 'b' => 2]; // остаётся stdClass

$ini->write('test.ini');
$ini2 = new IniFile('test.ini');

is_array($ini2->data['section']['as_array']);   // true
is_object($ini2->data['section']['as_object']); // true

$ini2->data['section']['as_array']['a'];  // 1
$ini2->data['section']['as_object']->a;   // 1
```

## Комментарии

Поддерживаются пять типов комментариев:

| Константа                | Положение                                          |
|--------------------------|----------------------------------------------------|
| `IniFile::BEFORE`        | На отдельной строке перед секцией/переменной       |
| `IniFile::BEHIND`        | В той же строке, сразу после значения              |
| `IniFile::AFTER`         | В той же строке, после `BEHIND`                    |
| `IniFile::AFTER_EOL`     | На следующей строке после секции/переменной        |
| `IniFile::AFTER_EMPTY`   | Пустая строка после секции/переменной              |

### Все типы комментариев для одной переменной

```php
$ini->addComment('before',     'section:var', IniFile::BEFORE);
$ini->addComment('behind',     'section:var', IniFile::BEHIND);
$ini->addComment('after',      'section:var', IniFile::AFTER);
$ini->addComment('after_eol',  'section:var', IniFile::AFTER_EOL);
$ini->addComment('',           'section:var', IniFile::AFTER_EMPTY);
```

Результат:

```ini
; before
var = "value" ; behind ; after
; after_eol

```

### Комментарии для секции

```php
$ini->addComment('',            'section', IniFile::BEFORE); // пустая строка перед секцией
$ini->addComment('Section one', 'section', IniFile::BEFORE);
$ini->addComment('inline',      'section', IniFile::BEHIND);
```

Результат:

```ini

; Section one
[section] ; inline
```

### Комментарии для переменной верхнего уровня (без секции)

```php
$ini->addComment('top before', 'key', IniFile::BEFORE);
$ini->addComment('top behind', 'key', IniFile::BEHIND);
```

Результат:

```ini
; top before
key = "value" ; top behind
```

### delComment

```php
$ini->delComment('section:var');                  // удалить все комментарии переменной
$ini->delComment('section:var', IniFile::BEHIND); // удалить только BEHIND
$ini->delComment('section');                      // удалить все комментарии секции
$ini->delComment();                               // удалить все комментарии
```

## Экранирование

Строковые значения экранируются для `"` и `\`:

```php
$ini->data['section']['path']  = 'C:\temp\file.txt';
$ini->data['section']['quote'] = 'a "b" c';
```

Записывается как:

```ini
path = "C:\\temp\\file.txt"
quote = "a \"b\" c"
```

Точки с запятой внутри JSON-значений заменяются на `Ow==` (base64 от `;`) при записи и восстанавливаются при чтении, чтобы `;` внутри JSON-строки не ломала парсинг.

## Запись без секций

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

Результат:

```ini
key1 = "value1"
key2 = 42
key3 = true
```

## Исключения

`read()` выбрасывает `\Exception` в случаях:

- путь к файлу не указан;
- файл не существует;
- путь указывает на директорию;
- файл недоступен для чтения.

`write()` выбрасывает `\Exception`, если файл не удаётся открыть для записи или запись не удалась.

```php
try {
    $ini = new IniFile('missing.ini');
} catch (\Exception $e) {
    echo $e->getMessage();
}
```

## Полный пример

```php
use videovv\IniFile\IniFile;

$obj = new stdClass();
$obj->name = 'Иван';
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

// Все типы комментариев для переменной
$ini->addComment('Комментарий перед animal', 'first_section:animal', IniFile::BEFORE);
$ini->addComment('Комментарий после animal', 'first_section:animal', IniFile::BEHIND);
$ini->addComment('Ещё комментарий',          'first_section:animal', IniFile::AFTER);
$ini->addComment('На следующей строке',      'first_section:animal', IniFile::AFTER_EOL);
$ini->addComment('',                         'first_section:animal', IniFile::AFTER_EMPTY);

// Комментарии для секции
$ini->addComment('',               'second_section', IniFile::BEFORE);
$ini->addComment('SECOND SECTION', 'second_section', IniFile::BEFORE);

$ini->write('test.ini');
```

Результат:

```ini
[first_section]
; Комментарий перед animal
animal = "HE SAID \"WOOF\"" ; Комментарий после animal ; Ещё комментарий
; На следующей строке

path = "C:\\temp\\file.txt"
quote = "a \"b\" c \\ d"
note = "'hello'Ow== world \"x\""
semicolon = "aOw== bOw== c"
enabled = true
disabled = false

; SECOND SECTION
[second_section]
array_json = {"word":"hello","number":200,"boolean":true,"object":{"\u0000obj":{"name":"Иван","age":25}}}
user = {"\u0000obj":{"name":"Иван","age":25}}
```

После чтения:

```php
$ini2 = new IniFile('test.ini');

// boolean
var_dump($ini2->data['first_section']['enabled']);  // bool(true)
var_dump($ini2->data['first_section']['disabled']); // bool(false)

// ассоциативный массив остаётся массивом
is_array($ini2->data['second_section']['array_json']); // true
$ini2->data['second_section']['array_json']['word'];    // 'hello'

// stdClass остаётся объектом
is_object($ini2->data['second_section']['user']);       // true
$ini2->data['second_section']['user']->name;            // 'Иван'

// вложенный объект внутри массива
is_object($ini2->data['second_section']['array_json']['object']); // true
$ini2->data['second_section']['array_json']['object']->age;        // 25
```

## Лицензия

MIT