<?php

declare(strict_types=1);

/**
 * Read-only comparison of the current Laravel HTML page routes with the saved
 * authenticated browser sweep. Writes its report only below ignored .tmp/.
 */
$root = dirname(__DIR__, 2);
$sweepPath = $root.'/.tmp/forms-ux-20261102/private/route-sweep.json';
$outputDir = $root.'/.tmp/forms-ux-20261102/private';

if (! is_file($sweepPath)) {
    fwrite(STDERR, "Missing saved route sweep: {$sweepPath}\n");
    exit(2);
}

$process = proc_open(
    [PHP_BINARY, 'artisan', 'route:list', '--json'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $root,
);

if (! is_resource($process)) {
    fwrite(STDERR, "Unable to run artisan route:list --json\n");
    exit(2);
}

$routeJson = stream_get_contents($pipes[1]);
$routeError = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);

if ($exitCode !== 0) {
    fwrite(STDERR, "route:list failed: ".trim($routeError)."\n");
    exit(2);
}

$routes = json_decode($routeJson, true, flags: JSON_THROW_ON_ERROR);
$sweep = json_decode(file_get_contents($sweepPath), true, flags: JSON_THROW_ON_ERROR);
$visited = [];
foreach ($sweep['visited'] ?? [] as $entry) {
    if (($entry['status'] ?? null) === 200) {
        $visited[rtrim('/'.ltrim((string) $entry['route'], '/'), '/') ?: '/'] = true;
    }
}

$included = [];
$excluded = [];
foreach ($routes as $route) {
    $method = (string) ($route['method'] ?? '');
    if (! str_contains($method, 'GET')) {
        continue;
    }

    $uri = trim((string) ($route['uri'] ?? ''), '/');
    $path = $uri === '' ? '/' : '/'.$uri;
    $name = (string) ($route['name'] ?? '');
    $action = (string) ($route['action'] ?? '');
    $reason = null;

    if (str_contains($action, 'RedirectController')) {
        $reason = 'compatibility_or_home_redirect';
    } elseif (preg_match('/(^|\.)(login|logout|register|password\.|verification\.|two-factor\.|passkey\.)/', $name)) {
        $reason = 'authentication_lifecycle_endpoint';
    } elseif (preg_match('/(ExportController|PdfController|DownloadController|DocumentController|StatementDownloadController|OfferFileController|ProductMediaController|CompanyLogoController)/', $action)
        || preg_match('/(^|\.)(export|exports|pdf|file|documents)(\.|$)/', $name)
        || preg_match('/(official-pdf|acceptance-evidence|\/logo$|\/media\/|offer-files\/)/', $uri)
        || str_contains($uri, '/pdf') || str_contains($uri, '/export') || str_contains($uri, '/exports')) {
        $reason = 'download_export_or_generated_document';
    } elseif ((str_ends_with($action, 'Controller') || str_contains($action, 'Controller@'))
        && ! str_contains($action, 'LivewirePageController')) {
        $reason = 'non_html_asset_or_action_endpoint';
    } elseif (! str_contains($action, 'App\\Modules\\')
        && ! str_contains($action, 'App\\Livewire\\')
        && ! str_contains($action, 'LivewirePageController')) {
        $reason = 'framework_or_non_owner_endpoint';
    }

    $entry = [
        'path' => $path,
        'name' => $name,
        'action' => $action,
        'middleware' => $route['middleware'] ?? [],
        'visited_200' => isset($visited[$path]),
    ];
    if ($reason !== null) {
        $entry['reason'] = $reason;
        $excluded[] = $entry;
    } else {
        $included[] = $entry;
    }
}

usort($included, fn (array $a, array $b): int => [$a['path'], $a['name']] <=> [$b['path'], $b['name']]);
usort($excluded, fn (array $a, array $b): int => [$a['reason'], $a['path']] <=> [$b['reason'], $b['path']]);
$gaps = array_values(array_filter($included, fn (array $route): bool => ! $route['visited_200']));

