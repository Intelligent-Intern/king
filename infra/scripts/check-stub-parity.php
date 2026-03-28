<?php

declare(strict_types=1);

const KING_STUB_PARITY_STUB_FILE = __DIR__ . '/../../stubs/king.php';

main();

function main(): void
{
    if (!extension_loaded('king')) {
        fail('The king extension is not loaded. Use ./infra/scripts/check-stub-parity.sh.');
    }

    if (!is_file(KING_STUB_PARITY_STUB_FILE)) {
        fail('Missing stub file: ' . KING_STUB_PARITY_STUB_FILE);
    }

    $stubSurface = parse_stub_surface((string) file_get_contents(KING_STUB_PARITY_STUB_FILE));
    $runtimeSurface = reflect_runtime_surface();
    $issues = [];

    compare_named_signatures(
        'Global functions',
        $stubSurface['functions'],
        $runtimeSurface['functions'],
        $issues
    );

    $stubClasses = $stubSurface['classes'];
    $runtimeClasses = $runtimeSurface['classes'];

    add_missing_name_issues(
        'Classes',
        array_keys($stubClasses),
        array_keys($runtimeClasses),
        $issues
    );

    foreach (shared_names(array_keys($stubClasses), array_keys($runtimeClasses)) as $className) {
        compare_named_signatures(
            sprintf('Public methods for %s', $className),
            $stubClasses[$className]['methods'],
            $runtimeClasses[$className]['methods'],
            $issues,
            true
        );
    }

    if ($issues !== []) {
        fwrite(STDERR, "Stub parity mismatches detected:\n");
        foreach ($issues as $issue) {
            fwrite(STDERR, ' - ' . $issue . PHP_EOL);
        }
        exit(1);
    }

    $methodCount = 0;
    foreach ($runtimeClasses as $classData) {
        $methodCount += count($classData['methods']);
    }

    printf(
        "Stub parity OK: %d functions, %d classes, %d declared public methods.\n",
        count($runtimeSurface['functions']),
        count($runtimeSurface['classes']),
        $methodCount
    );
}

function parse_stub_surface(string $source): array
{
    $tokens = token_get_all($source, TOKEN_PARSE);
    $functions = [];
    $classes = [];
    $namespace = '';
    $namespaceStack = [];
    $braceStack = [];
    $classStack = [];
    $pendingNamespace = null;
    $pendingClass = null;
    $tokenCount = count($tokens);

    for ($index = 0; $index < $tokenCount; $index++) {
        $token = $tokens[$index];

        if (is_string($token)) {
            if ($token === '{') {
                if ($pendingClass !== null) {
                    $classStack[] = $pendingClass;
                    $braceStack[] = ['type' => 'class'];
                    $pendingClass = null;
                    continue;
                }

                if ($pendingNamespace !== null) {
                    $namespaceStack[] = $namespace;
                    $namespace = $pendingNamespace;
                    $braceStack[] = ['type' => 'namespace'];
                    $pendingNamespace = null;
                    continue;
                }

                $braceStack[] = ['type' => 'other'];
            } elseif ($token === '}') {
                $frame = array_pop($braceStack);
                if (($frame['type'] ?? null) === 'class') {
                    array_pop($classStack);
                } elseif (($frame['type'] ?? null) === 'namespace') {
                    $namespace = array_pop($namespaceStack) ?? '';
                }
            }

            continue;
        }

        $tokenId = $token[0];

        if ($tokenId === T_NAMESPACE) {
            [$namespaceName, $delimiterIndex, $delimiter] = read_namespace_declaration($tokens, $index + 1);
            if ($delimiter === ';') {
                $namespace = $namespaceName;
                $pendingNamespace = null;
                $index = $delimiterIndex;
            } else {
                $pendingNamespace = $namespaceName;
                $index = $delimiterIndex - 1;
            }
            continue;
        }

        if (is_class_token($tokenId)) {
            $nameIndex = next_non_trivia_index($tokens, $index + 1);
            if ($nameIndex === null || !is_name_token($tokens[$nameIndex])) {
                continue;
            }

            $className = qualify_name($namespace, token_text($tokens[$nameIndex]));
            $classes[$className] ??= ['methods' => []];
            $pendingClass = $className;
            $index = $nameIndex;
            continue;
        }

        if ($tokenId !== T_FUNCTION) {
            continue;
        }

        $nameIndex = next_non_trivia_index($tokens, $index + 1, true);
        if ($nameIndex === null || !is_array($tokens[$nameIndex]) || $tokens[$nameIndex][0] !== T_STRING) {
            continue;
        }

        $name = $tokens[$nameIndex][1];
        $openParenIndex = find_next_character($tokens, $nameIndex + 1, '(');
        if ($openParenIndex === null) {
            continue;
        }

        [$required, $total, $endIndex] = read_parameter_counts($tokens, $openParenIndex);

        if ($classStack === []) {
            if (str_starts_with($name, 'king_')) {
                $functions[$name] = [
                    'required' => $required,
                    'total' => $total,
                ];
            }
        } else {
            $className = end($classStack);
            $classes[$className]['methods'][$name] = [
                'required' => $required,
                'total' => $total,
                'static' => method_is_static($tokens, $index),
            ];
        }

        $index = $endIndex;
    }

    ksort($functions);
    ksort($classes);
    foreach ($classes as &$classData) {
        ksort($classData['methods']);
    }
    unset($classData);

    return [
        'functions' => $functions,
        'classes' => $classes,
    ];
}

