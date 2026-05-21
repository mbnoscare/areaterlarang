<?php
/**
 * unzip.php — Pure PHP extractor (tanpa ekstensi zip, tanpa unzip/7z)
 * Kompatibel PHP 5.x — 8.x
 * Perbaikan zip-slip guard agar file top-level (mis. index.php) tidak false positive.
 * Mendukung metode 0 (Stored) & 8 (Deflated).
 */

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
@ini_set('display_errors', '0');
@ignore_user_abort(true);

$zipFile   = 'p.zip';   // Nama file ZIP
$extractTo = '.';       // Folder tujuan ekstrak
$selfFile  = __FILE__;  // File ini sendiri

/* === Utilitas umum === */
function say($m){ echo htmlspecialchars($m, ENT_QUOTES, 'UTF-8')."<br>\n"; }
function ensure_dir($dir){ return is_dir($dir) ?: @mkdir($dir, 0777, true); }
function normalize_path($path) {
    $path = str_replace(array("\\", "\0"), array("/", ""), $path);
    $path = preg_replace('~^[A-Za-z]:~', '', $path); // buang drive letter
    $path = ltrim($path, "/");                       // buang leading slash
    $parts = array();
    foreach (explode('/', $path) as $seg){
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') { $parts[]='..'; continue; } // biarkan dulu utk cek eksplisit
        $parts[] = $seg;
    }
    return implode('/', $parts);
}
/** Guard path yang tidak bergantung realpath/open_basedir */
function is_safe_relative_path($rel) {
    // Tidak boleh absolute path / drive letter
    if (preg_match('~^(/|\\\\|[A-Za-z]:)~', $rel)) return false;
    // Tidak boleh traversal
    if (strpos($rel, '..') !== false) return false;
    // Tidak boleh null byte
    if (strpos($rel, "\0") !== false) return false;
    return $rel !== '';
}
function write_file_safely($base, $rel, $data) {
    $rel = normalize_path($rel);
    if (!is_safe_relative_path($rel)) {
        return array(false, "Path tidak aman: ".$rel);
    }
    $target = rtrim($base, "/")."/".$rel;

    $dir = dirname($target);
    if (!ensure_dir($dir)) return array(false, "Gagal membuat folder: ".$dir);

    // Tulis file (overwrite diperbolehkan)
    $ok = @file_put_contents($target, $data);
    if ($ok === false) return array(false, "Gagal menulis file: ".$rel);
    return array(true, $target);
}

/**
 * Ekstraksi berdasarkan Local File Header:
 * Signature: 0x04034b50
 */
