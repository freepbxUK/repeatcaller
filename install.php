<?php

// Repeat Caller install hook.
//
// Schema creation is owned by src/Schema.php and is also invoked from
// Repeatcaller::install() (the BMO install() method), which FreePBX's module
// installer calls before this file. Both entrypoints share the same
// idempotent logic and use \FreePBX::Database() (a real PDO connection)
// rather than the legacy global $db wrapper, whose query() method silently
// swallows SQL exceptions instead of throwing them.
//
// This file is kept as a defensive, redundant second attempt for
// compatibility with FreePBX's legacy install.php convention. If schema
// creation genuinely fails here, the exception is allowed to propagate so
// the failure is visible rather than silently ignored.

require_once __DIR__ . '/src/Schema.php';
require_once __DIR__ . '/Repeatcaller.class.php';

\FreePBX\modules\Repeatcaller\Schema::install(\FreePBX::Database());

repeatcallerInstallAgi();
\FreePBX\modules\Repeatcaller::removeLegacyAlertCallDialplan((string)\FreePBX::Config()->get('ASTETCDIR'));

try {
	\FreePBX::Job()->addClass(
		'repeatcaller',
		'monitor',
		'\\FreePBX\\modules\\Repeatcaller\\Job',
		'* * * * *',
		30,
		true
	);
} catch (\Throwable $e) {
	\FreePBX::Log()->error('repeatcaller: failed to register background job: ' . $e->getMessage());
}

function repeatcallerInstallAgi(): void {
	$sourceAgiPath = __DIR__ . '/agi/repeatcaller_alert_response.php';
	if (!file_exists($sourceAgiPath)) {
		throw new \RuntimeException('Repeat Caller AGI source script is missing.');
	}

	$astAgiDir = rtrim((string)\FreePBX::Config()->get('ASTAGIDIR'), '/');
	if ($astAgiDir === '') {
		$astAgiDir = '/var/lib/asterisk/agi-bin';
	}

	if (!is_dir($astAgiDir)) {
		if (!@mkdir($astAgiDir, 0755, true) && !is_dir($astAgiDir)) {
			throw new \RuntimeException('Asterisk AGI directory is not available.');
		}
	}

	$deployedAgiPath = $astAgiDir . '/repeatcaller_alert_response.php';
	if (!@copy($sourceAgiPath, $deployedAgiPath)) {
		throw new \RuntimeException('Unable to deploy Repeat Caller AGI script to Asterisk AGI directory.');
	}

	if (!@chmod($deployedAgiPath, 0755)) {
		throw new \RuntimeException('Unable to enforce executable mode on deployed Repeat Caller AGI script.');
	}
}
