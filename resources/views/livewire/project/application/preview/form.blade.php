<form wire:submit.prevent='submit'>
    <div class="flex gap-2">
        <h3 class="pt-0">Settings</h3>
        <x-forms.button type="submit">Save</x-forms.button>
        <x-forms.button wire:click="resetToDefault">Reset to default</x-forms.button>
    </div>
    <div class="flex flex-col gap-2 pb-4">
        <x-forms.input id="application.preview_url_template" label="Preview URL Template"
            helper="Templates:<span class='text-helper'>@@{{ random }}</span> to generate random sub-domain each time a PR is deployed, <span class='text-helper'>@@{{ pr_id }}</span> to use pull request ID as sub-domain or <span class='text-helper'>@@{{ domain }}</span> to replace the domain name with the application's domain name." />
        <div class="text-sm">Domain Preview: {{ $preview_url_template }}</div>
    </div>
</form>
