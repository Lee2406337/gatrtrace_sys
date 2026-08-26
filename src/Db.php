<?php
namespace App;

final class Db
{
    public static function connect(array $config): \PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $config['host'],
            $config['dbname'],
            $config['charset'] ?? 'utf8mb4'
        );
        // mysqlnd 的讀取逾時預設極長（近 1 年），連線在不穩網路（如 Wi-Fi）中途卡住時
        // 會近乎無限等待；這裡設一個合理上限，讓卡住變成快速、明確的例外而非無限轉圈。
        @ini_set('mysqlnd.net_read_timeout', '15');
        return new \PDO($dsn, $config['user'], $config['pass'], [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
            \PDO::ATTR_TIMEOUT            => 5,
            \PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE'",
        ]);
    }
}