function reflect_runtime_surface(): array
{
    $functions = [];
    foreach (get_extension_funcs('king') ?: [] as $functionName) {
        if (!is_string($functionName) || !str_starts_with($functionName, 'king_')) {
            continue;
        }

        $reflection = new ReflectionFunction($functionName);
        $functions[$functionName] = [
            'required' => $reflection->getNumberOfRequiredParameters(),
            'total' => $reflection->getNumberOfParameters(),
        ];
    }

    $classes = [];
    foreach (get_declared_classes() as $className) {
        $reflection = new ReflectionClass($className);
        if (!$reflection->isInternal() || $reflection->getExtensionName() !== 'king' || !str_starts_with($className, 'King\\')) {
            continue;
        }

        $methods = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $className) {
                continue;
            }

            $methods[$method->getName()] = [
                'required' => $method->getNumberOfRequiredParameters(),
                'total' => $method->getNumberOfParameters(),
                'static' => $method->isStatic(),
            ];
        }

        ksort($methods);
        $classes[$className] = ['methods' => $methods];
    }

    ksort($functions);
    ksort($classes);

    return [
        'functions' => $functions,
        'classes' => $classes,
    ];
}

function compare_named_signatures(
    string $label,
    array $stubMap,
    array $runtimeMap,
    array &$issues,
    bool $compareStatic = false
): void {
    add_missing_name_issues($label, array_keys($stubMap), array_keys($runtimeMap), $issues);

    foreach (shared_names(array_keys($stubMap), array_keys($runtimeMap)) as $name) {
        $stubSignature = $stubMap[$name];
        $runtimeSignature = $runtimeMap[$name];

        if ($stubSignature['required'] !== $runtimeSignature['required']
            || $stubSignature['total'] !== $runtimeSignature['total']) {
            $issues[] = sprintf(
                '%s mismatch for %s: stubs=%d/%d runtime=%d/%d required/total parameters',
                $label,
                $name,
                $stubSignature['required'],
                $stubSignature['total'],
                $runtimeSignature['required'],
                $runtimeSignature['total']
            );
        }

        if ($compareStatic && $stubSignature['static'] !== $runtimeSignature['static']) {
            $issues[] = sprintf(
                '%s mismatch for %s: stubs=%s runtime=%s static',
                $label,
                $name,
                $stubSignature['static'] ? 'yes' : 'no',
                $runtimeSignature['static'] ? 'yes' : 'no'
            );
        }
    }
}

function add_missing_name_issues(string $label, array $stubNames, array $runtimeNames, array &$issues): void
{
    $onlyInRuntime = array_values(array_diff($runtimeNames, $stubNames));
    if ($onlyInRuntime !== []) {
        $issues[] = sprintf('%s only in runtime: %s', $label, implode(', ', $onlyInRuntime));
    }

    $onlyInStubs = array_values(array_diff($stubNames, $runtimeNames));
    if ($onlyInStubs !== []) {
        $issues[] = sprintf('%s only in stubs: %s', $label, implode(', ', $onlyInStubs));
    }
}

function shared_names(array $left, array $right): array
{
    $shared = array_values(array_intersect($left, $right));
    sort($shared);
    return $shared;
}

function read_namespace_declaration(array $tokens, int $startIndex): array
{
    $name = '';
    $tokenCount = count($tokens);

    for ($index = $startIndex; $index < $tokenCount; $index++) {
        $token = $tokens[$index];

        if (is_string($token)) {
            if ($token === ';' || $token === '{') {
                return [trim($name, '\\'), $index, $token];
            }
            continue;
        }

        if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        if (is_name_token($token)) {
            $name .= $token[1];
        }
    }

    fail('Unterminated namespace declaration in stubs/king.php.');
}

