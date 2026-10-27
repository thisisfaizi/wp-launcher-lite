<?php
/**
 * Test helper: install a launcher copy from the command line, the same way the
 * web installer does (configure → Core::step until done → finish).
 *
 *   php tests/install-cli.php /path/to/launcher-copy https://host/demos sqlite|mysql [path|subdomain] [base_domain]
 *
 * MySQL credentials come from WPL_TEST_MYSQL_{HOST,PORT,NAME,USER,PASS}.
 */
[$_, $root, $baseUrl, $engine] = $argv + [null, null, null, 'sqlite'];
$mode = $argv[4] ?? 'path';
$domain = $argv[5] ?? '';
if (!$root || !$baseUrl) {
    fwrite(STDERR, "usage: php tests/install-cli.php ROOT BASE_URL [sqlite|mysql] [path|subdomain] [domain]\n");
    exit(2);
}
require $root . '/app/bootstrap.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

Wpl\Installer::configure([
    'base_url' => $baseUrl,
    'url_mode' => $mode,
    'base_domain' => $domain,
    'engine' => $engine,
    'mysql' => [
        'host' => getenv('WPL_TEST_MYSQL_HOST') ?: '127.0.0.1',
        'port' => getenv('WPL_TEST_MYSQL_PORT') ?: '',
        'name' => getenv('WPL_TEST_MYSQL_NAME') ?: 'wpl_test',
        'user' => getenv('WPL_TEST_MYSQL_USER') ?: 'root',
        'pass' => getenv('WPL_TEST_MYSQL_PASS') ?: '',
    ],
    'admin_email' => 'admin@example.com',
    'admin_password' => 'correct-horse-battery',
]);
$state = [];
do {
    $state = Wpl\Core::step($state, $engine === 'sqlite');
    echo "  {$state['progress']}% {$state['message']}\n";
} while (empty($state['done']));
Wpl\Installer::finish();
echo "installed WordPress " . Wpl\Core::version() . "\n";
