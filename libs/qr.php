<?php

/**
 * Minimal QR generator for Version 1, ECC level M, Alphanumeric mode.
 * Produces SVG without requiring GD (works in a typical XAMPP PHP setup).
 *
 * Payload constraints (QR v1-M alphanumeric capacity): up to 20 chars.
 */

function qr_v1m_alnum_svg(string $text, int $scale = 6, int $margin = 4): string
{
    $text = strtoupper(trim($text));
    if ($text === '') {
        throw new InvalidArgumentException("QR payload cannot be empty.");
    }
    if (strlen($text) > 20) {
        throw new InvalidArgumentException("QR payload too long for QR v1-M alphanumeric (max 20 chars).");
    }

    $size = 21; // Version 1
    $dataCodewordsLen = 16; // v1-M
    $eccLen = 10; // v1-M

    // ----------------------------
    // 1) Encode data (alphanumeric)
    // ----------------------------
    $bits = [];
    $appendBits = function (int $val, int $len) use (&$bits): void {
        for ($i = $len - 1; $i >= 0; $i--) {
            $bits[] = ($val >> $i) & 1;
        }
    };

    $alphaVal = function (string $ch): int {
        if ($ch >= '0' && $ch <= '9') {
            return ord($ch) - ord('0');
        }
        if ($ch >= 'A' && $ch <= 'Z') {
            return ord($ch) - ord('A') + 10;
        }
        return match ($ch) {
            ' ' => 36,
            '$' => 37,
            '%' => 38,
            '*' => 39,
            '+' => 40,
            '-' => 41,
            '.' => 42,
            '/' => 43,
            ':' => 44,
            default => -1,
        };
    };

    // Mode indicator: alphanumeric (0010)
    $appendBits(0b0010, 4);
    // Character count indicator: 9 bits (version 1..9)
    $appendBits(strlen($text), 9);

    for ($i = 0; $i + 1 < strlen($text); $i += 2) {
        $v1 = $alphaVal($text[$i]);
        $v2 = $alphaVal($text[$i + 1]);
        if ($v1 < 0 || $v2 < 0) {
            throw new InvalidArgumentException("Invalid QR alphanumeric character.");
        }
        $appendBits(45 * $v1 + $v2, 11);
    }

    if (strlen($text) % 2 === 1) {
        $v = $alphaVal($text[strlen($text) - 1]);
        if ($v < 0) {
            throw new InvalidArgumentException("Invalid QR alphanumeric character.");
        }
        $appendBits($v, 6);
    }

    // Terminator (up to 4 bits)
    $maxDataBits = $dataCodewordsLen * 8;
    $remaining = $maxDataBits - count($bits);
    if ($remaining > 0) {
        $appendBits(0, min(4, $remaining));
    }

    // Pad to byte boundary
    while ((count($bits) % 8) !== 0) {
        $bits[] = 0;
    }

    // Convert to data codewords
    $data = [];
    for ($i = 0; $i < count($bits); $i += 8) {
        $byte = 0;
        for ($j = 0; $j < 8; $j++) {
            $byte = ($byte << 1) | $bits[$i + $j];
        }
        $data[] = $byte;
    }

    // Pad codewords
    $padBytes = [0xEC, 0x11];
    $padIndex = 0;
    while (count($data) < $dataCodewordsLen) {
        $data[] = $padBytes[$padIndex % 2];
        $padIndex++;
    }

    // ----------------------------
    // 2) Reed-Solomon ECC (GF256)
    // ----------------------------
    $gfExp = array_fill(0, 512, 0);
    $gfLog = array_fill(0, 256, 0);
    $x = 1;
    for ($i = 0; $i < 255; $i++) {
        $gfExp[$i] = $x;
        $gfLog[$x] = $i;
        $x <<= 1;
        if ($x & 0x100) {
            $x ^= 0x11D;
        }
    }
    for ($i = 255; $i < 512; $i++) {
        $gfExp[$i] = $gfExp[$i - 255];
    }

    $gfMul = function (int $a, int $b) use (&$gfExp, &$gfLog): int {
        if ($a === 0 || $b === 0) return 0;
        return $gfExp[$gfLog[$a] + $gfLog[$b]];
    };

    $polyMul = function (array $p, array $q) use (&$gfMul): array {
        $res = array_fill(0, count($p) + count($q) - 1, 0);
        for ($i = 0; $i < count($p); $i++) {
            for ($j = 0; $j < count($q); $j++) {
                $res[$i + $j] ^= $gfMul($p[$i], $q[$j]);
            }
        }
        return $res;
    };

    // Generator polynomial for eccLen
    $gen = [1];
    for ($i = 0; $i < $eccLen; $i++) {
        $gen = $polyMul($gen, [1, $gfExp[$i]]);
    }
    array_shift($gen); // remove leading term

    $ecc = array_fill(0, $eccLen, 0);
    foreach ($data as $byte) {
        $factor = $byte ^ $ecc[0];
        array_shift($ecc);
        $ecc[] = 0;
        for ($i = 0; $i < $eccLen; $i++) {
            $ecc[$i] ^= $gfMul($gen[$i], $factor);
        }
    }

    $codewords = array_merge($data, $ecc);
    $codeBits = [];
    foreach ($codewords as $cw) {
        for ($i = 7; $i >= 0; $i--) {
            $codeBits[] = ($cw >> $i) & 1;
        }
    }

    // ----------------------------
    // 3) Build base QR matrix
    // ----------------------------
    $modules = array_fill(0, $size, array_fill(0, $size, null));
    $isFunction = array_fill(0, $size, array_fill(0, $size, false));

    $setFunction = function (int $x, int $y, bool $dark) use (&$modules, &$isFunction): void {
        $modules[$y][$x] = $dark;
        $isFunction[$y][$x] = true;
    };

    $drawFinder = function (int $x, int $y) use (&$setFunction, &$size): void {
        for ($dy = -1; $dy <= 7; $dy++) {
            for ($dx = -1; $dx <= 7; $dx++) {
                $xx = $x + $dx;
                $yy = $y + $dy;
                if ($xx < 0 || $yy < 0 || $xx >= $size || $yy >= $size) continue;

                $isBorder = ($dx === -1 || $dx === 7 || $dy === -1 || $dy === 7);
                if ($isBorder) {
                    $setFunction($xx, $yy, false); // separator (white)
                } else {
                    $mx = max(abs($dx - 3), abs($dy - 3));
                    $setFunction($xx, $yy, ($mx === 0 || $mx === 1 || $mx === 3));
                }
            }
        }
    };

    $drawFinder(0, 0);
    $drawFinder($size - 7, 0);
    $drawFinder(0, $size - 7);

    // Timing patterns
    for ($i = 8; $i < $size - 8; $i++) {
        $dark = ($i % 2) === 0;
        $setFunction($i, 6, $dark);
        $setFunction(6, $i, $dark);
    }

    // Dark module
    $setFunction(8, 4 * 1 + 9, true); // (8, 13)

    // Reserve format info areas (set as function modules)
    for ($i = 0; $i <= 5; $i++) $setFunction(8, $i, false);
    $setFunction(8, 7, false);
    $setFunction(8, 8, false);
    $setFunction(7, 8, false);
    for ($i = 0; $i < 6; $i++) $setFunction($i, 8, false);
    $setFunction(8, $size - 1, false);
    for ($i = 0; $i < 8; $i++) $setFunction($size - 1 - $i, 8, false);
    for ($i = 0; $i < 7; $i++) $setFunction(8, $size - 7 + $i, false);

    // Place data bits
    $bitIndex = 0;
    $upward = true;
    for ($x = $size - 1; $x > 0; $x -= 2) {
        if ($x === 6) $x--; // skip timing column
        for ($i = 0; $i < $size; $i++) {
            $y = $upward ? ($size - 1 - $i) : $i;
            for ($dx = 0; $dx < 2; $dx++) {
                $xx = $x - $dx;
                if ($isFunction[$y][$xx]) continue;
                $modules[$y][$xx] = ($bitIndex < count($codeBits)) ? ((bool)$codeBits[$bitIndex++]) : false;
            }
        }
        $upward = !$upward;
    }

    $maskCondition = function (int $mask, int $x, int $y): bool {
        return match ($mask) {
            0 => (($x + $y) % 2) === 0,
            1 => ($y % 2) === 0,
            2 => ($x % 3) === 0,
            3 => (($x + $y) % 3) === 0,
            4 => (((int)floor($y / 2) + (int)floor($x / 3)) % 2) === 0,
            5 => ((($x * $y) % 2) + (($x * $y) % 3)) === 0,
            6 => (((($x * $y) % 2) + (($x * $y) % 3)) % 2) === 0,
            7 => (((($x + $y) % 2) + (($x * $y) % 3)) % 2) === 0,
            default => false,
        };
    };

    $formatBitsForMask = function (int $mask): int {
        // ECC level M => 00
        $data = ($mask & 0x7); // 5 bits with EC=00
        $rem = $data << 10;
        $gen = 0x537;
        for ($i = 14; $i >= 10; $i--) {
            if ((($rem >> $i) & 1) !== 0) {
                $rem ^= $gen << ($i - 10);
            }
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;
        return $bits & 0x7FFF;
    };

    $drawFormat = function (array &$m, int $mask) use (&$setFunction, &$size, &$formatBitsForMask): void {
        $bits = $formatBitsForMask($mask);
        $getBit = function (int $i) use ($bits): bool {
            return ((($bits >> $i) & 1) !== 0);
        };

        // First copy
        for ($i = 0; $i <= 5; $i++) $m[$i][8] = $getBit($i);
        $m[7][8] = $getBit(6);
        $m[8][8] = $getBit(7);
        $m[8][7] = $getBit(8);
        for ($i = 9; $i < 15; $i++) $m[8][14 - $i] = $getBit($i);

        // Second copy
        for ($i = 0; $i < 8; $i++) $m[8][$size - 1 - $i] = $getBit($i);
        for ($i = 8; $i < 15; $i++) $m[$size - 15 + $i][8] = $getBit($i);

        // Fixed dark module already set
    };

    $penalty = function (array $m) use ($size): int {
        $darkCount = 0;
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($m[$y][$x]) $darkCount++;
            }
        }

        $pen = 0;

        // N1: rows
        for ($y = 0; $y < $size; $y++) {
            $runColor = $m[$y][0];
            $runLen = 1;
            for ($x = 1; $x < $size; $x++) {
                if ($m[$y][$x] === $runColor) {
                    $runLen++;
                } else {
                    if ($runLen >= 5) $pen += 3 + ($runLen - 5);
                    $runColor = $m[$y][$x];
                    $runLen = 1;
                }
            }
            if ($runLen >= 5) $pen += 3 + ($runLen - 5);
        }

        // N1: cols
        for ($x = 0; $x < $size; $x++) {
            $runColor = $m[0][$x];
            $runLen = 1;
            for ($y = 1; $y < $size; $y++) {
                if ($m[$y][$x] === $runColor) {
                    $runLen++;
                } else {
                    if ($runLen >= 5) $pen += 3 + ($runLen - 5);
                    $runColor = $m[$y][$x];
                    $runLen = 1;
                }
            }
            if ($runLen >= 5) $pen += 3 + ($runLen - 5);
        }

        // N2: 2x2 blocks
        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                $c = $m[$y][$x];
                if ($c === $m[$y][$x + 1] && $c === $m[$y + 1][$x] && $c === $m[$y + 1][$x + 1]) {
                    $pen += 3;
                }
            }
        }

        $finderLike1 = [1,0,1,1,1,0,1,0,0,0,0];
        $finderLike2 = [0,0,0,0,1,0,1,1,1,0,1];

        $scanPattern = function (array $line) use ($finderLike1, $finderLike2): int {
            $count = 0;
            for ($i = 0; $i <= count($line) - 11; $i++) {
                $slice = array_slice($line, $i, 11);
                if ($slice === $finderLike1 || $slice === $finderLike2) {
                    $count++;
                }
            }
            return $count;
        };

        // N3: rows
        for ($y = 0; $y < $size; $y++) {
            $line = [];
            for ($x = 0; $x < $size; $x++) $line[] = $m[$y][$x] ? 1 : 0;
            $pen += 40 * $scanPattern($line);
        }

        // N3: cols
        for ($x = 0; $x < $size; $x++) {
            $line = [];
            for ($y = 0; $y < $size; $y++) $line[] = $m[$y][$x] ? 1 : 0;
            $pen += 40 * $scanPattern($line);
        }

        // N4: balance of dark modules
        $total = $size * $size;
        $k = (int)floor(abs(($darkCount * 100 / $total) - 50) / 5);
        $pen += $k * 10;

        return $pen;
    };

    $bestMask = 0;
    $bestPenalty = PHP_INT_MAX;
    $best = null;

    for ($mask = 0; $mask < 8; $mask++) {
        $m = $modules;
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($isFunction[$y][$x]) continue;
                if ($maskCondition($mask, $x, $y)) {
                    $m[$y][$x] = !$m[$y][$x];
                }
            }
        }
        $drawFormat($m, $mask);
        $p = $penalty($m);
        if ($p < $bestPenalty) {
            $bestPenalty = $p;
            $bestMask = $mask;
            $best = $m;
        }
    }

    if ($best === null) {
        throw new RuntimeException("Failed to generate QR code.");
    }

    // ----------------------------
    // 4) Render SVG
    // ----------------------------
    $view = $size + $margin * 2;
    $svgW = $view * $scale;
    $svgH = $view * $scale;

    $svg = '<svg xmlns="http://www.w3.org/2000/svg"'
        . ' width="' . (int)$svgW . '" height="' . (int)$svgH . '"'
        . ' viewBox="0 0 ' . (int)$view . ' ' . (int)$view . '"'
        . ' shape-rendering="crispEdges">';
    $svg .= '<rect width="100%" height="100%" fill="#FFFFFF"/>';

    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            if ($best[$y][$x]) {
                $svg .= '<rect x="' . ($x + $margin) . '" y="' . ($y + $margin) . '" width="1" height="1" fill="#000000"/>';
            }
        }
    }

    $svg .= '</svg>';
    return $svg;
}