$groups = [];
foreach ($gaps as $route) {
    $module = match (true) {
        str_starts_with($route['path'], '/organization/') => 'Organization',
        str_starts_with($route['path'], '/projects') || str_starts_with($route['path'], '/tenders') => 'Projects',
        str_starts_with($route['path'], '/quotations') || str_starts_with($route['path'], '/sales-orders') || str_starts_with($route['path'], '/sales-returns') => 'Sales',
        str_starts_with($route['path'], '/workshops') => 'WorkshopRepairs',
        str_starts_with($route['path'], '/maintenance') => 'FieldMaintenance',
        str_starts_with($route['path'], '/service-contracts') => 'ServiceContracts',
        str_starts_with($route['path'], '/purchasing') => 'Purchasing',
        str_starts_with($route['path'], '/inventory') => 'Inventory',
        str_starts_with($route['path'], '/financial') || str_starts_with($route['path'], '/accounting') => 'Finance',
        str_starts_with($route['path'], '/treasury') => 'Treasury',
        str_starts_with($route['path'], '/customers') || str_starts_with($route['path'], '/suppliers') || str_starts_with($route['path'], '/products') => 'Catalogs',
        str_starts_with($route['path'], '/attendance') => 'Attendance',
        str_starts_with($route['path'], '/data-operations') || str_starts_with($route['path'], '/employees') => 'System',
        str_starts_with($route['path'], '/settings') || str_starts_with($route['path'], '/user/') => 'Account settings',
        default => 'Other',
    };
    $groups[$module][] = ['path' => $route['path'], 'name' => $route['name'], 'action' => $route['action']];
}
ksort($groups);

$routeRoles = [];
foreach ($gaps as $route) {
    $shape = preg_replace('/\{[^}]+\}/', '{record}', $route['path']);
    $routeRoles[] = [
        'path' => $route['path'],
        'name' => $route['name'],
        'route_shape' => $shape,
        'fixture_status' => str_contains($route['path'], '{')
            ? 'blocked_until_existing_scoped_record_is_confirmed'
            : 'no_record_fixture_required_for_initial_GET',
        'fixture_note' => str_contains($route['path'], '{')
            ? 'No database access was used; browser owner must provide an existing record ID visible to the authenticated actor or report blocked if no valid producer fixture exists.'
            : 'The route itself is fixture-independent at GET time; page-specific lookups may still render empty states or block controls based on current records/configuration.',
    ];
}

$report = [
    'generated_at' => gmdate('c'),
    'scope' => 'Source route inventory compared to saved read-only browser sweep; this does not claim that unvisited routes are broken.',
    'sweep_asset_fingerprint' => $sweep['asset_fingerprint'] ?? null,
    'counts' => [
        'artisan_routes_total' => count($routes),
        'saved_paths' => count($sweep['routes'] ?? []),
        'saved_successful_visits' => count($visited),
        'eligible_owner_html_routes' => count($included),
        'eligible_routes_covered' => count(array_filter($included, fn (array $r): bool => $r['visited_200'])),
        'eligible_route_gaps' => count($gaps),
        'excluded_routes_with_reasons' => count($excluded),
    ],
    'coverage' => $included,
    'gap_groups' => $groups,
    'gap_route_fixture_classification' => $routeRoles,
    'additional_in_page_variants_not_counted_as_routes' => [
        [
            'route_names' => ['customers.show', 'suppliers.show'],
            'variant' => 'financial-tab query state',
            'evidence' => 'tests/E2E/support/page-speed-financial-tabs.cjs defines a separate financial-tab browser case for both detail pages.',
            'coverage' => 'Not represented in the saved URL-only route sweep; verify the tab after a valid scoped detail page is available.',
        ],
        [
            'route_names' => ['all visited Livewire page routes'],
            'variant' => 'modal, validation, save-draft, approval, denial, export, and tab interactions',
            'evidence' => 'tests/E2E/support/page-speed-after.cjs lists interaction categories separately from GET route cases.',
            'coverage' => 'Not established by route-level HTTP 200 screenshots; needs interaction-level checks against safe fixtures.',
        ],
    ],
    'excluded' => $excluded,
    'fixture_limit' => 'Dynamic detail/edit routes require a valid record visible to the read-only sweep actor. This script does not query the database, so fixture availability is unverified; route gaps are classified as needing an existing scoped record or an owner-safe producer fixture.',
];

