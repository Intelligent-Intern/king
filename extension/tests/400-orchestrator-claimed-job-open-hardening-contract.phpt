--TEST--
King file-worker claimed-job opens stay nofollow nonblocking and regular-file only
--FILE--
<?php
$source = file_get_contents(__DIR__ . '/../src/pipeline_orchestrator/tool_registry.c');

preg_match(
    '/static int king_orchestrator_open_claimed_job_fd\\([^\\)]*\\)\\s*\\{(?P<body>.*?)^\\}/ms',
    $source,
    $helperMatches
);
preg_match(
    '/int king_orchestrator_claim_next_run\\([^\\)]*\\)\\s*\\{(?P<body>.*?)^\\}/ms',
    $source,
    $claimMatches
);

var_dump(isset($helperMatches['body']));
var_dump(str_contains($helperMatches['body'], 'O_NOFOLLOW'));
var_dump(str_contains($helperMatches['body'], 'O_NONBLOCK'));
var_dump(str_contains($helperMatches['body'], 'fstat(fd, &st)'));
var_dump(str_contains($helperMatches['body'], 'S_ISREG(st.st_mode)'));
var_dump(str_contains($helperMatches['body'], 'F_SETFL'));
var_dump(isset($claimMatches['body']));
var_dump(str_contains($claimMatches['body'], 'king_orchestrator_open_claimed_job_fd('));
var_dump(str_contains($claimMatches['body'], 'claimed_fd = open('));
var_dump(str_contains($claimMatches['body'], 'discard_claimed_path || !selected_is_claimed'));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
