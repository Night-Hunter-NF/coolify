<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Yaml\Yaml;
use Illuminate\Support\Str;
use Spatie\Url\Url;

class Service extends BaseModel
{
    use HasFactory;
    protected $guarded = [];

    protected static function booted()
    {
        static::deleted(function ($service) {
            $storagesToDelete = collect([]);
            foreach ($service->applications()->get() as $application) {
                $storages = $application->persistentStorages()->get();
                foreach ($storages as $storage) {
                    $storagesToDelete->push($storage);
                }
                $application->persistentStorages()->delete();
            }
            foreach ($service->databases()->get() as $database) {
                $storages = $database->persistentStorages()->get();
                foreach ($storages as $storage) {
                    $storagesToDelete->push($storage);
                }
                $database->persistentStorages()->delete();
            }
            $service->environment_variables()->delete();
            $service->applications()->delete();
            $service->databases()->delete();
            if ($storagesToDelete->count() > 0) {
                $storagesToDelete->each(function ($storage) use ($service) {
                    instant_remote_process(["docker volume rm -f $storage->name"], $service->server, false);
                });
            }
            instant_remote_process(["docker network rm {$service->uuid}"], $service->server, false);
        });
    }
    public function type()
    {
        return 'service';
    }

    public function documentation()
    {
        $services = Cache::get('services', []);
        $service = data_get($services, Str::of($this->name)->beforeLast('-')->value, []);
        return data_get($service, 'documentation', config('constants.docs.base_url'));
    }
    public function applications()
    {
        return $this->hasMany(ServiceApplication::class);
    }
    public function databases()
    {
        return $this->hasMany(ServiceDatabase::class);
    }
    public function environment()
    {
        return $this->belongsTo(Environment::class);
    }
    public function server()
    {
        return $this->belongsTo(Server::class);
    }
    public function byName(string $name)
    {
        $app = $this->applications()->whereName($name)->first();
        if ($app) {
            return $app;
        }
        $db = $this->databases()->whereName($name)->first();
        if ($db) {
            return $db;
        }
        return null;
    }
    public function environment_variables(): HasMany
    {
        return $this->hasMany(EnvironmentVariable::class)->orderBy('key', 'asc');
    }
    public function workdir()
    {
        return service_configuration_dir() . "/{$this->uuid}";
    }
    public function saveComposeConfigs()
    {
        $workdir = $this->workdir();
        $commands[] = "mkdir -p $workdir";
        $commands[] = "cd $workdir";

        $docker_compose_base64 = base64_encode($this->docker_compose);
        $commands[] = "echo $docker_compose_base64 | base64 -d > docker-compose.yml";
        $envs = $this->environment_variables()->get();
        $commands[] = "rm -f .env || true";
        foreach ($envs as $env) {
            $commands[] = "echo '{$env->key}={$env->value}' >> .env";
        }
        if ($envs->count() === 0) {
            $commands[] = "touch .env";
        }
        instant_remote_process($commands, $this->server);
    }

