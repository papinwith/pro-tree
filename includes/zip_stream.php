<?php
// A tiny ZIP writer and reader in plain PHP - the web container has no zip extension (php:apache images ship without it),
// and the full backup must work there.
//
// StreamingZip writes the archive straight to an output stream, one chunk at a time, so a backup of many photos and a large
// database never sits in memory. Entries are STORED (not compressed): photos are already compressed, and the SQL text is
// small next to them. CRC and sizes are written after each entry (the "data descriptor" form), which every common unzip
// tool - Windows Explorer, 7-Zip, macOS, Python's zipfile - reads through the central directory at the end.
//
// StoredZipReader reads such an archive back (also ones re-zipped with normal compression) for restoring photos.
// Limits: archives under 4 GB and 65,535 entries (no ZIP64).
final class StreamingZip
{
    /** @var resource */
    private $out;
    private array $central = [];
    private int $offset = 0;
    private ?array $open = null;

    /** @param resource $out a writable stream (php://output, a file, php://temp) */
    public function __construct($out)
    {
        $this->out = $out;
    }

    private function put(string $bytes): void
    {
        $len = strlen($bytes);
        $done = 0;
        while ($done < $len) {
            $n = fwrite($this->out, $done === 0 ? $bytes : substr($bytes, $done));
            if ($n === false || $n === 0) {
                throw new RuntimeException('cannot write the archive (connection closed or disk full)');
            }
            $done += $n;
        }
        $this->offset += $len;
    }

    private static function dosTime(int $mtime): array
    {
        $d = getdate(max($mtime, 315532800)); // the DOS clock starts in 1980
        return [($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2), (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday']];
    }

    /** Starts an entry whose bytes are sent with write() and closed with endEntry(). */
    public function beginEntry(string $name, ?int $mtime = null): void
    {
        if ($this->open !== null) {
            throw new LogicException('previous entry still open');
        }
        $name = ltrim(str_replace('\\', '/', $name), '/');
        if ($name === '' || str_contains($name, '../') || strlen($name) > 65000 || count($this->central) >= 65535) {
            throw new InvalidArgumentException('bad entry name: ' . $name);
        }
        [$time, $date] = self::dosTime($mtime ?? time());
        $this->open = ['name' => $name, 'offset' => $this->offset, 'time' => $time, 'date' => $date, 'size' => 0, 'hash' => hash_init('crc32b')];
        // local header: flags 0x0808 = sizes/CRC follow the data + the name is UTF-8
        $this->put(pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0808, 0, $time, $date, 0, 0, 0, strlen($name), 0) . $name);
    }

    public function write(string $chunk): void
    {
        if ($this->open === null) {
            throw new LogicException('no open entry');
        }
        if ($chunk === '') {
            return;
        }
        hash_update($this->open['hash'], $chunk);
        $this->open['size'] += strlen($chunk);
        $this->put($chunk);
    }

    public function endEntry(): void
    {
        $e = $this->open;
        if ($e === null) {
            throw new LogicException('no open entry');
        }
        $this->open = null;
        if ($e['size'] > 0xFFFFFFFE || $this->offset > 0xFFFFFFFE) {
            throw new RuntimeException('archive too large for this writer (4 GB)');
        }
        $crc = hexdec(hash_final($e['hash']));
        $this->put(pack('VVVV', 0x08074b50, $crc, $e['size'], $e['size']));
        $this->central[] = $e + ['crc' => $crc];
    }

    public function addString(string $name, string $data, ?int $mtime = null): void
    {
        $this->beginEntry($name, $mtime);
        $this->write($data);
        $this->endEntry();
    }

    public function addFile(string $name, string $path): void
    {
        $h = fopen($path, 'rb');
        if ($h === false) {
            throw new RuntimeException('cannot read ' . $path);
        }
        try {
            $this->beginEntry($name, (int) @filemtime($path));
            while (!feof($h)) {
                $chunk = fread($h, 65536);
                if ($chunk === false) {
                    throw new RuntimeException('read error on ' . $path);
                }
                $this->write($chunk);
            }
            $this->endEntry();
        } finally {
            fclose($h);
        }
    }

    public function finish(): void
    {
        if ($this->open !== null) {
            $this->endEntry();
        }
        $start = $this->offset;
        foreach ($this->central as $e) {
            $this->put(pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0808, 0, $e['time'], $e['date'], $e['crc'], $e['size'], $e['size'], strlen($e['name']), 0, 0, 0, 0, 0, $e['offset']) . $e['name']);
        }
        $size = $this->offset - $start;
        $this->put(pack('VvvvvVVv', 0x06054b50, 0, 0, count($this->central), count($this->central), $size, $start, 0));
    }
}

