# Простой PHP класс для работы с INI (Готов к PHP 8.4)

[![Latest Release](https://img.shields.io/github/v/release/Video-VV/ini-file)](https://github.com/Video-VV/ini-file/releases)

[🇬🇧 Read in English](README.md)

Простой, надежный и современный PHP-класс для управления конфигурационными файлами INI (чтение и запись). 
Пакет полностью совместим с PHP 8.4, поддерживает PSR-4 пространства имен и корректно обрабатывает сложные строковые значения, содержащие кавычки (`"`), обратные слеши (`\`) и точки с запятой (`;`), не нарушая синтаксис INI.

## Требования

- PHP 8.0 или выше (Полностью протестирован на PHP 8.4)
- Composer для установки

## Установка

Установите пакет через Composer:

```bash
composer require videovv/ini-file
```

## Базовое использование

### Чтение INI-файла

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use videovv\IniFile\IniFile;

try {
    // Парсим settings.ini
    $ini = new IniFile('settings.ini');
    
    // Выводим полученные данные в виде массива
    echo '<pre>';
    print_r($ini->data);
    echo '</pre>';
    
} catch (\Exception $e) {
    echo 'Ошибка: ' . $e->getMessage();
}
```

### Запись и обновление INI-файла

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use videovv\IniFile\IniFile;

try {
    $ini = new IniFile('settings.ini');
    
    // Обновление существующего параметра
    $ini->data['first_section']['animal'] = 'HORSE';
    
    // Добавление нового параметра в существующую секцию
    $ini->data['first_section']['new_key'] = 'new_value';
    
    // Добавление абсолютно новой секции
    $ini->data['new_section']['some_key'] = 123;
    
    // Сохранение настроек обратно в файл
    $ini->write();
    
} catch (\Exception $e) {
    echo 'Ошибка: ' . $e->getMessage();
}
```

## Продвинутые возможности

### Работа с массивами (JSON-кодирование)
Класс автоматически обрабатывает массивы и объекты, кодируя их в JSON-строки. Это идеально подходит для хранения сложных конфигурационных данных в плоском INI-файле.

```php
$ini->data['fifth_section']['my_array_json'] = ['a' => 200, 'bb' => 'test'];
$ini->write();

// Вывод в INI-файле:
// [fifth_section]
// my_array_json = {"a":200,"bb":"test"}
```
*При обратном чтении файла JSON автоматически декодируется обратно в PHP-массив.*

### Управление комментариями
Вы можете программно добавлять комментарии в ваши INI-файлы.

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use videovv\IniFile\IniFile;

$ini = new IniFile('settings.ini');

// Добавить комментарий сразу после переменной
$ini->addComment('Это комментарий с "кавычками" и ; точками с запятой', 'first_section:animal');

// Добавить комментарий перед переменной
$ini->addComment('Это комментарий сверху', 'first_section:animal', IniFile::BEFORE);

// Удалить комментарий
$ini->delComment('first_section:animal');

$ini->write();
```

### Удаление секций и ключей

```php
// Удалить конкретный ключ
unset($ini->data['first_section']['animal']);

// Удалить целую секцию
unset($ini->data['new_section']);

$ini->write();
```

### Запись в новый файл
Вы можете легко сохранить текущие данные в другой файл и продолжить работу с ним:

```php
// Сохранить в новый файл и переключить внутренний указатель на него
$ini->write('settings-backup.ini');
```

## Обработка ошибок

Пакет выбрасывает стандартные PHP-исключения `\Exception`. Это позволяет корректно перехватывать ошибки:

```php
try {
    $ini = new IniFile('/путь/к/несуществующему/файлу.ini');
} catch (\Exception $e) {
    echo 'Перехвачена ошибка: ' . $e->getMessage();
}
```

## Лицензия

Этот проект распространяется под лицензией MIT.
