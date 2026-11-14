# Simple PHP INI Class (PHP 8.4 Ready)

[![Latest Release](https://img.shields.io/github/v/release/Video-VV/ini-file)](https://github.com/Video-VV/ini-file/releases)

[🇷🇺 Читать на русском (Read in Russian)](README.ru.md)

A simple, robust, and modern PHP class to manage INI configuration files (read and write). 
This package is fully compatible with PHP 8.4, supports PSR-4 namespaces, and correctly handles complex string values containing quotes (`"`), backslashes (`\`), and semicolons (`;`) without breaking the INI syntax.

## Requirements

- PHP 8.0 or higher (Fully tested on PHP 8.4)
- Composer for installation

## Installation

You can install the package via Composer:

```bash
composer require videovv/ini-file
```

## Basic Usage

### Reading an ini file

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use videovv\IniFile\IniFile;

try {
    // Parse settings.ini
    $ini = new IniFile('settings.ini');
    
    // Print parsed data as an array
    echo '<pre>';
    print_r($ini->data);
    echo '</pre>';
    
} catch (\Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
```

### Writing and Updating an INI file

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use videovv\IniFile\IniFile;

try {
    $ini = new IniFile('settings.ini');
    
    // Update an existing setting
    $ini->data['first_section']['animal'] = 'HORSE';
    
    // Add a new setting to an existing section
    $ini->data['first_section']['new_key'] = 'new_value';
    
    // Add a completely new section
    $ini->data['new_section']['some_key'] = 123;
    
    // Save settings back to the file
    $ini->write();
    
} catch (\Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
```

## Advanced Features

### Handling Arrays (JSON Encoding)
The class automatically handles arrays and objects by encoding them into JSON strings. This is perfect for storing complex configuration data in a flat INI file.

```php
$ini->data['fifth_section']['my_array_json'] = ['a' => 200, 'bb' => 'test'];
$ini->write();

// Output in ini file:
// [fifth_section]
// my_array_json = {"a":200,"bb":"test"}
```
*When the file is read back, the JSON is automatically decoded back into a PHP array.*

### Managing Comments
You can add comments to your ini files programmatically.

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use videovv\IniFile\IniFile;

$ini = new IniFile('settings.ini');

// Add a comment immediately after the variable
$ini->addComment('This is a comment with "quotes" and ; semicolons', 'first_section:animal');

// Add a comment before the variable
$ini->addComment('This is a top comment', 'first_section:animal', IniFile::BEFORE);

// Remove a comment
$ini->delComment('first_section:animal');

$ini->write();
```

### Removing Sections and Keys

```php
// Remove a specific key
unset($ini->data['first_section']['animal']);

// Remove a whole section
unset($ini->data['new_section']);

$ini->write();
```

### Writing to a New File
You can easily save the current data to a different file and continue working with it:

```php
// Save to a new file and switch the internal pointer to it
$ini->write('settings-backup.ini');
```

## Error Handling

This package throws standard PHP `\Exception` objects. This allows for proper error catching:

```php
try {
    $ini = new IniFile('/path/to/nonexistent/file.ini');
} catch (\Exception $e) {
    echo 'Caught error: ' . $e->getMessage();
}
```

## License

This project is licensed under the MIT.