final class StoredZipReader
{
    private string $path;
    /** @var array<string, array{method:int, csize:int, size:int, crc:int, offset:int}> */
    private array $entries = [];

    public function __construct(string $path)
    {
        $this->path = $path;
        $h = fopen($path, 'rb');
        if ($h === false) {
            throw new RuntimeException('cannot open the archive');
        }
        try {
            $len = filesize($path);
            $tailLen = (int) min($len, 65557);
            fseek($h, $len - $tailLen);
            $tail = (string) fread($h, $tailLen);
            $pos = strrpos($tail, "PK\x05\x06");
            if ($pos === false || strlen($tail) - $pos < 22) {
                throw new RuntimeException('this is not a ZIP file');
            }
            $eocd = unpack('vdisk/vcdisk/ventries/vtotal/Vcdsize/Vcdoffset/vcomment', substr($tail, $pos + 4, 18));
            if ($eocd['cdoffset'] + $eocd['cdsize'] > $len) {
                throw new RuntimeException('the ZIP file is damaged');
            }
            fseek($h, $eocd['cdoffset']);
            $cd = (string) fread($h, max(1, $eocd['cdsize']));
            $p = 0;
            for ($i = 0; $i < $eocd['total']; $i++) {
                if (substr($cd, $p, 4) !== "PK\x01\x02") {
                    throw new RuntimeException('the ZIP file is damaged');
                }
                $f = unpack('vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnlen/velen/vclen/vdisk/viattr/Veattr/Voffset', substr($cd, $p + 4, 42));
                $name = substr($cd, $p + 46, $f['nlen']);
                $this->entries[$name] = ['method' => $f['method'], 'csize' => $f['csize'], 'size' => $f['size'], 'crc' => $f['crc'], 'offset' => $f['offset']];
                $p += 46 + $f['nlen'] + $f['elen'] + $f['clen'];
            }
        } finally {
            fclose($h);
        }
    }

    /** @return string[] entry names (directories excluded) */
    public function names(): array
    {
        return array_values(array_filter(array_keys($this->entries), fn($n) => !str_ends_with($n, '/')));
    }

    public function size(string $name): int
    {
        return $this->entries[$name]['size'] ?? 0;
    }

    /** The bytes of an entry, checked against its CRC. Whole entry in memory: callers cap the size first. */
    public function read(string $name): string
    {
        $e = $this->entries[$name] ?? null;
        if ($e === null) {
            throw new RuntimeException('no such entry: ' . $name);
        }
        $h = fopen($this->path, 'rb');
        try {
            fseek($h, $e['offset']);
            $lh = (string) fread($h, 30);
            if (strlen($lh) < 30 || substr($lh, 0, 4) !== "PK\x03\x04") {
                throw new RuntimeException('the ZIP file is damaged');
            }
            $l = unpack('vnlen/velen', substr($lh, 26, 4));
            fseek($h, $e['offset'] + 30 + $l['nlen'] + $l['elen']);
            $raw = $e['csize'] > 0 ? (string) fread($h, $e['csize']) : '';
        } finally {
            fclose($h);
        }
        $data = match ($e['method']) {
            0 => $raw,
            8 => (string) gzinflate($raw),
            default => throw new RuntimeException('unsupported compression in ' . $name),
        };
        if (strlen($data) !== $e['size'] || hexdec(hash('crc32b', $data)) !== $e['crc']) {
            throw new RuntimeException('checksum mismatch in ' . $name . ' - the file is damaged');
        }
        return $data;
    }
}
