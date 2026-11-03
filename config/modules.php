<?php

// Compatibility identities, keyed by owner rather than an ambiguous historical ID.
// This is not an activation registry: release gates and owner authorization still apply.
return [
    'Attendance' => ['id' => 'M15', 'permission_prefix' => 'M15', 'implemented' => true],
    'ServiceContracts' => ['id' => 'M10', 'permission_prefix' => 'M10', 'implemented' => true],
    'WorkshopRepairs' => ['id' => 'M09', 'permission_prefix' => 'M09', 'implemented' => true],
    'FieldMaintenance' => [
        'id' => 'M08', 'permission_prefix' => 'M08', 'implemented' => true,
    ],
    'Projects' => [
        'id' => 'M07',
        'permission_prefix' => 'M07',
        'implemented' => true,
        'capabilities' => ['M07.Tenders' => ['implemented' => true, 'permission_prefix' => 'M07.tender_']],
    ],
    'DeliveryReturns' => [
        'id' => null,
        'historical_id' => 'M07',
        'permission_prefix' => null,
        'implemented' => false,
    ],
];
