<?php

declare(strict_types=1);

use DG\BypassFinals;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Enable bypassing final classes for testing
BypassFinals::enable();