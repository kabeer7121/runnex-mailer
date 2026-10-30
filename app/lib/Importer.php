<?php
declare(strict_types=1);

/** Reads CSV / XLSX / Google Sheets and imports carriers into contacts. */
final class Importer
{
    private const DISPOSABLE = ['mailinator.com', 'guerrillamail.com', '10minutemail.com', 'tempmail.com', 'yopmail.com', 'trashmail.com', 'sharklasers.com', 'getnada.com', 'temp-mail.org', 'dispostable.com'];
    public const MAX_BYTES = 20 * 1024 * 1024;
    public const MAX_ROWS = 50000;

    /** @return array<int, array<int,string>> rows including header row */
    public static function read(string $path, int $limit = 0): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return $ext === 'xlsx' ? self::readXlsx($path, $limit) : self::readCsv($path, $limit);
    }

    private static function readCsv(string $path, int $limit): array
    {
        $fh = fopen($path, 'r');
        if (!$fh) throw new RuntimeException('Cannot open file');
        $first = fgets($fh) ?: '';
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
        $delim = ',';
        foreach ([';', "\t", '|'] as $d) {
            if (substr_count($first, $d) > substr_count($first, $delim)) $delim = $d;
        }
        rewind($fh);
        $rows = [];
        $i = 0;
        while (($r = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
            if ($i === 0 && isset($r[0])) $r[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$r[0]);
            if ($r === [null]) continue;
            $rows[] = array_map(fn($c) => self::clean((string)$c), $r);
            if ($limit && ++$i >= $limit) break;
            if (!$limit) $i++;
        }
        fclose($fh);
        return $rows;
    }

    private static function readXlsx(string $path, int $limit): array
    {
        if (!class_exists('ZipArchive')) throw new RuntimeException('XLSX needs the PHP zip extension. Save the sheet as CSV instead.');
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new RuntimeException('Invalid XLSX file');
        $shared = [];
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if ($ss !== false) {
            $x = simplexml_load_string($ss);
            foreach ($x->si as $si) {
                if (isset($si->t)) {
                    $shared[] = (string)$si->t;
                } else {
                    $s = '';
                    foreach ($si->r as $r) $s .= (string)$r->t;
                    $shared[] = $s;
                }
            }
        }
        $sheetPath = 'xl/worksheets/sheet1.xml';
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb && $rels) {
            $wx = simplexml_load_string($wb);
            $rx = simplexml_load_string($rels);
            $sheet = $wx->sheets->sheet[0] ?? null;
            if ($sheet) {
                $rid = (string)$sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                foreach ($rx->Relationship as $rel) {
                    if ((string)$rel['Id'] === $rid) {
                        $t = ltrim((string)$rel['Target'], '/');
                        $sheetPath = str_starts_with($t, 'xl/') ? $t : 'xl/' . $t;
                    }
                }
            }
        }
        $xml = $zip->getFromName($sheetPath);
        $zip->close();
        if ($xml === false) throw new RuntimeException('Sheet not found in XLSX');
        $sx = simplexml_load_string($xml);
        $rows = [];
        foreach ($sx->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $ref = (string)$c['r'];
                $col = self::colIndex(preg_replace('/\d+/', '', $ref));
                $type = (string)$c['t'];
                if ($type === 's') $v = $shared[(int)$c->v] ?? '';
                elseif ($type === 'inlineStr') $v = (string)($c->is->t ?? '');
                else $v = (string)$c->v;
                if ($type === '' && is_numeric($v) && str_contains($v, 'E')) $v = number_format((float)$v, 0, '', '');
                $cells[$col] = self::clean($v);
            }
            if (!$cells) continue;
            $max = max(array_keys($cells));
            $line = [];
            for ($i = 0; $i <= $max; $i++) $line[] = $cells[$i] ?? '';
            $rows[] = $line;
            if ($limit && count($rows) >= $limit) break;
        }
        return $rows;
    }

    private static function colIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) $n = $n * 26 + (ord($ch) - 64);
        return $n - 1;
    }

    private static function clean(string $v): string
    {
        $v = trim($v);
        if (!mb_check_encoding($v, 'UTF-8')) $v = mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
        return $v;
    }

    /** Download a Google Sheet (shared "anyone with link") as CSV. */
    public static function fetchGoogleSheet(string $url, string $dest): void
    {
        if (!preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]+)#', $url, $m)) throw new RuntimeException('Not a Google Sheets link.');
        $gid = preg_match('/[#&?]gid=(\d+)/', $url, $g) ? '&gid=' . $g[1] : '';
        $csvUrl = 'https://docs.google.com/spreadsheets/d/' . $m[1] . '/export?format=csv' . $gid;
        $data = false;
        if (function_exists('curl_init')) {
            $ch = curl_init($csvUrl);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60]);
            $data = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code !== 200) $data = false;
        } else {
            $data = @file_get_contents($csvUrl);
        }
        if ($data === false || stripos(substr((string)$data, 0, 200), '<!DOCTYPE html') !== false) {
            throw new RuntimeException('Could not download the sheet. Set sharing to "Anyone with the link can view".');
        }
        file_put_contents($dest, $data);
    }

    /** Guess contact field for a header name. */
    public static function guess(string $header): string
    {
        $h = preg_replace('/[^a-z0-9]/', '', strtolower($header));
        $map = [
            'email' => ['email', 'emailaddress', 'mail', 'contactemail', 'emailid', 'emails', 'email1', 'primaryemail'],
            'first_name' => ['firstname', 'fname', 'first', 'contactfirstname', 'givenname'],
            'last_name' => ['lastname', 'lname', 'last', 'surname', 'contactlastname'],
            '__full_name' => ['name', 'fullname', 'contactname', 'contact', 'owner', 'ownername', 'contactperson'],
            'company_name' => ['company', 'companyname', 'carrier', 'carriername', 'legalname', 'dba', 'dbaname', 'businessname', 'organization'],
            'mc_number' => ['mc', 'mcnumber', 'mcno', 'mc#', 'docket', 'docketnumber', 'mcmx', 'mcmxff'],
            'dot_number' => ['dot', 'dotnumber', 'usdot', 'usdotnumber', 'dotno'],
            'phone' => ['phone', 'phonenumber', 'telephone', 'mobile', 'cell', 'cellphone', 'tel', 'contactphone'],
            'city' => ['city', 'phycity', 'physicalcity'],
            'state' => ['state', 'st', 'phystate', 'physicalstate', 'province'],
            'equipment_type' => ['equipment', 'equipmenttype', 'trucktype', 'trailertype', 'trailer', 'cargo', 'cargotype'],
            'truck_count' => ['trucks', 'truckcount', 'powerunits', 'fleetsize', 'nooftrucks', 'numberoftrucks', 'units'],
            'notes' => ['notes', 'note', 'comments', 'comment'],
        ];
        foreach ($map as $field => $aliases) {
            if (in_array($h, $aliases, true)) return $field;
        }
        if (str_contains($h, 'email')) return 'email';
        if (str_starts_with($h, 'mc')) return 'mc_number';
        if (str_contains($h, 'dot')) return 'dot_number';
        if (str_contains($h, 'phone')) return 'phone';
        return '__custom';
    }

    /**
     * Import rows. $map: column index => field ('__skip', '__custom', '__full_name' or contact field).
     * Returns stats.
     */
    public static function import(array $rows, array $headers, array $map, int $companyId, ?int $listId, array $opt): array
    {
        $db = db();
        $stats = ['total' => 0, 'added' => 0, 'updated' => 0, 'duplicates' => 0, 'invalid' => 0, 'suppressed' => 0, 'fixed' => 0, 'invalid_samples' => []];
        $emailCol = array_search('email', $map, true);
        if ($emailCol === false) throw new RuntimeException('Map one column to Email.');
        $fields = array_keys(contact_fields());
        $seen = [];
        $db->pdo->beginTransaction();
        try {
            foreach ($rows as $row) {
                $stats['total']++;
                $email = strtolower(trim((string)($row[$emailCol] ?? '')));
                // cells sometimes contain "a@b.com; c@d.com"
                if (preg_match('/[\s,;]/', $email)) $email = preg_split('/[\s,;]+/', $email)[0];
                $email = trim($email, " <>\"'");
                if (str_starts_with($email, 'mailto:')) $email = substr($email, 7);
                // instant checks (format, placeholder, misspelled domain, disposable) – no network
                $chk = Verifier::check($email, false);
                if ($chk['status'] === 'invalid' && $chk['suggestion'] && !empty($opt['fix_typos'])) {
                    $email = $chk['suggestion'];
                    $chk = Verifier::check($email, false);
                    $stats['fixed']++;
                }
                if ($chk['status'] === 'invalid' || in_array(email_domain($email), self::DISPOSABLE, true)) {
                    $stats['invalid']++;
                    if (count($stats['invalid_samples']) < 15) $stats['invalid_samples'][] = $email . ' – ' . $chk['reason'];
                    continue;
                }
                if (isset($seen[$email])) {
                    $stats['duplicates']++;
                    continue;
                }
                $seen[$email] = true;
                if (is_suppressed($email, $companyId)) {
                    $stats['suppressed']++;
                    continue;
                }
                $data = [];
                $custom = [];
                foreach ($map as $i => $f) {
                    $v = trim((string)($row[$i] ?? ''));
                    if ($f === '__skip' || $f === 'email' || $v === '') continue;
                    if ($f === '__full_name') {
                        $parts = preg_split('/\s+/', $v, 2);
                        $data['first_name'] = $data['first_name'] ?? $parts[0];
                        if (isset($parts[1])) $data['last_name'] = $data['last_name'] ?? $parts[1];
                    } elseif ($f === '__custom') {
                        $custom[$headers[$i] ?? ('column_' . ($i + 1))] = $v;
                    } elseif (in_array($f, $fields, true)) {
                        $data[$f] = mb_substr($v, 0, $f === 'notes' ? 5000 : 190);
                    }
                }
                if (isset($data['mc_number'])) $data['mc_number'] = preg_replace('/^mc[\s\-#:]*/i', '', $data['mc_number']);
                $existing = $db->row('SELECT id, custom, verify_status FROM contacts WHERE company_id = ? AND email = ?', [$companyId, $email]);
                if ($existing && $existing['verify_status'] === 'invalid') {
                    // already known to be a bad address – never put it back on a list
                    $stats['invalid']++;
                    if (count($stats['invalid_samples']) < 15) $stats['invalid_samples'][] = $email . ' – previously verified invalid';
                    continue;
                }
                if ($existing) {
                    if (!empty($opt['update_existing'])) {
                        if ($custom) {
                            $data['custom'] = json_encode(array_merge(json_decode((string)$existing['custom'], true) ?: [], $custom));
                        }
                        if ($data) {
                            $data['updated_at'] = now();
                            $db->update('contacts', $data, 'id = ?', [$existing['id']]);
                        }
                        $stats['updated']++;
                    } else {
                        $stats['duplicates']++;
                    }
                    $cid = (int)$existing['id'];
                } else {
                    $data['company_id'] = $companyId;
                    $data['email'] = $email;
                    $data['custom'] = $custom ? json_encode($custom) : null;
                    $data['created_by'] = Auth::id() ?: null;
                    $data['created_at'] = now();
                    $cid = $db->insert('contacts', $data);
                    $stats['added']++;
                }
                if ($listId) $db->insertIgnore('list_contacts', ['list_id' => $listId, 'contact_id' => $cid]);
            }
            $db->pdo->commit();
        } catch (Throwable $e) {
            $db->pdo->rollBack();
            throw $e;
        }
        return $stats;
    }
}
