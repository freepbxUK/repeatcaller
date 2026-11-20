<?php

declare(strict_types=1);

// Install-specific contract, added after a real FreePBX 16 installation test
// found that a fresh module install reported success while the schema was
// never created. Root cause: install.php used FreePBX's legacy global $db
// wrapper (admin/libraries/DB.class.php), whose query() method catches every
// PDOException and returns a DB_Error object instead of throwing -- so a
// failed CREATE TABLE was silently swallowed and never surfaced. The fix
// moves schema creation into src/Schema.php using a real PDO connection
// (\FreePBX::Database()) with ERRMODE_EXCEPTION, called from both
// Repeatcaller::install() (the BMO method FreePBX unconditionally calls
// before install.php, confirmed against the real FreePBX 16.0.45 framework
// source) and install.php itself as a redundant safety net.
//
// The actual CREATE TABLE / guarded-migration / INSERT IGNORE SQL was
// additionally executed for real against a disposable MariaDB 10.11.14
// instance during development of this fix (not automated here, since the
// default php binary in this environment has no pdo_mysql extension). This
// suite proves the structural/behavioral invariants that ARE checkable
// without a live MySQL/MariaDB connection.

require_once __DIR__ . '/../src/Schema.php';

if (!function_exists('_')) {
	function _($text) {
		return $text;
	}
}

if (!interface_exists('BMO')) {
	interface BMO {}
}

if (!class_exists('FreePBX')) {
	class FreePBX {
		public static $database;
		public static $astEtcDir = '';

		public static function Database() {
			return self::$database;
		}

		public static function Config() {
			return new class {
				public function get(string $key): string {
					return $key === 'ASTETCDIR' ? (string)FreePBX::$astEtcDir : '';
				}
			};
		}

		public static function Log() {
			return new class {
				public function error($message): void {}
				public function warning($message): void {}
				public function info($message): void {}
			};
		}

		public static function Modules() {
			return new class {
				public function getInfo($name): array {
					return [];
				}
			};
		}
	}
}

require_once __DIR__ . '/../Repeatcaller.class.php';

use FreePBX\modules\Repeatcaller;
use FreePBX\modules\Repeatcaller\Schema;

