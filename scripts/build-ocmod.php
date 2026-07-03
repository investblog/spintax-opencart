<?php
/**
 * Build the distributable OCMOD package.
 *
 * Structure (what OpenCart's Extensions → Installer expects):
 *   install.xml            — OCMOD modifications (menu injection, §10.3)
 *   upload/                — files copied into the docroot on install
 *     admin/…              — controller / model / view / language
 *     system/library/spintax/…  — the engine kernel + shims
 *
 * Only the runtime `upload/` tree is shipped — never vendor/, tests/, reference/
 * or composer files (those live outside upload/).
 *
 * Usage:  php build-ocmod.php <extension-dir> <output-zip>
 *   e.g.  php build-ocmod.php /opt/spintax /opt/spintax/spintax_seo.ocmod.zip
 * (PHP ZipArchive writes forward-slash entry paths, so the archive unzips
 * correctly on the Linux host OpenCart runs on.)
 */

declare(strict_types=1);

$src = rtrim($argv[1] ?? '', '/');
$out = $argv[2] ?? '';
if ('' === $src || '' === $out) {
    fwrite(STDERR, "usage: php build-ocmod.php <extension-dir> <output-zip>\n");
    exit(2);
}
if (!is_file($src . '/install.xml') || !is_dir($src . '/upload')) {
    fwrite(STDERR, "not an extension dir (need install.xml + upload/): {$src}\n");
    exit(1);
}

if (is_file($out)) {
    unlink($out);
}

$zip = new ZipArchive();
if (true !== $zip->open($out, ZipArchive::CREATE)) {
    fwrite(STDERR, "cannot create {$out}\n");
    exit(1);
}

$zip->addFile($src . '/install.xml', 'install.xml');

$base = $src . '/upload';
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
$count = 0;
foreach ($it as $file) {
    $rel = 'upload/' . str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
    if ($file->isDir()) {
        $zip->addEmptyDir($rel);
    } else {
        $zip->addFile($file->getPathname(), $rel);
        ++$count;
    }
}

$zip->close();
echo "Built {$out} (install.xml + {$count} upload files)\n";
