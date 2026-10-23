<x-layouts::app :title="__('Workspace')">
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6">
        <x-page.heading :title="__('Workspace')" :description="__('Electrical ERP')" />

        <x-ui.panel>
            <x-ui.empty-state
                :title="__('No operational data yet')"
                :description="__('Your operational pages will appear here as their modules are configured.')"
            />
        </x-ui.panel>
    </div>
</x-layouts::app>
