<?php

/**
 * GEDCOM 7 export regression tests, using the real webtrees export dependencies.
 * Run: php tests/gedcom7.php /path/to/webtrees [module-root]
 * The webtrees release and module's Composer dependencies must be installed.
 * No web application, database, network or PHPUnit installation is needed.
 * Licensed under GPL-3.0-or-later, as the module itself.
 */
declare(strict_types=1);

use Fisharebest\Webtrees\Factories\EncodingFactory;
use Fisharebest\Webtrees\GedcomFilters\GedcomEncodingFilter;
use Fisharebest\Webtrees\Registry;
use Illuminate\Support\Collection;
use Jefferson49\Webtrees\Module\ExtendedImportExport\DownloadGedcomWithURL;
use Jefferson49\Webtrees\Module\ExtendedImportExport\FilteredGedcomExportService;
use Jefferson49\Webtrees\Module\ExtendedImportExport\GEDCOM_7_GedcomFilter;
use Jefferson49\Webtrees\Module\ExtendedImportExport\RemoveWebtreesUserGedcomFilter;
use Nyholm\Psr7\Factory\Psr17Factory;

$core = $argv[1] ?? throw new InvalidArgumentException('Pass the webtrees release directory');
$module = $argv[2] ?? dirname(__DIR__);
require $core . '/vendor/autoload.php';
require $module . '/autoload.php';
require $module . '/resources/filter/GEDCOM_7_GedcomFilter.php';
require $module . '/resources/filter/RemoveWebtreesUserGedcomFilter.php';
require $module . '/resources/filter/AllRecordsGedcomFilter.php';
Registry::encodingFactory(new EncodingFactory());
stream_filter_register(GedcomEncodingFilter::class, GedcomEncodingFilter::class);

function convert(string $input, ?array $filters = null): string {
    $factory = new Psr17Factory();
    $reader = (new ReflectionClass(DownloadGedcomWithURL::class))->newInstanceWithoutConstructor();
    $records = $reader->importGedcomFileToRecords($factory->createStream($input), 'UTF-8');
    $service = new FilteredGedcomExportService($factory, $factory);
    $stream = $service->filteredExport(
        tree: null, encoding: 'UTF-8', line_endings: 'LF',
        gedcom_filters: $filters ?? [new GEDCOM_7_GedcomFilter()],
        records: new Collection($records), head_and_trlr: false,
    );
    $result = stream_get_contents($stream);
    fclose($stream);
    return $result;
}

$failed = 0;
$checks = 0;
function check(bool $condition, string $name): void {
    global $failed, $checks;
    ++$checks;
    if (!$condition) {
        ++$failed;
    }
    echo ($condition ? 'PASS ' : 'FAIL ') . $name . "\n";
}

$header = "0 HEAD\n1 GEDC\n2 VERS 5.5.1\n1 CHAR UTF-8\n";
$identifiers = $header . <<<'GED'
0 @i1@ INDI
1 NAME Lower /Case/
1 FAMS @f1@
1 NOTE Literal @i1@ and user@example.org
0 @I1@ INDI
1 NAME Upper /Case/
1 FAMS @f1@
0 @X1@ INDI
1 NAME Reserved /Identifier/
0 @f1@ FAM
1 HUSB @i1@
1 WIFE @I1@
1 SOUR @missing@
0 @m-1@ OBJE
1 FILE photos/@i1@.png
2 FORM png
0 TRLR
GED;
$identifiers .= "\n";
$result = convert($identifiers);
preg_match('/^0 (@[A-Z0-9_]+@) INDI\n1 NAME Lower/m', $result, $lower);
check(isset($lower[1]) && !in_array($lower[1], ['@I1@', '@X1@'], true), 'colliding identifier receives an unused identifier');
check(isset($lower[1]) && strpos($result, '1 HUSB ' . $lower[1] . "\n") !== false, 'pointer tracks remapped identifier');
check(strpos($result, "0 @I1@ INDI\n") !== false && strpos($result, "1 WIFE @I1@\n") !== false, 'valid identifier and its pointer stay stable');
check(substr_count($result, "1 FAMS @F1@\n") === 2 && strpos($result, "0 @F1@ FAM\n") !== false, 'forward pointers are remapped consistently');
check(strpos($result, "1 NOTE Literal @i1@ and user@example.org\n") !== false, 'literal text and email remain unchanged');
check(strpos($result, "1 FILE photos/@i1@.png\n") !== false, 'media path remains unchanged');
check(strpos($result, "1 SOUR @MISSING@\n") !== false && strpos($result, '0 @MISSING@') === false, 'dangling references are not silently repaired');
preg_match_all('/^0 (@[^@]+@) /m', $result, $definitions);
check(count($definitions[1]) === 5 && count(array_unique($definitions[1])) === 5 && array_filter($definitions[1], static fn(string $identifier): bool => preg_match('/^@[A-Z0-9_]+@$/D', $identifier) !== 1) === [], 'record count, distinct identities and valid identifier syntax are preserved');
check(convert($result) === $result, 'GEDCOM 7 conversion is idempotent for identifiers and MIME');
check(strpos(convert($identifiers, [new RemoveWebtreesUserGedcomFilter()]), "0 @i1@ INDI\n") !== false, 'GEDCOM 5 export keeps original identifiers');

