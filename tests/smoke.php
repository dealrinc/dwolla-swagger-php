<?php
/**
 * Deprecation / behavior smoke test for the dwolla/dwollaswagger package.
 *
 * Runs under error_reporting(E_ALL). Any deprecation raised from a file inside
 * this package's lib/ directory is treated as a test failure.
 *
 * Usage: php tests/smoke.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../vendor/autoload.php';

$packageRoot = realpath(__DIR__ . '/../lib');
$failures = [];
$passes = [];

set_error_handler(function ($severity, $message, $file, $line) use ($packageRoot, &$failures) {
    if (($severity & (E_DEPRECATED | E_USER_DEPRECATED)) === 0) {
        return false;
    }
    $real = realpath($file);
    if ($real !== false && strpos($real, $packageRoot) === 0) {
        $failures[] = sprintf('DEPRECATION in package: %s at %s:%d', $message, $file, $line);
    }
    return true;
});

function assertTrue($cond, $label) {
    global $failures, $passes;
    if ($cond) {
        $passes[] = $label;
    } else {
        $failures[] = "ASSERTION FAILED: $label";
    }
}

// --- ApiClient: $host property is declared and set correctly ---
$client = new DwollaSwagger\ApiClient('https://api-sandbox.dwolla.com');
assertTrue($client->host === 'https://api-sandbox.dwolla.com', 'ApiClient->host set from constructor arg');

$defaultClient = new DwollaSwagger\ApiClient();
assertTrue($defaultClient->host === 'https://localhost', 'ApiClient->host defaults to https://localhost');

$ref = new ReflectionClass(DwollaSwagger\ApiClient::class);
assertTrue($ref->hasProperty('host'), 'ApiClient declares $host property');

// --- Money: construct with and without data; ArrayAccess ---
$emptyMoney = new DwollaSwagger\models\Money();
assertTrue($emptyMoney->value === null && $emptyMoney->currency === null, 'Money() empty construct sets nulls');

$money = new DwollaSwagger\models\Money(['value' => '12.34', 'currency' => 'USD']);
assertTrue($money->value === '12.34', 'Money->value read');
assertTrue($money->currency === 'USD', 'Money->currency read');
assertTrue($money['value'] === '12.34', 'Money ArrayAccess read');
assertTrue(isset($money['currency']), 'Money offsetExists for declared');
$money['value'] = '99.99';
assertTrue($money->value === '99.99', 'Money ArrayAccess write to declared property');
unset($money['value']);
assertTrue(!isset($money->value), 'Money ArrayAccess unset');

// Dynamic write to an unknown offset: preserves prior behavior.
$money['extra'] = 'foo';
assertTrue($money['extra'] === 'foo', 'Money ArrayAccess write to unknown offset preserved');

// --- TransferRequestBody: construct with nested data ---
$emptyXfer = new DwollaSwagger\models\TransferRequestBody();
assertTrue($emptyXfer->amount === null, 'TransferRequestBody() empty construct');

$xfer = new DwollaSwagger\models\TransferRequestBody([
    '_links' => ['source' => 'x'],
    'amount' => ['value' => '1.00', 'currency' => 'USD'],
    'metadata' => ['k' => 'v'],
    'correlation_id' => 'abc-123',
]);
assertTrue($xfer->_links === ['source' => 'x'], 'TransferRequestBody->_links');
assertTrue($xfer['correlation_id'] === 'abc-123', 'TransferRequestBody ArrayAccess read');
$xfer['imad'] = 'IMAD-1';
assertTrue($xfer->imad === 'IMAD-1', 'TransferRequestBody ArrayAccess write');

// --- A list response model: CustomerListResponse ---
$list = new DwollaSwagger\models\CustomerListResponse();
assertTrue($list instanceof ArrayAccess, 'CustomerListResponse implements ArrayAccess');
$list['total'] = 5;
assertTrue($list['total'] === 5, 'CustomerListResponse ArrayAccess round-trip');

// --- Instantiate an Api class (exercises constructor + property declarations) ---
$api = new DwollaSwagger\CustomersApi($client);
assertTrue($api->getApiClient() === $client, 'CustomersApi stores provided client');

// Default-constructed Api class should also work (uses Configuration::$apiClient).
DwollaSwagger\Configuration::$apiClient = null;
$defaultApi = new DwollaSwagger\TransfersApi();
assertTrue($defaultApi->getApiClient() instanceof DwollaSwagger\ApiClient, 'TransfersApi creates default client');

// --- Construct every model with both empty and null arg to surface deprecations ---
foreach (glob(__DIR__ . '/../lib/models/*.php') as $modelFile) {
    $base = basename($modelFile, '.php');
    $fqcn = 'DwollaSwagger\\models\\' . $base;
    if (!class_exists($fqcn)) {
        $failures[] = "Model class not autoloaded: $fqcn";
        continue;
    }
    $instance1 = new $fqcn();
    $instance2 = new $fqcn(null);
    $instance3 = new $fqcn([]);
    assertTrue($instance1 instanceof ArrayAccess, "$base implements ArrayAccess");
}

restore_error_handler();

echo "Passes: " . count($passes) . PHP_EOL;
foreach ($failures as $f) {
    echo "  - $f" . PHP_EOL;
}

if (!empty($failures)) {
    echo "FAILED with " . count($failures) . " issue(s)" . PHP_EOL;
    exit(1);
}

echo "OK: no package deprecations and all assertions passed." . PHP_EOL;
exit(0);
