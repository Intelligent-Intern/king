<?php

declare(strict_types=1);

require_once __DIR__ . '/../../support/localization.php';

function videochat_email_template_namespaces(string ...$resourceKeys): array
{
    $namespaces = [];
    foreach ($resourceKeys as $resourceKey) {
        $parts = explode('.', trim($resourceKey), 2);
        $namespace = trim((string) ($parts[0] ?? ''));
        if ($namespace !== '') {
            $namespaces[$namespace] = $namespace;
        }
    }
    return array_values($namespaces);
}

function videochat_email_template_placeholders(mixed $template): array
{
    preg_match_all('/\{([A-Za-z][A-Za-z0-9_]*)\}/', (string) $template, $matches);
    $placeholders = array_values(array_unique(array_map('strval', $matches[1] ?? [])));
    sort($placeholders);
    return $placeholders;
}

function videochat_email_template_has_required_placeholders(mixed $template, array $required): bool
{
    $present = array_flip(videochat_email_template_placeholders($template));
    foreach ($required as $placeholder) {
        $key = trim((string) $placeholder);
        if ($key !== '' && !isset($present[$key])) {
            return false;
        }
    }
    return true;
}

function videochat_email_template_choose(array $resources, string $resourceKey, string $fallback, array $required): string
{
    $candidate = trim((string) ($resources[$resourceKey] ?? ''));
    if ($candidate !== '' && videochat_email_template_has_required_placeholders($candidate, $required)) {
        return $candidate;
    }
    return $fallback;
}

function videochat_resolve_localized_email_templates(
    PDO $pdo,
    ?int $tenantId,
    mixed $locale,
    string $subjectResourceKey,
    string $bodyResourceKey,
    string $subjectFallback,
    string $bodyFallback,
    array $requiredSubjectPlaceholders,
    array $requiredBodyPlaceholders
): array {
    $resolvedLocale = videochat_resolve_locale_code($pdo, $locale);
    $fallbackLocale = videochat_default_locale_code();
    $namespaces = videochat_email_template_namespaces($subjectResourceKey, $bodyResourceKey);
    $activeResources = videochat_fetch_translation_resources($pdo, $resolvedLocale, $tenantId, $namespaces);
    $fallbackResources = $resolvedLocale === $fallbackLocale
        ? $activeResources
        : videochat_fetch_translation_resources($pdo, $fallbackLocale, $tenantId, $namespaces);

    $subject = videochat_email_template_choose($activeResources, $subjectResourceKey, '', $requiredSubjectPlaceholders);
    $subjectLocale = $subject !== '' ? $resolvedLocale : $fallbackLocale;
    if ($subject === '') {
        $subject = videochat_email_template_choose($fallbackResources, $subjectResourceKey, $subjectFallback, $requiredSubjectPlaceholders);
    }

    $body = videochat_email_template_choose($activeResources, $bodyResourceKey, '', $requiredBodyPlaceholders);
    $bodyLocale = $body !== '' ? $resolvedLocale : $fallbackLocale;
    if ($body === '') {
        $body = videochat_email_template_choose($fallbackResources, $bodyResourceKey, $bodyFallback, $requiredBodyPlaceholders);
    }

    return [
        'locale' => $resolvedLocale,
        'fallback_locale' => $fallbackLocale,
        'subject_locale' => $subjectLocale,
        'body_locale' => $bodyLocale,
        'subject_template' => $subject,
        'body_template' => $body,
    ];
}
