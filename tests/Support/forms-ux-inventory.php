<?php

declare(strict_types=1);

// Read-only source inventory. It records template paths and markup signals, never field values.
$root = dirname(__DIR__, 2);
$views = $root.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'views';
$routesPath = $root.DIRECTORY_SEPARATOR.'routes'.DIRECTORY_SEPARATOR.'web.php';
$validationPath = $root.DIRECTORY_SEPARATOR.'lang'.DIRECTORY_SEPARATOR.'ar'.DIRECTORY_SEPARATOR.'validation.php';
$output = $root.DIRECTORY_SEPARATOR.'.tmp'.DIRECTORY_SEPARATOR.'forms-ux-20261102';

$ownerByView = [
    'accounting' => 'Accounting', 'attendance' => 'Attendance', 'data-operations' => 'System',
    'financial' => 'ReceivablesPayables', 'inventory' => 'Inventory', 'maintenance' => 'FieldMaintenance',
    'organization' => 'Organization', 'parties' => 'Parties', 'products' => 'Products', 'projects' => 'Projects',
    'purchasing' => 'Purchasing', 'receivables-payables' => 'ReceivablesPayables', 'sales' => 'Sales',
    'service-contracts' => 'ServiceContracts', 'tenders' => 'Projects', 'treasury' => 'Treasury', 'workshops' => 'WorkshopRepairs',
];
$moduleStatus = [
    'Organization' => ['requirement_id' => 'M01', 'source_status' => 'implemented'],
    'Products' => ['requirement_id' => 'M02', 'source_status' => 'implemented'],
    'Parties' => ['requirement_id' => 'M03', 'source_status' => 'implemented'],
    'Sales' => ['requirement_id' => 'M04', 'source_status' => 'implemented'],
    'Purchasing' => ['requirement_id' => 'M05', 'source_status' => 'implemented'],
    'Inventory' => ['requirement_id' => 'M06', 'source_status' => 'implemented'],
    'Projects' => ['requirement_id' => 'M07', 'source_status' => 'implemented under current owner map'],
    'DeliveryReturns' => ['requirement_id' => 'historical M07', 'source_status' => 'explicitly unimplemented; no runtime route owner'],
    'FieldMaintenance' => ['requirement_id' => 'M08', 'source_status' => 'implemented'],
    'WorkshopRepairs' => ['requirement_id' => 'M09', 'source_status' => 'implemented'],
    'ServiceContracts' => ['requirement_id' => 'M10', 'source_status' => 'implemented'],
    'Accounting' => ['requirement_id' => 'M11', 'source_status' => 'implemented'],
    'ReceivablesPayables' => ['requirement_id' => 'M12', 'source_status' => 'implemented'],
    'Treasury' => ['requirement_id' => 'M13', 'source_status' => 'implemented'],
    'Payroll' => ['requirement_id' => 'M14', 'source_status' => 'deferred / no current view folder'],
    'Attendance' => ['requirement_id' => 'M15', 'source_status' => 'implemented'],
    'Reporting' => ['requirement_id' => 'M16', 'source_status' => 'deferred / no current view folder'],
    'System' => ['requirement_id' => 'M17', 'source_status' => 'implemented'],
];

if (! is_dir($views)) {
    fwrite(STDERR, "resources/views was not found.\n");
    exit(1);
}

$signals = [
    'forms' => '/<form\b/i',
    'wire_submit' => '/\bwire:submit(?:\.[\w-]+)?\s*=/i',
    'wire_model' => '/\bwire:model(?:\.[\w-]+)*\s*=/i',
    'flux_input' => '/<flux:input\b/i',
    'flux_select' => '/<flux:select\b/i',
    'flux_textarea' => '/<flux:textarea\b/i',
    'flux_checkbox' => '/<flux:checkbox\b/i',
    'native_input' => '/<input\b/i',
    'native_select' => '/<select\b/i',
    'native_textarea' => '/<textarea\b/i',
    'validation_feedback' => '/@error\b|<flux:error\b|\$errors->(?:any|all|first|has|hasAny)\s*\(/i',
    'alert_role' => '/\brole\s*=\s*["\']alert["\']/i',
    'invalid_state' => '/\baria-invalid\b/i',
    'describedby' => '/\baria-describedby\b/i',
    'dirty_state' => '/\bwire:dirty\b/i',
    'loading_disabled' => '/\bwire:loading\.attr\s*=\s*["\']disabled["\']/i',
    'loading_status' => '/\bwire:loading\b[^>]*\brole\s*=\s*["\']status["\']/i',
    'focus_invalid_hook' => '/invalid\.window|aria-invalid\s*=\s*["\']true["\']/i',
];

