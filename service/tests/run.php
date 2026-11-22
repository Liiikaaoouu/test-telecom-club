<?php
declare(strict_types=1);

/**
 * Runs every test group as a fresh subprocess (isolated stacks, no state
 * bleeding between groups). Usage:  php service/tests/run.php
 */

$dir = __DIR__;
$groups = ['money_test.php', 'retry_test.php', 'optout_test.php', 'security_test.php'];

$failed = [];
foreach ($groups as $g) {
    echo "\n### $g\n";
    passthru('php ' . escapeshellarg("$dir/$g"), $rc);
    if ($rc !== 0) {
        $failed[] = $g;
    }
}

echo "\n====================\n";
if ($failed === []) {
    echo "ALL TEST GROUPS PASSED\n";
    exit(0);
}
echo 'FAILED GROUPS: ' . implode(', ', $failed) . "\n";
exit(1);
