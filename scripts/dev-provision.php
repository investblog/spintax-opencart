<?php
/**
 * Dev-stand provisioning for Spintax OpenCart testing (idempotent).
 *
 * Runs INSIDE the web container (has mysqli + reaches the `db` service):
 *   docker compose cp scripts/dev-provision.php web:/tmp/dev-provision.php
 *   docker compose exec web php /tmp/dev-provision.php
 * or just: scripts/dev-provision.sh
 *
 * What it does:
 *   1. Enables OpenCart SEO URLs (config_seo_url = 1).
 *   2. Adds a second language slot `ru-ru` (DEV FIXTURE — English UI strings,
 *      Russian locale code so the engine's plural resolver (§13) exercises the
 *      ru/uk 3-form path and per-language_id writes are genuinely multilingual).
 *   3. Duplicates language_id=1 content into the new language across the catalog
 *      *_description / localization tables so the storefront + admin are clean.
 *
 * It does NOT touch oc_seo_url (the extension manages SEO keywords) or any
 * transactional/customer tables. Re-running is safe (INSERT IGNORE / upserts).
 */

declare(strict_types=1);

const DB_HOST   = 'db';
const DB_USER   = 'opencart';
const DB_PASS   = 'opencart';
const DB_NAME   = 'opencart';
const PREFIX    = 'oc_';
const LANG_CODE = 'ru-ru';
const LANG_NAME = 'Russian (dev clone)';
const LANG_LOCALE = 'ru-RU.UTF-8,ru_RU,ru-ru,russian';
const LANG_DIR  = 'ru-ru';

/** Catalog content + localization tables to clone (all keyed by composite PK incl. language_id). */
const CONTENT_TABLES = [
    'product_description', 'category_description', 'information_description',
    'attribute_description', 'attribute_group_description',
    'option_description', 'option_value_description',
    'filter_description', 'filter_group_description',
    'custom_field_description', 'custom_field_value_description',
    'download_description', 'recurring_description', 'voucher_theme_description',
    'length_class_description', 'weight_class_description',
    'stock_status', 'order_status', 'return_action', 'return_reason', 'return_status',
];

function fail(string $msg): never
{
    fwrite(STDERR, "ERROR: {$msg}\n");
    exit(1);
}

$db = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($db->connect_errno) {
    fail('DB connect failed: ' . $db->connect_error);
}
$db->set_charset('utf8mb4');

// ---- 1. Enable SEO URLs -----------------------------------------------------
$db->query("UPDATE `" . PREFIX . "setting` SET `value` = '1' WHERE `key` = 'config_seo_url'");
echo "SEO URLs: config_seo_url = 1\n";

// ---- 2. Ensure the second language slot ------------------------------------
$code = $db->real_escape_string(LANG_CODE);
$res  = $db->query("SELECT language_id FROM `" . PREFIX . "language` WHERE code = '{$code}'");
$row  = $res->fetch_assoc();

if ($row) {
    $langId = (int) $row['language_id'];
    $db->query(
        "UPDATE `" . PREFIX . "language` SET status = 1, sort_order = 2, directory = '" . LANG_DIR . "' "
        . "WHERE language_id = {$langId}"
    );
    echo "Language: '" . LANG_CODE . "' already present (id {$langId}) — ensured status=1\n";
} else {
    $name   = $db->real_escape_string(LANG_NAME);
    $locale = $db->real_escape_string(LANG_LOCALE);
    $dir    = $db->real_escape_string(LANG_DIR);
    $db->query(
        "INSERT INTO `" . PREFIX . "language` (name, code, locale, image, directory, sort_order, status) "
        . "VALUES ('{$name}', '{$code}', '{$locale}', '', '{$dir}', 2, 1)"
    );
    $langId = (int) $db->insert_id;
    echo "Language: added '" . LANG_CODE . "' (id {$langId})\n";
}

// ---- 3. Duplicate language_id=1 content into the new language --------------
$copied = [];
foreach (CONTENT_TABLES as $short) {
    $table = PREFIX . $short;

    // Table exists?
    if (!$db->query("SHOW TABLES LIKE '{$table}'")->num_rows) {
        continue;
    }

    // Introspect columns; drop any AUTO_INCREMENT column (let it self-assign).
    $cols = [];
    $hasLang = false;
    $meta = $db->query("SHOW COLUMNS FROM `{$table}`");
    while ($c = $meta->fetch_assoc()) {
        if (stripos($c['Extra'], 'auto_increment') !== false) {
            continue;
        }
        $cols[] = $c['Field'];
        if ($c['Field'] === 'language_id') {
            $hasLang = true;
        }
    }
    if (!$hasLang) {
        continue;
    }

    // Build INSERT IGNORE ... SELECT with language_id swapped to the target.
    $insertCols = array_map(static fn($f) => "`{$f}`", $cols);
    $selectCols = array_map(
        static fn($f) => $f === 'language_id' ? (string) $langId : "`{$f}`",
        $cols
    );
    $sql = "INSERT IGNORE INTO `{$table}` (" . implode(', ', $insertCols) . ") "
         . "SELECT " . implode(', ', $selectCols) . " FROM `{$table}` WHERE language_id = 1";

    if (!$db->query($sql)) {
        fail("copy failed on {$table}: " . $db->error);
    }
    if ($db->affected_rows > 0) {
        $copied[$short] = $db->affected_rows;
    }
}

echo "Content cloned into language {$langId}:\n";
foreach ($copied as $t => $n) {
    echo "  +{$n}\t{$t}\n";
}
if (!$copied) {
    echo "  (nothing new — already provisioned)\n";
}

// ---- Report -----------------------------------------------------------------
echo "\nLanguages now active:\n";
$r = $db->query("SELECT language_id, name, code, status FROM `" . PREFIX . "language` ORDER BY sort_order");
while ($l = $r->fetch_assoc()) {
    echo "  [{$l['language_id']}] {$l['code']}  {$l['name']}  (status {$l['status']})\n";
}
$seo = $db->query("SELECT value FROM `" . PREFIX . "setting` WHERE `key`='config_seo_url'")->fetch_assoc();
echo "config_seo_url = {$seo['value']}\n";

$db->close();
echo "\nProvisioning complete.\n";
