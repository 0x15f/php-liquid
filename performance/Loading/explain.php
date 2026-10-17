<?php

// These are the adapter's two actual query shapes for a 24-product grid.
$pdo = new PDO('sqlite:'.dirname(__DIR__, 2).'/build/loading-catalog.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$markers = implode(',', array_fill(0, 24, '?'));
$queries = [
    'product_page' => ['SELECT id,image,price_formatted,title,url FROM products WHERE tenant_id = ? ORDER BY id LIMIT ? OFFSET ?', [1, 24, 0]],
    'batched_variants' => ['SELECT id,product_id,available,title FROM variants WHERE tenant_id = ? AND product_id IN ('.$markers.') ORDER BY product_id,id LIMIT ?', [1, ...range(1, 24), 2025]],
];
$output = [];
foreach ($queries as $name => [$sql, $parameters]) {
    $statement = $pdo->prepare('EXPLAIN QUERY PLAN '.$sql);
    foreach ($parameters as $i => $value) {
        $statement->bindValue($i + 1, $value, PDO::PARAM_INT);
    }
    $statement->execute();
    $output[$name] = ['sql' => $sql, 'plan' => $statement->fetchAll(PDO::FETCH_ASSOC)];
}
echo json_encode($output, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
