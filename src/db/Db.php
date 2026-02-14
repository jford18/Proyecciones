<?php

declare(strict_types=1);

namespace App\db;

use PDO;

class Db
{
    public static function pdo(array $config): PDO
    {
        $db = $config['db'];
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['DB_HOST'],
            (int) $db['DB_PORT'],
            $db['DB_NAME'],
            $db['DB_CHARSET']
        );

        return new PDO($dsn, $db['DB_USER'], $db['DB_PASS'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