foreach (['png' => 'image/png', 'PnG' => 'image/png', 'tif' => 'image/tiff', 'tiff' => 'image/tiff', 'html' => 'text/html', 'htm' => 'text/html', 'jpeg' => 'image/jpeg', 'jpg' => 'image/jpeg', 'image/png' => 'image/png', 'png-extra' => 'png-extra'] as $old => $expected) {
    $result = convert($header . "0 @M1@ OBJE\n1 FILE image.bin\n2 FORM $old\n3 TYPE photo\n0 TRLR\n");
    check(strpos($result, "2 FORM $expected\n3 MEDI PHOTO\n") !== false, 'complete media type conversion: ' . $old);
}

foreach (['ASSO', '_ASSO'] as $tag) {
    foreach (['INDI', 'FAM'] as $recordType) {
        foreach (['godfather' => ['GODP', 'godfather'], 'godmother' => ['GODP', 'godmother'], 'GODPARENT' => ['GODP', null], 'witness' => ['WITN', null], 'friend' => ['FRIEND', null], 'School friend' => ['OTHER', 'School friend']] as $description => [$role, $phrase]) {
            $event = $recordType === 'INDI' ? 'BAPM' : 'MARR';
            $result = convert($header . "0 @R1@ $recordType\n1 $event\n2 $tag @I2@\n3 NOTE Before relationship\n3 RELA $description\n0 @I2@ INDI\n1 NAME A /Person/\n0 TRLR\n");
            $expected = "2 ASSO @I2@\n3 NOTE Before relationship\n3 ROLE $role\n";
            if ($phrase !== null) {
                $expected .= "4 PHRASE $phrase\n";
            }
            check(strpos($result, $expected) !== false && strpos($result, ' RELA ') === false, "$recordType event $tag relationship: $description");
            check(convert($result) === $result, "repeat conversion preserves $recordType $tag $description");
        }
    }
}
$result = convert($header . "0 @I1@ INDI\n1 ASSO @VOID@\n2 RELA friend\n0 TRLR\n");
check(strpos($result, "1 ASSO @VOID@\n2 ROLE FRIEND\n") !== false, 'person association and VOID pointer remain supported');

$extensions = "0 @I1@ INDI\n1 _WT_USER Editor\n1 _INTE value\n1 _MARNM Other\n1 BIRT\n2 PLAC Town\n3 _LOC @L1@\n0 @L1@ _LOC\n1 NAME Town\n1 _GOV GOV-ID\n0 TRLR\n";
$result = convert($header . $extensions);
check(strpos($result, "2 TAG _LOC ") === false && strpos($result, "2 TAG _GOV ") === false && strpos($result, "2 TAG _INTE ") === false && strpos($result, "2 TAG _MARNM ") === false, 'unrelated tags are not declared aliases of a catalog URI');
check(strpos($result, "0 @L1@ _LOC\n1 NAME Town\n1 _GOV GOV-ID\n") !== false && strpos($result, "3 _LOC @L1@\n") !== false, 'extension records, values and pointers are retained');
check(strpos($result, "2 TAG _WT_USER https://www.webtrees.net/\n") !== false, 'existing single-tag schema mapping remains available');
check(convert($result) === $result && substr_count($result, "1 SCHMA\n") === 1, 'schema generation is idempotent');

$result = convert($header . "1 SCHMA\n2 TAG _LOC https://example.org/location\n" . $extensions);
check(substr_count($result, "1 SCHMA\n") === 1 && substr_count($result, "2 TAG _LOC ") === 1 && strpos($result, "2 TAG _LOC https://example.org/location\n") !== false && strpos($result, "2 TAG _WT_USER https://www.webtrees.net/\n") !== false, 'explicit schemas are preserved and new declarations merge into one SCHMA');
$result = convert($header . "1 SCHMA\n2 TAG _WT_USER https://example.org/editor\n" . $extensions);
check(substr_count($result, '2 TAG _WT_USER ') === 1 && strpos($result, 'https://example.org/editor') !== false, 'input declaration takes precedence over automatic mapping');

echo "$checks checks, $failed failures\n";
exit($failed === 0 ? 0 : 1);
