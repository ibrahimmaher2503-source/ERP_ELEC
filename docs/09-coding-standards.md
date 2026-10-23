# Conventions
Follow Laravel and surrounding code. Format PHP/Blade with Pint. Thin Livewire handlers validate input and call owner actions; never trust browser IDs, amounts or scopes. Immutable posted snapshots and exact decimals are mandatory when implementing business operations.
Use named routes, escaped Blade output, labelled Flux inputs, semantic headings, keyboard access, wire:key in loops and request/loading feedback. No generic repositories, DTO frameworks or dependencies for a few lines of code.
Keep secrets in ignored environment files. Use English identifiers and translation keys/Arabic JSON strings for interface copy.
