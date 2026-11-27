<?php

declare(strict_types=1);

// In the repository, core is next to web. On shared hosting, web's contents
// and core are both copied to htdocs. Prefer the deployed layout if present.
$coreDirectory = is_file(__DIR__ . '/core/config.php')
    ? __DIR__ . '/core'
    : dirname(__DIR__) . '/core';