if (! is_dir($outputDir)) {
    mkdir($outputDir, 0770, true);
}
file_put_contents($outputDir.'/route-coverage.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

$lines = [
    '# Forms UX route coverage gaps',
    '',
    'Source inventory versus the saved authenticated GET sweep. This is a gap report, not a pass matrix.',
    '',
    '| Measure | Count |',
    '| --- | ---: |',
];
foreach ($report['counts'] as $label => $count) {
    $lines[] = '| '.str_replace('_', ' ', $label).' | '.$count.' |';
}
$lines[] = '';
$lines[] = '## Unvisited owner page routes';
$lines[] = '';
$lines[] = 'Every route below needs a browser visit. A static/create route needs no record fixture for its initial GET (but may render an empty or configuration-blocked state). A dynamic record route is blocked until BrowserLuna confirms an existing record visible to the authenticated actor; if none is available, report it blocked pending its valid owner producer fixture. The audit did not query the database.';
$lines[] = '';
foreach ($groups as $module => $entries) {
    $lines[] = '### '.$module;
    $lines[] = '';
    foreach ($entries as $entry) {
        $lines[] = '- `'.$entry['path'].'` (`'.$entry['name'].'`) — '.$entry['action'];
    }
    $lines[] = '';
}
$lines[] = '## Explicit exclusions';
$lines[] = '';
$reasonCounts = [];
foreach ($excluded as $entry) {
    $reasonCounts[$entry['reason']] = ($reasonCounts[$entry['reason']] ?? 0) + 1;
}
ksort($reasonCounts);
foreach ($reasonCounts as $reason => $count) {
    $lines[] = '- `'.$reason.'`: '.$count.' routes.';
}
$lines[] = '';
$lines[] = 'These routes are excluded because they are redirects, authentication lifecycle endpoints, file/media/logo assets, generated documents/downloads/exports, or framework/non-owner endpoints rather than HTML form pages. Exact routes and reason codes are in `route-coverage.json`.';
$lines[] = '';
$lines[] = '## In-page variants outside URL coverage';
$lines[] = '';
$lines[] = '- Customer and supplier financial tabs are separate variants in `tests/E2E/support/page-speed-financial-tabs.cjs`; the saved sweep only counts their base URL (and neither detail page was visited).';
$lines[] = '- Existing performance interaction categories include tab, modal, validation, save-draft, approval, denial, and export. Route screenshots do not prove these states.';
$lines[] = '';
$lines[] = '## Coverage limits';
$lines[] = '';
$lines[] = '- This compares route URLs, not every Livewire tab, modal, or query-driven state within an already visited URL.';
$lines[] = '- A 200 GET proves only that the route rendered at the recorded desktop/mobile viewports; it does not cover form submission, server errors, or every valid business state.';
$lines[] = '- No database query, mutation, seeding, or browser action was performed by this audit.';
file_put_contents($outputDir.'/route-coverage.md', implode("\n", $lines)."\n");

printf(
    "Wrote %s and %s\nRoutes: %d owner HTML page routes, %d covered, %d gaps, %d explicitly excluded.\n",
    $outputDir.'/route-coverage.json',
    $outputDir.'/route-coverage.md',
    count($included),
    $report['counts']['eligible_routes_covered'],
    count($gaps),
    count($excluded),
);
