<?php

namespace App\Http\Livewire\Project\Service;

use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use Illuminate\Support\Collection;
use Livewire\Component;

class Show extends Component
{
    public Service $service;
    public ServiceApplication $serviceApplication;
    public ServiceDatabase $serviceDatabase;
    public array $parameters;
    public array $query;
    public Collection $services;
    protected $listeners = ['generateDockerCompose'];

    public function mount()
    {
        $this->services = collect([]);
        $this->parameters = get_route_parameters();
        $this->query = request()->query();
        $this->service = Service::whereUuid($this->parameters['service_uuid'])->firstOrFail();
        $service = $this->service->applications()->whereName($this->parameters['service_name'])->first();
        if ($service) {
            $this->serviceApplication = $service;
        } else {
            $this->serviceDatabase = $this->service->databases()->whereName($this->parameters['service_name'])->first();
        }
    }
    public function generateDockerCompose()
    {
        $this->service->parse();
    }
    public function render()
    {
        return view('livewire.project.service.show');
    }
}