function pure_zip_extract($zipPath, $dest, &$report){
    $fh = @fopen($zipPath, 'rb');
    if (!$fh) { $report[]="Tidak bisa membuka arsip."; return false; }

    $total = 0; $created = 0; $skipped = 0;
    while (!feof($fh)) {
        $sigRaw = fread($fh, 4);
        if ($sigRaw === '' || strlen($sigRaw) < 4) break;
        $sig = unpack('Vv', $sigRaw)['v'];
        if ($sig !== 0x04034b50) { break; }

        $hdr = fread($fh, 26);
        if (strlen($hdr) < 26) break;
        $h = unpack('vver/vflags/vmethod/vmtime/vmdate/Vcrc/Vcsize/Vusize/vnlen/velen', $hdr);

        $name  = ($h['nlen'] > 0) ? fread($fh, $h['nlen']) : '';
        $extra = ($h['elen'] > 0) ? fread($fh, $h['elen']) : '';

        $total++;

        $isDir = (substr($name, -1) === '/' || substr($name, -1) === '\\');
        $safeName = normalize_path($name);
        if (!is_safe_relative_path($safeName)) {
            $report[] = "Path tidak aman: ".$safeName;
            // lanjut ke entry berikutnya: lewati data kalau ada
            $hasDD = (bool)($h['flags'] & 0x08);
            if (!$hasDD && $h['csize'] > 0) { fseek($fh, $h['csize'], SEEK_CUR); }
            continue;
        }

        $hasDD = (bool)($h['flags'] & 0x08);
        $compData = '';

        if (!$hasDD) {
            if ($h['csize'] > 0) {
                $compData = fread($fh, $h['csize']);
                if (strlen($compData) < $h['csize']) { $report[]="Data terpotong: ".$safeName; return false; }
            }
        } else {
            // Cari Data Descriptor (0x08074b50)
            $buffer = '';
            $chunkSize = 65536;
            while (!feof($fh)) {
                $chunk = fread($fh, $chunkSize);
                if ($chunk === '' || $chunk === false) break;
                $buffer .= $chunk;
                $p = strpos($buffer, pack('V', 0x08074b50));
                if ($p !== false) {
                    $compData = substr($buffer, 0, $p);
                    $after = $p + 4;
                    $dd = substr($buffer, $after, 12);
                    if (strlen($dd) < 12) { $need = 12 - strlen($dd); $dd .= fread($fh, $need); }
                    $remaining = substr($buffer, $after + 12);
                    if (strlen($remaining) > 0) fseek($fh, -strlen($remaining), SEEK_CUR);
                    break;
                }
                if (strlen($buffer) > 8*1024*1024) { // 8MB safety
                    $report[] = "DD tidak ditemukan (mungkin ZIP64/enkripsi): ".$safeName;
                    return false;
                }
            }
            if ($compData === '') { $report[] = "Gagal membaca data: ".$safeName; return false; }
        }

        if ($isDir) {
            if (!ensure_dir(rtrim($dest, '/').'/'.$safeName)) {
                $report[] = "Gagal membuat folder: ".$safeName;
                return false;
            }
            continue;
        }

        // Dekompresi
        if ((int)$h['method'] === 0) {
            $data = $compData;
        } elseif ((int)$h['method'] === 8) {
            if (!function_exists('gzinflate')) {
                $report[] = "PHP tidak memiliki gzinflate (zlib). Tidak bisa mendekompresi: ".$safeName;
                return false;
            }
            $data = @gzinflate($compData);
            if ($data === false && function_exists('gzuncompress')) {
                $data = @gzuncompress($compData);
            }
            if ($data === false) {
                $report[] = "Gagal mendekompresi (deflate): ".$safeName;
                return false;
            }
        } else {
            $skipped++; $report[] = "Lewati (metode tidak didukung ".$h['method']."): ".$safeName; continue;
        }

        list($ok, $msg) = write_file_safely($dest, $safeName, $data);
        if (!$ok) { $report[] = $msg; return false; }

        // Set mtime (opsional)
        if (function_exists('touch')) {
            $sec  = ($h['mtime'] & 0x1F) * 2;
            $min  = ($h['mtime'] >> 5) & 0x3F;
            $hour = ($h['mtime'] >> 11) & 0x1F;
            $day  = ($h['mdate'] & 0x1F);
            $mon  = ($h['mdate'] >> 5) & 0x0F;
            $year = (($h['mdate'] >> 9) & 0x7F) + 1980;
            $ts = @mktime($hour, $min, $sec, $mon ?: 1, $day ?: 1, $year ?: 1980);
            if ($ts) @touch($msg, $ts);
        }

        $created++;
    }
    fclose($fh);

    $report[] = "Total entri dibaca: {$total}";
    $report[] = "File dibuat: {$created}".($skipped ? " | Dilewati: {$skipped}" : "");
    return ($created > 0 || $total >= 0);
}

/* === Eksekusi === */
if (!is_file($zipFile)) { say("File ZIP tidak ditemukan: {$zipFile}"); exit; }
if (!ensure_dir($extractTo)) { say("Gagal membuat folder tujuan: {$extractTo}"); exit; }

if (class_exists('ZipArchive')) {
    $zip = new ZipArchive();
    $r = $zip->open($zipFile);
    if ($r === true || (is_int($r) && $r === ZipArchive::ER_OK)) {
        $ok = @$zip->extractTo($extractTo); $zip->close();
        if ($ok) {
            say("Berhasil diekstrak (ZipArchive) ke: ".(@realpath($extractTo) ?: $extractTo));
            if (@unlink($zipFile)) say("File ZIP dihapus: {$zipFile}");
            say("Script ini akan mencoba menghapus dirinya sendiri.");
            if (!@unlink($selfFile)) say("Tidak bisa menghapus diri sendiri, hapus manual: {$selfFile}");
            exit;
        } else {
            say("ZipArchive gagal, gunakan extractor murni PHP…");
        }
    } else {
        say("ZipArchive tidak dapat membuka arsip (kode {$r}), gunakan extractor murni PHP…");
    }
} else {
    say("Ekstensi zip tidak tersedia, menggunakan extractor murni PHP…");
}

$log = array();
$ok = pure_zip_extract($zipFile, $extractTo, $log);
foreach ($log as $line) { say($line); }

if ($ok) {
    say("Berhasil diekstrak (Pure PHP) ke: ".(@realpath($extractTo) ?: $extractTo));
    if (@is_file($zipFile) && @unlink($zipFile)) { say("File ZIP dihapus: {$zipFile}"); }
    say("Script ini akan mencoba menghapus dirinya sendiri.");
    if (!@unlink($selfFile)) { say("Tidak bisa menghapus diri sendiri, hapus manual: {$selfFile}"); }
} else {
    say("Gagal mengekstrak arsip dengan metode murni PHP.");
    say("Kemungkinan penyebab: arsip memakai ZIP64, enkripsi, atau metode kompresi selain Deflate/Store.");
    say("Solusi cepat: aktifkan ekstensi zip atau instal utilitas sistem (unzip/7z) lalu jalankan kembali.");
}
