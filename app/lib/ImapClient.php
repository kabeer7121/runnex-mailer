<?php
declare(strict_types=1);

/** Minimal dependency-free IMAP client (works without PHP's imap extension). */
final class ImapClient
{
    /** @var resource|null */
    private $sock = null;
    private int $n = 0;
    public array $caps = [];

    public function connect(string $host, int $port = 993, int $timeout = 25): void
    {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client('ssl://' . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) throw new RuntimeException("IMAP connect failed to $host:$port – $errstr");
        $this->sock = $sock;
        stream_set_timeout($this->sock, $timeout);
        $greet = $this->readLine();
        if (!str_starts_with($greet, '* OK')) throw new RuntimeException('IMAP bad greeting: ' . $greet);
    }

    public function login(string $user, string $pass): void
    {
        [$st, , $text] = $this->command('LOGIN ' . self::q($user) . ' ' . self::q($pass));
        if ($st !== 'OK') throw new RuntimeException('IMAP login failed: ' . $text);
        [, $entries] = $this->command('CAPABILITY');
        foreach ($entries as $en) {
            if (str_starts_with($en['text'], '* CAPABILITY')) {
                $this->caps = explode(' ', strtoupper(substr($en['text'], 13)));
            }
        }
    }

    public function logout(): void
    {
        if ($this->sock) {
            try {
                $this->command('LOGOUT');
            } catch (Throwable $e) {
            }
            @fclose($this->sock);
            $this->sock = null;
        }
    }

    public function __destruct()
    {
        if ($this->sock) @fclose($this->sock);
    }

    /** @return array<int, array{name:string, flags:string[]}> */
    public function listFolders(): array
    {
        [, $entries] = $this->command('LIST "" "*"');
        $out = [];
        foreach ($entries as $en) {
            if (!preg_match('/^\* LIST \(([^)]*)\) (?:"(?:[^"\\\\]|\\\\.)*"|NIL) (.+)$/i', $en['text'], $m)) continue;
            $name = trim($m[2]);
            if (preg_match("/^\x00L(\d+)\x00$/", $name, $lm)) {
                $name = $en['lits'][(int)$lm[1]];
            } elseif ($name !== '' && $name[0] === '"') {
                $name = stripcslashes(substr($name, 1, -1));
            }
            $out[] = ['name' => $name, 'flags' => array_filter(explode(' ', strtolower($m[1])))];
        }
        return $out;
    }

    public function findSpecial(string $flag): ?string
    {
        foreach ($this->listFolders() as $f) {
            if (in_array(strtolower($flag), $f['flags'], true)) return $f['name'];
        }
        return null;
    }

    public function select(string $folder): array
    {
        [$st, $entries, $text] = $this->command('SELECT ' . self::q($folder));
        if ($st !== 'OK') throw new RuntimeException("Cannot select $folder: $text");
        $info = ['exists' => 0, 'uidvalidity' => 0, 'uidnext' => 0];
        foreach ($entries as $en) {
            if (preg_match('/^\* (\d+) EXISTS/', $en['text'], $m)) $info['exists'] = (int)$m[1];
            if (preg_match('/UIDVALIDITY (\d+)/', $en['text'], $m)) $info['uidvalidity'] = (int)$m[1];
            if (preg_match('/UIDNEXT (\d+)/', $en['text'], $m)) $info['uidnext'] = (int)$m[1];
        }
        return $info;
    }

    /** @return int[] */
    public function uidSearch(string $criteria): array
    {
        [$st, $entries, $text] = $this->command('UID SEARCH ' . $criteria);
        if ($st !== 'OK') throw new RuntimeException('Search failed: ' . $text);
        $uids = [];
        foreach ($entries as $en) {
            if (str_starts_with($en['text'], '* SEARCH')) {
                foreach (explode(' ', trim(substr($en['text'], 8))) as $u) {
                    if ($u !== '') $uids[] = (int)$u;
                }
            }
        }
        sort($uids);
        return $uids;
    }

