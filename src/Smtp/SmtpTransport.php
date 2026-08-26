<?php
namespace App\Smtp;

interface SmtpTransport
{
    public function connect(string $host, int $port, string $encryption, float $timeout): void;
    public function write(string $data): void;
    public function readLine(): string;
    public function startTls(): void;
    public function close(): void;
}
