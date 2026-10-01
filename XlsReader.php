<?php
/**
 * Minimal pure-PHP reader for classic Excel .xls (BIFF8).
 * Reads sheet names and cell values (numbers, labels, shared strings).
 */
class XlsReader
{
    private string $data = '';
    private array $sheets = []; // name => ['offset'=>, 'rows'=>]
    private array $sst = [];
    private ?array $pendingFormula = null; // [sheet, row, col] waiting for STRING
    private array $sheetNames = [];

    public function __construct(string $path)
    {
        $raw = file_get_contents($path);
        if ($raw === false || strlen($raw) < 512) {
            throw new RuntimeException('Не удалось прочитать .xls файл');
        }
        // OLE compound document
        if (substr($raw, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            throw new RuntimeException('Файл не похож на .xls (OLE)');
        }
        $this->data = $this->oleExtractWorkbook($raw);
        $this->parseBiff();
    }

    public function sheetNames(): array
    {
        return $this->sheetNames;
    }

    /** @return list<list<string|float|int|null>> */
    public function readSheet(string $name, bool $skipHidden = false): array
    {
        if (!isset($this->sheets[$name])) {
            return [];
        }
        $rows = $this->sheets[$name]['rows'];
        if (!$skipHidden) {
            return $rows;
        }
        // скрытые строки отбрасываются, ячейки скрытых столбцов обнуляются
        $hr = $this->sheets[$name]['hidRows'] ?? [];
        $hc = $this->sheets[$name]['hidCols'] ?? [];
        $out = [];
        foreach ($rows as $i => $r) {
            if (isset($hr[$i])) {
                continue;
            }
            foreach ($hc as $c => $_) {
                if (array_key_exists($c, $r)) {
                    $r[$c] = null;
                }
            }
            $out[] = $r;
        }
        return $out;
    }

    private function oleExtractWorkbook(string $ole): string
    {
        // Sector size
        $uShort = fn($o) => unpack('v', substr($ole, $o, 2))[1];
        $uLong = fn($o) => unpack('V', substr($ole, $o, 4))[1];
        $secSize = 1 << $uShort(30);
        $numFat = $uLong(44);
        $dirStart = $uLong(48);
        $fat = '';
        // First 109 FAT sector indices at offset 76
        for ($i = 0; $i < 109 && $i < $numFat; $i++) {
            $secId = $uLong(76 + $i * 4);
            if ($secId >= 0xFFFFFFFE) break;
            $fat .= substr($ole, 512 + $secId * $secSize, $secSize);
        }
        $getSec = function (int $id) use ($ole, $secSize) {
            return substr($ole, 512 + $id * $secSize, $secSize);
        };
        $readStream = function (int $start) use ($fat, $getSec, $uLong) {
            $out = '';
            $id = $start;
            $guard = 0;
            while ($id < 0xFFFFFFFE && $guard++ < 100000) {
                $out .= $getSec($id);
                $id = $uLong($id * 4); // from fat - wait fat is array of longs
            }
            return $out;
        };
        // Rebuild readStream properly
        $fatIds = [];
        $len = strlen($fat);
        for ($i = 0; $i + 4 <= $len; $i += 4) {
            $fatIds[] = unpack('V', substr($fat, $i, 4))[1];
        }
        $readChain = function (int $start) use ($fatIds, $getSec) {
            $out = '';
            $id = $start;
            $guard = 0;
            while ($id < 0xFFFFFFFE && $guard++ < 100000) {
                $out .= $getSec($id);
                if (!isset($fatIds[$id])) break;
                $id = $fatIds[$id];
            }
            return $out;
        };
        // Directory stream
        $dir = $readChain($dirStart);
        $workbookStart = null;
        for ($i = 0; $i + 128 <= strlen($dir); $i += 128) {
            $nameUtf16 = substr($dir, $i, 64);
            $name = iconv("UTF-16LE","UTF-8//IGNORE",$nameUtf16);
            $name = rtrim($name, "\0");
            $startSec = unpack('V', substr($dir, $i + 116, 4))[1];
            $size = unpack('V', substr($dir, $i + 120, 4))[1];
            if (strcasecmp($name, 'Workbook') === 0 || strcasecmp($name, 'Book') === 0) {
                $workbookStart = $startSec;
                $streamSize = $size;
                break;
            }
        }
        if ($workbookStart === null) {
            throw new RuntimeException('В .xls не найден поток Workbook');
        }
        $stream = $readChain($workbookStart);
        return substr($stream, 0, $streamSize ?? strlen($stream));
    }

    private function parseBiff(): void
    {
        $data = $this->data;
        $pos = 0;
        $len = strlen($data);
        $currentSheet = null;
        $sheetIndex = -1;
        $boundsheets = [];

        while ($pos + 4 <= $len) {
            $code = unpack('v', substr($data, $pos, 2))[1];
            $length = unpack('v', substr($data, $pos + 2, 2))[1];
            $pos += 4;
            if ($pos + $length > $len) break;
            $rec = substr($data, $pos, $length);
            $pos += $length;

            // BOF
            if ($code === 0x0809) {
                $type = unpack('v', substr($rec, 2, 2))[1];
                if ($type === 0x10) { // worksheet
                    $sheetIndex++;
                    $currentSheet = $sheetIndex;
                    if (!isset($this->sheets[$sheetIndex])) {
                        $this->sheets[$sheetIndex] = ['rows' => []];
                    }
                } else {
                    $currentSheet = null;
                }
                continue;
            }
            // EOF
            if ($code === 0x000A) {
                $currentSheet = null;
                continue;
            }
            // BOUNDSHEET
            if ($code === 0x0085) {
                $hidden = ord($rec[4] ?? "\0");
                $nameLen = ord($rec[6] ?? "\0");
                $opt = ord($rec[7] ?? "\0");
                $nameBytes = substr($rec, 8, $nameLen * (($opt & 1) ? 2 : 1));
                if ($opt & 1) {
                    $name = iconv("UTF-16LE","UTF-8//IGNORE",$nameBytes);
                } else {
                    $name = $nameBytes; // compressed unicode / latin
                    if (!preg_match("//u", $name)) {
                        $name = iconv("CP1251","UTF-8//IGNORE",$name) ?: $name;
                    }
                }
                // 0=visible, 1=hidden, 2=very hidden
                $boundsheets[] = ['name' => $name, 'hidden' => ((int)$hidden !== 0)];
                continue;
            }
            // SST
            if ($code === 0x00FC) {
                $this->parseSst($rec, $data, $pos, $len);
                continue;
            }
            // CONTINUE handled inside SST
            if ($currentSheet === null) continue;

            $row = null;
            $col = null;
            $val = null;

            // FORMULA (0x0006) — берём кэшированный результат
            if ($code === 0x0006 && $length >= 14 && $currentSheet !== null) {
                $row = unpack('v', substr($rec, 0, 2))[1];
                $col = unpack('v', substr($rec, 2, 2))[1];
                $res = substr($rec, 6, 8);
                $hi = unpack('v', substr($res, 6, 2))[1];
                if ($hi === 0xFFFF) {
                    $typ = ord($res[0]);
                    if ($typ === 0) {
                        // строка придёт в следующем STRING
                        $this->pendingFormula = [$currentSheet, $row, $col];
                    } elseif ($typ === 1) {
                        $this->setCell($currentSheet, $row, $col, ord($res[2]) ? true : false);
                    } elseif ($typ === 2) {
                        // error code
                        $errCodes = [0x00=>'#NULL!',0x07=>'#DIV/0!',0x0F=>'#VALUE!',0x17=>'#REF!',0x1D=>'#NAME?',0x24=>'#NUM!',0x2A=>'#N/A'];
                        $ec = ord($res[2]);
                        $this->setCell($currentSheet, $row, $col, $errCodes[$ec] ?? ('#ERR'.$ec));
                    } elseif ($typ === 3) {
                        $this->setCell($currentSheet, $row, $col, '');
                    }
                } else {
                    $val = unpack('d', $res)[1];
                    $this->setCell($currentSheet, $row, $col, $val);
                }
                continue;
            }
            // STRING (0x0207) — строковый результат формулы
            if ($code === 0x0207 && $this->pendingFormula !== null) {
                [$ps, $pr, $pc] = $this->pendingFormula;
                $this->pendingFormula = null;
                if ($length >= 3) {
                    $strLen = unpack('v', substr($rec, 0, 2))[1];
                    $flags = ord($rec[2] ?? "\0");
                    $raw = substr($rec, 3);
                    if ($flags & 1) {
                        $val = @iconv('UTF-16LE', 'UTF-8//IGNORE', substr($raw, 0, $strLen * 2)) ?: '';
                    } else {
                        $val = substr($raw, 0, $strLen);
                        if (!preg_match('//u', $val)) {
                            $val = @iconv('CP1251', 'UTF-8//IGNORE', $val) ?: $val;
                        }
                    }
                    $this->setCell($ps, $pr, $pc, $val);
                }
                continue;
            }
            // LABEL (0x0204)
            if ($code === 0x0204 && $length >= 8) {
                $row = unpack('v', substr($rec, 0, 2))[1];
                $col = unpack('v', substr($rec, 2, 2))[1];
                $strLen = unpack('v', substr($rec, 6, 2))[1];
                $flags = ord($rec[8] ?? "\0");
                $raw = substr($rec, 9);
                if ($flags & 1) {
                    $val = mb_convert_encoding(substr($raw, 0, $strLen * 2), 'UTF-8', 'UTF-16LE');
                } else {
                    $val = substr($raw, 0, $strLen);
                    if (!preg_match("//u", $val)) {
                        $val = iconv("CP1251","UTF-8//IGNORE",$val) ?: $val;
                    }
                }
            }
            // LABELSST (0x00FD)
            elseif ($code === 0x00FD && $length >= 10) {
                $row = unpack('v', substr($rec, 0, 2))[1];
                $col = unpack('v', substr($rec, 2, 2))[1];
                $idx = unpack('V', substr($rec, 6, 4))[1];
                $val = $this->sst[$idx] ?? '';
            }
            // NUMBER (0x0203)
            elseif ($code === 0x0203 && $length >= 14) {
                $row = unpack('v', substr($rec, 0, 2))[1];
                $col = unpack('v', substr($rec, 2, 2))[1];
                $val = unpack('d', substr($rec, 6, 8))[1];
            }
            // RK (0x027E)
            elseif ($code === 0x027E && $length >= 10) {
                $row = unpack('v', substr($rec, 0, 2))[1];
                $col = unpack('v', substr($rec, 2, 2))[1];
                $rk = unpack('V', substr($rec, 6, 4))[1];
                $val = $this->decodeRk($rk);
            }
            // MULRK
            elseif ($code === 0x00BD) {
                $row = unpack('v', substr($rec, 0, 2))[1];
                $colFirst = unpack('v', substr($rec, 2, 2))[1];
                $o = 4;
                $n = intdiv($length - 6, 6);
                for ($i = 0; $i < $n; $i++) {
                    $rk = unpack('V', substr($rec, $o + 2, 4))[1];
                    $v = $this->decodeRk($rk);
                    $this->setCell($currentSheet, $row, $colFirst + $i, $v);
                    $o += 6;
                }
                continue;
            }
            // ROW (0x0208): бит 0x20 в grbit — скрытая строка
            elseif ($code === 0x0208 && $length >= 14 && $currentSheet !== null) {
                $rw = unpack('v', substr($rec, 0, 2))[1];
                $grbit = unpack('v', substr($rec, 12, 2))[1];
                if ($grbit & 0x20) {
                    $this->sheets[$currentSheet]['hidRows'][$rw] = true;
                }
                continue;
            }
            // COLINFO (0x007D): бит 0 в grbit — скрытые столбцы colFirst..colLast
            elseif ($code === 0x007D && $length >= 12 && $currentSheet !== null) {
                $c1 = unpack('v', substr($rec, 0, 2))[1];
                $c2 = unpack('v', substr($rec, 2, 2))[1];
                $grbit = unpack('v', substr($rec, 8, 2))[1];
                if ($grbit & 0x01) {
                    for ($cc = $c1; $cc <= min($c2, 255); $cc++) {
                        $this->sheets[$currentSheet]['hidCols'][$cc] = true;
                    }
                }
                continue;
            }
            else {
                continue;
            }
            if ($row !== null && $col !== null) {
                $this->setCell($currentSheet, $row, $col, $val);
            }
        }

        // Map index to names (skip hidden sheets)
        $named = [];
        foreach ($boundsheets as $i => $info) {
            if (!empty($info['hidden'])) {
                continue;
            }
            $name = $info['name'];
            if (isset($this->sheets[$i])) {
                $named[$name] = $this->sheets[$i];
            }
        }
        $this->sheets = $named;
        $this->sheetNames = array_keys($named);
    }

    private function setCell(int $sheet, int $row, int $col, $val): void
    {
        if (!isset($this->sheets[$sheet])) {
            $this->sheets[$sheet] = ['rows' => []];
        }
        while (count($this->sheets[$sheet]['rows']) <= $row) {
            $this->sheets[$sheet]['rows'][] = [];
        }
        $r = &$this->sheets[$sheet]['rows'][$row];
        while (count($r) <= $col) {
            $r[] = null;
        }
        $r[$col] = $val;
    }

    private function decodeRk(int $rk)
    {
        $cent = $rk & 1;
        $int = $rk & 2;
        if ($int) {
            $v = ($rk >> 2);
            if ($v & 0x20000000) {
                $v = $v - 0x40000000;
            }
            return $cent ? $v / 100 : $v;
        }
        $hi = ($rk & 0xFFFFFFFC);
        $buf = pack('V', 0) . pack('V', $hi);
        $v = unpack('d', $buf)[1];
        return $cent ? $v / 100 : $v;
    }

    private function parseSst(string $rec, string &$data, int &$pos, int $len): void
    {
        if (strlen($rec) < 8) return;
        $total = unpack('V', substr($rec, 0, 4))[1];
        $unique = unpack('V', substr($rec, 4, 4))[1];
        $offset = 8;
        $blob = $rec;
        $this->sst = [];
        for ($i = 0; $i < $unique; $i++) {
            // need more data?
            while ($offset + 3 > strlen($blob) && $pos + 4 <= $len) {
                $code = unpack('v', substr($data, $pos, 2))[1];
                $clen = unpack('v', substr($data, $pos + 2, 2))[1];
                $pos += 4;
                if ($code !== 0x003C) { // CONTINUE
                    $pos -= 4;
                    break;
                }
                $blob .= substr($data, $pos, $clen);
                $pos += $clen;
            }
            if ($offset + 3 > strlen($blob)) break;
            $charCount = unpack('v', substr($blob, $offset, 2))[1];
            $flags = ord($blob[$offset + 2]);
            $offset += 3;
            $compressed = !($flags & 1);
            $ext = ($flags & 4) ? 1 : 0;
            $rich = ($flags & 8) ? 1 : 0;
            $rt = 0;
            $sz = 0;
            if ($rich) {
                $rt = unpack('v', substr($blob, $offset, 2))[1];
                $offset += 2;
            }
            if ($ext) {
                $sz = unpack('V', substr($blob, $offset, 4))[1];
                $offset += 4;
            }
            $byteLen = $compressed ? $charCount : $charCount * 2;
            while ($offset + $byteLen > strlen($blob) && $pos + 4 <= $len) {
                $code = unpack('v', substr($data, $pos, 2))[1];
                $clen = unpack('v', substr($data, $pos + 2, 2))[1];
                $pos += 4;
                if ($code !== 0x003C) {
                    $pos -= 4;
                    break;
                }
                // CONTINUE may switch encoding flag
                $contFlags = ord($data[$pos] ?? "\0");
                // simplified: append
                $chunk = substr($data, $pos, $clen);
                // if unicode continue, first byte is option flags
                if (!$compressed && $clen > 0) {
                    // often first byte is flags again
                }
                $blob .= $chunk;
                $pos += $clen;
            }
            $raw = substr($blob, $offset, $byteLen);
            $offset += $byteLen;
            if ($rich) {
                $offset += $rt * 4;
            }
            if ($ext) {
                $offset += $sz;
            }
            if ($compressed) {
                $str = $raw;
                if (!preg_match("//u", $str)) {
                    $str = iconv("CP1251","UTF-8//IGNORE",$str) ?: $str;
                }
            } else {
                $str = iconv("UTF-16LE","UTF-8//IGNORE",$raw);
            }
            $this->sst[] = $str;
        }
    }
}
