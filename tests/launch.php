<?php
/** Test helper: launch a demo of a blueprint; prints slug, url, login url, password. php tests/launch.php ROOT BLUEPRINT_ID [email] [ip] */
[$_, $root, $id] = $argv;
require $root . '/app/bootstrap.php';
$_SERVER['REMOTE_ADDR'] = $argv[4] ?? '127.0.0.1';
$bp = Wpl\Blueprints::find($id);
$t = microtime(true);
$site = Wpl\Sites::launch($bp, hash('sha256', 'cli-' . ($argv[4] ?? 'x')), $argv[3] ?? '', $_SERVER['REMOTE_ADDR']);
fwrite(STDERR, sprintf("launched in %.2fs\n", microtime(true) - $t));
echo json_encode(['slug' => $site['slug'], 'url' => Wpl\Sites::url($site['slug']), 'login' => Wpl\Sites::loginUrl($site['slug']), 'user' => $site['admin_user'], 'pass' => $site['admin_password']]), "\n";