$files = [];
$totals = array_fill_keys(array_keys($signals), 0);
$bladeFileCount = 0;
$summaryViews = [];
$orphanErrorViews = [];
$moduleCounts = [];
$routeCounts = [];
$unlabelledControls = [];
$modelNames = [];
$ruleTokens = [];

$containsSummary = static function (string $source, array $seen = []) use (&$containsSummary, $views): bool {
    if (preg_match('/<x-ui\.validation-summary\b/', $source)) {
        return true;
    }
    $referenced = [];
    if (preg_match_all('/@include(?:If|When|Unless)?\s*\(\s*["\']([^"\']+)["\']/', $source, $includes)) {
        foreach ($includes[1] as $viewName) {
            $referenced[] = [$views.DIRECTORY_SEPARATOR.str_replace('.', DIRECTORY_SEPARATOR, $viewName).'.blade.php'];
        }
    }
    if (preg_match_all('/<x-([a-z][a-z0-9_.-]*)\b/i', $source, $components)) {
        foreach ($components[1] as $componentName) {
            $referenced[] = [$views.DIRECTORY_SEPARATOR.'components'.DIRECTORY_SEPARATOR.str_replace('.', DIRECTORY_SEPARATOR, $componentName).'.blade.php'];
        }
    }
    foreach ($referenced as [$candidate]) {
        if (isset($seen[$candidate]) || ! is_file($candidate)) {
            continue;
        }
        $seen[$candidate] = true;
        $included = file_get_contents($candidate);
        if (is_string($included) && $containsSummary($included, $seen)) {
            return true;
        }
    }

    return false;
};
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($views, FilesystemIterator::SKIP_DOTS));

