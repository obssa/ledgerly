<?php

declare(strict_types=1);

namespace App\Domain\Export;

/**
 * Writes a ZIP archive to an open file, entry by entry, deflated with zlib (stored as is without it). Written here
 * rather than with ZipArchive so the export needs no PHP extension the shared host may not have. Names are UTF-8;
 * no ZIP64, so an archive stays under 4 GB and 65,535 entries, far beyond one company's books.
 */
final class ZipWriter
{
    /** @var list<string> */
    private array $central = [];

    private int $offset = 0;

    /** @param resource $out */
    public function __construct(private $out)
    {
    }

    public function add(string $name, string $data): void
    {
        $crc = crc32($data);
        $deflated = function_exists('gzdeflate') ? gzdeflate($data, 6) : false;
        [$method, $body] = $deflated !== false ? [8, $deflated] : [0, $data];
        [$time, $date] = self::dosTime(time());

        $local = pack('VvvvvvVVVvv', 0x04034B50, 20, 0x0800, $method, $time, $date, $crc, strlen($body), strlen($data), strlen($name), 0) . $name;
        fwrite($this->out, $local);
        fwrite($this->out, $body);

        $this->central[] = pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, 0x0800, $method, $time, $date, $crc, strlen($body), strlen($data),
            strlen($name), 0, 0, 0, 0, 0, $this->offset) . $name;
        $this->offset += strlen($local) + strlen($body);
    }

    public function finish(): void
    {
        $directory = implode('', $this->central);
        fwrite($this->out, $directory);
        fwrite($this->out, pack('VvvvvVVv', 0x06054B50, 0, 0, count($this->central), count($this->central), strlen($directory), $this->offset, 0));
    }

    /** @return array{int, int} the MS-DOS time and date ZIP keeps */
    private static function dosTime(int $timestamp): array
    {
        $d = getdate($timestamp);

        return [
            ($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2),
            (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday'],
        ];
    }
}
