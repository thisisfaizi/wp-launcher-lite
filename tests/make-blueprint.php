<?php
/** Test helper: create a blueprint and print its template setup URL. php tests/make-blueprint.php ROOT NAME [plugin,plugin] */
[$_, $root, $name] = $argv;
$plugins = isset($argv[3]) && $argv[3] !== '' ? explode(',', $argv[3]) : [];
require $root . '/app/bootstrap.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$config = ['name' => $name, 'description' => 'Test blueprint', 'plugins' => ['preinstall' => array_map(fn ($s) => ['source' => 'wordpress.org', 'slug' => $s, 'activate' => true], $plugins)]];
$bp = Wpl\Blueprints::create($config);
echo Wpl\Sites::setupUrl($bp['template_slug']), "\n";