    /** @return array<int,string> uid => raw header block */
    public function fetchHeaders(array $uids, array $fields): array
    {
        if (!$uids) return [];
        $spec = 'BODY.PEEK[HEADER.FIELDS (' . implode(' ', array_map('strtoupper', $fields)) . ')]';
        [, $entries] = $this->command('UID FETCH ' . implode(',', $uids) . ' (UID ' . $spec . ')');
        return $this->collect($entries);
    }

    public function fetchRaw(int $uid, int $maxBytes = 200000): string
    {
        [, $entries] = $this->command('UID FETCH ' . $uid . ' (UID BODY.PEEK[]<0.' . $maxBytes . '>)');
        $r = $this->collect($entries);
        return $r[$uid] ?? '';
    }

    private function collect(array $entries): array
    {
        $out = [];
        foreach ($entries as $en) {
            if (!preg_match('/^\* \d+ FETCH/', $en['text'])) continue;
            if (!preg_match('/UID (\d+)/', $en['text'], $m)) continue;
            $out[(int)$m[1]] = $en['lits'][0] ?? '';
        }
        return $out;
    }

    public function addFlags(array $uids, string $flags): void
    {
        if ($uids) $this->command('UID STORE ' . implode(',', $uids) . ' +FLAGS.SILENT (' . $flags . ')');
    }

    public function addGmailLabel(int $uid, string $label): void
    {
        if (in_array('X-GM-EXT-1', $this->caps, true)) {
            $this->command('UID STORE ' . $uid . ' +X-GM-LABELS (' . $label . ')');
        }
    }

    public function move(int $uid, string $folder): bool
    {
        if (in_array('MOVE', $this->caps, true)) {
            [$st] = $this->command('UID MOVE ' . $uid . ' ' . self::q($folder));
            return $st === 'OK';
        }
        [$st] = $this->command('UID COPY ' . $uid . ' ' . self::q($folder));
        if ($st !== 'OK') return false;
        $this->command('UID STORE ' . $uid . ' +FLAGS.SILENT (\\Deleted)');
        $this->command('EXPUNGE');
        return true;
    }

    public function createFolder(string $name): void
    {
        $this->command('CREATE ' . self::q($name)); // ignore "already exists"
    }

    public static function q(string $s): string
    {
        return '"' . addcslashes($s, "\"\\") . '"';
    }

    /** @return array{0:string,1:array,2:string} [status, untagged entries, tagged text] */
    public function command(string $cmd): array
    {
        $tag = 'R' . (++$this->n);
        $this->write($tag . ' ' . $cmd . "\r\n");
        $entries = [];
        while (true) {
            $line = $this->readLine();
            if (str_starts_with($line, $tag . ' ')) {
                $rest = substr($line, strlen($tag) + 1);
                $status = strtoupper(strtok($rest, ' '));
                return [$status, $entries, $rest];
            }
            if (str_starts_with($line, '+')) {
                continue;
            }
            // untagged response, possibly containing literals
            $text = '';
            $lits = [];
            while (true) {
                if (preg_match('/\{(\d+)\}$/', $line, $m)) {
                    $text .= substr($line, 0, -strlen($m[0])) . "\x00L" . count($lits) . "\x00";
                    $lits[] = $this->readBytes((int)$m[1]);
                    $line = $this->readLine();
                    continue;
                }
                $text .= $line;
                break;
            }
            $entries[] = ['text' => $text, 'lits' => $lits];
        }
    }

    private function write(string $s): void
    {
        if (@fwrite($this->sock, $s) === false) throw new RuntimeException('IMAP write failed');
    }

    private function readLine(): string
    {
        $line = fgets($this->sock);
        if ($line === false) {
            $meta = stream_get_meta_data($this->sock);
            throw new RuntimeException($meta['timed_out'] ? 'IMAP timeout' : 'IMAP connection closed');
        }
        return rtrim($line, "\r\n");
    }

    private function readBytes(int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($this->sock, min(65536, $n - strlen($buf)));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->sock);
                if ($meta['timed_out'] || feof($this->sock)) throw new RuntimeException('IMAP read failed');
                continue;
            }
            $buf .= $chunk;
        }
        return $buf;
    }
}

