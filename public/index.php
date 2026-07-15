<?php

/* For licensing terms, see /license.txt */

use Chamilo\Kernel;
use Symfony\Component\HttpFoundation\RedirectResponse as HttpRedirectResponse;

// Pre-set session.save_path as early as possible, before any output or
// session activity, so NativeFileSessionHandler's constructor (see
// config/services.yaml) finds the ini value already correct and never needs
// to call ini_set() itself later in the request. That later call fails once
// output has been sent or a session is already active, depending on exactly
// when the DI container ends up constructing the handler.
$sessionSavePath = dirname(__DIR__).'/var/sessions';
if (!is_dir($sessionSavePath)) {
    @mkdir($sessionSavePath, 0777, true);
}
if ($sessionSavePath !== ini_get('session.save_path')) {
    @ini_set('session.save_path', $sessionSavePath);
}

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return function (array $context) {
    // Do NOT rely on $context for custom env vars (Runtime may not include them).
    $installed = (string) (
        $_SERVER['APP_INSTALLED']
        ?? $_ENV['APP_INSTALLED']
        ?? getenv('APP_INSTALLED')
        ?? '0'
    );

    if ($installed !== '1') {
        return new HttpRedirectResponse('./main/install/index.php');
    }

    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