function next_non_trivia_index(array $tokens, int $startIndex, bool $skipAmpersands = false): ?int
{
    $tokenCount = count($tokens);

    for ($index = $startIndex; $index < $tokenCount; $index++) {
        $token = $tokens[$index];

        if (is_string($token)) {
            if ($skipAmpersands && $token === '&') {
                continue;
            }

            if (trim($token) === '') {
                continue;
            }

            return $index;
        }

        if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        if ($skipAmpersands && is_ampersand_token($token[0])) {
            continue;
        }

        return $index;
    }

    return null;
}

function find_next_character(array $tokens, int $startIndex, string $character): ?int
{
    $tokenCount = count($tokens);

    for ($index = $startIndex; $index < $tokenCount; $index++) {
        if ($tokens[$index] === $character) {
            return $index;
        }
    }

    return null;
}

function read_parameter_counts(array $tokens, int $openParenIndex): array
{
    $required = 0;
    $total = 0;
    $nestedDepth = 0;
    $hasVariable = false;
    $hasDefault = false;
    $tokenCount = count($tokens);

    for ($index = $openParenIndex + 1; $index < $tokenCount; $index++) {
        $token = $tokens[$index];

        if (is_string($token)) {
            if ($token === '(' || $token === '[' || $token === '{') {
                $nestedDepth++;
                continue;
            }

            if ($token === ')' && $nestedDepth === 0) {
                if ($hasVariable) {
                    $total++;
                    if (!$hasDefault) {
                        $required++;
                    }
                }

                return [$required, $total, $index];
            }

            if ($token === ')' || $token === ']' || $token === '}') {
                $nestedDepth--;
                continue;
            }

            if ($nestedDepth === 0 && $token === ',') {
                if ($hasVariable) {
                    $total++;
                    if (!$hasDefault) {
                        $required++;
                    }
                }

                $hasVariable = false;
                $hasDefault = false;
                continue;
            }

            if ($nestedDepth === 0 && $token === '=') {
                $hasDefault = true;
            }

            continue;
        }

        if ($nestedDepth === 0 && $token[0] === T_VARIABLE) {
            $hasVariable = true;
        }
    }

    fail('Unterminated parameter list in stubs/king.php.');
}

function method_is_static(array $tokens, int $functionIndex): bool
{
    for ($index = $functionIndex - 1; $index >= 0; $index--) {
        $token = $tokens[$index];

        if (is_string($token)) {
            if (trim($token) === '') {
                continue;
            }

            if (in_array($token, [';', '{', '}', ')'], true)) {
                return false;
            }

            continue;
        }

        if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        if ($token[0] === T_STATIC) {
            return true;
        }

        if (in_array($token[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_ABSTRACT, T_FINAL], true)) {
            continue;
        }

        return false;
    }

    return false;
}

function qualify_name(string $namespace, string $name): string
{
    if ($namespace === '') {
        return ltrim($name, '\\');
    }

    return $namespace . '\\' . ltrim($name, '\\');
}

function is_class_token(int $tokenId): bool
{
    if ($tokenId === T_CLASS || $tokenId === T_INTERFACE || $tokenId === T_TRAIT) {
        return true;
    }

    return defined('T_ENUM') && $tokenId === T_ENUM;
}

function is_name_token(mixed $token): bool
{
    if (!is_array($token)) {
        return false;
    }

    return in_array(
        $token[0],
        array_filter([
            T_STRING,
            defined('T_NAME_QUALIFIED') ? constant('T_NAME_QUALIFIED') : null,
            defined('T_NAME_FULLY_QUALIFIED') ? constant('T_NAME_FULLY_QUALIFIED') : null,
            defined('T_NAME_RELATIVE') ? constant('T_NAME_RELATIVE') : null,
            T_NS_SEPARATOR,
        ], static fn (mixed $value): bool => $value !== null),
        true
    );
}

function is_ampersand_token(int $tokenId): bool
{
    $ampersandTokens = array_filter([
        defined('T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG') ? constant('T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG') : null,
        defined('T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG') ? constant('T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG') : null,
    ], static fn (mixed $value): bool => $value !== null);

    return in_array($tokenId, $ampersandTokens, true);
}

function token_text(mixed $token): string
{
    if (is_string($token)) {
        return $token;
    }

    return $token[1];
}

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
