<?php
// READ-ONLY preflight probe, piped to `php` on stdin by the runner (Stage B only). No Laravel bootstrap, no writes, no DDL.
// Inputs via env: PC_HOST PC_PORT PC_USER DB_PASSWORD PC_DBS (comma list). Prints one JSON line.
$out = ['ok' => false, 'dbs' => [], 'grants' => [], 'server' => null, 'error' => null];
try {
    $pdo = new PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4', getenv('PC_HOST'), getenv('PC_PORT')), getenv('PC_USER'), getenv('DB_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10,
    ]);
    $out['server'] = $pdo->query('SELECT VERSION()')->fetchColumn();
    foreach (array_filter(explode(',', (string) getenv('PC_DBS'))) as $db) {
        $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?'); $s->execute([$db]);
        $l = $pdo->prepare('SELECT IS_USED_LOCK(?)'); $l->execute(['primeclassy:testrun:'.$db]);
        $l2 = $pdo->prepare('SELECT IS_USED_LOCK(?)'); $l2->execute(['primeclassy:parallelrun:'.$db]);
        $out['dbs'][$db] = ['exists' => (int) $s->fetchColumn() === 1, 'lock_holder' => $l->fetchColumn(), 'lease_holder' => $l2->fetchColumn()];
    }
    foreach ($pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN) as $g) {
        $out['grants'][] = preg_replace('/IDENTIFIED BY .*/i', '', (string) $g);
    }
    $out['ok'] = true;
} catch (Throwable $e) {
    $out['error'] = get_class($e).': '.substr($e->getMessage(), 0, 200);
}
echo json_encode($out), PHP_EOL;
