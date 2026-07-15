<?php

use Symfony\Component\Dotenv\Dotenv;

// Pre-set session.save_path as early as possible, before PHPUnit prints any
// output, so NativeFileSessionHandler's constructor (see config/services.yaml)
// finds the ini value already correct and never needs to call ini_set() itself
// later in the request/test lifecycle. See public/index.php for the same guard
// on the real HTTP front controller.
$sessionSavePath = dirname(__DIR__).'/var/sessions';
if (!is_dir($sessionSavePath)) {
    @mkdir($sessionSavePath, 0777, true);
}
if ($sessionSavePath !== ini_get('session.save_path')) {
    @ini_set('session.save_path', $sessionSavePath);
}

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}