foreach ($iterator as $file) {
    if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
        continue;
    }
    $bladeFileCount++;

    $source = file_get_contents($file->getPathname());
    if (! is_string($source)) {
        continue;
    }

    $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
    $viewArea = explode('/', substr($relative, strlen('resources/views/')))[0] ?? 'shared';
    $owner = $ownerByView[$viewArea] ?? ($viewArea === 'pages' ? 'Authentication/Settings' : (in_array($viewArea, ['components', 'layouts', 'flux'], true) ? 'Shared UI' : 'Unmapped'));
    $counts = [];
    foreach ($signals as $name => $pattern) {
        $counts[$name] = preg_match_all($pattern, $source) ?: 0;
        $totals[$name] += $counts[$name];
    }

    $formIndex = 0;
    $hasSummary = $containsSummary($source);
    $hasErrorRendering = (bool) preg_match('/@error\b|<flux:error\b|\$errors->(?:any|all|first|has|hasAny)\s*\(/i', $source);
    if ($hasSummary) {
        $summaryViews[] = $relative;
    }
    if ($hasErrorRendering && ! $hasSummary && $relative !== 'resources/views/components/ui/validation-summary.blade.php') {
        $orphanErrorViews[] = ['view' => $relative, 'owner' => $owner];
    }
    $moduleCounts[$owner] ??= ['views' => 0, 'forms' => 0, 'selects' => 0, 'validation_summary_views' => 0];
    $moduleCounts[$owner]['views']++;
    $moduleCounts[$owner]['forms'] += $counts['forms'];
    $moduleCounts[$owner]['selects'] += $counts['flux_select'] + $counts['native_select'];
    $moduleCounts[$owner]['validation_summary_views'] += $hasSummary ? 1 : 0;

    if (preg_match_all('/<flux:(input|select|textarea|checkbox|switch)\b((?:"[^"]*"|\'[^\']*\'|[^\'">])*)>/is', $source, $controlTags, PREG_SET_ORDER)) {
        foreach ($controlTags as $controlTag) {
            if (! preg_match('/\bwire:model(?:\.[\w-]+)*\s*=\s*["\']([^"\']+)["\']/i', $controlTag[2], $binding)) {
                continue;
            }
            $modelNames[$binding[1]] = true;
            if (! preg_match('/(?:^|\s):?(?:label|aria-label)\s*=/i', $controlTag[2])) {
                $unlabelledControls[] = ['view' => $relative, 'owner' => $owner, 'control' => $controlTag[1], 'binding' => $binding[1]];
            }
        }
    }
    if (preg_match_all('/<form\b[^>]*>.*?<\/form\s*>/is', $source, $forms)) {
        foreach ($forms[0] as $form) {
            $formIndex++;
            $hasSubmit = (bool) preg_match('/\bwire:submit(?:\.[\w-]+)?\s*=/i', $form);
            if (! $hasSubmit) {
                continue;
            }

            $hasLoadingDisable = (bool) preg_match('/\bwire:loading\.attr\s*=\s*["\']disabled["\']/i', $form);
            $hasLoadingTarget = (bool) preg_match('/\bwire:target\s*=/i', $form);
            $hasFieldFeedback = (bool) preg_match('/@error\b|<flux:error\b|\baria-describedby\b/i', $form);
            $hasAlert = (bool) preg_match('/\brole\s*=\s*["\']alert["\']|\$errors->(?:any|all|first)\s*\(/i', $form);
            $hasDirty = (bool) preg_match('/\bwire:dirty\b/i', $form);
            $hasInvalidFocus = (bool) preg_match('/invalid\.window|aria-invalid\s*=\s*["\']true["\']/i', $form);
            $files[$relative]['forms'][] = [
                'index' => $formIndex,
                'has_submit' => $hasSubmit,
                'has_loading_disable' => $hasLoadingDisable,
                'has_loading_target' => $hasLoadingTarget,
                'has_validation_hook' => $hasFieldFeedback || $hasAlert,
                'has_field_error_association' => $hasFieldFeedback,
                'has_dirty_notice' => $hasDirty,
                'has_invalid_focus_hook' => $hasInvalidFocus,
            ];
        }
    }

    if (array_sum($counts) > 0) {
        $files[$relative]['signals'] = $counts;
        $files[$relative]['owner'] = $owner;
        $files[$relative]['validation_summary_in_view'] = $hasSummary;
        $files[$relative]['renders_validation_errors'] = $hasErrorRendering;
    }
}

