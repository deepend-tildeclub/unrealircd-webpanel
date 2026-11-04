<?php
declare(strict_types=1);

// Run: php tests/health-check-acceptance.php
$dir = sys_get_temp_dir().'/webpanel-health-'.bin2hex(random_bytes(8));
if (!mkdir($dir.'/inc', 0700, true)) {
    throw new RuntimeException('Unable to create temporary test directory');
}
file_put_contents($dir.'/inc/connection.php', "<?php\n");
define('UPATH', $dir);
require_once dirname(__DIR__).'/Classes/class-checkup.php';

function get_config(string $key): mixed
{
    return $GLOBALS['test_config'][$key] ?? null;
}

function panel_csrf_token(): string
{
    return 'test-token';
}

function check(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: '.$description);
    }
}

try {
    $hubHedy = 'Module <code>webserver</code> exists on <code>hub.zoite.net</code> but not <code>hedy.zoite.net</code>';
    $hubPenumbra = 'Module <code>webserver</code> exists on <code>hub.zoite.net</code> but not <code>penumbra.zoite.net</code>';
    $id = CheckUp::issueId('modules', $hubHedy);
    check(strlen($id) === 64, 'issue key is a SHA-256 hex digest');
    check($id === CheckUp::issueId('modules', str_replace('<code>', '<b>', str_replace('</code>', '</b>', $hubHedy))), 'HTML markup does not change the issue ID');
    check($id !== CheckUp::issueId('modules', $hubPenumbra), 'individual servers produce independent exceptions');
    check($id !== CheckUp::issueId('usermodes', $hubHedy), 'issue categories are independent');

    $GLOBALS['test_config']['health_check_accepted'] = [
        $id => ['category' => 'modules', 'summary' => strip_tags($hubHedy), 'reason' => 'Hub only', 'by' => 'admin', 'at' => '2026-11-04'],
    ];
    $checkup = (new ReflectionClass(CheckUp::class))->newInstanceWithoutConstructor();
    $checkup->problems['modules'] = [$hubHedy, $hubPenumbra];
    $checkup->num_of_problems['modules'] = 2;
    $checkup->applyAcceptances();
    check($checkup->total() === 1, 'accepted warning does not count against network health');
    check($checkup->accepted_current_count === 1, 'accepted current finding is counted separately');
    check(count($checkup->issue_details['modules']) === 1, 'the unrelated conflict remains open');
    check(isset($checkup->all_issues[$id]), 'accepted issue remains available for auditing');

    ob_start();
    $checkup->toManageTable('modules', false);
    $html = ob_get_clean();
    check(str_contains($html, 'penumbra.zoite.net'), 'unresolved warning remains visible');
    check(!str_contains($html, 'hedy.zoite.net'), 'accepted warning leaves unresolved table');
    check(!str_contains($html, '<form'), 'read-only viewer cannot submit changes');

    ob_start();
    $checkup->toManageTable('modules', true);
    $html = ob_get_clean();
    check(str_contains($html, 'Accept as intended'), 'authorized operators get acceptance action');
    check(str_contains($html, 'test-token'), 'action includes a CSRF token');

    $GLOBALS['test_config']['health_check_accepted'] = [];
    $checkup->problems['modules'] = [$hubHedy, $hubPenumbra];
    $checkup->num_of_problems['modules'] = 2;
    $checkup->applyAcceptances();
    check($checkup->total() === 2, 'reopening restores the original health score');
    echo "Health acceptance checks passed.\n";
} finally {
    @unlink($dir.'/inc/connection.php');
    @rmdir($dir.'/inc');
    @rmdir($dir);
}