final class MailParser
{
    public static function headers(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $pos = strpos($raw, "\n\n");
        $block = $pos === false ? $raw : substr($raw, 0, $pos);
        $block = preg_replace("/\n[ \t]+/", ' ', $block);
        $out = [];
        foreach (explode("\n", $block) as $line) {
            $i = strpos($line, ':');
            if ($i === false) continue;
            $k = strtolower(trim(substr($line, 0, $i)));
            $v = trim(substr($line, $i + 1));
            if (!isset($out[$k])) $out[$k] = self::decodeHeader($v);
        }
        return $out;
    }

    public static function decodeHeader(string $v): string
    {
        if (!str_contains($v, '=?')) return $v;
        $d = @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        return $d === false ? $v : $d;
    }

    /** "Name <a@b.com>" -> ['a@b.com', 'Name'] */
    public static function address(string $v): array
    {
        if (preg_match('/<([^>]+)>/', $v, $m)) {
            $name = trim(str_replace('"', '', substr($v, 0, strpos($v, '<'))));
            return [strtolower(trim($m[1])), $name];
        }
        return [strtolower(trim($v)), ''];
    }

    public static function messageIds(string $v): array
    {
        preg_match_all('/<[^>]+>/', $v, $m);
        return $m[0];
    }

    /** Parse a full message into headers + text + html bodies. */
    public static function parse(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $res = ['headers' => self::headers($raw), 'text' => '', 'html' => ''];
        self::walk($raw, $res);
        if ($res['text'] === '' && $res['html'] !== '') $res['text'] = Mailer::htmlToText($res['html']);
        return $res;
    }

    private static function walk(string $part, array &$res, int $depth = 0): void
    {
        if ($depth > 6) return;
        $pos = strpos($part, "\n\n");
        $head = $pos === false ? $part : substr($part, 0, $pos);
        $body = $pos === false ? '' : substr($part, $pos + 2);
        $h = self::headers($head . "\n\n");
        $ct = strtolower($h['content-type'] ?? 'text/plain');
        if (str_starts_with($ct, 'multipart/') && preg_match('/boundary="?([^";]+)"?/i', $h['content-type'], $m)) {
            $chunks = explode('--' . $m[1], $body);
            array_shift($chunks);
            foreach ($chunks as $c) {
                if (str_starts_with($c, '--')) break;
                self::walk(ltrim($c, "\n"), $res, $depth + 1);
            }
            return;
        }
        if (str_starts_with($ct, 'message/') || str_starts_with($ct, 'text/rfc822-headers')) {
            $res['text'] .= ($res['text'] ? "\n" : '') . $body;
            return;
        }
        if (!str_starts_with($ct, 'text/')) return;
        $enc = strtolower($h['content-transfer-encoding'] ?? '');
        if ($enc === 'base64') $body = (string)base64_decode(preg_replace('/\s+/', '', $body));
        elseif ($enc === 'quoted-printable') $body = quoted_printable_decode($body);
        if (preg_match('/charset="?([\w\-]+)"?/i', $ct, $cm) && strtolower($cm[1]) !== 'utf-8') {
            $conv = @mb_convert_encoding($body, 'UTF-8', $cm[1]);
            if ($conv !== false) $body = $conv;
        }
        if (str_starts_with($ct, 'text/html')) {
            if ($res['html'] === '') $res['html'] = $body;
        } else {
            $res['text'] .= ($res['text'] ? "\n" : '') . $body;
        }
    }

    /** Remove quoted history from a reply. */
    public static function stripQuoted(string $text): string
    {
        $lines = explode("\n", str_replace("\r", '', $text));
        $out = [];
        foreach ($lines as $l) {
            if (preg_match('/^On .+wrote:\s*$/i', $l) || preg_match('/^-{2,}\s*Original Message/i', $l) || preg_match('/^From: .+/i', $l) && $out) break;
            if (str_starts_with(ltrim($l), '>')) continue;
            $out[] = $l;
        }
        return trim(implode("\n", $out));
    }
}
