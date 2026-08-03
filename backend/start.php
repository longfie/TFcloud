#!/usr/bin/env php
<?php
chdir(__DIR__);
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/support/polyfill_sort_direction.php';
support\App::run();
