<?php
/**
 * SQL-safety lint — fail the build if any SQL query is assembled with PHP string
 * INTERPOLATION ("... {$x} ..." / "... $x ...") instead of concatenation with a
 * visible escaper.
 *
 * Why: OpenCart marketplace / opencartforum moderation scanners reject interpolated
 * queries even when the value was escaped on a prior line — they key on the pattern,
 * not on data flow. Our rule (see CLAUDE.md) is that escaping must be VISIBLE at the
 * point of use:
 *
 *     GOOD:  "... WHERE name = '" . $this->db->escape($name) . "'"
 *     GOOD:  "... WHERE id = "    . (int) $id
 *     BAD:   "... WHERE name = '{$n}'"          // interpolated — rejected
 *     BAD:   "... WHERE id = {$id}"             // interpolated — rejected
 *
 * How (precise for this codebase, which always backtick-quotes identifiers):
 * PHP only emits T_ENCAPSED_AND_WHITESPACE tokens INSIDE an interpolated double-quoted
 * string or heredoc. Plain concatenation ("..." . $x . "...") never produces them —
 * each quoted part is a single T_CONSTANT_ENCAPSED_STRING. So an interpolated literal
 * chunk that contains a backtick or a SQL verb is, by construction, an interpolated
 * SQL string — which is exactly what we forbid. Non-SQL interpolations (parser token
 * keys, exception messages, event-route strings) contain neither, so they pass.
 *
 * Usage:  php scripts/lint-sql.php [dir ...]      (defaults to extension/upload)
 * Exit:   0 = clean, 1 = violations found.
 */

declare(strict_types=1);

$roots = array_slice($argv, 1);
if (empty($roots)) {
    $roots = array(__DIR__ . '/../extension/upload');
}

// Uppercase SQL verbs only (this codebase writes SQL keywords in caps; lowercase
// English/route words like "delete" in an event route must NOT match). The backtick
// check is the primary detector — every real query quotes an identifier — and this
// is a belt-and-suspenders second signal. Case-SENSITIVE on purpose.
$sqlVerb = '/(?:^|[^A-Za-z_])(SELECT|INSERT|UPDATE|DELETE|REPLACE|CREATE\s+TABLE|DROP\s+TABLE|ALTER\s+TABLE|TRUNCATE)(?:[^A-Za-z_]|$)/';

$violations = array();

foreach ($roots as $root) {
    if (!is_dir($root) && !is_file($root)) {
        fwrite(STDERR, "lint-sql: path not found: {$root}\n");
        exit(2);
    }
    $files = is_dir($root)
        ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS))
        : array(new SplFileInfo($root));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        $tokens = token_get_all((string) file_get_contents($path));
        foreach ($tokens as $tok) {
            if (!is_array($tok) || $tok[0] !== T_ENCAPSED_AND_WHITESPACE) {
                continue; // only literal chunks of an *interpolated* string reach here
            }
            $text = $tok[1];
            if (false !== strpos($text, '`') || preg_match($sqlVerb, $text)) {
                $violations[] = sprintf('%s:%d  %s', $path, $tok[2], trim($text));
            }
        }
    }
}

if (!empty($violations)) {
    fwrite(STDERR, "SQL-safety lint FAILED — interpolated SQL detected.\n");
    fwrite(STDERR, "Build queries with concatenation + \$this->db->escape() / (int), never \"...{\$var}...\".\n\n");
    foreach ($violations as $v) {
        fwrite(STDERR, "  {$v}\n");
    }
    fwrite(STDERR, "\n" . count($violations) . " violation(s).\n");
    exit(1);
}

echo "SQL-safety lint OK — no interpolated SQL under: " . implode(', ', $roots) . "\n";
exit(0);
