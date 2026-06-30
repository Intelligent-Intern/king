<?php
declare(strict_types=1);

function king_openai_router_model_configs(): array
{
    $configs = king_inference_runtime_model_registry_config();
    if (!is_array($configs) || $configs === []) {
        throw new RuntimeException('King runtime model registry did not return any configured models.');
    }

    foreach ($configs as $id => $config) {
        if (!is_string($id) && !is_int($id)) {
            throw new RuntimeException('King runtime model registry returned an invalid model id.');
        }
        if (!is_array($config)) {
            throw new RuntimeException('King runtime model registry entry is not a model config array.');
        }
    }

    return $configs;
}

function king_openai_router_model_config_name(array $config, string $fallback): string
{
    foreach (['id', 'name'] as $key) {
        if (is_string($config[$key] ?? null) && $config[$key] !== '') {
            return $config[$key];
        }
    }
    return $fallback !== '' ? $fallback : 'king-runtime';
}

function king_openai_router_model_config_aliases(array $config): array
{
    $aliases = $config['aliases'] ?? [];
    if (!is_array($aliases)) {
        return [];
    }

    $normalized = [];
    foreach ($aliases as $alias) {
        if (is_string($alias) && $alias !== '') {
            $normalized[$alias] = true;
        }
    }

    return array_keys($normalized);
}

function king_openai_router_load_model_registry(array $configs): array
{
    $models = [];
    $aliases = [];

    foreach ($configs as $id => $config) {
        $registryName = king_openai_router_model_config_name($config, (string) $id);
        if (isset($models[$registryName])) {
            throw new RuntimeException('Duplicate King model registry id: ' . $registryName);
        }

        $models[$registryName] = king_inference_model_load($config);
        foreach (king_openai_router_model_config_aliases($config) as $alias) {
            if (isset($models[$alias]) || isset($aliases[$alias])) {
                throw new RuntimeException('Duplicate King model registry alias: ' . $alias);
            }
            $aliases[$alias] = $registryName;
        }
    }

    if ($models === []) {
        throw new RuntimeException('No King inference models were loaded from the runtime registry.');
    }

    return [$models, $aliases];
}