    public function parse(bool $isNew = false): Collection
    {
        // ray()->clearAll();
        if ($this->docker_compose_raw) {
            try {
                $yaml = Yaml::parse($this->docker_compose_raw);
            } catch (\Exception $e) {
                throw new \Exception($e->getMessage());
            }

            $topLevelVolumes = collect(data_get($yaml, 'volumes', []));
            $topLevelNetworks = collect(data_get($yaml, 'networks', []));
            $dockerComposeVersion = data_get($yaml, 'version') ?? '3.8';
            $services = data_get($yaml, 'services');
            $definedNetwork = $this->uuid;

            $generatedServiceFQDNS = collect([]);

            $services = collect($services)->map(function ($service, $serviceName) use ($topLevelVolumes, $topLevelNetworks, $definedNetwork, $isNew, $generatedServiceFQDNS) {
                $serviceVolumes = collect(data_get($service, 'volumes', []));
                $servicePorts = collect(data_get($service, 'ports', []));
                $serviceNetworks = collect(data_get($service, 'networks', []));
                $serviceVariables = collect(data_get($service, 'environment', []));
                $serviceLabels = collect(data_get($service, 'labels', []));

                $containerName = "$serviceName-{$this->uuid}";

                // Decide if the service is a database
                $isDatabase = false;
                $image = data_get_str($service, 'image');
                if ($image->contains(':')) {
                    $image = Str::of($image);
                } else {
                    $image = Str::of($image)->append(':latest');
                }
                $imageName = $image->before(':');

                if (collect(DATABASE_DOCKER_IMAGES)->contains($imageName)) {
                    $isDatabase = true;
                }
                data_set($service, 'is_database', $isDatabase);

                // Create new serviceApplication or serviceDatabase
                if ($isDatabase) {
                    if ($isNew) {
                        $savedService = ServiceDatabase::create([
                            'name' => $serviceName,
                            'image' => $image,
                            'service_id' => $this->id
                        ]);
                    } else {
                        $savedService = ServiceDatabase::where([
                            'name' => $serviceName,
                            'service_id' => $this->id
                        ])->first();
                    }
                } else {
                    if ($isNew) {
                        $savedService = ServiceApplication::create([
                            'name' => $serviceName,
                            'image' => $image,
                            'service_id' => $this->id
                        ]);
                    } else {
                        $savedService = ServiceApplication::where([
                            'name' => $serviceName,
                            'service_id' => $this->id
                        ])->first();
                    }
                }
                if (is_null($savedService)) {
                    if ($isDatabase) {
                        $savedService = ServiceDatabase::create([
                            'name' => $serviceName,
                            'image' => $image,
                            'service_id' => $this->id
                        ]);
                    } else {
                        $savedService = ServiceApplication::create([
                            'name' => $serviceName,
                            'image' => $image,
                            'service_id' => $this->id
                        ]);
                    }
                }

                // Collect/create/update networks
                if ($serviceNetworks->count() > 0) {
                    foreach ($serviceNetworks as $networkName => $networkDetails) {
                        $networkExists = $topLevelNetworks->contains(function ($value, $key) use ($networkName) {
                            return $value == $networkName || $key == $networkName;
                        });
                        if (!$networkExists) {
                            $topLevelNetworks->put($networkDetails, null);
                        }
                    }
                }

                // Collect/create/update ports
                $collectedPorts = collect([]);
                if ($servicePorts->count() > 0) {
                    foreach ($servicePorts as $sport) {
                        if (is_string($sport) || is_numeric($sport)) {
                            $collectedPorts->push($sport);
                        }
                        if (is_array($sport)) {
                            $target = data_get($sport, 'target');
                            $published = data_get($sport, 'published');
                            $protocol = data_get($sport, 'protocol');
                            $collectedPorts->push("$target:$published/$protocol");
                        }
                    }
                }
                $savedService->ports = $collectedPorts->implode(',');
                $savedService->save();

                // Add Coolify specific networks
                $definedNetworkExists = $topLevelNetworks->contains(function ($value, $_) use ($definedNetwork) {
                    return $value == $definedNetwork;
                });
                if (!$definedNetworkExists) {
                    $topLevelNetworks->put($definedNetwork, [
                        'name' => $definedNetwork,
                        'external' => true
                    ]);
                }
                $networks = $serviceNetworks->toArray();
                $networks = array_merge($networks, [$definedNetwork]);
                data_set($service, 'networks', $networks);

                // Collect/create/update volumes
                if ($serviceVolumes->count() > 0) {
                    foreach ($serviceVolumes as $volume) {
                        $type = null;
                        $source = null;
                        $target = null;
                        $content = null;
                        $isDirectory = false;
                        if (is_string($volume)) {
                            $source = Str::of($volume)->before(':');
                            $target = Str::of($volume)->after(':')->beforeLast(':');
                            if ($source->startsWith('./') || $source->startsWith('/') || $source->startsWith('~')) {
                                $type = Str::of('bind');
                            } else {
                                $type = Str::of('volume');
                            }
                        } else if (is_array($volume)) {
                            $type = data_get_str($volume, 'type');
                            $source = data_get_str($volume, 'source');
                            $target = data_get_str($volume, 'target');
                            $content = data_get($volume, 'content');
                            $isDirectory = (bool) data_get($volume, 'isDirectory', false);
                            $foundConfig = $savedService->fileStorages()->whereMountPath($target)->first();
                            if ($foundConfig) {
                                $content = data_get($foundConfig, 'content');
                                $isDirectory = (bool) data_get($foundConfig, 'is_directory');
                            }
                        }
                        if ($type->value() === 'bind') {
                            if ($source->value() === "/var/run/docker.sock") {
                                continue;
                            }
                            if ($source->value() === '/tmp' || $source->value() === '/tmp/') {
                                continue;
                            }
                            LocalFileVolume::updateOrCreate(
                                [
                                    'mount_path' => $target,
                                    'resource_id' => $savedService->id,
                                    'resource_type' => get_class($savedService)
                                ],
                                [
                                    'fs_path' => $source,
                                    'mount_path' => $target,
                                    'content' => $content,
                                    'is_directory' => $isDirectory,
                                    'resource_id' => $savedService->id,
                                    'resource_type' => get_class($savedService)
                                ]
                            );
                        } else if ($type->value() === 'volume') {
                            $topLevelVolumes->put($source->value(), null);
                            LocalPersistentVolume::updateOrCreate(
                                [
                                    'mount_path' => $target,
                                    'resource_id' => $savedService->id,
                                    'resource_type' => get_class($savedService)
                                ],
                                [
                                    'name' => Str::slug($source, '-'),
                                    'mount_path' => $target,
                                    'resource_id' => $savedService->id,
                                    'resource_type' => get_class($savedService)
                                ]
                            );
                        }
                        $savedService->saveFileVolumes();
                    }
                }

                // Add env_file with at least .env to the service
                // $envFile = collect(data_get($service, 'env_file', []));
                // if ($envFile->count() > 0) {
                //     if (!$envFile->contains('.env')) {
                //         $envFile->push('.env');
                //     }
                // } else {
                //     $envFile = collect(['.env']);
                // }
                // data_set($service, 'env_file', $envFile->toArray());


                // Get variables from the service
                foreach ($serviceVariables as $variableName => $variable) {
                    if (is_numeric($variableName)) {
                        $variable = Str::of($variable);
                        if ($variable->contains('=')) {
                            // - SESSION_SECRET=123
                            // - SESSION_SECRET=
                            $key = $variable->before('=');
                            $value = $variable->after('=');
                        } else {
                            // - SESSION_SECRET
                            $key = $variable;
                            $value = null;
                        }
                    } else {
                        // SESSION_SECRET: 123
                        // SESSION_SECRET:
                        $key = Str::of($variableName);
                        $value = Str::of($variable);
                    }
                    if ($key->startsWith('SERVICE_FQDN')) {
                        if (is_null(data_get($savedService, 'fqdn'))) {
                            $sslip = sslip($this->server);
                            $fqdn = "http://$containerName.$sslip";
                            if (substr_count($key->value(), '_') === 2 && $key->contains("=")) {
                                $path = $value->value();
                                if ($generatedServiceFQDNS->count() > 0) {
                                    $alreadyGenerated = $generatedServiceFQDNS->has($key->value());
                                    if ($alreadyGenerated) {
                                        $fqdn = $generatedServiceFQDNS->get($key->value());
                                    } else {
                                        $generatedServiceFQDNS->put($key->value(), $fqdn);
                                    }
                                } else {
                                    $generatedServiceFQDNS->put($key->value(), $fqdn);
                                }
                                $fqdn = "http://$containerName.$sslip$path";
                            }
                            if (!$isDatabase) {
                                $savedService->fqdn = $fqdn;
                                $savedService->save();
                            }
                        }
                        continue;
                    }
                    if ($value?->startsWith('$')) {
                        $value = Str::of(replaceVariables($value));
                        $key = $value;
                        $foundEnv = EnvironmentVariable::where([
                            'key' => $key,
                            'service_id' => $this->id,
                        ])->first();
                        if ($value->startsWith('SERVICE_')) {
                            $command = $value->after('SERVICE_')->beforeLast('_');
                            $forService = $value->afterLast('_');
                            $generatedValue = null;
                            if ($command->value() === 'FQDN' || $command->value() === 'URL') {
                                $sslip = sslip($this->server);
                                $fqdn = "http://$containerName.$sslip";
                                if ($foundEnv) {
                                    $fqdn = data_get($foundEnv, 'value');
                                } else {
                                    EnvironmentVariable::create([
                                        'key' => $key,
                                        'value' => $fqdn,
                                        'is_build_time' => false,
                                        'service_id' => $this->id,
                                        'is_preview' => false,
                                    ]);
                                }

                                if (!$isDatabase) {
                                    $savedService->fqdn = $fqdn;
                                    $savedService->save();
                                }
                            } else {
                                switch ($command) {
                                    case 'PASSWORD':
                                        $generatedValue = Str::password(symbols: false);
                                        break;
                                    case 'PASSWORD_64':
                                        $generatedValue = Str::password(length: 64, symbols: false);
                                        break;
                                    case 'BASE64_64':
                                        $generatedValue = Str::random(64);
                                        break;
                                    case 'BASE64_128':
                                        $generatedValue = Str::random(128);
                                        break;
                                    case 'BASE64':
                                        $generatedValue = Str::random(32);
                                        break;
                                    case 'USER':
                                        $generatedValue = Str::random(16);
                                        break;
                                }

                                if (!$foundEnv) {
                                    EnvironmentVariable::create([
                                        'key' => $key,
                                        'value' => $generatedValue,
                                        'is_build_time' => false,
                                        'service_id' => $this->id,
                                        'is_preview' => false,
                                    ]);
                                }
                            }
                        } else {
                            if ($value->contains(':-')) {
                                $key = $value->before(':');
                                $defaultValue = $value->after(':-');
                            } else if ($value->contains('-')) {
                                $key = $value->before('-');
                                $defaultValue = $value->after('-');
                            } else if ($value->contains(':?')) {
                                $key = $value->before(':');
                                $defaultValue = $value->after(':?');
                            } else if ($value->contains('?')) {
                                $key = $value->before('?');
                                $defaultValue = $value->after('?');
                            } else {
                                $key = $value;
                                $defaultValue = null;
                            }
                            if ($foundEnv) {
                                $defaultValue = data_get($foundEnv, 'value');
                            }
                            EnvironmentVariable::updateOrCreate([
                                'key' => $key,
                                'service_id' => $this->id,
                            ], [
                                'value' => $defaultValue,
                                'is_build_time' => false,
                                'service_id' => $this->id,
                                'is_preview' => false,
                            ]);
                        }
                    }
                }

                // Add labels to the service
                $fqdns = collect(data_get($savedService, 'fqdns'));
                $defaultLabels = defaultLabels($this->id, $containerName, type: 'service', subType: $isDatabase ? 'database' : 'application', subId: $savedService->id);
                $serviceLabels = $serviceLabels->merge($defaultLabels);
                if (!$isDatabase && $fqdns->count() > 0) {
                    if ($fqdns) {
                        $serviceLabels = $serviceLabels->merge(fqdnLabelsForTraefik($fqdns, $containerName, true));
                    }
                }

                data_set($service, 'labels', $serviceLabels->toArray());
                data_forget($service, 'is_database');
                data_set($service, 'restart', RESTART_MODE);
                data_set($service, 'container_name', $containerName);
                data_forget($service, 'volumes.*.content');
                data_forget($service, 'volumes.*.isDirectory');

                // Remove unnecessary variables from service.environment
                $withoutServiceEnvs = collect([]);
                collect(data_get($service, 'environment'))->each(function ($value, $key) use ($withoutServiceEnvs) {
                    if (!Str::of($key)->startsWith('$SERVICE_')) {
                        $withoutServiceEnvs->put($key, $value);
                    }
                });
                data_set($service, 'environment', $withoutServiceEnvs->toArray());
                return $service;
            });
            $finalServices = [
                'version' => $dockerComposeVersion,
                'services' => $services->toArray(),
                'volumes' => $topLevelVolumes->toArray(),
                'networks' => $topLevelNetworks->toArray(),
            ];
            $this->docker_compose_raw = Yaml::dump($yaml, 10, 2);
            $this->docker_compose = Yaml::dump($finalServices, 10, 2);
            $this->save();
            $this->saveComposeConfigs();
            return collect([]);
        } else {
            return collect([]);
        }
    }
}
