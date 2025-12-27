<?php
/**
 * 纯 PHP 混淆/压缩构建脚本（无需任何第三方扩展/Loader）。
 *
 * 目标：提升源码可读性门槛（类似“小旋风”那类壳），但不等同于不可逆加密。
 *
 * 用法：
 *   php index/tools/obfuscate.php --in index/lib/_lic/LicenseGuard.php --out index/lib/_lic/LicenseGuard.obf.php
 *   php index/tools/obfuscate.php --in index/lib/_lic/LicenseGuard.php --out index/lib/_lic/LicenseGuard.php --force
 */

declare(strict_types=1);

function usageAndExit(string $msg = ''): void
{
    if ($msg !== '') {
        fwrite(STDERR, $msg . "\n\n");
    }
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php index/tools/obfuscate.php --in <input.php> --out <output.php> [--force]\n\n");
    fwrite(STDERR, "Options:\n");
    fwrite(STDERR, "  --in     Input PHP file\n");
    fwrite(STDERR, "  --out    Output PHP file\n");
    fwrite(STDERR, "  --force  Allow overwriting output file\n");
    exit(1);
}

$args = $argv;
array_shift($args);

$ins = array();
$outs = array();
$force = false;

for ($i = 0; $i < count($args); $i++) {
    $a = $args[$i];
    if ($a === '--in') {
        $v = $args[$i + 1] ?? null;
        if (!is_string($v) || $v === '') {
            usageAndExit('Invalid --in');
        }
        $ins[] = $v;
        $i++;
        continue;
    }
    if ($a === '--out') {
        $v = $args[$i + 1] ?? null;
        if (!is_string($v) || $v === '') {
            usageAndExit('Invalid --out');
        }
        $outs[] = $v;
        $i++;
        continue;
    }
    if ($a === '--force') {
        $force = true;
        continue;
    }
    usageAndExit('Unknown arg: ' . $a);
}

if (count($ins) === 0 || count($outs) === 0 || count($ins) !== count($outs)) {
    usageAndExit('Missing --in/--out, or counts do not match');
}

for ($k = 0; $k < count($ins); $k++) {
    $in = $ins[$k];
    $out = $outs[$k];

    if (!is_file($in)) {
        usageAndExit('Input not found: ' . $in);
    }

    if (is_file($out) && !$force) {
        usageAndExit('Output exists (use --force to overwrite): ' . $out);
    }

    $src = file_get_contents($in);
    if (!is_string($src) || $src === '') {
        usageAndExit('Failed to read input: ' . $in);
    }

// 1) token 化：移除注释/Docblock，压缩空白
$tokens = token_get_all($src);

$reservedVars = array(
    '$this' => true,
    // PHP magic vars
    '$http_response_header' => true,
    '$argc' => true,
    '$argv' => true,
    '$GLOBALS' => true,
    '$_GET' => true,
    '$_POST' => true,
    '$_SERVER' => true,
    '$_COOKIE' => true,
    '$_FILES' => true,
    '$_ENV' => true,
    '$_REQUEST' => true,
    '$_SESSION' => true,
);

$varMap = array();
$varSeq = 0;

$minified = '';
$prevWasSpace = false;

$encodeVar = function (string $name) use (&$varMap, &$varSeq, $reservedVars): string {
    if (isset($reservedVars[$name])) {
        return $name;
    }
    // 不处理变量变量（例如 $$x）
    if ($name === '$$' || strpos($name, '$$') === 0) {
        return $name;
    }
    if (!isset($varMap[$name])) {
        // 更难读的变量名：随机、短、易混淆字符组合（提升人工阅读成本）
        $first = 'lI';
        $rest = 'lI10O0o';
        do {
            $len = random_int(3, 7);
            $s = $first[random_int(0, strlen($first) - 1)];
            for ($j = 1; $j < $len; $j++) {
                $s .= $rest[random_int(0, strlen($rest) - 1)];
            }
            $candidate = '$__' . $s;
        } while (in_array($candidate, $varMap, true));
        $varMap[$name] = $candidate;
        $varSeq++;
    }
    return $varMap[$name];
};

for ($i = 0; $i < count($tokens); $i++) {
    $t = $tokens[$i];

    if (is_array($t)) {
        $id = $t[0];
        $text = $t[1];

        if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
            continue;
        }

        if ($id === T_WHITESPACE) {
            if (!$prevWasSpace) {
                $minified .= ' ';
                $prevWasSpace = true;
            }
            continue;
        }

        if ($id === T_VARIABLE) {
            $minified .= $encodeVar($text);
            $prevWasSpace = false;
            continue;
        }

        // 其它 token 原样输出
        $minified .= $text;
        $prevWasSpace = false;
        continue;
    }

    // 单字符 token
    $ch = $t;

    // 清理多余空格：在某些符号前后可删
    if ($ch === ';' || $ch === ',' || $ch === ')' || $ch === ']' || $ch === '}' ) {
        $minified = rtrim($minified);
        $minified .= $ch;
        $prevWasSpace = false;
        continue;
    }

    if ($ch === '(' || $ch === '[' || $ch === '{') {
        $minified = rtrim($minified);
        $minified .= $ch;
        $prevWasSpace = false;
        continue;
    }

    $minified .= $ch;
    $prevWasSpace = false;
}

