<?php

$host = getenv('RESTORE_AUDIT_HOST') ?: '127.0.0.1';
$port = (int) (getenv('RESTORE_AUDIT_PORT') ?: 0);
$database = getenv('RESTORE_AUDIT_DATABASE') ?: '';
$username = getenv('RESTORE_AUDIT_USERNAME') ?: 'erp_app';
$privateRoot = getenv('RESTORE_AUDIT_PRIVATE_ROOT') ?: '';
$output = getenv('RESTORE_AUDIT_OUTPUT') ?: '';
if ($host !== '127.0.0.1' || ! in_array($port, [55475, 55481, 55486, 55487], true)
    || $database !== 'erp_elec_new_test' || ! is_dir($privateRoot) || $output === '') {
    throw new RuntimeException('Restore verifier requires the exact loopback disposable database and explicit private storage roots.');
}
$root = realpath($privateRoot);
if ($root === false) {
    throw new RuntimeException('Private storage root cannot be resolved.');
}
$pdo = new PDO("pgsql:host={$host};port={$port};dbname={$database}", $username, getenv('RESTORE_AUDIT_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$identity = $pdo->query('SELECT current_database() AS database, inet_server_addr()::text AS address, inet_server_port() AS port')->fetch();
$normalizedAddress = preg_replace('#/\d+$#', '', (string) $identity['address']);
$identity['address'] = $normalizedAddress;
if ($identity['database'] !== $database || $normalizedAddress !== $host || (int) $identity['port'] !== $port) {
    throw new RuntimeException('Restore verifier connected to an unexpected database identity.');
}
$tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
$quote = static fn (string $name): string => '"'.str_replace('"', '""', $name).'"';
$counts = [];
foreach ($tables as $table) {
    $counts[$table] = (int) $pdo->query('SELECT count(*) FROM public.'.$quote($table))->fetchColumn();
}
$columns = $pdo->query("SELECT table_name,column_name,ordinal_position,data_type,is_nullable,column_default,character_maximum_length,numeric_precision,numeric_scale,udt_name,is_identity,identity_generation,is_generated,generation_expression FROM information_schema.columns WHERE table_schema='public' ORDER BY table_name,ordinal_position")->fetchAll();
$constraints = $pdo->query("SELECT t.relname AS table_name,c.conname AS constraint_name,CASE c.contype WHEN 'p' THEN 'PRIMARY KEY' WHEN 'f' THEN 'FOREIGN KEY' WHEN 'u' THEN 'UNIQUE' WHEN 'c' THEN 'CHECK' WHEN 'x' THEN 'EXCLUSION' ELSE c.contype::text END AS constraint_type,c.condeferrable AS is_deferrable,c.condeferred AS initially_deferred FROM pg_constraint c JOIN pg_class t ON t.oid=c.conrelid JOIN pg_namespace n ON n.oid=t.relnamespace WHERE n.nspname='public' AND NOT (c.contype='c' AND c.conname ~ '^[0-9]+_[0-9]+_[0-9]+_not_null$') ORDER BY t.relname,c.conname")->fetchAll();
$indexes = $pdo->query("SELECT t.relname AS table_name,i.relname AS index_name,ix.indisunique AS is_unique,ix.indisprimary AS is_primary,ix.indisvalid AS is_valid,ix.indisready AS is_ready FROM pg_index ix JOIN pg_class t ON t.oid=ix.indrelid JOIN pg_class i ON i.oid=ix.indexrelid JOIN pg_namespace n ON n.oid=t.relnamespace WHERE n.nspname='public' ORDER BY t.relname,i.relname")->fetchAll();
$triggers = $pdo->query("SELECT t.relname AS table_name,tr.tgname AS trigger_name,tr.tgtype::integer AS trigger_type,tr.tgenabled AS enabled,p.proname AS function_name FROM pg_trigger tr JOIN pg_class t ON t.oid=tr.tgrelid JOIN pg_namespace n ON n.oid=t.relnamespace JOIN pg_proc p ON p.oid=tr.tgfoid WHERE n.nspname='public' AND NOT tr.tgisinternal ORDER BY t.relname,tr.tgname")->fetchAll();
$functions = $pdo->query("SELECT p.proname AS function_name,pg_get_function_identity_arguments(p.oid) AS identity_arguments,pg_get_function_result(p.oid) AS result_type,l.lanname AS language,p.prosrc AS function_source FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace JOIN pg_language l ON l.oid=p.prolang WHERE n.nspname='public' ORDER BY p.proname,identity_arguments")->fetchAll();
foreach ($functions as &$function) {
    $function['source_sha256'] = hash('sha256', (string) $function['function_source']);
    unset($function['function_source']);
}
unset($function);
$sequences = $pdo->query("SELECT c.relname AS sequence_name FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relkind='S' ORDER BY c.relname")->fetchAll(PDO::FETCH_COLUMN);
$views = $pdo->query("SELECT c.relname AS view_name,c.relkind AS kind FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relkind IN ('v','m') ORDER BY c.relname")->fetchAll();
$enums = $pdo->query("SELECT t.typname AS enum_name,e.enumlabel AS label,e.enumsortorder AS sort_order FROM pg_type t JOIN pg_enum e ON e.enumtypid=t.oid JOIN pg_namespace n ON n.oid=t.typnamespace WHERE n.nspname='public' ORDER BY t.typname,e.enumsortorder")->fetchAll();
$schemaInventory = ['columns' => $columns, 'constraints' => $constraints, 'indexes' => $indexes, 'triggers' => $triggers,
    'functions' => $functions, 'sequences' => $sequences, 'views' => $views, 'enums' => $enums];

$pathTables = $pdo->query("SELECT table_name, bool_or(column_name='sha256') AS has_sha256 FROM information_schema.columns WHERE table_schema='public' GROUP BY table_name HAVING bool_or(column_name='path') ORDER BY table_name")->fetchAll();
$fileManifest = [];
$missingFiles = [];
$unsafePaths = [];
foreach ($pathTables as $tableInfo) {
    $table = $tableInfo['table_name'];
    $hasHash = filter_var($tableInfo['has_sha256'], FILTER_VALIDATE_BOOLEAN);
    $select = 'SELECT '.$quote('path').($hasHash ? ', '.$quote('sha256') : '').' FROM public.'.$quote($table);
    foreach ($pdo->query($select) as $row) {
        $relative = str_replace('\\', '/', (string) $row['path']);
        if ($relative === '' || str_starts_with($relative, '/') || preg_match('/^[A-Za-z]:\//', $relative) || in_array('..', explode('/', $relative), true)) {
            $unsafePaths[] = ['table' => $table, 'path' => $relative];

            continue;
        }
        $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $resolved = realpath($path);
        if ($resolved === false || ! is_file($resolved) || ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR)) {
            $missingFiles[] = ['table' => $table, 'path' => $relative];

            continue;
        }
        $actualHash = hash_file('sha256', $resolved);
        $metadataHash = $hasHash ? (string) ($row['sha256'] ?? '') : null;
        $fileManifest[] = ['table' => $table, 'path' => $relative, 'bytes' => filesize($resolved),
            'sha256' => $actualHash, 'metadata_sha256' => $metadataHash];
        if ($metadataHash !== null && $metadataHash !== '' && ! hash_equals($metadataHash, $actualHash)) {
            $missingFiles[] = ['table' => $table, 'path' => $relative, 'reason' => 'content hash mismatch'];
        }
    }
}
usort($fileManifest, static fn (array $a, array $b): int => [$a['table'], $a['path']] <=> [$b['table'], $b['path']]);

$officialDocs = [];
$officialIntegrity = true;
foreach ($pdo->query('SELECT id,document_type,source_id,revision,official_number,sha256,pdf_base64 FROM system_official_documents ORDER BY id') as $row) {
    $bytes = base64_decode((string) $row['pdf_base64'], true);
    $actualHash = is_string($bytes) ? hash('sha256', $bytes) : null;
    $valid = is_string($bytes) && hash_equals((string) $row['sha256'], $actualHash);
    $officialIntegrity = $officialIntegrity && $valid;
    $officialDocs[] = ['id' => $row['id'], 'document_type' => $row['document_type'], 'source_id' => $row['source_id'],
        'revision' => (int) $row['revision'], 'official_number' => $row['official_number'], 'sha256' => $row['sha256'],
        'bytes' => is_string($bytes) ? strlen($bytes) : 0, 'integrity' => $valid];
}

$result = ['database' => $identity, 'table_count' => count($tables), 'table_counts' => $counts,
    'schema_inventory' => $schemaInventory,
    'private_file_count' => count($fileManifest), 'private_files' => $fileManifest, 'missing_private_files' => $missingFiles,
    'unsafe_private_paths' => $unsafePaths, 'official_document_count' => count($officialDocs),
    'official_document_integrity' => $officialIntegrity, 'official_documents' => $officialDocs];
file_put_contents($output, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
echo json_encode(['database' => $identity, 'table_count' => count($tables), 'private_file_count' => count($fileManifest),
    'missing_private_files' => count($missingFiles), 'unsafe_private_paths' => count($unsafePaths),
    'official_document_count' => count($officialDocs), 'official_document_integrity' => $officialIntegrity,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
if ($missingFiles !== [] || $unsafePaths !== [] || ! $officialIntegrity) {
    exit(1);
}
