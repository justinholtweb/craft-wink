<?php

use craft\test\TestSetup;

ini_set('date.timezone', 'UTC');
date_default_timezone_set('UTC');

// Paths for the throwaway Craft installation the integration suite boots against.
const CRAFT_TESTS_PATH = __DIR__;
const CRAFT_ROOT_PATH = __DIR__ . DIRECTORY_SEPARATOR . '_craft';
const CRAFT_STORAGE_PATH = __DIR__ . DIRECTORY_SEPARATOR . '_craft' . DIRECTORY_SEPARATOR . 'storage';
const CRAFT_TEMPLATES_PATH = __DIR__ . DIRECTORY_SEPARATOR . '_craft' . DIRECTORY_SEPARATOR . 'templates';
const CRAFT_CONFIG_PATH = __DIR__ . DIRECTORY_SEPARATOR . '_craft' . DIRECTORY_SEPARATOR . 'config';
const CRAFT_MIGRATIONS_PATH = __DIR__ . DIRECTORY_SEPARATOR . '_craft' . DIRECTORY_SEPARATOR . 'migrations';
const CRAFT_TRANSLATIONS_PATH = __DIR__ . DIRECTORY_SEPARATOR . '_craft' . DIRECTORY_SEPARATOR . 'translations';
define('CRAFT_VENDOR_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor');

TestSetup::configureCraft();
