<?php
// Every external host the code refers to must be classified in includes/data_flows.php (Phase 0 audit; F15 builds on it).
require_once __DIR__ . '/../includes/data_flows.php';

function test_every_external_host_in_the_code_is_registered() {
    $root = realpath(__DIR__ . '/..');
    $files = array_merge(glob("$root/*.php"), glob("$root/*.html"), glob("$root/includes/*.php"), glob("$root/pages/*.php"),
                         glob("$root/assets/js/*.js"), glob("$root/whatsapp-extension/*.js"));
    $known = dataFlowHosts();
    $ignore = ['localhost', '127.0.0.1', 'www.w3.org', 'crm.example.ae', 'crm.example.ae', 'yourdomain.com', 'example.com', 'crm.test', 'schema.org'];
    $missing = [];
    foreach ($files as $f) {
        if (str_ends_with($f, 'data_flows.php')) continue;
        preg_match_all('#https?://([a-z0-9.-]+\.[a-z]{2,})#i', file_get_contents($f), $m);
        foreach (array_unique($m[1]) as $h) {
            $h = strtolower($h);
            if (in_array($h, $ignore, true) || str_ends_with($h, '.test') || str_ends_with($h, '.example')) continue;
            if (!in_array($h, $known, true)) $missing[] = "$h (" . basename($f) . ')';
        }
    }
    check(!$missing, 'unregistered hosts: ' . implode(', ', array_unique($missing)));
}

function test_every_flow_is_fully_described() {
    foreach (DATA_FLOWS as $k => $f) {
        foreach (['name', 'side', 'hosts', 'code', 'enabled_by', 'sends', 'health', 'where', 'action'] as $field)
            check(array_key_exists($field, $f), "$k has $field");
        check(in_array($f['health'], ['no', 'low', 'yes'], true), "$k health is no/low/yes");
    }
}