function assert_true(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function assert_same($expected, $actual, string $message): void {
	if ($expected !== $actual) {
		throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
	}
}

class CallerNameDialplanCommand {
	public array $arguments;
	public function __construct(...$arguments) {
		$this->arguments = $arguments;
	}
}
class ext_gotoif extends CallerNameDialplanCommand {}
class ext_set extends CallerNameDialplanCommand {}
class ext_goto extends CallerNameDialplanCommand {}
class ext_return extends CallerNameDialplanCommand {}
class ext_gosubif extends CallerNameDialplanCommand {}
class ext_gosub extends CallerNameDialplanCommand {}
class extension extends CallerNameDialplanCommand {}

class CallerNameDialplan {
	public array $contexts = [];
	public array $insertions = [];
	public function section_exists(string $context): bool {
		return isset($this->contexts[$context]);
	}
	public function add(string $context, string $extension, string $label, CallerNameDialplanCommand $command): void {
		$this->contexts[$context][] = ['extension' => $extension, 'label' => $label, 'command' => $command];
	}
	public function splice(string $context, string $extension, $position, CallerNameDialplanCommand $command, string $tag = '', int $offset = 0, bool $fixMultipleLabels = false): void {
		$this->insertions[] = [$context, $extension, $position, $command];
		if (!is_int($position)) {
			$position = array_search($position, array_column($this->contexts[$context], 'label'), true);
			assert_true($position !== false, 'a label-based insertion must target an existing FreePBX label');
			$position += $offset;
		}
		if ($fixMultipleLabels && $tag !== '') {
			foreach ($this->contexts[$context] as &$entry) {
				if ($entry['label'] === $tag) {
					$entry['label'] = '';
				}
			}
			unset($entry);
		}
		array_splice($this->contexts[$context], $position, 0, [['label' => $tag, 'command' => $command]]);
	}
}

function runOriginNameDialplan(array $commands, array $variables): array {
	$expand = static function (string $value) use (&$variables): string {
		while (strpos($value, '${') !== false) {
			$value = preg_replace_callback('/\$\{([^{}]*)\}/', static function (array $match) use (&$variables): string {
				assert_true(array_key_exists($match[1], $variables), 'caller-name simulation must recognize every variable: ' . $match[1]);
				return (string)$variables[$match[1]];
			}, $value);
		}
		return $value;
	};
	$labels = [];
	foreach ($commands as $position => $entry) {
		if ($entry['label'] !== '') {
			$labels[$entry['label']] = $position;
		}
	}
	$position = 0;
	for ($steps = 0; $steps < 30; $steps++) {
		assert_true(isset($commands[$position]), 'caller-name subroutine must terminate with Return');
		$command = $commands[$position]['command'];
		$arguments = $command->arguments;
		$target = null;
		if ($command instanceof ext_return) {
			return $variables;
		} elseif ($command instanceof ext_set) {
			assert_true(strpos($arguments[0], '__REPEATCALLER_') === 0 || $arguments[0] === 'CALLERID(name)', 'originating-name handling must only change caller name and inherited module bookkeeping');
			$variables[ltrim($arguments[0], '_')] = $expand($arguments[1]);
		} elseif ($command instanceof ext_gotoif) {
			assert_true(preg_match('/^\$\["([^"]*)"(!=|=)"([^"]*)"\]$/', $expand($arguments[0]), $condition) === 1, 'caller-name simulation must recognize the generated condition');
			$matches = $condition[2] === '=' ? $condition[1] === $condition[3] : $condition[1] !== $condition[3];
			$target = $matches ? $arguments[1] : ($arguments[2] ?? null);
		} elseif ($command instanceof ext_goto) {
			$target = $arguments[0];
		} else {
			throw new RuntimeException('Unexpected caller-name dialplan command');
		}
		$position = $target !== null ? $labels[$target] : $position + 1;
	}
	throw new RuntimeException('Caller-name subroutine exceeded its simulation step limit');
}

/** Bytes a single indexed column contributes to InnoDB's key-length total. */
function columnByteWidth(string $type, int $bytesPerChar): int {
	$type = trim($type);
	if (preg_match('/^VARCHAR\((\d+)\)/i', $type, $m)) {
		return (int)$m[1] * $bytesPerChar;
	}
	if (preg_match('/^CHAR\((\d+)\)/i', $type, $m)) {
		return (int)$m[1] * $bytesPerChar;
	}
	if (preg_match('/^TINYINT/i', $type)) {
		return 1;
	}
	if (preg_match('/^BIGINT/i', $type)) {
		return 8;
	}
	if (preg_match('/^INT/i', $type)) {
		return 4;
	}
	if (preg_match('/^DATETIME/i', $type)) {
		return 8;
	}
	if (preg_match('/^TIME/i', $type)) {
		return 3;
	}
	throw new RuntimeException("columnByteWidth: unhandled column type '{$type}'");
}

/**
 * Parses a single CREATE TABLE statement and returns every index whose
 * combined column byte width exceeds $limit, as ["index_name" => bytes].
 * Deliberately assumes InnoDB Antelope/COMPACT row format with
 * innodb_large_prefix off (MariaDB 5.5 defaults) -- i.e. no credit is given
 * for a wider limit that a newer server/row-format might allow.
 */
function indexesExceedingKeyLimit(string $createStatement, int $bytesPerChar, int $limit): array {
	if (!preg_match('/\((.*)\)\s*ENGINE=/s', $createStatement, $bodyMatch)) {
		throw new RuntimeException('indexesExceedingKeyLimit: could not locate column/index body');
	}
	$lines = preg_split('/,\s*\n/', $bodyMatch[1]);

	$columnTypes = [];
	foreach ($lines as $line) {
		$line = trim($line);
		if ($line === '' || preg_match('/^(PRIMARY KEY|UNIQUE KEY|KEY)\b/i', $line)) {
			continue;
		}
		if (preg_match('/^(\w+)\s+(.*)$/', $line, $columnMatch)) {
			$columnTypes[$columnMatch[1]] = $columnMatch[2];
		}
	}

	$exceeding = [];
	foreach ($lines as $line) {
		$line = trim($line);
		$indexName = null;
		$columnList = null;
		if (preg_match('/^PRIMARY KEY\s*\(([^)]+)\)/i', $line, $keyMatch)) {
			$indexName = 'PRIMARY';
			$columnList = $keyMatch[1];
		} elseif (preg_match('/^UNIQUE KEY\s+(\w+)\s*\(([^)]+)\)/i', $line, $keyMatch)) {
			$indexName = $keyMatch[1];
			$columnList = $keyMatch[2];
		} elseif (preg_match('/^KEY\s+(\w+)\s*\(([^)]+)\)/i', $line, $keyMatch)) {
			$indexName = $keyMatch[1];
			$columnList = $keyMatch[2];
		}
		if ($indexName === null) {
			continue;
		}

		$totalBytes = 0;
		foreach (array_map('trim', explode(',', $columnList)) as $columnName) {
			if (!isset($columnTypes[$columnName])) {
				throw new RuntimeException("indexesExceedingKeyLimit: column '{$columnName}' referenced by index '{$indexName}' not found");
			}
			$totalBytes += columnByteWidth($columnTypes[$columnName], $bytesPerChar);
		}
		if ($totalBytes > $limit) {
			$exceeding[$indexName] = $totalBytes;
		}
	}

	return $exceeding;
}

class SchemaUpgradePDO extends PDO {
	private string $fixedNow = '2030-01-02 03:04:05';

	public function __construct() {
		parent::__construct('sqlite::memory:');
		$this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		if (method_exists($this, 'sqliteCreateFunction')) {
			$this->sqliteCreateFunction('DATABASE', static function (): string {
				return 'main';
			}, 0);
			$this->sqliteCreateFunction('NOW', function (): string {
				return $this->fixedNow;
			}, 0);
		}
		parent::exec("ATTACH DATABASE ':memory:' AS information_schema");
		parent::exec('CREATE TABLE information_schema.TABLES (TABLE_SCHEMA TEXT NOT NULL, TABLE_NAME TEXT NOT NULL)');
		parent::exec('CREATE TABLE information_schema.COLUMNS (TABLE_SCHEMA TEXT NOT NULL, TABLE_NAME TEXT NOT NULL, COLUMN_NAME TEXT NOT NULL, COLUMN_TYPE TEXT NOT NULL)');
	}

	public function fixedNow(): string {
		return $this->fixedNow;
	}

	public function prepare(string $query, array $options = []): PDOStatement|false {
		if (strpos($query, 'INSERT IGNORE INTO repeatcaller_settings') !== false) {
			$query = str_replace('INSERT IGNORE', 'INSERT OR IGNORE', $query);
		}

		return parent::prepare($query, $options);
	}

	public function exec(string $statement): int|false {
		$trimmed = ltrim($statement);
		if (stripos($trimmed, 'CREATE TABLE IF NOT EXISTS ') === 0) {
			return 0;
		}

		$result = parent::exec($statement);
		if ($result !== false && preg_match('/^ALTER TABLE\s+(\w+)\s+ADD COLUMN\s+/i', $trimmed)) {
			$this->syncInformationSchema();
		}

		return $result;
	}

	public function syncInformationSchema(): void {
		parent::exec('DELETE FROM information_schema.TABLES');
		parent::exec('DELETE FROM information_schema.COLUMNS');

		$tableStmt = parent::query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' AND name NOT LIKE 'information_schema.%' ORDER BY name");
		if (!$tableStmt) {
			return;
		}
		$insertTable = parent::prepare('INSERT INTO information_schema.TABLES (TABLE_SCHEMA, TABLE_NAME) VALUES (?, ?)');
		$insertColumn = parent::prepare('INSERT INTO information_schema.COLUMNS (TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, COLUMN_TYPE) VALUES (?, ?, ?, ?)');
		while (($table = $tableStmt->fetchColumn()) !== false) {
			$tableName = (string)$table;
			$insertTable->execute(['main', $tableName]);
			$columnStmt = parent::query("PRAGMA table_info('" . str_replace("'", "''", $tableName) . "')");
			if (!$columnStmt) {
				continue;
			}
			while (($column = $columnStmt->fetch(PDO::FETCH_ASSOC)) !== false) {
				$insertColumn->execute(['main', $tableName, (string)($column['name'] ?? ''), (string)($column['type'] ?? '')]);
			}
		}
	}
}

final class SchemaInstallPathPDO extends SchemaUpgradePDO {
	private bool $schemaCreated = false;

	public function exec(string $statement): int|false {
		$trimmed = ltrim($statement);
		if (stripos($trimmed, 'CREATE TABLE IF NOT EXISTS ') === 0) {
			if (!$this->schemaCreated) {
				schemaUpgradeCreateLegacyTables($this);
				$this->schemaCreated = true;
			}
			return 0;
		}

		return parent::exec($statement);
	}
}

function schemaUpgradeCreateLegacyTables(SchemaUpgradePDO $db): void {
	$db->exec('CREATE TABLE repeatcaller_settings (
		setting_key TEXT PRIMARY KEY,
		setting_value TEXT,
		updated_at TEXT
	)');
	$db->exec('CREATE TABLE repeatcaller_rules (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		name TEXT NOT NULL,
		enabled INTEGER NOT NULL DEFAULT 1,
		mode TEXT NOT NULL,
		threshold_count INTEGER NOT NULL,
		observation_window_minutes INTEGER NOT NULL,
		caller_mode TEXT NOT NULL,
		did_scope_mode TEXT NOT NULL,
		exclude_withheld INTEGER NOT NULL DEFAULT 0,
		alert_reminder_mode_override TEXT,
		suppression_minutes_override INTEGER,
		created_at TEXT,
		updated_at TEXT
	)');
	$db->exec('CREATE TABLE repeatcaller_rule_schedules (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		rule_id INTEGER NOT NULL,
		day_of_week INTEGER NOT NULL,
		start_time TEXT NOT NULL,
		end_time TEXT NOT NULL,
		created_at TEXT
	)');
	$db->exec('CREATE TABLE repeatcaller_rule_callers (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		rule_id INTEGER NOT NULL,
		list_type TEXT NOT NULL,
		raw_value TEXT NOT NULL,
		normalized_value TEXT NOT NULL,
		created_at TEXT
	)');
	$db->exec('CREATE TABLE repeatcaller_rule_dids (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		rule_id INTEGER NOT NULL,
		list_type TEXT NOT NULL,
		route_key TEXT NOT NULL,
		route_label TEXT NOT NULL,
		did_value TEXT,
		cid_value TEXT,
		created_at TEXT
	)');
	$db->exec('CREATE TABLE repeatcaller_seen_calls (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		call_identity TEXT NOT NULL,
		identity_type TEXT NOT NULL,
		fingerprint TEXT NOT NULL,
		linkedid TEXT,
		uniqueid TEXT,
		caller_raw TEXT,
		caller_normalized TEXT,
		inbound_route_key TEXT,
		did_value TEXT,
		call_started_at TEXT NOT NULL,
		call_completed_at TEXT,
		disposition TEXT,
		source_context TEXT,
		processed_at TEXT NOT NULL
	)');
	$db->exec('CREATE TABLE repeatcaller_rule_subject_state (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		rule_id INTEGER NOT NULL,
		subject_key TEXT NOT NULL,
		current_window_started_at TEXT,
		current_window_ends_at TEXT,
		current_window_call_count INTEGER NOT NULL DEFAULT 0,
		threshold_met INTEGER NOT NULL DEFAULT 0,
		clear_observed_since_trigger INTEGER NOT NULL DEFAULT 0,
		active_incident_id INTEGER,
		suppression_expires_at TEXT,
		last_call_at TEXT,
		last_evaluated_at TEXT,
		created_at TEXT,
		updated_at TEXT
	)');
	$db->exec('CREATE TABLE repeatcaller_incidents (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		rule_id INTEGER NOT NULL,
		subject_key TEXT NOT NULL,
		active_subject_key TEXT,
		subject_label TEXT NOT NULL,
		caller_normalized TEXT,
		caller_display TEXT,
		withheld_caller INTEGER NOT NULL DEFAULT 0,
		mode TEXT NOT NULL,
		threshold_count INTEGER NOT NULL DEFAULT 0,
		observation_window_minutes INTEGER NOT NULL DEFAULT 0,
		first_matched_at TEXT NOT NULL,
		last_matched_at TEXT NOT NULL,
		matched_call_count INTEGER NOT NULL DEFAULT 0,
		state TEXT NOT NULL,
		accepted_by TEXT,
		accepted_at TEXT,
		accept_source TEXT,
		suppression_expires_at TEXT,
		created_at TEXT,
		updated_at TEXT
	)');
	$db->exec('CREATE TABLE repeatcaller_incident_alert_state (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		incident_id INTEGER NOT NULL,
		rule_id INTEGER NOT NULL,
		alert_reminder_mode TEXT NOT NULL,
		initial_sent_at TEXT,
		last_alert_at TEXT,
		reminders_sent INTEGER NOT NULL DEFAULT 0,
		next_due_at TEXT,
		created_at TEXT NOT NULL,
		updated_at TEXT NOT NULL
	)');
	$db->exec('CREATE TABLE repeatcaller_incident_alert_history (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		incident_id INTEGER NOT NULL,
		rule_id INTEGER NOT NULL,
		subject_key TEXT NOT NULL,
		subject_label TEXT NOT NULL,
		action_type TEXT NOT NULL,
		event_type TEXT NOT NULL,
		stage_n INTEGER NOT NULL DEFAULT 0,
		recipient TEXT,
		delivery_status TEXT NOT NULL,
		attempted_at TEXT,
		successful_at TEXT,
		next_retry_at TEXT,
		failure_detail TEXT,
		alert_reminder_mode TEXT NOT NULL,
		dedupe_key TEXT NOT NULL UNIQUE,
		created_at TEXT NOT NULL,
		updated_at TEXT NOT NULL
	)');
	$db->exec('CREATE TABLE repeatcaller_incident_suppression_history (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		related_incident_id INTEGER NOT NULL UNIQUE,
		rule_id INTEGER NOT NULL,
		rule_name TEXT NOT NULL,
		mode TEXT NOT NULL,
		subject_key TEXT NOT NULL,
		subject_label TEXT NOT NULL,
		caller_normalized TEXT,
		caller_display TEXT,
		inbound_route_key TEXT,
		inbound_route_label TEXT,
		did_value TEXT,
		matched_call_count INTEGER NOT NULL DEFAULT 0,
		threshold_count INTEGER NOT NULL,
		observation_window_minutes INTEGER NOT NULL,
		suppression_source TEXT NOT NULL,
		suppression_minutes INTEGER NOT NULL,
		suppression_started_at TEXT NOT NULL,
		suppression_expires_at TEXT NOT NULL,
		related_incident_state TEXT NOT NULL,
		detected_at TEXT NOT NULL,
		created_at TEXT NOT NULL,
		updated_at TEXT NOT NULL
	)');

	$db->syncInformationSchema();
}

function schemaUpgradeColumnList(PDO $db, string $table): array {
	$stmt = $db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY COLUMN_NAME ASC');
	$stmt->execute([$table]);
	return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

$root = dirname(__DIR__);

// --- 1: the install script contains executable schema creation for every
// current table (via the shared Schema class it delegates to) -------------

$createStatements = new ReflectionMethod(Schema::class, 'createTableStatements');
$createStatements->setAccessible(true);
$statements = $createStatements->invoke(null);
assert_true(count($statements) === count(Schema::REQUIRED_TABLES), 'one CREATE TABLE statement must exist per required table');

$createdTables = [];
foreach ($statements as $statement) {
	assert_true((bool)preg_match('/^CREATE TABLE IF NOT EXISTS (\w+)/', trim($statement), $match), 'each schema statement must be a guarded, executable CREATE TABLE IF NOT EXISTS');
	$createdTables[] = $match[1];
}
sort($createdTables);
$sortedRequired = Schema::REQUIRED_TABLES;
sort($sortedRequired);
assert_same($sortedRequired, $createdTables, 'schema creation must cover exactly the required table set');

$installSource = file_get_contents($root . '/install.php');
assert_true(strpos($installSource, 'Schema::install(') !== false, 'install.php must actually invoke schema creation, not merely reference the class');
$legacyPreflightPosition = strpos($installSource, 'Repeatcaller::assertLegacyAlertCallDialplanSafe(');
$legacySchemaPosition = strpos($installSource, 'Schema::install(');
$legacyCleanupPosition = strpos($installSource, 'Repeatcaller::removeLegacyAlertCallDialplan(');
$legacyAgiPosition = strpos($installSource, 'repeatcallerInstallAgi();');
$legacyJobPosition = strpos($installSource, 'FreePBX::Job()->addClass(');
assert_true($legacyPreflightPosition !== false && $legacySchemaPosition !== false && $legacyPreflightPosition < $legacySchemaPosition, 'legacy install entrypoint must preflight unsafe includes before schema changes');
assert_true($legacyCleanupPosition !== false && $legacyAgiPosition !== false && $legacyJobPosition !== false && $legacySchemaPosition < $legacyAgiPosition && $legacyAgiPosition < $legacyCleanupPosition && $legacyCleanupPosition < $legacyJobPosition, 'legacy install entrypoint must deploy AGI before cleanup and register the job only after cleanup');
$bmoInstallMethod = new ReflectionMethod(Repeatcaller::class, 'install');
$controllerLines = explode("\n", file_get_contents($root . '/Repeatcaller.class.php'));
$bmoInstallBody = implode("\n", array_slice($controllerLines, $bmoInstallMethod->getStartLine() - 1, $bmoInstallMethod->getEndLine() - $bmoInstallMethod->getStartLine() + 1));
$bmoPreflightPosition = strpos($bmoInstallBody, 'assertLegacyAlertCallDialplanSafe(');
$bmoSchemaPosition = strpos($bmoInstallBody, 'Schema::install(');
assert_true($bmoPreflightPosition !== false && $bmoSchemaPosition !== false && $bmoPreflightPosition < $bmoSchemaPosition, 'BMO install entrypoint must preflight unsafe includes before schema changes');

$moduleXml = file_get_contents($root . '/module.xml');
assert_true($moduleXml !== false, 'module.xml should be readable');
assert_true(strpos($moduleXml, '<version>1.2.0</version>') !== false, 'module version must declare 1.2.0 for the current release');

$schemaSource = file_get_contents($root . '/src/Schema.php');
assert_true($schemaSource !== false, 'src/Schema.php should be readable');
assert_true(strpos($schemaSource, 'repeatcaller_incident_suppression_history') !== false, 'schema must define a dedicated suppression-history table');
assert_true(strpos($schemaSource, 'addColumnIfMissing($pdo, \'repeatcaller_incidents\', \'cleared_at\'') !== false, 'guarded migrations must add cleared_at for existing incident rows');
assert_true(strpos($schemaSource, 'addColumnIfMissing($pdo, \'repeatcaller_incident_suppression_history\', \'cleared_at\'') !== false, 'guarded migrations must add cleared_at for existing suppression-history rows');
assert_true(strpos($schemaSource, 'addColumnIfMissing($pdo, \'repeatcaller_rules\', \'alert_call_strategy\'') !== false, 'guarded migrations must add alert_call_strategy for existing installs');
assert_true(strpos($schemaSource, 'addColumnIfMissing($pdo, \'repeatcaller_rules\', \'alert_call_keep_trying\'') !== false, 'guarded migrations must add alert_call_keep_trying for existing installs');
assert_true(strpos($schemaSource, 'addColumnIfMissing($pdo, \'repeatcaller_rules\', \'enabled_at\'') !== false, 'guarded migrations must add enabled_at for existing installs');
assert_true(strpos($schemaSource, "alert_call_strategy VARCHAR(20) NOT NULL DEFAULT 'ringall'") !== false, 'fresh schema must default alert_call_strategy to ringall');
assert_true(strpos($schemaSource, 'alert_call_keep_trying TINYINT(1) NOT NULL DEFAULT 1') !== false, 'fresh schema must default alert_call_keep_trying to enabled');
assert_true(strpos($schemaSource, 'enabled_at DATETIME NULL') !== false, 'fresh schema must include an enabled_at activation-boundary column for rules');
assert_true(strpos($schemaSource, "'initial_processing_boundary_at', NOW(), NOW()") !== false, 'fresh install boundary seed must be sourced atomically from the database clock');
assert_true(strpos($schemaSource, 'databaseNow(') === false, 'schema install should not include a PHP-time boundary fallback helper');
assert_true(strpos($schemaSource, "'alert_recipients' => ''") === false, 'fresh schema must not define removed global email destinations');
assert_true(strpos($schemaSource, 'email_recipients TEXT NULL') !== false, 'fresh schema must provide rule-level email recipients');
assert_true(strpos($schemaSource, 'cleared_at DATETIME NULL') !== false, 'fresh suppression schema must include cleared_at for incident and suppression-history tables');
assert_true(strpos($schemaSource, 'alert_enabled') === false, 'schema defaults and guarded migrations must not include a removed global email enable flag');
assert_true(strpos($schemaSource, "'suppression_history_prune_policy' => 'daily'") !== false, 'fresh schema must default suppression-history pruning to daily');
assert_true(strpos($schemaSource, 'day_of_week TINYINT NOT NULL') !== false && strpos($schemaSource, 'ALTER TABLE repeatcaller_rule_schedules MODIFY COLUMN day_of_week TINYINT NOT NULL') !== false, 'schedule schema must define signed day_of_week and migrate existing unsigned columns');
assert_true(strpos($schemaSource, 'self::ensureScheduleDayOfWeekIsSigned($pdo);') !== false && strpos($schemaSource, 'self::applyGuardedMigrations($pdo);') !== false, 'signed day_of_week migration must be invoked during the normal Schema::install upgrade path');

$preflightDirectory = sys_get_temp_dir() . '/repeatcaller_install_preflight_' . uniqid('', true);
assert_true(mkdir($preflightDirectory), 'BMO preflight fixture directory must be created');
$preflightCustomPath = $preflightDirectory . '/extensions_custom.conf';
$preflightFragmentPath = $preflightDirectory . '/repeatcaller_alert.conf';
$preflightCustomContent = "#include repeatcaller_alert.conf\n";
file_put_contents($preflightCustomPath, $preflightCustomContent);
file_put_contents($preflightFragmentPath, 'legacy module fragment');
$preflightDb = new SchemaInstallPathPDO();
FreePBX::$database = $preflightDb;
FreePBX::$astEtcDir = $preflightDirectory;
$preflightModule = new Repeatcaller(new stdClass());
try {
	$preflightException = null;
	try {
		$preflightModule->install();
	} catch (RuntimeException $e) {
		$preflightException = $e;
	}
	assert_true($preflightException instanceof RuntimeException, 'BMO install must reject unmanaged legacy references before schema installation');
	assert_same(0, (int)$preflightDb->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table'")->fetchColumn(), 'BMO preflight failure must happen before any schema mutation');
	assert_same($preflightCustomContent, file_get_contents($preflightCustomPath), 'BMO preflight failure must leave administrator dialplan unchanged');
	assert_same('legacy module fragment', file_get_contents($preflightFragmentPath), 'BMO preflight failure must retain the referenced fragment');
	file_put_contents($preflightCustomPath, "; administrator resolved the conflicting include\n");
	$preflightModule->install();
	assert_true(Schema::isReady($preflightDb), 'retry after resolving the conflict must complete the idempotent BMO schema install');
	Repeatcaller::removeLegacyAlertCallDialplan($preflightDirectory);
	Repeatcaller::removeLegacyAlertCallDialplan($preflightDirectory);
	assert_same("; administrator resolved the conflicting include\n", file_get_contents($preflightCustomPath), 'retry after conflict resolution must preserve administrator content and be idempotent');
	assert_true(!file_exists($preflightFragmentPath), 'retry after conflict resolution must remove the module-owned fragment');
} finally {
	FreePBX::$astEtcDir = '';
	foreach (glob($preflightDirectory . '/*') as $preflightFile) {
		unlink($preflightFile);
	}
	rmdir($preflightDirectory);
}

$legacyDb = new SchemaUpgradePDO();
schemaUpgradeCreateLegacyTables($legacyDb);

$insertLegacyRule = $legacyDb->prepare('INSERT INTO repeatcaller_rules (name, enabled, mode, threshold_count, observation_window_minutes, caller_mode, did_scope_mode, exclude_withheld, created_at, updated_at) VALUES (?, 1, ?, 2, 60, ?, ?, 0, ?, ?)');
$insertLegacyRule->execute(['Legacy All', 'repeat', 'any', 'all', '2026-07-13 09:00:00', '2026-07-13 09:00:00']);
$legacyAllRuleId = (int)$legacyDb->lastInsertId();
$insertLegacyRule->execute(['Legacy All Minus Route', 'repeat', 'any', 'all', '2026-07-13 09:00:00', '2026-07-13 09:00:00']);
$legacyAllMinusRuleId = (int)$legacyDb->lastInsertId();
$insertLegacyRule->execute(['Legacy Selected', 'repeat', 'any', 'selected', '2026-07-13 09:00:00', '2026-07-13 09:00:00']);
$legacySelectedRuleId = (int)$legacyDb->lastInsertId();
$insertLegacyDid = $legacyDb->prepare('INSERT INTO repeatcaller_rule_dids (rule_id, list_type, route_key, route_label, did_value, cid_value, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
$insertLegacyDid->execute([$legacyAllMinusRuleId, 'exclude', 'blocked|', 'Blocked Route', 'blocked', '', '2026-07-13 09:00:00']);
$insertLegacyDid->execute([$legacySelectedRuleId, 'include', 'included|', 'Included Route', 'included', '', '2026-07-13 09:00:00']);

$legacyDb->prepare('INSERT INTO repeatcaller_incidents (rule_id, subject_key, active_subject_key, subject_label, caller_normalized, caller_display, withheld_caller, mode, first_matched_at, last_matched_at, matched_call_count, state, accepted_by, accepted_at, accept_source, suppression_expires_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
	7,
	'+441234567890',
	'active-7',
	'Legacy Incident',
	'+441234567890',
	'01234567890',
	0,
	'repeat',
	'2026-07-13 10:00:00',
	'2026-07-13 10:05:00',
	2,
	'suppressed',
	null,
	null,
	null,
	'2026-07-13 11:00:00',
	'2026-07-13 10:00:00',
	'2026-07-13 10:05:00',
]);
$legacyDb->prepare('INSERT INTO repeatcaller_incident_suppression_history (related_incident_id, rule_id, rule_name, mode, subject_key, subject_label, caller_normalized, caller_display, inbound_route_key, inbound_route_label, did_value, matched_call_count, threshold_count, observation_window_minutes, suppression_source, suppression_minutes, suppression_started_at, suppression_expires_at, related_incident_state, detected_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
	42,
	7,
	'Legacy Rule',
	'repeat',
	'+441234567890',
	'Legacy Incident',
	'+441234567890',
	'01234567890',
	null,
	null,
	null,
	2,
	2,
	30,
	'global_default',
	30,
	'2026-07-13 10:00:00',
	'2026-07-13 11:00:00',
	'suppressed',
	'2026-07-13 10:05:00',
	'2026-07-13 10:05:00',
	'2026-07-13 10:05:00',
]);

FreePBX::$database = $legacyDb;
$upgradeModule = new Repeatcaller(new stdClass());
$upgradeModule->install();

assert_same('all', (string)$legacyDb->query('SELECT did_scope_mode FROM repeatcaller_rules WHERE id = ' . $legacyAllRuleId)->fetchColumn(), 'legacy Include All DIDs rules without exclusions must remain all');
assert_same('selected', (string)$legacyDb->query('SELECT did_scope_mode FROM repeatcaller_rules WHERE id = ' . $legacyAllMinusRuleId)->fetchColumn(), 'legacy all-DID rules with exclusions must migrate to Select DIDs');
assert_same('selected', (string)$legacyDb->query('SELECT did_scope_mode FROM repeatcaller_rules WHERE id = ' . $legacySelectedRuleId)->fetchColumn(), 'legacy selected-DID rules must remain selected');
assert_same(1, (int)$legacyDb->query('SELECT COUNT(*) FROM repeatcaller_rule_dids WHERE rule_id = ' . $legacyAllMinusRuleId . " AND list_type = 'exclude'")->fetchColumn(), 'legacy exclusions must survive DID scope migration');
assert_same(1, (int)$legacyDb->query('SELECT COUNT(*) FROM repeatcaller_rule_dids WHERE rule_id = ' . $legacySelectedRuleId . " AND list_type = 'include'")->fetchColumn(), 'legacy selected includes must survive DID scope migration');
assert_same(1, (int)$legacyDb->query("SELECT COUNT(*) FROM repeatcaller_settings WHERE setting_key = 'did_scope_semantics_migrated_1_0_3'")->fetchColumn(), 'DID scope compatibility migration must record its one-time marker');

$incidentColumnsAfterUpgrade = schemaUpgradeColumnList($legacyDb, 'repeatcaller_incidents');
assert_true(in_array('cleared_at', $incidentColumnsAfterUpgrade, true), 'upgrade migration must add cleared_at to repeatcaller_incidents');
$suppressionColumnsAfterUpgrade = schemaUpgradeColumnList($legacyDb, 'repeatcaller_incident_suppression_history');
assert_true(in_array('cleared_at', $suppressionColumnsAfterUpgrade, true), 'upgrade migration must add cleared_at to repeatcaller_incident_suppression_history');
$rulesColumnsAfterUpgrade = schemaUpgradeColumnList($legacyDb, 'repeatcaller_rules');
assert_true(in_array('enabled_at', $rulesColumnsAfterUpgrade, true), 'upgrade migration must add enabled_at to repeatcaller_rules');

$legacyIncident = $legacyDb->query('SELECT subject_label, suppression_expires_at, cleared_at FROM repeatcaller_incidents WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
assert_same('Legacy Incident', (string)$legacyIncident['subject_label'], 'existing incident rows must be preserved during schema upgrade');
assert_same('2026-07-13 11:00:00', (string)$legacyIncident['suppression_expires_at'], 'existing incident suppression timestamps must be preserved during schema upgrade');
assert_same(null, $legacyIncident['cleared_at'], 'new incident cleared_at column must default to NULL for preserved rows');

$legacySuppression = $legacyDb->query('SELECT rule_name, suppression_expires_at, cleared_at FROM repeatcaller_incident_suppression_history WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
assert_same('Legacy Rule', (string)$legacySuppression['rule_name'], 'existing suppression-history rows must be preserved during schema upgrade');
assert_same('2026-07-13 11:00:00', (string)$legacySuppression['suppression_expires_at'], 'existing suppression-history expiry must be preserved during schema upgrade');
assert_same(null, $legacySuppression['cleared_at'], 'new suppression-history cleared_at column must default to NULL for preserved rows');

$upgradeModule->install();
assert_same(1, (int)$legacyDb->query('SELECT COUNT(*) FROM repeatcaller_incidents')->fetchColumn(), 'running the schema migration twice must not duplicate existing incident rows');
assert_same(1, (int)$legacyDb->query('SELECT COUNT(*) FROM repeatcaller_incident_suppression_history')->fetchColumn(), 'running the schema migration twice must not duplicate suppression-history rows');
assert_same('selected', (string)$legacyDb->query('SELECT did_scope_mode FROM repeatcaller_rules WHERE id = ' . $legacyAllMinusRuleId)->fetchColumn(), 'running DID scope migration twice must preserve the migrated mode');
assert_same(1, (int)$legacyDb->query("SELECT COUNT(*) FROM repeatcaller_settings WHERE setting_key = 'did_scope_semantics_migrated_1_0_3'")->fetchColumn(), 'running DID scope migration twice must preserve exactly one marker');

$freshInstallDb = new SchemaInstallPathPDO();
Schema::install($freshInstallDb);
$freshBoundaryRows = $freshInstallDb->query("SELECT setting_value FROM repeatcaller_settings WHERE setting_key = 'initial_processing_boundary_at'")->fetchAll(PDO::FETCH_COLUMN);
assert_same(1, count($freshBoundaryRows), 'fresh install must persist exactly one initial_processing_boundary_at setting row');
$freshBoundaryValue = trim((string)$freshBoundaryRows[0]);
assert_true($freshBoundaryValue !== '', 'fresh install initial_processing_boundary_at must be non-empty');
assert_true((bool)preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $freshBoundaryValue), 'fresh install initial_processing_boundary_at must be persisted as a datetime string');
assert_true(strtotime($freshBoundaryValue) !== false, 'fresh install initial_processing_boundary_at must be parseable as a valid datetime');
assert_same($freshInstallDb->fixedNow(), $freshBoundaryValue, 'fresh install initial_processing_boundary_at must be seeded from the database clock source (NOW)');

Schema::install($freshInstallDb);
$freshBoundaryRowsAfterRerun = $freshInstallDb->query("SELECT setting_value FROM repeatcaller_settings WHERE setting_key = 'initial_processing_boundary_at'")->fetchAll(PDO::FETCH_COLUMN);
assert_same(1, count($freshBoundaryRowsAfterRerun), 're-running install must preserve a single initial_processing_boundary_at row');
assert_same($freshBoundaryValue, trim((string)$freshBoundaryRowsAfterRerun[0]), 're-running install must not alter initial_processing_boundary_at once seeded');

$upgradeContinuityDb = new SchemaUpgradePDO();
schemaUpgradeCreateLegacyTables($upgradeContinuityDb);
$upgradeContinuityDb->prepare('INSERT INTO repeatcaller_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)')->execute(['enabled', '1', '2026-07-13 09:00:00']);
Schema::install($upgradeContinuityDb);
assert_same(0, (int)$upgradeContinuityDb->query("SELECT COUNT(*) FROM repeatcaller_settings WHERE setting_key = 'initial_processing_boundary_at'")->fetchColumn(), 'existing installs without initial_processing_boundary_at must not be backfilled during upgrade install continuity path');

$controllerSource = file_get_contents($root . '/Repeatcaller.class.php');
assert_true($controllerSource !== false, 'Repeatcaller.class.php should be readable');
assert_true(strpos($controllerSource, "setSetting('initial_processing_boundary_at'") === false, 'ordinary settings saves must not persist the internal initial_processing_boundary_at key');
assert_true(strpos($controllerSource, 'astDbGetValue(') !== false && strpos($controllerSource, 'astmanAction(') !== false, 'controller acceptance path must query tracked live channels and issue AMI control actions');
assert_true(strpos($controllerSource, "'Redirect'") !== false && strpos($controllerSource, "'Hangup'") !== false, 'controller acceptance path must redirect answered playback legs and hang up launch or ringing legs');

$callerNameModule = new Repeatcaller(new stdClass());
assert_same(500, $callerNameModule->myDialplanHooks(), 'caller-name hook must run after Core legacy generation at priority 100');
$callerNameDialplan = new CallerNameDialplan();
$existingSipHeaderCommands = [
	['label' => '', 'command' => new CallerNameDialplanCommand('Existing SIP header handler')],
	['label' => '', 'command' => new ext_return()],
];
$callerNameDialplan->contexts['func-apply-sipheaders'] = $existingSipHeaderCommands;
$originalUserCommands = [
	['label' => '', 'command' => new CallerNameDialplanCommand('FreePBX caller identity selection')],
	['label' => 'continue', 'command' => new CallerNameDialplanCommand('FreePBX post-selection processing')],
	['label' => '', 'command' => new ext_return()],
];
$callerNameDialplan->contexts['macro-user-callerid'] = $originalUserCommands;
$callerNameDialplan->contexts['macro-outbound-callerid'] = $existingSipHeaderCommands;
$callerNameModule->doDialplanHook($callerNameDialplan, 'asterisk', 500);
assert_same($existingSipHeaderCommands, $callerNameDialplan->contexts['func-apply-sipheaders'], 'originating-name integration must leave the entire called-channel SIP-header handler unchanged');
assert_same(3, count($callerNameDialplan->insertions), 'originating-name integration must add only internal post-selection and external restoration calls');
assert_same(['macro-user-callerid', 's', 0], array_slice($callerNameDialplan->insertions[0], 0, 3), 'external name restoration must precede normal external caller identity selection');
assert_same(['$["${ARG2}"="EXTERNAL"]', 'repeatcaller-alert-origin-name,s,1', false, 'external'], $callerNameDialplan->insertions[0][3]->arguments, 'external processing must restore the original name through the normal FreePBX argument');
assert_same(['macro-user-callerid', 's', 'continue'], array_slice($callerNameDialplan->insertions[1], 0, 3), 'internal name must be established after FreePBX identity rewriting');
assert_same('continue', $callerNameDialplan->contexts['macro-user-callerid'][2]['label'], 'existing jumps to continue must execute the post-rewrite module call');
assert_same('', $callerNameDialplan->contexts['macro-user-callerid'][3]['label'], 'the continue label must not remain duplicated on the old command');
assert_same($originalUserCommands[0], $callerNameDialplan->contexts['macro-user-callerid'][1], 'FreePBX original identity selection must remain intact');
assert_same($existingSipHeaderCommands, array_slice($callerNameDialplan->contexts['macro-outbound-callerid'], 1), 'trunk caller identity commands must be preserved');
$missingSipHandler = new CallerNameDialplan();
$callerNameModule->doDialplanHook($missingSipHandler, 'asterisk', 500);
assert_true(!isset($missingSipHandler->contexts['func-apply-sipheaders']), 'a missing Core handler must not cause creation or replacement of a shared context');
$legacyContexts = ['repeatcaller-alert-launch', 'repeatcaller-alert-playback'];
$migratedInstructions = [];
foreach ($legacyContexts as $legacyContext) {
	assert_true(isset($missingSipHandler->contexts[$legacyContext]), 'all legacy contexts must be generated even if the optional shared SIP handler is missing');
	foreach ($callerNameDialplan->contexts[$legacyContext] as $entry) {
		if ($entry['command']->arguments[0] === 'Gosub(repeatcaller-alert-origin-name,s,1(internal))') {
			continue;
		}
		$migratedInstructions[] = [$legacyContext, $entry['extension'], $entry['label'], $entry['command']->arguments[0]];
	}
}
assert_same(33, count($migratedInstructions), 'all supported legacy launch and playback instructions plus ownership-guarded cleanup registration must be generated through BMO');
assert_same('4c6b195080e689729d05a2a931c5d5aa39efb54e7dbdaabda1828b4b9d4931ab', hash('sha256', json_encode($migratedInstructions, JSON_UNESCAPED_SLASHES)), 'migration must preserve every supported launch and playback extension, label, application and argument plus cleanup registration');
assert_true(strpos($controllerSource, 'repeatcaller-alert-accept-check') === false, 'generated dialplan must not retain calls to the undefined legacy acceptance-check subroutine');
$launchApplications = array_map(static function (array $entry): string { return $entry['command']->arguments[0]; }, $callerNameDialplan->contexts['repeatcaller-alert-launch']);
assert_same('Gosub(repeatcaller-alert-origin-name,s,1(internal))', $launchApplications[3], 'originating name must be established before the normal destination Dial');
assert_true(strpos($launchApplications[4], 'Dial(Local/${EXTEN}@from-internal/n,,U(') === 0, 'destination routing and acknowledgement handling must remain unchanged after naming');
assert_true(strpos($controllerSource, 'CONNECTEDLINE(') === false && !isset($callerNameDialplan->contexts['repeatcaller-alert-caller-name']), 'the obsolete connected-line mechanism and its context must be removed');
$otherEngineDialplan = new CallerNameDialplan();
$otherEngineDialplan->contexts['func-apply-sipheaders'] = $existingSipHeaderCommands;
$callerNameModule->doDialplanHook($otherEngineDialplan, 'other', 500);
assert_same([], $otherEngineDialplan->insertions, 'non-Asterisk engines must not receive caller-name integration');
$callerNameCommands = $callerNameDialplan->contexts['repeatcaller-alert-origin-name'];
foreach ([
	['', ''], ['', '+441234567890'], ['', '201'],
	['Original PBX name', ''], ['Original PBX name', '+441234567890'], ['Original PBX name', '201'],
] as [$originalName, $callerNumber]) {
	foreach ([['allowed_not_screened', 'allowed_not_screened'], ['prohib_not_screened', 'allowed_not_screened'], ['allowed_not_screened', 'prohib_not_screened'], ['unavailable', 'unavailable']] as [$namePresentation, $numberPresentation]) {
		$channelIdentity = [
			'REPEATCALLER_INTERNAL_ORIGIN' => '1',
			'REPEATCALLER_ORIGINAL_NAME' => '',
			'REPEATCALLER_ORIGINAL_NAME_SAVED' => '',
			'REPEATCALLER_EXTERNAL_IDENTITY' => '',
			'ARG1' => 'internal',
			'CALLERID(name)' => $originalName,
			'CALLERID(num)' => $callerNumber,
			'CONNECTEDLINE(name)' => 'Existing connected name',
			'CONNECTEDLINE(num)' => $callerNumber,
			'CALLERID(name-pres)' => $namePresentation,
			'CALLERID(num-pres)' => $numberPresentation,
			'CONNECTEDLINE(name-pres)' => $namePresentation,
			'CONNECTEDLINE(num-pres)' => $numberPresentation,
		];
		$internal = runOriginNameDialplan($callerNameCommands, $channelIdentity);
		assert_same('Repeat Caller', $internal['CALLERID(name)'], 'the originating name must match the successful live test exactly');
		assert_same($originalName, $internal['REPEATCALLER_ORIGINAL_NAME'], 'the original name, including a blank name, must be saved for external routing');
		foreach ($channelIdentity as $field => $value) {
			if (in_array($field, ['CALLERID(name)', 'REPEATCALLER_ORIGINAL_NAME', 'REPEATCALLER_ORIGINAL_NAME_SAVED'], true)) {
				continue;
			}
			assert_same($value, $internal[$field], 'originating-name handling must preserve numbers, privacy and connected-line values: ' . $field);
		}
		$afterFreepbxRewrite = $internal;
		$afterFreepbxRewrite['CALLERID(name)'] = 'FreePBX extension name';
		assert_same('Repeat Caller', runOriginNameDialplan($callerNameCommands, $afterFreepbxRewrite)['CALLERID(name)'], 'internal FreePBX full-identity rewriting must not defeat the originating name');
		$externalLeg = $internal;
		$externalLeg['ARG1'] = 'external';
		$externalLeg = runOriginNameDialplan($callerNameCommands, $externalLeg);
		assert_same($channelIdentity['CALLERID(name)'], $externalLeg['CALLERID(name)'], 'external routing must see the original originating name');
		assert_same($callerNumber, $externalLeg['CALLERID(num)'], 'external restoration must never change caller numbers');
		$externalLeg['CALLERID(name)'] = 'Normal outbound PBX policy';
		$externalLeg['ARG1'] = 'internal';
		assert_same('Normal outbound PBX policy', runOriginNameDialplan($callerNameCommands, $externalLeg)['CALLERID(name)'], 'the shared user-callerid continuation must not rename an external leg');
		$externalLeg['ARG1'] = 'external';
		assert_same('Normal outbound PBX policy', runOriginNameDialplan($callerNameCommands, $externalLeg)['CALLERID(name)'], 'later trunk processing must not undo existing outbound policy');
		assert_same('Repeat Caller', $internal['CALLERID(name)'], 'an external child must not change its internal siblings or parent');
		$channelIdentity['REPEATCALLER_INTERNAL_ORIGIN'] = '';
		assert_same($channelIdentity, runOriginNameDialplan($callerNameCommands, $channelIdentity), 'unmarked calls must remain completely unchanged');
	}
}
assert_true(strpos($installSource, 'CALLERID(') === false && strpos($installSource, 'CONNECTEDLINE(') === false, 'legacy cleanup must not inject caller identity');

assert_true(strpos($installSource, 'Repeatcaller::removeLegacyAlertCallDialplan(') !== false, 'install and upgrade must invoke selective legacy cleanup');
assert_true(strpos($installSource, 'file_put_contents(') === false && strpos($installSource, 'repeatcallerInstallDialplan') === false, 'install must not create a dialplan fragment or append a custom include');
assert_true(strpos($installSource, 'repeatcallerInstallAgi()') !== false, 'install hook must deploy the module AGI script into the Asterisk AGI directory');
assert_true(strpos($installSource, 'ASTAGIDIR') !== false, 'install hook must resolve the target Asterisk AGI directory from FreePBX configuration');
assert_true(strpos($installSource, "'/repeatcaller_alert_response.php'") !== false, 'install hook must deploy the expected AGI filename');
assert_true(strpos($installSource, '@copy($sourceAgiPath, $deployedAgiPath)') !== false, 'install hook must copy the AGI source into the Asterisk AGI directory');
assert_true(strpos($installSource, '@chmod($deployedAgiPath, 0755)') !== false, 'install hook must explicitly enforce executable mode on the deployed AGI script');
assert_true(strpos($controllerSource, 'U(repeatcaller-alert-playback^${REPEATCALLER_PLAYBACK_TARGET}') !== false, 'BMO dialplan must retain the answered-call playback subroutine');
assert_same(1, substr_count($controllerSource, 'same => n,Dial(Local/${EXTEN}@from-internal/n,,U(repeatcaller-alert-playback^'), 'BMO dialplan must retain exactly one answered-call playback subroutine');
assert_true(strpos($controllerSource, 'AGI(repeatcaller_alert_response.php,${REPEATCALLER_ALERT_HISTORY_ID},${REPEATCALLER_INCIDENT_ID},interactive,${REPEATCALLER_ALERT_RECIPIENT},${ARG1},${ARG6},${ARG7},${ARG8},${ARG9},${ARG10},${ARG11},${ARG12},${ARG13})') !== false, 'BMO playback must retain the deployed AGI filename and all interactive arguments');
assert_true(strpos($controllerSource, 'exten => remote_accepted,1,Set(REPEATCALLER_ALERT_COMPLETED=1)') !== false, 'BMO playback must retain the remote acceptance redirect target');

$agiSource = file_get_contents($root . '/agi/repeatcaller_alert_response.php');
assert_true($agiSource !== false, 'alert-call AGI handler should be readable');
assert_true(strpos($agiSource, 'recordAlertCallDtmfResponse($historyId, $incidentId, $response, $recipient, $digit') !== false, 'AGI handler must route exact alert attempt context to the repository response handler');
assert_true(strpos($agiSource, 'function repeatcallerAgiCreateTransport()') !== false && strpos($agiSource, 'new class implements \\FreePBX\\modules\\Repeatcaller\\AlertCallAgiTransport') !== false, 'AGI handler must expose a dedicated interactive transport for AGI audio and digit primitives after loading the session interface');
assert_true(strpos($agiSource, 'STREAM FILE') !== false && strpos($agiSource, 'SAY NUMBER') !== false && strpos($agiSource, 'SAY DIGITS') !== false && strpos($agiSource, 'WAIT FOR DIGIT ') !== false, 'AGI interactive path must use digit-interruptible AGI primitives for recordings, spoken numbers, spoken digits, and menu wait');
$agiSessionSource = file_get_contents($root . '/src/AlertCallAgiSession.php');
assert_true($agiSessionSource !== false, 'AlertCallAgiSession.php should be readable');
assert_true(strpos($agiSessionSource, 'waitForDigit(1)') !== false, 'AGI interactive path must retain a digit entered immediately after answer');
assert_true(strpos($agiSessionSource, 'waitForDigit(10000)') !== false, 'AGI interactive path must preserve the 10-second response wait after spoken prompts');
assert_true(strpos($agiSource, 'function repeatcallerTryImmediateOrderedFollowUp(') !== false, 'AGI handler must include a focused immediate ordered follow-up bridge');
assert_true(strpos($agiSource, 'IncidentAlertProcessor::continueImmediateOrderedStage(') !== false, 'AGI immediate bridge must reuse the shared bounded same-stage continuation helper');
assert_true(strpos($agiSource, 'sendAlertCall(') !== false, 'AGI immediate bridge must reuse existing module alert-call sender');
assert_true(strpos($agiSource, 'signalIncidentAcceptedForLiveAlertCalls($incidentId, $historyId)') !== false, 'AGI acceptance path must signal live answered alert-call legs when the incident is accepted while excluding the accepting leg');
assert_true(strpos($agiSource, 'repeatcallerTryImmediateOrderedFollowUp($repository, $moduleRoot, $context, $result, $now);') !== false, 'AGI handler must invoke the immediate ordered follow-up bridge after terminal callback persistence');
assert_true(strpos($agiSource, "in_array(\$response, ['accepted', 'declined', 'timeout', 'hangup', 'answered_no_response', 'dialstatus', 'interactive'], true)") !== false, 'AGI handler must allow known DTMF, interactive, and dialstatus callback actions');
assert_true(strpos($agiSource, 'function repeatcallerResolveModuleRoot(): ?string') !== false, 'AGI handler must resolve the module root explicitly when executed from Asterisk AGI-bin');
assert_true(strpos($agiSource, "FreePBX::Config()->get('AMPWEBROOT')") !== false, 'AGI handler must use FreePBX bootstrap configuration to find the installed module path');
assert_true(strpos($agiSource, 'attempted_roots') !== false, 'AGI handler must log attempted resolver roots on module-path resolution failure');
assert_true(strpos($agiSource, "__DIR__ . '/../src/RepeatCallerRepository.php'") === false, 'AGI handler must not depend on its own directory being inside the module root when deployed to AGI-bin');
assert_true(strpos($agiSource, "repeatcallerAgiLog('repeatcaller AGI: DTMF response was not persisted:") !== false, 'AGI handler must log persistence failures with contextual details rather than failing silently');
assert_true(strpos($agiSource, 'REPEATCALLER_AGI_EXIT_PERSISTENCE_FAILED') !== false, 'AGI handler must return non-zero on persistence failures');

$agiContractTemp = sys_get_temp_dir() . '/repeatcaller_agi_contract_' . uniqid('', true);
assert_true(@mkdir($agiContractTemp, 0777, true), 'AGI contract should create a temporary workspace');

$agiRuntimeDir = $agiContractTemp . '/agi-bin';
$fakeWebRoot = $agiContractTemp . '/web';
$fakeModuleRoot = $fakeWebRoot . '/admin/modules/repeatcaller';
$fakeSrcDir = $fakeModuleRoot . '/src';
assert_true(@mkdir($agiRuntimeDir, 0777, true), 'AGI contract should create a non-module AGI runtime directory');
assert_true(@mkdir($fakeSrcDir, 0777, true), 'AGI contract should create a fake FreePBX module source directory');

$copiedAgiPath = $agiRuntimeDir . '/repeatcaller_alert_response.php';
assert_true(copy($root . '/agi/repeatcaller_alert_response.php', $copiedAgiPath), 'AGI contract should copy the AGI script into a non-module runtime directory');
@chmod($copiedAgiPath, 0755);

$bootstrapPath = $agiContractTemp . '/freepbx.conf';
$bootstrapSource = "<?php\n"
	. "\$GLOBALS['amp_conf'] = ['AMPWEBROOT' => '" . addslashes($fakeWebRoot) . "'];\n"
	. "class FreePBX {\n"
	. "    public static function Config() {\n"
	. "        return new class {\n"
	. "            public function get(\$key) {\n"
	. "                return \$key === 'AMPWEBROOT' ? '" . addslashes($fakeWebRoot) . "' : '';\n"
	. "            }\n"
	. "        };\n"
	. "    }\n"
	. "    public static function Database() { return null; }\n"
	. "}\n";
assert_true(file_put_contents($bootstrapPath, $bootstrapSource) !== false, 'AGI contract should write a fake FreePBX bootstrap file');

$repoStubPath = $fakeSrcDir . '/RepeatCallerRepository.php';
$repoStub = "<?php\n"
	. "namespace FreePBX\\modules\\Repeatcaller;\n"
	. "class RepeatCallerRepository {\n"
	. "    public function __construct(\$db) {}\n"
	. "    public function recordAlertCallDtmfResponse(\$historyId, \$incidentId, \$response, \$recipient, \$digit, \$now): array {\n"
	. "        return ['status' => true, 'message' => 'ok'];\n"
	. "    }\n"
	. "}\n";
assert_true(file_put_contents($repoStubPath, $repoStub) !== false, 'AGI contract should write a fake RepeatCallerRepository class');

$agiSessionStubPath = $fakeSrcDir . '/AlertCallAgiSession.php';
$agiSessionStub = '<?php' . "\n"
	. 'namespace FreePBX\\modules\\Repeatcaller;' . "\n"
	. 'interface AlertCallAgiTransport {' . "\n"
	. '    public function setVariable(string $name, string $value): void;' . "\n"
	. '    public function streamFile(string $file, string $escapeDigits): string;' . "\n"
	. '    public function sayNumber(int $number, string $escapeDigits): string;' . "\n"
	. '    public function sayDigits(string $digits, string $escapeDigits): string;' . "\n"
	. '    public function waitForDigit(int $milliseconds): string;' . "\n"
	. '}' . "\n"
	. 'class AlertCallAgiSession {' . "\n"
	. '    public function run(array $context, AlertCallAgiTransport $transport, callable $isRemotelyAccepted): array {' . "\n"
	. "        return ['response' => 'accepted', 'digit' => '1', 'accepted' => true];\n"
	. '    }' . "\n"
	. '}' . "\n";
assert_true(file_put_contents($agiSessionStubPath, $agiSessionStub) !== false, 'AGI contract should write a fake AlertCallAgiSession class');

$promptInventoryStubPath = $fakeSrcDir . '/AlertCallPromptInventory.php';
$promptInventoryStub = '<?php' . "\n"
	. 'namespace FreePBX\\modules\\Repeatcaller;' . "\n"
	. 'class AlertCallPromptInventory {' . "\n"
	. '    public static function discover(string $soundsRoot, string $language): ?array { return []; }' . "\n"
	. '}' . "\n";
assert_true(file_put_contents($promptInventoryStubPath, $promptInventoryStub) !== false, 'AGI contract should write a fake AlertCallPromptInventory class');

$languageSupportStubPath = $fakeSrcDir . '/AlertCallLanguageSupport.php';
$languageSupportStub = '<?php' . "\n"
	. 'namespace FreePBX\\modules\\Repeatcaller;' . "\n"
	. 'class AlertCallLanguageSupport {' . "\n"
	. '    public function __construct(string $soundsRoot) {}' . "\n"
	. "    public function status(): array { return ['fallback_language' => '']; }" . "\n"
	. '}' . "\n";
assert_true(file_put_contents($languageSupportStubPath, $languageSupportStub) !== false, 'AGI contract should write a fake AlertCallLanguageSupport class');

$moduleStubPath = $fakeModuleRoot . '/Repeatcaller.class.php';
$moduleStub = '<?php' . "\n"
	. 'namespace FreePBX\\modules;' . "\n"
	. 'class Repeatcaller {' . "\n"
	. '    public function __construct($container) {}' . "\n"
	. '    public function signalIncidentAcceptedForLiveAlertCalls(int $incidentId, ?int $excludeHistoryId = null): void {}' . "\n"
	. '}' . "\n";
assert_true(file_put_contents($moduleStubPath, $moduleStub) !== false, 'AGI contract should write a fake Repeatcaller module class for accepted callback signalling');

$runAgi = function (string $scriptPath, string $bootstrap, string $args, ?array &$lines = null): int {
	$lines = [];
	$command = 'REPEATCALLER_AGI_BOOTSTRAP=' . escapeshellarg($bootstrap)
		. ' php ' . escapeshellarg($scriptPath)
		. ' ' . $args
		. ' 2>&1';
	exec($command, $lines, $status);
	return (int)$status;
};

$successOutput = [];
$successExit = $runAgi($copiedAgiPath, $bootstrapPath, '63 23 accepted 1112223333 1', $successOutput);
assert_same(0, $successExit, 'AGI execution from a non-module directory should succeed with exit code 0 when module resolution and persistence succeed');
assert_same('', trim(implode("\n", $successOutput)), 'successful AGI execution from a non-module directory should remain silent');

assert_true(@unlink($repoStubPath), 'AGI contract should remove the fake repository class to test missing-repository failure');
$failureOutput = [];
$failureExit = $runAgi($copiedAgiPath, $bootstrapPath, '63 23 accepted 1112223333 1', $failureOutput);
assert_true($failureExit !== 0, 'missing RepeatCallerRepository class should return non-zero from AGI execution');

$cleanupIterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator($agiContractTemp, FilesystemIterator::SKIP_DOTS),
	RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($cleanupIterator as $pathInfo) {
	$path = $pathInfo->getPathname();
	if ($pathInfo->isDir()) {
		@rmdir($path);
	} else {
		@unlink($path);
	}
}
@rmdir($agiContractTemp);

$uninstallSource = file_get_contents($root . '/uninstall.php');
assert_true($uninstallSource !== false, 'uninstall.php should be readable');
assert_true(strpos($uninstallSource, 'Repeatcaller::removeLegacyAlertCallDialplan(') !== false, 'uninstall must share the selective upgrade cleanup');
assert_true(strpos($uninstallSource, 'file_put_contents(') === false && strpos($uninstallSource, 'unlink($customPath)') === false, 'uninstall must not rewrite or delete administrator custom dialplan itself');
$legacyBlock = "; Repeat Caller managed include\n#include repeatcaller_alert.conf\n; End Repeat Caller managed include\n";

final class RepeatcallerShortWriteStream {
	public static string $data = '';
	public static int $writeLimit = 2;
	public static ?int $failOnWrite = null;
	public static int $writes = 0;

	public static function reset(): void {
		self::$data = '';
		self::$writeLimit = 2;
		self::$failOnWrite = null;
		self::$writes = 0;
	}

	public function stream_open($path, $mode, $options, &$openedPath): bool {
		return true;
	}

	public function stream_write($data): int {
		self::$writes++;
		if (self::$failOnWrite !== null && self::$writes >= self::$failOnWrite) {
			return 0;
		}
		$length = min(self::$writeLimit, strlen($data));
		self::$data .= substr($data, 0, $length);
		return $length;
	}

	public function stream_flush(): bool {
		return true;
	}

	public function stream_eof(): bool {
		return true;
	}

	public function url_stat($path, $flags): array {
		return [];
	}
}

assert_true(stream_wrapper_register('repeatcaller-short-write', RepeatcallerShortWriteStream::class), 'short-write contract must register its isolated stream wrapper');
$writeAll = new ReflectionMethod(Repeatcaller::class, 'writeLegacyDialplanAll');
$writeAll->setAccessible(true);
RepeatcallerShortWriteStream::reset();
$shortWriteHandle = fopen('repeatcaller-short-write://complete', 'w+b');
assert_true(is_resource($shortWriteHandle), 'short-write wrapper must open for the write-all contract');
assert_true($writeAll->invoke(null, $shortWriteHandle, 'complete write'), 'positive short writes must be retried until the entire value is written');
assert_same('complete write', RepeatcallerShortWriteStream::$data, 'positive short writes must preserve every byte in order');
fclose($shortWriteHandle);
RepeatcallerShortWriteStream::reset();
RepeatcallerShortWriteStream::$failOnWrite = 3;
$interruptedWriteHandle = fopen('repeatcaller-short-write://interrupted', 'w+b');
assert_true(is_resource($interruptedWriteHandle), 'interrupted-write wrapper must open for the write-all contract');
assert_true(!$writeAll->invoke(null, $interruptedWriteHandle, 'interrupted write'), 'a zero-byte interrupted write must be reported instead of accepted as success');
assert_true(RepeatcallerShortWriteStream::$data !== 'interrupted write', 'an interrupted write must not be mistaken for a complete temporary file');
fclose($interruptedWriteHandle);
stream_wrapper_unregister('repeatcaller-short-write');

$atomicLegacyDirectory = sys_get_temp_dir() . '/repeatcaller_legacy_atomic_' . uniqid('', true);
assert_true(mkdir($atomicLegacyDirectory), 'atomic legacy cleanup fixture directory must be created');
$atomicCustomPath = $atomicLegacyDirectory . '/extensions_custom.conf';
$atomicFragmentPath = $atomicLegacyDirectory . '/repeatcaller_alert.conf';
$atomicBefore = "; administrator before\n" . $legacyBlock . "[administrator-after]\n same => n,NoOp(keep exactly)\n";
$atomicAfter = "; administrator before\n[administrator-after]\n same => n,NoOp(keep exactly)\n";
file_put_contents($atomicCustomPath, $atomicBefore);
file_put_contents($atomicFragmentPath, 'legacy module fragment');
chmod($atomicCustomPath, 0640);
$atomicOriginalStat = stat($atomicCustomPath);
try {
	Repeatcaller::assertLegacyAlertCallDialplanSafe($atomicLegacyDirectory);
	file_put_contents($atomicCustomPath, $atomicBefore);
	chmod($atomicCustomPath, 0640);
	clearstatcache(true, $atomicCustomPath);
	$atomicOriginalStat = stat($atomicCustomPath);
	Repeatcaller::removeLegacyAlertCallDialplan($atomicLegacyDirectory);
	clearstatcache(true, $atomicCustomPath);
	$atomicUpdatedStat = stat($atomicCustomPath);
	assert_same($atomicAfter, file_get_contents($atomicCustomPath), 'atomic cleanup must preserve every administrator byte outside the exact managed block');
	assert_true($atomicUpdatedStat['ino'] !== $atomicOriginalStat['ino'], 'managed cleanup must replace the administrator file atomically rather than truncate it in place');
	assert_same($atomicOriginalStat['mode'], $atomicUpdatedStat['mode'], 'atomic cleanup must preserve extensions_custom.conf permissions');
	assert_same($atomicOriginalStat['uid'], $atomicUpdatedStat['uid'], 'atomic cleanup must preserve extensions_custom.conf ownership');
	assert_same($atomicOriginalStat['gid'], $atomicUpdatedStat['gid'], 'atomic cleanup must preserve extensions_custom.conf group ownership');
	assert_same([], glob($atomicLegacyDirectory . '/.repeatcaller-dialplan-*') ?: [], 'atomic cleanup must remove its temporary file');
	assert_true(!file_exists($atomicFragmentPath), 'atomic custom-file success must precede legacy fragment deletion');
	Repeatcaller::removeLegacyAlertCallDialplan($atomicLegacyDirectory);
	clearstatcache(true, $atomicCustomPath);
	assert_same($atomicUpdatedStat['ino'], stat($atomicCustomPath)['ino'], 'repeated cleanup must not replace an already-clean administrator file');
} finally {
	foreach (glob($atomicLegacyDirectory . '/*') ?: [] as $atomicFile) {
		unlink($atomicFile);
	}
	foreach (glob($atomicLegacyDirectory . '/.*') ?: [] as $atomicHiddenFile) {
		if (!in_array(basename($atomicHiddenFile), ['.', '..'], true) && file_exists($atomicHiddenFile)) {
			unlink($atomicHiddenFile);
		}
	}
	rmdir($atomicLegacyDirectory);
}

$concurrentLegacyDirectory = sys_get_temp_dir() . '/repeatcaller_legacy_concurrent_' . uniqid('', true);
assert_true(mkdir($concurrentLegacyDirectory), 'concurrent cleanup fixture directory must be created');
$concurrentCustomPath = $concurrentLegacyDirectory . '/extensions_custom.conf';
$concurrentSavedPath = $concurrentLegacyDirectory . '/extensions_custom.saved';
$concurrentFragmentPath = $concurrentLegacyDirectory . '/repeatcaller_alert.conf';
$concurrentBefore = $legacyBlock . "; original administrator bytes\n";
$concurrentUpdated = "; original administrator bytes\n";
$concurrentReplacement = "; concurrent administrator edit\n";
file_put_contents($concurrentCustomPath, $concurrentBefore);
file_put_contents($concurrentFragmentPath, 'legacy module fragment');
$concurrentHandle = fopen($concurrentCustomPath, 'r+b');
assert_true(is_resource($concurrentHandle) && flock($concurrentHandle, LOCK_EX), 'concurrent cleanup fixture must lock the inspected administrator file');
assert_true(rename($concurrentCustomPath, $concurrentSavedPath), 'concurrent cleanup fixture must move the inspected path');
file_put_contents($concurrentCustomPath, $concurrentReplacement);
$atomicReplace = new ReflectionMethod(Repeatcaller::class, 'replaceLegacyDialplanFileAtomically');
$atomicReplace->setAccessible(true);
$concurrentException = null;
try {
	try {
		$atomicReplace->invoke(null, $concurrentHandle, $concurrentCustomPath, $concurrentBefore, $concurrentUpdated);
	} catch (Throwable $e) {
		$concurrentException = $e;
	}
	assert_true($concurrentException instanceof RuntimeException, 'replacement must fail if the administrator file changes after inspection');
	assert_same($concurrentReplacement, file_get_contents($concurrentCustomPath), 'concurrent administrator replacement must remain byte-for-byte unchanged');
	assert_same($concurrentBefore, file_get_contents($concurrentSavedPath), 'the originally inspected file must also remain unchanged after a race');
	assert_same('legacy module fragment', file_get_contents($concurrentFragmentPath), 'a concurrent-change failure must retain the legacy fragment for retry');
	assert_same([], glob($concurrentLegacyDirectory . '/.repeatcaller-dialplan-*') ?: [], 'a concurrent-change failure must remove its temporary file');
} finally {
	flock($concurrentHandle, LOCK_UN);
	fclose($concurrentHandle);
}
unlink($concurrentSavedPath);
Repeatcaller::removeLegacyAlertCallDialplan($concurrentLegacyDirectory);
assert_same($concurrentReplacement, file_get_contents($concurrentCustomPath), 'retry after a concurrent change must preserve the new administrator content');
assert_true(!file_exists($concurrentFragmentPath), 'retry after a concurrent change must complete fragment cleanup');
unlink($concurrentCustomPath);
rmdir($concurrentLegacyDirectory);

$originalEffectiveUser = function_exists('posix_geteuid') ? posix_geteuid() : null;
$readOnlyNoopDirectory = sys_get_temp_dir() . '/repeatcaller_legacy_readonly_noop_' . uniqid('', true);
assert_true(mkdir($readOnlyNoopDirectory), 'read-only no-op cleanup fixture directory must be created');
$readOnlyNoopCustomPath = $readOnlyNoopDirectory . '/extensions_custom.conf';
$readOnlyNoopContent = "; administrator configuration only\n";
file_put_contents($readOnlyNoopCustomPath, $readOnlyNoopContent);
chmod($readOnlyNoopCustomPath, 0444);
clearstatcache(true, $readOnlyNoopCustomPath);
$readOnlyNoopStat = stat($readOnlyNoopCustomPath);
$readOnlyNoopException = null;
try {
	if ($originalEffectiveUser === 0 && function_exists('posix_seteuid')) {
		assert_true(posix_seteuid(65534), 'read-only no-op contract must switch to an unprivileged effective user');
	}
	try {
		Repeatcaller::removeLegacyAlertCallDialplan($readOnlyNoopDirectory);
		Repeatcaller::removeLegacyAlertCallDialplan($readOnlyNoopDirectory);
	} catch (RuntimeException $e) {
		$readOnlyNoopException = $e;
	}
} finally {
	if ($originalEffectiveUser === 0 && function_exists('posix_seteuid')) {
		assert_true(posix_seteuid(0), 'read-only no-op contract must restore the original effective user');
	}
}
assert_same(null, $readOnlyNoopException, 'a readable non-writable administrator file with no module work must allow no-op cleanup');
assert_same($readOnlyNoopContent, file_get_contents($readOnlyNoopCustomPath), 'read-only no-op cleanup must preserve every administrator byte');
clearstatcache(true, $readOnlyNoopCustomPath);
assert_same($readOnlyNoopStat['ino'], stat($readOnlyNoopCustomPath)['ino'], 'repeated read-only no-op cleanup must not replace the administrator file');
unlink($readOnlyNoopCustomPath);
rmdir($readOnlyNoopDirectory);

$readOnlyFragmentDirectory = sys_get_temp_dir() . '/repeatcaller_legacy_readonly_fragment_' . uniqid('', true);
assert_true(mkdir($readOnlyFragmentDirectory, 0777), 'read-only fragment cleanup fixture directory must be created');
chmod($readOnlyFragmentDirectory, 0777);
$readOnlyFragmentCustomPath = $readOnlyFragmentDirectory . '/extensions_custom.conf';
$readOnlyFragmentPath = $readOnlyFragmentDirectory . '/repeatcaller_alert.conf';
$readOnlyFragmentContent = "; administrator configuration without a Repeat Caller reference\n";
file_put_contents($readOnlyFragmentCustomPath, $readOnlyFragmentContent);
file_put_contents($readOnlyFragmentPath, 'obsolete legacy module fragment');
chmod($readOnlyFragmentCustomPath, 0444);
chmod($readOnlyFragmentPath, 0444);
clearstatcache(true, $readOnlyFragmentCustomPath);
$readOnlyFragmentStat = stat($readOnlyFragmentCustomPath);
$readOnlyFragmentException = null;
try {
	if ($originalEffectiveUser === 0 && function_exists('posix_seteuid')) {
		assert_true(posix_seteuid(65534), 'read-only fragment contract must switch to an unprivileged effective user');
	}
	try {
		Repeatcaller::removeLegacyAlertCallDialplan($readOnlyFragmentDirectory);
		Repeatcaller::removeLegacyAlertCallDialplan($readOnlyFragmentDirectory);
	} catch (RuntimeException $e) {
		$readOnlyFragmentException = $e;
	}
} finally {
	if ($originalEffectiveUser === 0 && function_exists('posix_seteuid')) {
		assert_true(posix_seteuid(0), 'read-only fragment contract must restore the original effective user');
	}
}
assert_same(null, $readOnlyFragmentException, 'an obsolete fragment must be removable without write access to an unchanged administrator file');
assert_true(!file_exists($readOnlyFragmentPath), 'read-only fragment cleanup must remove the obsolete module fragment');
assert_same($readOnlyFragmentContent, file_get_contents($readOnlyFragmentCustomPath), 'fragment-only cleanup must preserve the read-only administrator file byte-for-byte');
clearstatcache(true, $readOnlyFragmentCustomPath);
assert_same($readOnlyFragmentStat['ino'], stat($readOnlyFragmentCustomPath)['ino'], 'fragment-only cleanup must not replace the administrator file');
unlink($readOnlyFragmentCustomPath);
rmdir($readOnlyFragmentDirectory);

$permissionLegacyDirectory = sys_get_temp_dir() . '/repeatcaller_legacy_permission_' . uniqid('', true);
assert_true(mkdir($permissionLegacyDirectory), 'permission-failure cleanup fixture directory must be created');
$permissionCustomPath = $permissionLegacyDirectory . '/extensions_custom.conf';
$permissionFragmentPath = $permissionLegacyDirectory . '/repeatcaller_alert.conf';
$permissionBefore = "; administrator\n" . $legacyBlock;
file_put_contents($permissionCustomPath, $permissionBefore);
file_put_contents($permissionFragmentPath, 'legacy module fragment');
chmod($permissionCustomPath, 0444);
clearstatcache(true, $permissionCustomPath);
$permissionOriginalStat = stat($permissionCustomPath);
$permissionException = null;
try {
	if ($originalEffectiveUser === 0 && function_exists('posix_seteuid')) {
		assert_true(posix_seteuid(65534), 'permission contract must switch to an unprivileged effective user');
	}
	try {
		Repeatcaller::removeLegacyAlertCallDialplan($permissionLegacyDirectory);
	} catch (RuntimeException $e) {
		$permissionException = $e;
	}
} finally {
	if ($originalEffectiveUser === 0 && function_exists('posix_seteuid')) {
		assert_true(posix_seteuid(0), 'permission contract must restore the original effective user');
	}
}
assert_true($permissionException instanceof RuntimeException, 'cleanup must reject a read-only administrator file when managed-block removal requires replacement');
assert_same($permissionBefore, file_get_contents($permissionCustomPath), 'permission failure must preserve the complete administrator file');
clearstatcache(true, $permissionCustomPath);
assert_same($permissionOriginalStat['ino'], stat($permissionCustomPath)['ino'], 'permission failure must not replace the administrator file');
assert_same('legacy module fragment', file_get_contents($permissionFragmentPath), 'permission failure must retain the legacy fragment for retry');
chmod($permissionCustomPath, 0644);
Repeatcaller::removeLegacyAlertCallDialplan($permissionLegacyDirectory);
assert_true(!file_exists($permissionFragmentPath), 'cleanup must recover after a permission failure is resolved');
unlink($permissionCustomPath);
rmdir($permissionLegacyDirectory);

if ($originalEffectiveUser === 0 && function_exists('posix_seteuid')) {
	$tempFailureDirectory = sys_get_temp_dir() . '/repeatcaller_legacy_temp_' . uniqid('', true);
	assert_true(mkdir($tempFailureDirectory, 0555), 'same-directory temporary-file failure fixture must be created');
	$tempFailureCustomPath = $tempFailureDirectory . '/extensions_custom.conf';
	$tempFailureFragmentPath = $tempFailureDirectory . '/repeatcaller_alert.conf';
	$tempFailureBefore = "; administrator\n" . $legacyBlock;
	file_put_contents($tempFailureCustomPath, $tempFailureBefore);
	file_put_contents($tempFailureFragmentPath, 'legacy module fragment');
	chown($tempFailureCustomPath, 65534);
	chmod($tempFailureCustomPath, 0644);
	$tempFailureException = null;
	try {
		assert_true(posix_seteuid(65534), 'temporary-file contract must switch to an unprivileged effective user');
		try {
			Repeatcaller::removeLegacyAlertCallDialplan($tempFailureDirectory);
		} catch (RuntimeException $e) {
			$tempFailureException = $e;
		}
	} finally {
		assert_true(posix_seteuid(0), 'temporary-file contract must restore the original effective user');
	}
	assert_true($tempFailureException instanceof RuntimeException, 'cleanup must fail when a same-directory temporary file cannot be created');
	assert_same($tempFailureBefore, file_get_contents($tempFailureCustomPath), 'temporary-file creation failure must preserve the original administrator file');
	assert_same('legacy module fragment', file_get_contents($tempFailureFragmentPath), 'temporary-file creation failure must retain the fragment for retry');
	assert_same([], glob($tempFailureDirectory . '/.repeatcaller-dialplan-*') ?: [], 'temporary-file failure must not leave a cleanup temp file in the configuration directory');
	chmod($tempFailureDirectory, 0755);
	unlink($tempFailureCustomPath);
	unlink($tempFailureFragmentPath);
	rmdir($tempFailureDirectory);
}

$interruptedLegacyDirectory = sys_get_temp_dir() . '/repeatcaller_legacy_interrupted_' . uniqid('', true);
assert_true(mkdir($interruptedLegacyDirectory), 'interrupted cleanup fixture directory must be created');
$interruptedCustomPath = $interruptedLegacyDirectory . '/extensions_custom.conf';
$interruptedFragmentPath = $interruptedLegacyDirectory . '/repeatcaller_alert.conf';
file_put_contents($interruptedCustomPath, $legacyBlock . "; administrator retained\n");
mkdir($interruptedFragmentPath);
try {
	try {
		Repeatcaller::removeLegacyAlertCallDialplan($interruptedLegacyDirectory);
		$interruptedException = null;
	} catch (RuntimeException $e) {
		$interruptedException = $e;
	}
	assert_true($interruptedException instanceof RuntimeException, 'fragment removal failure must be visible after the custom-file replacement');
	assert_same("; administrator retained\n", file_get_contents($interruptedCustomPath), 'an interruption after atomic replacement must retain the successful custom-file cleanup');
	assert_true(is_dir($interruptedFragmentPath), 'failed fragment deletion must retain the fragment path for a recoverable retry');
	rmdir($interruptedFragmentPath);
	Repeatcaller::removeLegacyAlertCallDialplan($interruptedLegacyDirectory);
	assert_true(!file_exists($interruptedFragmentPath), 'retry after interrupted fragment deletion must complete cleanup');
} finally {
	if (is_dir($interruptedFragmentPath)) {
		rmdir($interruptedFragmentPath);
	}
	foreach (glob($interruptedLegacyDirectory . '/*') ?: [] as $interruptedFile) {
		unlink($interruptedFile);
	}
	rmdir($interruptedLegacyDirectory);
}

$symlinkLegacyDirectory = sys_get_temp_dir() . '/repeatcaller_legacy_symlink_' . uniqid('', true);
assert_true(mkdir($symlinkLegacyDirectory), 'symlink cleanup fixture directory must be created');
$symlinkTargetPath = $symlinkLegacyDirectory . '/administrator-real.conf';
$symlinkCustomPath = $symlinkLegacyDirectory . '/extensions_custom.conf';
$symlinkFragmentPath = $symlinkLegacyDirectory . '/repeatcaller_alert.conf';
file_put_contents($symlinkTargetPath, $legacyBlock);
file_put_contents($symlinkFragmentPath, 'legacy module fragment');
assert_true(symlink($symlinkTargetPath, $symlinkCustomPath), 'symlink fixture must be created');
try {
	$symlinkException = null;
	try {
		Repeatcaller::removeLegacyAlertCallDialplan($symlinkLegacyDirectory);
	} catch (RuntimeException $e) {
		$symlinkException = $e;
	}
	assert_true($symlinkException instanceof RuntimeException, 'cleanup must refuse to replace a symlinked extensions_custom.conf');
	assert_same($legacyBlock, file_get_contents($symlinkTargetPath), 'symlink refusal must preserve the administrator target byte-for-byte');
	assert_same('legacy module fragment', file_get_contents($symlinkFragmentPath), 'symlink refusal must retain the legacy fragment');
} finally {
	unlink($symlinkCustomPath);
	unlink($symlinkTargetPath);
	unlink($symlinkFragmentPath);
	rmdir($symlinkLegacyDirectory);
}

$cleanLegacyDirectory = sys_get_temp_dir() . '/repeatcaller_legacy_clean_' . uniqid('', true);
assert_true(mkdir($cleanLegacyDirectory), 'clean install preflight fixture directory must be created');
try {
	Repeatcaller::assertLegacyAlertCallDialplanSafe($cleanLegacyDirectory);
	Repeatcaller::removeLegacyAlertCallDialplan($cleanLegacyDirectory);
	assert_true(!file_exists($cleanLegacyDirectory . '/extensions_custom.conf') && !file_exists($cleanLegacyDirectory . '/repeatcaller_alert.conf'), 'clean install preflight and cleanup must not create legacy configuration');
} finally {
	rmdir($cleanLegacyDirectory);
}
$legacyCleanupCases = [
	[null, null],
	['', ''],
	[$legacyBlock, ''],
	[rtrim($legacyBlock, "\n"), ''],
	["; administrator before\n\n" . $legacyBlock . "[administrator-after]\n same => n,NoOp(keep)\n\n  ", "; administrator before\n\n[administrator-after]\n same => n,NoOp(keep)\n\n  "],
	["; before\r\n" . str_replace("\n", "\r\n", $legacyBlock) . "; after\r\n", "; before\r\n; after\r\n"],
	[$legacyBlock . "; middle\n" . $legacyBlock, "; middle\n"],
	["; #include repeatcaller_alert.conf\n; unmanaged include\n", "; #include repeatcaller_alert.conf\n; unmanaged include\n"],
	["; Repeat Caller managed include\n; incomplete block\n", "; Repeat Caller managed include\n; incomplete block\n"],
];
foreach (['upgrade', 'uninstall'] as $cleanupEntryPoint) {
	foreach ($legacyCleanupCases as [$beforeCleanup, $afterCleanup]) {
		$legacyDirectory = sys_get_temp_dir() . '/repeatcaller_legacy_' . uniqid('', true);
		assert_true(mkdir($legacyDirectory), 'cleanup fixture directory must be created');
		$legacyCustomPath = $legacyDirectory . '/extensions_custom.conf';
		$legacyFragmentPath = $legacyDirectory . '/repeatcaller_alert.conf';
		if ($beforeCleanup !== null) {
			file_put_contents($legacyCustomPath, $beforeCleanup);
		}
		file_put_contents($legacyFragmentPath, 'legacy module fragment');
		file_put_contents($legacyDirectory . '/administrator.conf', 'unrelated configuration');
		try {
			Repeatcaller::removeLegacyAlertCallDialplan($legacyDirectory);
			assert_true(!file_exists($legacyFragmentPath), $cleanupEntryPoint . ' must remove the legacy module fragment');
			if ($afterCleanup === null) {
				assert_true(!file_exists($legacyCustomPath), $cleanupEntryPoint . ' must not create extensions_custom.conf');
			} else {
				assert_true(file_exists($legacyCustomPath), $cleanupEntryPoint . ' must retain the administrator file even if empty');
				assert_same($afterCleanup, file_get_contents($legacyCustomPath), $cleanupEntryPoint . ' must preserve every byte outside complete managed blocks');
			}
			Repeatcaller::removeLegacyAlertCallDialplan($legacyDirectory);
			assert_true(!file_exists($legacyFragmentPath), 'repeated cleanup must not create any module fragment');
			assert_same('unrelated configuration', file_get_contents($legacyDirectory . '/administrator.conf'), 'cleanup must not change any other configuration file');
		} finally {
			foreach (glob($legacyDirectory . '/*') as $legacyFile) {
				unlink($legacyFile);
			}
			rmdir($legacyDirectory);
		}
	}
}
$unmanagedLegacyReferences = [
	"#include repeatcaller_alert.conf\n",
	"#include /etc/asterisk/repeatcaller_alert.conf\n",
	"#include \"repeatcaller_alert.conf\"\n",
	"#tryinclude repeatcaller_alert.conf\n",
	"#include repeatcaller_alert.conf\n#tryinclude /etc/asterisk/repeatcaller_alert.conf\n",
	" \t#include repeatcaller_alert.conf ; administrator include\r\n",
	"; Repeat Caller managed include\n#include repeatcaller_alert.conf\n; End Repeat Caller managed include extra\n",
	$legacyBlock . "#include repeatcaller_alert.conf\n" . $legacyBlock,
];
foreach (['upgrade', 'uninstall'] as $cleanupEntryPoint) {
	foreach ($unmanagedLegacyReferences as $beforeCleanup) {
		$legacyDirectory = sys_get_temp_dir() . '/repeatcaller_legacy_ref_' . uniqid('', true);
		assert_true(mkdir($legacyDirectory), 'unmanaged-reference fixture directory must be created');
		$legacyCustomPath = $legacyDirectory . '/extensions_custom.conf';
		$legacyFragmentPath = $legacyDirectory . '/repeatcaller_alert.conf';
		file_put_contents($legacyCustomPath, $beforeCleanup);
		file_put_contents($legacyFragmentPath, 'legacy module fragment');
		try {
			$cleanupException = null;
			try {
				Repeatcaller::removeLegacyAlertCallDialplan($legacyDirectory);
			} catch (RuntimeException $e) {
				$cleanupException = $e;
			}
			assert_true($cleanupException instanceof RuntimeException, $cleanupEntryPoint . ' must refuse cleanup while an unmanaged reference remains');
			assert_true(strpos($cleanupException->getMessage(), 'An unmanaged reference to repeatcaller_alert.conf remains') === 0, $cleanupEntryPoint . ' refusal must explain the remaining reference');
			assert_same($beforeCleanup, file_get_contents($legacyCustomPath), $cleanupEntryPoint . ' refusal must leave administrator dialplan byte-for-byte unchanged');
			assert_same('legacy module fragment', file_get_contents($legacyFragmentPath), $cleanupEntryPoint . ' refusal must retain the referenced fragment');
		} finally {
			foreach (glob($legacyDirectory . '/*') as $legacyFile) {
				unlink($legacyFile);
			}
			rmdir($legacyDirectory);
		}
	}
}
$missingFragmentCases = [
	[$legacyBlock, '', true],
	["#include repeatcaller_alert.conf\n", null, false],
	[$legacyBlock . "#include repeatcaller_alert.conf\n", null, false],
	["; administrator configuration without a Repeat Caller reference\n", "; administrator configuration without a Repeat Caller reference\n", true],
];
foreach ($missingFragmentCases as [$beforeCleanup, $afterCleanup, $shouldSucceed]) {
	$missingFragmentDirectory = sys_get_temp_dir() . '/repeatcaller_legacy_missing_' . uniqid('', true);
	assert_true(mkdir($missingFragmentDirectory), 'missing-fragment fixture directory must be created');
	$missingFragmentCustomPath = $missingFragmentDirectory . '/extensions_custom.conf';
	file_put_contents($missingFragmentCustomPath, $beforeCleanup);
	try {
		$cleanupException = null;
		try {
			Repeatcaller::assertLegacyAlertCallDialplanSafe($missingFragmentDirectory);
			Repeatcaller::removeLegacyAlertCallDialplan($missingFragmentDirectory);
		} catch (RuntimeException $e) {
			$cleanupException = $e;
		}
		if ($shouldSucceed) {
			assert_same(null, $cleanupException, 'missing fragment without unmanaged references, or with only the exact managed block, must clean safely');
			assert_same($afterCleanup, file_get_contents($missingFragmentCustomPath), 'missing-fragment cleanup must remove only the exact managed block');
		} else {
			assert_true($cleanupException instanceof RuntimeException, 'missing fragment must not conceal an active unmanaged reference');
			assert_true(strpos($cleanupException->getMessage(), 'Remove or update that administrator include') !== false, 'missing-fragment conflict must give actionable recovery guidance');
			assert_same($beforeCleanup, file_get_contents($missingFragmentCustomPath), 'missing-fragment conflict must preserve administrator configuration byte-for-byte');
		}
		assert_true(!file_exists($missingFragmentDirectory . '/repeatcaller_alert.conf'), 'missing-fragment cleanup must not create the module fragment');
		$retryException = null;
		try {
			Repeatcaller::removeLegacyAlertCallDialplan($missingFragmentDirectory);
		} catch (RuntimeException $e) {
			$retryException = $e;
		}
		assert_same(!$shouldSucceed, $retryException instanceof RuntimeException, 'repeated cleanup must preserve the same success or conflict result');
		assert_same($shouldSucceed ? $afterCleanup : $beforeCleanup, file_get_contents($missingFragmentCustomPath), 'repeated cleanup must remain safe and idempotent');
	} finally {
		unlink($missingFragmentCustomPath);
		rmdir($missingFragmentDirectory);
	}
}
assert_true(strpos($uninstallSource, 'repeatcallerRemoveAgi()') !== false, 'uninstall hook must remove the deployed AGI script');
assert_true(strpos($uninstallSource, "'/repeatcaller_alert_response.php'") !== false, 'uninstall hook must target the deployed AGI filename in Asterisk AGI-bin');

// --- 1b: MariaDB 5.5 compatibility -- utf8 (not utf8mb4) charset, and every
// index fits within the 767-byte InnoDB max key length (utf8 = 3 bytes/char,
// vs. utf8mb4's 4), assuming the conservative Antelope/COMPACT row format
// with innodb_large_prefix off, i.e. MariaDB 5.5's defaults with no reliance
// on ROW_FORMAT=DYNAMIC or innodb_large_prefix being enabled. --------------

foreach ($statements as $statement) {
	assert_true(strpos($statement, 'utf8mb4') === false, 'schema statements must not use utf8mb4 (exceeds the 767-byte key length limit on MariaDB 5.5 InnoDB defaults)');
	assert_true((bool)preg_match('/DEFAULT CHARSET=utf8(?:\s+COLLATE=utf8_\w+)?"?$/', trim($statement)), 'schema statements must declare an explicit MariaDB 5.5-compatible utf8 charset/collation');
}

assert_same(
	array_fill(0, count($statements), 0),
	array_map(function (string $statement): int {
		return count(indexesExceedingKeyLimit($statement, 3, 767));
	}, $statements),
	'every index in every table must fit within the 767-byte InnoDB key length limit under utf8 (3 bytes/char)'
);

// --- 2: fresh-install table names match production repository references -

// repeatcaller_settings is owned by Repeatcaller.class.php (getAlertSettings()/
// setSetting()); every other table is owned by the repository.
$referencedTables = [];
foreach (['/src/RepeatCallerRepository.php', '/Repeatcaller.class.php'] as $productionFile) {
	$source = file_get_contents($root . $productionFile);
	assert_true($source !== false, "{$productionFile} should be readable");
	preg_match_all('/\b(?:FROM|INTO|UPDATE|JOIN)\s+(repeatcaller_\w+)/', $source, $matches);
	$referencedTables = array_merge($referencedTables, $matches[1]);
}
$referencedTables = array_values(array_unique($referencedTables));
sort($referencedTables);
assert_same($sortedRequired, $referencedTables, 'every table read or written by production code must be exactly one of the schema-declared required tables (no orphaned or undeclared table)');

// --- 3: re-running install is idempotent and does not overwrite settings --
// (structural proof; the actual SQL was verified end-to-end against live
// MariaDB separately -- see commit/report notes)

$schemaSource = file_get_contents($root . '/src/Schema.php');
assert_true(strpos($schemaSource, 'INSERT IGNORE') !== false, 'default settings must be seeded with INSERT IGNORE so existing values are never overwritten by a repeat install');
assert_true(strpos($schemaSource, 'CREATE TABLE IF NOT EXISTS') !== false, 'table creation must be idempotent (IF NOT EXISTS)');
assert_true((bool)preg_match('/COUNT\(\*\).*information_schema\.COLUMNS/s', $schemaSource) || (bool)preg_match('/information_schema\.COLUMNS/', $schemaSource), 'guarded column migrations must check for existing columns before altering');

// --- 4: missing schema produces a bounded page error, not a raw PDO fault -

$pageDb = new PDO('sqlite::memory:');
$pageDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
FreePBX::$database = $pageDb;
$module = new Repeatcaller(new stdClass());

$page = $module->showPage();
assert_true(strpos($page, 'missing') !== false || strpos($page, 'Reinstall') !== false, 'a missing schema must render a clear operator-facing message');
assert_true(stripos($page, 'PDOException') === false, 'a missing schema must never expose the PDOException class name to the browser');
assert_true(stripos($page, 'SQLSTATE') === false, 'a missing schema must never expose raw SQL error detail to the browser');
assert_true(stripos($page, '.php:') === false && stripos($page, 'Stack trace') === false, 'a missing schema must never expose a stack trace to the browser');

// Note: Schema::isReady() queries information_schema.TABLES, which does not
// exist under SQLite regardless of which tables are present, so the
// "ready schema renders the normal page" side of this guard cannot be
// exercised here even after creating all ten tables -- isSchemaReady()
// correctly treats the resulting query failure as not-ready (fail closed).
// That behavior, and the true ready/positive path, were verified for real
// against live MariaDB 10.11.14 during development of this fix.

// --- 5: existing suites are expected to still pass; proven by the shared
// validation run (php tests/repeat_*.php), not re-implemented here.

// --- 6-7: uninstall tolerates current + inherited tables, no obsolete
// registration table is created on fresh install --------------------------

$obsoleteRegistrationTables = [
	'repeatcaller_alert_escalation',
	'repeatcaller_alert_history',
];
foreach ($obsoleteRegistrationTables as $legacyTable) {
	assert_true(!in_array($legacyTable, $createdTables, true), "fresh install must not create obsolete registration table '{$legacyTable}'");
}

assert_true(strpos($uninstallSource, 'Schema::REQUIRED_TABLES') !== false, 'uninstall.php must drop every current table via the shared Schema table list');
foreach ($obsoleteRegistrationTables as $legacyTable) {
	assert_true(strpos($uninstallSource, "'{$legacyTable}'") !== false, "uninstall.php must tolerate dropping inherited table '{$legacyTable}'");
}

echo "repeat install contract tests passed\n";
