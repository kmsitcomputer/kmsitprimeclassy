<?php
// RUNNER LEASE (Stage B). Passed to `php -r` by the runner; holds one MariaDB session whose named locks
// 'primeclassy:parallelrun:<db>' mark the worker databases as IN USE for the whole run. Provisioning cleanup takes the same locks
// (pctest/provision.py), so a DROP can never overlap a run. The server releases the locks when this session ends — also if the runner
// is killed (stdin EOF / dead connection) — so there is no stale lock to delete.
// Env: PC_HOST PC_PORT PC_USER DB_PASSWORD PC_DBS. Protocol: prints LOCKED or FAIL:<db>, then blocks on stdin until EOF.
$pdo = new PDO(sprintf('mysql:host=%s;port=%s', getenv('PC_HOST'), getenv('PC_PORT')), getenv('PC_USER'), getenv('DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
$pdo->exec('SET SESSION wait_timeout = 172800');
$locks = [];
foreach (array_filter(explode(',', (string) getenv('PC_DBS'))) as $db) {
    $s = $pdo->prepare('SELECT GET_LOCK(?, 0)'); $s->execute(['primeclassy:parallelrun:'.$db]);
    $locks[] = 'primeclassy:parallelrun:'.$db;
    if ((int) $s->fetchColumn() !== 1) { echo 'FAIL:'.$db.PHP_EOL; exit(3); }
}
echo 'LOCKED'.PHP_EOL;
while (($line = fgets(STDIN)) !== false) {
    if (trim($line) !== 'PING') { exit(4); }
    foreach ($locks as $lock) {
        $check = $pdo->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID()');
        $check->execute([$lock]);
        if ((int) $check->fetchColumn() !== 1) { echo 'LOST'.PHP_EOL; exit(4); }
    }
    echo 'OWNED'.PHP_EOL;
}
