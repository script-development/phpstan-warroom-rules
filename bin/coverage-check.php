<?php

declare(strict_types = 1);

/**
 * Parse a PHPUnit clover XML report and fail if line coverage falls below
 * a threshold. Standalone script — no Composer/runtime dependency on a
 * coverage-check library, since the package itself is consumed as a static-
 * analysis library and adding a runtime dep for a single CI gate is overhead
 * without commensurate value.
 *
 * Usage: php bin/coverage-check.php <clover.xml> <threshold>
 */
$cloverPath = $argv[1] ?? null;
$threshold = isset($argv[2]) ? (float) $argv[2] : null;

if ($cloverPath === null || $threshold === null) {
    fwrite(\STDERR, "Usage: coverage-check.php <clover.xml> <threshold>\n");
    exit(2);
}

if (!is_file($cloverPath)) {
    fwrite(\STDERR, "Clover report not found: {$cloverPath}\n");
    exit(2);
}

$xml = simplexml_load_file($cloverPath);

if ($xml === false) {
    fwrite(\STDERR, "Failed to parse clover XML: {$cloverPath}\n");
    exit(2);
}

$metrics = $xml->project->metrics ?? null;

if ($metrics === null) {
    fwrite(\STDERR, "No <project><metrics> element in clover XML\n");
    exit(2);
}

$statements = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];

if ($statements === 0) {
    fwrite(\STDERR, "No statements measured — cannot evaluate coverage\n");
    exit(2);
}

$percent = ($covered / $statements) * 100.0;

printf("Line coverage: %.2f%% (%d/%d) — threshold: %.2f%%\n", $percent, $covered, $statements, $threshold);

if ($percent + 1e-9 < $threshold) {
    fwrite(\STDERR, "FAIL: line coverage below threshold\n");
    exit(1);
}

echo "PASS\n";
exit(0);
