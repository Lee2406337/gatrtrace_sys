<?php
namespace App;

use App\Smtp\MimeEncoder;
use App\Smtp\SmtpTransport;
use App\Smtp\StreamSmtpTransport;

final class SmtpMailer implements Mailer
{
    /** @var string */
    private $host;
    /** @var int */
    private $port;
    /** @var string */
    private $encryption;
    /** @var string */
    private $username;
    /** @var string */
    private $password;
    /** @var string */
    private $fromEmail;
    /** @var string */
    private $fromName;
    /** @var float */
    private $timeout;
    /** @var SmtpTransport */
    private $transport;

    public function __construct(array $config, ?SmtpTransport $transport = null)
    {
        $host = trim((string) ($config['host'] ?? ''));
        $fromEmail = trim((string) ($config['from_email'] ?? ''));
        if ($host === '') {
            throw new \InvalidArgumentException('config/mail.php 的 host 未設定，無法建立 SmtpMailer');
        }
        if ($fromEmail === '') {
            throw new \InvalidArgumentException('config/mail.php 的 from_email 未設定，無法建立 SmtpMailer');
        }
        $encryption = strtolower(trim((string) ($config['encryption'] ?? 'none')));
        if (!in_array($encryption, ['none', 'tls', 'ssl'], true)) {
            throw new \InvalidArgumentException("config/mail.php 的 encryption 值不合法：「{$encryption}」，只接受 none/tls/ssl");
        }
        $username = (string) ($config['username'] ?? '');
        if ($encryption === 'none' && $username !== '') {
            throw new \InvalidArgumentException('config/mail.php 設定了帳密卻 encryption=none，會讓密碼以明碼傳輸，請改用 tls 或 ssl');
        }
        $this->host = $host;
        $this->port = (int) ($config['port'] ?? 25);
        $this->encryption = $encryption;
        $this->username = $username;
        $this->password = (string) ($config['password'] ?? '');
        $this->fromEmail = $fromEmail;
        $this->fromName = (string) ($config['from_name'] ?? '');
        $this->timeout = (float) ($config['timeout'] ?? 10);
        $this->transport = $transport ?? new StreamSmtpTransport();
    }

    public function send(string $to, string $subject, string $body): bool
    {
        if (str_contains($to, "\r") || str_contains($to, "\n")) {
            return false;
        }

        $this->transport->connect($this->host, $this->port, $this->encryption, $this->timeout);
        try {
            $this->expect('220', $this->readFullResponse(), '連線問候');
            $this->ehlo();

            if ($this->encryption === 'tls') {
                $this->command('STARTTLS', '220', 'STARTTLS');
                $this->transport->startTls();
                $this->ehlo();
            }

            if ($this->username !== '') {
                $this->command('AUTH LOGIN', '334', 'AUTH LOGIN');
                $this->command(base64_encode($this->username), '334', 'AUTH 帳號');
                // 密碼不透過 command() 傳遞，避免萬一未捕捉例外印出堆疊追蹤時，
                // base64 密碼以函式參數形式外洩（zend.exception_ignore_args 預設關閉時會印參數列）
                $this->transport->write(base64_encode($this->password) . "\r\n");
                $this->expect('235', $this->readFullResponse(), 'AUTH 密碼');
            }

            $this->command('MAIL FROM:<' . $this->fromEmail . '>', '250', 'MAIL FROM');

            $rcptResponse = $this->sendCommandRaw('RCPT TO:<' . $to . '>');
            if (!$this->isCode($rcptResponse, ['250', '251'])) {
                $this->tryQuit();
                return false;
            }

            $this->command('DATA', '354', 'DATA');
            $this->transport->write($this->buildMessage($to, $subject, $body));
            $this->expect('250', $this->readFullResponse(), '信件內容送出');

            $this->tryQuit();
            return true;
        } finally {
            $this->transport->close();
        }
    }

    private function ehlo(): void
    {
        $this->transport->write('EHLO ' . (gethostname() ?: 'localhost') . "\r\n");
        $this->expect('250', $this->readFullResponse(), 'EHLO');
    }

    private function command(string $line, string $expectedCode, string $label): void
    {
        $this->expect($expectedCode, $this->sendCommandRaw($line), $label);
    }

    private function sendCommandRaw(string $line): string
    {
        $this->transport->write($line . "\r\n");
        return $this->readFullResponse();
    }

    private function tryQuit(): void
    {
        try {
            $this->transport->write("QUIT\r\n");
            $this->transport->readLine();
        } catch (\Throwable $e) {
            // QUIT 失敗不影響本次寄送結果，finally 還是會關閉底層連線
        }
    }

    // SMTP 多行回應格式：250-xxx\r\n...\r\n250 xxx\r\n（延續行第4碼是 '-'，最後一行是空白），
    // 逐行讀到非延續行為止，回傳最後一行供狀態碼判斷
    private function readFullResponse(): string
    {
        $line = $this->transport->readLine();
        while (strlen($line) >= 4 && $line[3] === '-') {
            $line = $this->transport->readLine();
        }
        return $line;
    }

    private function isCode(string $response, array $codes): bool
    {
        return in_array(substr($response, 0, 3), $codes, true);
    }

    private function expect(string $code, string $response, string $label): void
    {
        if (!$this->isCode($response, [$code])) {
            throw new \RuntimeException("SMTP {$label} 失敗，伺服器回應：" . trim($response));
        }
    }

    private function buildMessage(string $to, string $subject, string $body): string
    {
        $fromHeader = $this->fromName !== ''
            ? MimeEncoder::encodeHeader($this->fromName) . ' <' . $this->fromEmail . '>'
            : $this->fromEmail;
        $headers = [
            'From: ' . $fromHeader,
            'To: <' . $to . '>',
            'Subject: ' . MimeEncoder::encodeHeader($subject),
            'Date: ' . date('r'),
            'Message-ID: <' . uniqid('', true) . '@' . $this->messageIdDomain() . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        // 內文一律用 base64 編碼，base64 字母表不含 '.'，不會出現以 '.' 開頭的行，
        // 不需要額外做 SMTP DATA 的 dot-stuffing
        return implode("\r\n", $headers) . "\r\n\r\n" . MimeEncoder::encodeBody($body) . "\r\n.\r\n";
    }

    private function messageIdDomain(): string
    {
        $at = strrpos($this->fromEmail, '@');
        return $at !== false ? substr($this->fromEmail, $at + 1) : 'localhost';
    }
}
