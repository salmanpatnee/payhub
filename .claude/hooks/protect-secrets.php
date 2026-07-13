<?php

/**
 * PreToolUse hook: blocks Edit/Write/MultiEdit calls that would
 * 1. write to a real .env file, or
 * 2. commit an obvious hardcoded provider secret / private key, or
 * 3. assign a hardcoded literal directly to one of the known
 *    secret columns (secret_key, access_token, webhook_secret,
 *    webhook_signature_key) instead of pulling it from request
 *    input, config(), or env().
 *
 * Exit 0 = allow. Exit 2 = block (stderr is surfaced back to Claude).
 */
$input = json_decode(file_get_contents('php://stdin'), true) ?? [];

$toolName = $input['tool_name'] ?? '';
if (! in_array($toolName, ['Edit', 'Write', 'MultiEdit'], true)) {
    exit(0);
}

$toolInput = $input['tool_input'] ?? [];
$filePath = $toolInput['file_path'] ?? '';
$basename = basename(str_replace('\\', '/', $filePath));

// Rule 1: never let Claude write to a real .env file.
if (preg_match('/^\.env(\..+)?$/', $basename) && $basename !== '.env.example') {
    fwrite(STDERR, "Blocked: {$filePath} is a real .env file. Secrets belong in the environment, not in an automated edit — ask the user to change it directly.\n");
    exit(2);
}

// Fake secrets in tests/factories/seeders are expected, not a leak.
$isTestOrFactory = (bool) preg_match('#(^|[/\\\\])(tests|database[/\\\\](factories|seeders))[/\\\\]#i', $filePath);
if ($isTestOrFactory) {
    exit(0);
}

$chunks = [];
if (isset($toolInput['content'])) {
    $chunks[] = (string) $toolInput['content'];
}
if (isset($toolInput['new_string'])) {
    $chunks[] = (string) $toolInput['new_string'];
}
if (isset($toolInput['edits']) && is_array($toolInput['edits'])) {
    foreach ($toolInput['edits'] as $edit) {
        if (isset($edit['new_string'])) {
            $chunks[] = (string) $edit['new_string'];
        }
    }
}
$text = implode("\n", $chunks);

if ($text === '') {
    exit(0);
}

// Rule 2: block obvious hardcoded secrets landing in tracked code.
$secretPatterns = [
    '/sk_live_[A-Za-z0-9]{10,}/' => 'a live Stripe secret key',
    '/sk_test_[A-Za-z0-9]{10,}/' => 'a Stripe test secret key',
    '/rk_live_[A-Za-z0-9]{10,}/' => 'a live Stripe restricted key',
    '/whsec_[A-Za-z0-9]{10,}/' => 'a Stripe webhook signing secret',
    '/AKIA[0-9A-Z]{16}/' => 'an AWS access key',
    '/-----BEGIN[ A-Z]*PRIVATE KEY-----/' => 'a private key block',
];

foreach ($secretPatterns as $pattern => $label) {
    if (preg_match($pattern, $text)) {
        fwrite(STDERR, "Blocked: this edit to {$filePath} looks like it hardcodes {$label}. Secrets belong in encrypted DB columns or .env, read via config()/env() — not committed literals.\n");
        exit(2);
    }
}

// Rule 3: hardcoded literal assigned directly to a known secret column.
$secretColumns = ['secret_key', 'access_token', 'webhook_secret', 'webhook_signature_key'];
foreach ($secretColumns as $column) {
    $pattern = '/[\'"]'.preg_quote($column, '/').'[\'"]\s*=>\s*[\'"]([^\'"]{8,})[\'"]/';
    if (preg_match($pattern, $text)) {
        fwrite(STDERR, "Blocked: this edit assigns a hardcoded literal directly to '{$column}' in {$filePath}. That column must come from validated request input, not a string literal in code.\n");
        exit(2);
    }
}

exit(0);
