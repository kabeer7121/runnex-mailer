<?php
declare(strict_types=1);

final class SmtpException extends RuntimeException
{
    /** connect | auth | recipient | quota | rejected | transient */
    public string $kind;

    public function __construct(string $message, string $kind, int $code = 0)
    {
        parent::__construct($message, $code);
        $this->kind = $kind;
    }
}

/** Minimal dependency-free SMTP client (STARTTLS / SSL, AUTH LOGIN). */
final class SmtpClient
{
    /** @var resource|null */
    private $sock = null;
    private string $stage = 'connect';

    public function __construct(
        private string $host,
        private int $port,
        private string $secure = 'tls',
        private int $timeout = 25
    ) {
    }

    public function connect(): void
    {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $this->host]]);
        $remote = ($this->secure === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client($remote, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) {
            throw new SmtpException("Cannot connect to {$this->host}:{$this->port} – $errstr ($errno). If port 587 is blocked by your host, try 465/SSL.", 'connect');
        }
        $this->sock = $sock;
        stream_set_timeout($this->sock, $this->timeout);
        $this->expect([220]);
        $this->ehlo();
        if ($this->secure === 'tls') {
            $this->cmd('STARTTLS', [220]);
            $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) $method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            if (!@stream_socket_enable_crypto($this->sock, true, $method)) {
                throw new SmtpException('TLS negotiation failed', 'connect');
            }
            $this->ehlo();
        }
    }

    private function ehlo(): void
    {
        $host = parse_url(base_url(), PHP_URL_HOST) ?: 'localhost';
        $this->cmd('EHLO ' . $host, [250]);
    }

    public function auth(string $user, string $pass): void
    {
        $this->stage = 'auth';
        $this->cmd('AUTH LOGIN', [334]);
        $this->cmd(base64_encode($user), [334]);
        $this->cmd(base64_encode($pass), [235]);
    }

    /** Send a raw RFC 5322 message. Returns the final server response. */
    public function send(string $from, array $recipients, string $data): string
    {
        $this->stage = 'mail';
        $this->cmd('MAIL FROM:<' . $from . '>', [250]);
        $this->stage = 'rcpt';
        foreach ($recipients as $r) {
            $this->cmd('RCPT TO:<' . $r . '>', [250, 251]);
        }
        $this->stage = 'data';
        $this->cmd('DATA', [354]);
        $data = str_replace(["\r\n", "\r"], "\n", $data);
        $data = str_replace("\n", "\r\n", $data);
        $data = preg_replace('/^\./m', '..', $data);
        $this->write($data . "\r\n.\r\n");
        $resp = $this->expect([250]);
        $this->stage = 'done';
        return $resp;
    }

    public function quit(): void
    {
        if ($this->sock) {
            try {
                $this->write("QUIT\r\n");
            } catch (Throwable $e) {
            }
            @fclose($this->sock);
            $this->sock = null;
        }
    }

    public function __destruct()
    {
        $this->quit();
    }

    private function cmd(string $line, array $ok): string
    {
        $this->write($line . "\r\n");
        return $this->expect($ok);
    }

    private function write(string $s): void
    {
        $len = strlen($s);
        $done = 0;
        while ($done < $len) {
            $n = @fwrite($this->sock, substr($s, $done, 8192));
            if ($n === false || $n === 0) {
                throw new SmtpException('Connection lost while writing', 'transient');
            }
            $done += $n;
        }
    }

    private function expect(array $ok): string
    {
        $resp = '';
        while (true) {
            $line = fgets($this->sock, 1024);
            if ($line === false) {
                $meta = stream_get_meta_data($this->sock);
                throw new SmtpException($meta['timed_out'] ? 'SMTP timeout' : 'SMTP connection closed', $this->stage === 'connect' ? 'connect' : 'transient');
            }
            $resp .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        $code = (int)substr($resp, 0, 3);
        if (!in_array($code, $ok, true)) {
            throw new SmtpException(trim($resp), $this->classify($code, $resp), $code);
        }
        return trim($resp);
    }

    private function classify(int $code, string $resp): string
    {
        $r = strtolower($resp);
        if ($this->stage === 'auth' || in_array($code, [530, 534, 535], true)) return 'auth';
        if (str_contains($r, '5.4.5') || str_contains($r, 'quota') || str_contains($r, 'sending limit') || str_contains($r, 'daily user sending')) return 'quota';
        if ($code >= 400 && $code < 500) return 'transient';
        if ($this->stage === 'rcpt') return 'recipient';
        if ($this->stage === 'connect') return 'connect';
        return 'rejected';
    }
}