if (is_dir($root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Modules')) {
    $presentations = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Modules', FilesystemIterator::SKIP_DOTS));
    foreach ($presentations as $sourceFile) {
        if (! $sourceFile->isFile() || $sourceFile->getExtension() !== 'php' || ! str_contains($sourceFile->getPathname(), DIRECTORY_SEPARATOR.'Presentation'.DIRECTORY_SEPARATOR)) {
            continue;
        }
        $source = file_get_contents($sourceFile->getPathname());
        if (! is_string($source)) {
            continue;
        }
        if (preg_match_all("/'([^']+)'/", $source, $quoted)) {
            foreach ($quoted[1] as $candidate) {
                foreach (preg_split('/\|/', $candidate) ?: [] as $rule) {
                    $rule = strtolower((string) preg_replace('/:.*/', '', trim($rule)));
                    if (preg_match('/^[a-z_]+$/', $rule)) {
                        $ruleTokens[$rule] = true;
                    }
                }
            }
        }
    }
}

$arabicValidation = is_file($validationPath) ? include $validationPath : [];
$arabicValidation = is_array($arabicValidation) ? $arabicValidation : [];
$attributes = is_array($arabicValidation['attributes'] ?? null) ? $arabicValidation['attributes'] : [];
$missingAttributeNames = array_values(array_diff(array_keys($modelNames), array_keys($attributes)));
sort($missingAttributeNames);
$englishValidationPath = $root.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'laravel'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Illuminate'.DIRECTORY_SEPARATOR.'Translation'.DIRECTORY_SEPARATOR.'lang'.DIRECTORY_SEPARATOR.'en'.DIRECTORY_SEPARATOR.'validation.php';
$englishValidation = is_file($englishValidationPath) ? include $englishValidationPath : [];
$englishValidation = is_array($englishValidation) ? $englishValidation : [];
$presentationRules = array_values(array_intersect(array_keys($englishValidation), array_keys($ruleTokens)));
sort($presentationRules);
$missingArabicRules = array_values(array_diff($presentationRules, array_keys($arabicValidation)));
sort($missingArabicRules);
$missingNumericSizeMessages = [];
foreach (['max', 'min', 'size', 'between', 'gt', 'gte', 'lt', 'lte'] as $rule) {
    if (in_array($rule, $presentationRules, true) && is_array($arabicValidation[$rule] ?? null) && ! array_key_exists('numeric', $arabicValidation[$rule])) {
        $missingNumericSizeMessages[] = $rule.'.numeric';
    }
}

if (is_file($routesPath)) {
    $routeSource = file_get_contents($routesPath);
    if (is_string($routeSource) && preg_match_all('/Route::(?:get|post|put|patch|delete|redirect)\s*\(\s*["\']([^"\']+)["\']/', $routeSource, $routeMatches)) {
        foreach ($routeMatches[1] as $uri) {
            $owner = match (true) {
                str_starts_with($uri, 'organization/') => 'Organization',
                str_starts_with($uri, 'products') => 'Products',
                str_starts_with($uri, 'customers'), str_starts_with($uri, 'suppliers') => 'Parties',
                str_starts_with($uri, 'sales-orders'), str_starts_with($uri, 'quotations'), str_starts_with($uri, 'sales-returns') => 'Sales',
                str_starts_with($uri, 'purchasing') => 'Purchasing',
                str_starts_with($uri, 'inventory') => 'Inventory',
                str_starts_with($uri, 'maintenance') => 'FieldMaintenance',
                str_starts_with($uri, 'workshops') => 'WorkshopRepairs',
                str_starts_with($uri, 'service-contract') => 'ServiceContracts',
                str_starts_with($uri, 'accounting') => 'Accounting',
                str_starts_with($uri, 'financial') => 'ReceivablesPayables',
                str_starts_with($uri, 'treasury') => 'Treasury',
                str_starts_with($uri, 'attendance') => 'Attendance',
                str_starts_with($uri, 'projects'), str_starts_with($uri, 'tenders') => 'Projects',
                str_starts_with($uri, 'data-operations') => 'System',
                default => 'Shared UI',
            };
            $routeCounts[$owner] = ($routeCounts[$owner] ?? 0) + 1;
        }
    }
}

ksort($files);
if (! is_dir($output) && ! mkdir($output, 0777, true) && ! is_dir($output)) {
    fwrite(STDERR, "Could not create ignored report directory.\n");
    exit(1);
}

$report = [
    'generated_at_utc' => gmdate(DATE_ATOM),
    'scope' => 'resources/views/**/*.blade.php',
    'method' => 'Read-only source scan; Flux runtime behavior and route authorization are not inferred.',
    'runtime_claim' => false,
    'flux_note' => 'Flux may provide labels, aria-invalid, field errors, and submit loading behavior at render time; explicit-source counts do not imply runtime absence.',
    'blade_file_count' => $bladeFileCount,
    'submitted_forms_with_field_error_association' => count(array_filter(
        array_merge(...array_values(array_map(static fn (array $row): array => $row['forms'] ?? [], $files ?: [[]]))),
        static fn (array $form): bool => $form['has_field_error_association'],
    )),
    'totals' => $totals,
    'owner_coverage' => array_map(static fn (string $owner, array $status): array => $status + ($moduleCounts[$owner] ?? ['views' => 0, 'forms' => 0, 'selects' => 0, 'validation_summary_views' => 0]) + ['literal_route_definitions' => $routeCounts[$owner] ?? 0], array_keys($moduleStatus), array_values($moduleStatus)),
    'literal_route_definitions_by_owner' => $routeCounts,
    'validation_summary_views' => $summaryViews,
    'error_rendering_views_without_summary_resolution' => $orphanErrorViews,
    'flux_bound_controls_without_explicit_label_attribute' => $unlabelledControls,
    'flux_bound_control_bindings_missing_arabic_attribute_alias' => $missingAttributeNames,
    'presentation_validation_rule_key_candidates' => $presentationRules,
    'presentation_rule_keys_missing_arabic_translation' => $missingArabicRules,
    'numeric_size_messages_missing_for_candidate_rules' => $missingNumericSizeMessages,
    'files' => $files,
];
file_put_contents($output.DIRECTORY_SEPARATOR.'inventory.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

$summary = [
    '# Forms UX source inventory',
    '',
    'Generated: '.$report['generated_at_utc'],
    'Scope: `resources/views/**/*.blade.php` (read-only source scan).',
    'Source-only evidence: no rendered browser state, authorization decision, database access, or field values are collected.',
    'Flux may provide labels, error state, field messages, and loading behavior at render time. Counts below describe explicit source markup only.',
    '',
    '## Module and page-source coverage',
    '',
    '| Owner | Requirement | Source status | Views | Forms | Selects | Summary views | Literal route definitions |',
    '| --- | --- | --- | ---: | ---: | ---: | ---: | ---: |',
];
foreach ($moduleStatus as $owner => $status) {
    $counts = $moduleCounts[$owner] ?? ['views' => 0, 'forms' => 0, 'selects' => 0, 'validation_summary_views' => 0];
    $summary[] = '| '.$owner.' | '.$status['requirement_id'].' | '.$status['source_status'].' | '.$counts['views'].' | '.$counts['forms'].' | '.$counts['selects'].' | '.$counts['validation_summary_views'].' | '.($routeCounts[$owner] ?? 0).' |';
}
$summary[] = '';
$summary[] = 'M07 currently belongs to Projects. DeliveryReturns is explicitly unimplemented; Payroll (M14) and Reporting (M16) have no current view folder. Route counts scan literal URIs and can miss URIs built from variables.';
$summary[] = '';
$summary[] = '## Validation coverage';
$summary[] = '';
$summary[] = '- Error-rendering views without a summary resolved directly or through a literal `@include`: '.count($orphanErrorViews).'; inspect the detailed list in `inventory.json`.';
$summary[] = '- Bound Flux controls without an explicit label/aria-label attribute: '.count($unlabelledControls).'; Flux-generated labels and wrapper context are not assessed.';
$summary[] = '- Distinct bound field names without a `lang/ar/validation.php` attribute alias: '.count($missingAttributeNames).'; direct Flux labels may still provide the visible label.';
$summary[] = '- Candidate built-in validation keys used in Presentation source but missing Arabic messages: '.count($missingArabicRules).'.';
$summary[] = '- Candidate contextual numeric size messages missing: '.count($missingNumericSizeMessages).'.';
$summary[] = '- Remaining error-rendering views outside summary resolution: '.implode(', ', array_map(static fn (array $item): string => '`'.$item['view'].'`', $orphanErrorViews)).'.';
$summary[] = '- Bound controls without an explicit source label: '.implode(', ', array_map(static fn (array $item): string => '`'.$item['view'].'` / `'.$item['binding'].'`', $unlabelledControls)).'.';
$summary[] = '';
$summary[] = '## Explicit source signals';
$summary[] = '';
$summary[] = '| Signal | Count |';
$summary[] = '| --- | ---: |';
foreach ($totals as $name => $count) {
    $summary[] = '| '.$name.' | '.$count.' |';
}
$summary[] = '';
$summary[] = 'Per-view controls, form signals, unresolved error-summary views, label candidates, and validation keys are in `inventory.json`.';
file_put_contents($output.DIRECTORY_SEPARATOR.'summary.md', implode(PHP_EOL, $summary).PHP_EOL);
fwrite(STDOUT, 'Wrote read-only form inventory: '.str_replace(DIRECTORY_SEPARATOR, '/', substr($output, strlen($root) + 1)).PHP_EOL);
