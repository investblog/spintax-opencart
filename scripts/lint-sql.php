<?php
/**
 * SQL-safety lint — fail the build if a query is assembled in a way the marketplace
 * scanner rejects.
 *
 * TWO rules, and the second is newer than the first:
 *
 *   1. No INTERPOLATION in a SQL string — "... WHERE id = {$id}".
 *   2. No CONCATENATION inside a `->query(...)` argument — compose the statement into a
 *      variable first and pass that variable alone.
 *
 * Rule 2 exists because rule 1 was never the whole contract, and this file used to say
 * the opposite. Its previous docblock recommended
 *
 *     GOOD:  "... WHERE name = '" . $this->db->escape($name) . "'"
 *
 * which is exactly the shape opencartforum's scanner flags. The scanner keys on the
 * concatenation itself, not on data flow: it cannot see that the value was escaped,
 * because the escaping sits inside the expression it is already refusing to read. A
 * previous release moved escaping to the point of substitution to satisfy it and was
 * rejected again — which is what identified the CALL SITE, rather than the escaping, as
 * the thing that has to change.
 *
 * So the rule is now:
 *
 *     GOOD:  $sql = sprintf("... WHERE name = '%s'", $db->escape($name));
 *            $db->query($sql);
 *     GOOD:  $db->query('SELECT LAST_INSERT_ID() AS id');      // bare literal
 *     BAD:   $db->query("... WHERE name = '" . $db->escape($name) . "'");
 *     BAD:   $db->query("... WHERE id = {$id}");
 *
 * Identifiers (table and column names) cannot be escaped at all, so they go through
 * `SqlIdentifiers::table()` / `column()`, which accept only `^[a-z_]+$`.
 *
 * HOW rule 1 works: PHP emits T_ENCAPSED_AND_WHITESPACE only INSIDE an interpolated
 * double-quoted string or heredoc. Plain concatenation never produces one — each quoted
 * part is a single T_CONSTANT_ENCAPSED_STRING. So an interpolated chunk containing a
 * backtick or a SQL verb is, by construction, an interpolated SQL string.
 *
 * HOW rule 2 works: find each `->query(`, walk to its matching `)`, and fail on a `.`
 * at the TOP level of that argument list. Depth is tracked so a `.` inside a nested call
 * — `sprintf('%s', $a . $b)` — is not mistaken for the query's own concatenation.
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

/**
 * Rule 2: a `.` at the top level of a `->query( … )` argument list.
 *
 * @param array<int, mixed> $tokens
 * @return array<int, array{line:int, snippet:string}>
 */
function concatenatedQueryArgs(array $tokens): array
{
    $found = array();
    $count = count($tokens);

    for ($i = 1; $i < $count; $i++) {
        $tok = $tokens[$i];
        $prev = $tokens[$i - 1];

        $isCall = is_array($tok)
            && $tok[0] === T_STRING
            && $tok[1] === 'query'
            && is_array($prev)
            && ($prev[0] === T_OBJECT_OPERATOR || $prev[0] === T_DOUBLE_COLON);

        if (!$isCall) {
            continue;
        }

        // Skip whitespace to the opening paren; anything else means it was not a call.
        $j = $i + 1;
        while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if ($j >= $count || $tokens[$j] !== '(') {
            continue;
        }

        $depth = 0;
        $line = $tok[2];
        $snippet = '';

        for ($k = $j; $k < $count; $k++) {
            $text = is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];

            if ($text === '(') {
                $depth++;
            } elseif ($text === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            } elseif ($depth === 1 && $text === '.') {
                $found[] = array(
                    'line' => $line,
                    'snippet' => trim((string) preg_replace('/\s+/', ' ', $snippet)),
                );
                break;
            }

            if ($depth >= 1) {
                $snippet .= $text;
            }
        }
    }

    return $found;
}

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

        // Rule 1 — interpolation.
        foreach ($tokens as $tok) {
            if (!is_array($tok) || $tok[0] !== T_ENCAPSED_AND_WHITESPACE) {
                continue; // only literal chunks of an *interpolated* string reach here
            }
            $text = $tok[1];
            if (false !== strpos($text, '`') || preg_match($sqlVerb, $text)) {
                $violations[] = sprintf('%s:%d  interpolated SQL: %s', $path, $tok[2], trim($text));
            }
        }

        // Rule 2 — concatenation in the query() argument.
        foreach (concatenatedQueryArgs($tokens) as $hit) {
            $violations[] = sprintf(
                '%s:%d  concatenation inside query(): %s',
                $path,
                $hit['line'],
                mb_substr($hit['snippet'], 0, 90)
            );
        }
    }
}

if (!empty($violations)) {
    fwrite(STDERR, "SQL-safety lint FAILED.\n");
    fwrite(STDERR, "Compose the statement into \$sql (sprintf + table()/column()/escape()), then pass \$sql alone.\n\n");
    foreach ($violations as $v) {
        fwrite(STDERR, "  {$v}\n");
    }
    fwrite(STDERR, "\n" . count($violations) . " violation(s).\n");
    exit(1);
}

echo "SQL-safety lint OK — no interpolated SQL and no concatenated query() argument under: "
    . implode(', ', $roots) . "\n";
exit(0);