$minified = trim($minified) . "\n";

// 2) 再走一遍 PHP 自带 strip（进一步去掉空白/注释）；注意它要求写入临时文件
$tmp = sys_get_temp_dir() . '/wwppcms_obf_' . bin2hex(random_bytes(6)) . '.php';
file_put_contents($tmp, $minified);
$final = php_strip_whitespace($tmp);
@unlink($tmp);

if (!is_string($final) || $final === '') {
    usageAndExit('php_strip_whitespace failed');
}

// 确保输出体没有 php open tag
$body = preg_replace('/^<\?php\s*/', '', $final);

$header = "<?php\n" .
    "// Generated by index/tools/obfuscate.php at " . gmdate('c') . "\n" .
    "// NOTE: This is obfuscation/minification (no Loader). Do not edit.\n\n";

// 仅对授权驱动注入“上下文限制 + 自完整性校验”壳，避免对 core 类文件造成 autoload 失败
$shouldWrapLicense = (strpos($body, 'final class LicenseGuard') !== false);

if ($shouldWrapLicense) {
    $markerPlaceholder = '/*__WWPP_SELFHASH:' . str_repeat('0', 64) . '__*/';
    $markerPattern = '/\\/\\*__WWPP_SELFHASH:([a-f0-9]{64})__\\*\\//';

    $guard = <<<'PHP'
if (!(defined('WWPPCMS_LICENSE_CTX') && WWPPCMS_LICENSE_CTX === 1 && class_exists('Wwppcms', false))) {
    if (!headers_sent()) { http_response_code(403); header('Content-Type: text/plain; charset=UTF-8'); }
    exit('access denied');
}

$__wwpp_raw = @file_get_contents(__FILE__);
if (is_string($__wwpp_raw)) {
    $__wwpp_pat = '/\/\*__WWPP_SELFHASH:([a-f0-9]{64})__\*\//';
    if (preg_match($__wwpp_pat, $__wwpp_raw, $__wwpp_m)) {
        $__wwpp_exp = $__wwpp_m[1];
        $__wwpp_calc = hash('sha256', preg_replace($__wwpp_pat, '', $__wwpp_raw));
        if (!hash_equals($__wwpp_exp, $__wwpp_calc)) {
            if (!defined('WWPP_SELF_TAMPER')) { define('WWPP_SELF_TAMPER', 1); }
        }
    }
}
PHP;

    $wrapper = $header . $guard . "\n" . $markerPlaceholder . "\n\n" . $body;
    $calc = hash('sha256', preg_replace($markerPattern, '', $wrapper));
    $wrapper = str_replace($markerPlaceholder, '/*__WWPP_SELFHASH:' . $calc . '__*/', $wrapper);
} else {
    $wrapper = $header . $body;
}

$dir = dirname($out);
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

file_put_contents($out, $wrapper);

fwrite(STDOUT, "OK\n");
fwrite(STDOUT, "Input : {$in}\n");
fwrite(STDOUT, "Output: {$out}\n");
fwrite(STDOUT, "Vars  : " . count($varMap) . " renamed\n");
fwrite(STDOUT, "Wrap  : " . ($shouldWrapLicense ? 'license' : 'none') . "\n\n");
}
