<?php
namespace App\Smtp;

final class StreamSmtpTransport implements SmtpTransport
{
    /** @var resource|null */
    private $socket;

    public function connect(string $host, int $port, string $encryption, float $timeout): void
    {
        $scheme = $encryption === 'ssl' ? 'ssl' : 'tcp';
        $address = "{$scheme}://{$host}:{$port}";
        // 明確建立 TLS context，不依賴部署主機 php.ini 的 openssl.cafile 是否剛好有設好
        // （原本完全沒帶 context，只是恰好在這台 XAMPP 的 openssl.cafile 有設才「看起來」有
        // 驗證，換一台沒設的機器會在完全不驗證憑證的情況下正常寄信）。peer_name 明確指定為
        // $host：STARTTLS（scheme=tcp，先明碼連線再升級加密）情境下 stream_socket_enable_crypto()
        // 不會自動從 address 推導 peer_name，必須透過 context 明講，否則 verify_peer_name 形同虛設。
        $context = stream_context_create([
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
                'peer_name'        => $host,
            ],
        ]);
        $socket = @stream_socket_client($address, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new \RuntimeException("連線 SMTP 主機失敗：{$errstr}（{$errno}）");
        }
        stream_set_timeout($socket, (int) $timeout);
        $this->socket = $socket;
    }

    public function write(string $data): void
    {
        if ($this->socket === null) {
            throw new \RuntimeException('SMTP 連線尚未建立');
        }
        if (fwrite($this->socket, $data) === false) {
            throw new \RuntimeException('SMTP 寫入失敗');
        }
    }

    public function readLine(): string
    {
        if ($this->socket === null) {
            throw new \RuntimeException('SMTP 連線尚未建立');
        }
        $line = fgets($this->socket, 1024);
        if ($line === false) {
            throw new \RuntimeException('SMTP 讀取回應失敗（連線可能已中斷）');
        }
        return $line;
    }

    public function startTls(): void
    {
        if ($this->socket === null) {
            throw new \RuntimeException('SMTP 連線尚未建立');
        }
        $ok = stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($ok !== true) {
            throw new \RuntimeException('SMTP STARTTLS 升級加密失敗');
        }
    }

    public function close(): void
    {
        if ($this->socket !== null) {
            fclose($this->socket);
            $this->socket = null;
        }
    }
}
