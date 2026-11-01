<?php

// Stand-in for `git` in TestRunnerSupportTest: prints what FAKE_GIT_STDOUT / FAKE_GIT_STDERR say
// (escape \0 as "\\0") and exits with FAKE_GIT_EXIT. Arguments are ignored.
fwrite(STDOUT, str_replace('\\0', "\0", (string) getenv('FAKE_GIT_STDOUT')));
fwrite(STDERR, (string) getenv('FAKE_GIT_STDERR'));
exit((int) (getenv('FAKE_GIT_EXIT') ?: 0));
