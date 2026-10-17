<?php

/** @return array<string, string> */
function loadingTemplates(): array
{
    return [
        'grid' => '<section data-customer="{{ customer.id }}">{% for product in collection.products limit: 24 %}{% render "card", product: product, customer: customer %}{% endfor %}</section>',
        'card' => '<article data-id="{{ product.id }}"><a href="{{ product.url | escape }}"><img src="{{ product.image | escape }}">{{ product.title | escape }}</a>{% render "price", item: product %}{% if customer.show_variants %}<ul>{% for variant in product.variants %}{% if variant.available == 1 %}<li>{{ variant.title | escape }}</li>{% endif %}{% endfor %}</ul>{% endif %}</article>',
        'price' => '<span>{{ item.price_formatted }}</span>',
        'dynamic' => '<section>{% for p in collection.products limit: 24 %}{{ p[field] | escape }}{% for v in p.variants %}{{ v[variant_field] | escape }}{% endfor %}{% endfor %}</section>',
    ];
}

/** File-backed, wide synthetic catalog; creates rows without a PHP catalog array. */
function createLoadingDatabase(string $path, int $productsPerTenant = 2000): void
{
    $pdo = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE products (tenant_id INTEGER NOT NULL, id INTEGER NOT NULL, title TEXT, url TEXT, image TEXT, price_formatted TEXT, available INTEGER, description TEXT, extra TEXT, PRIMARY KEY (tenant_id,id))');
    $pdo->exec('CREATE TABLE variants (tenant_id INTEGER NOT NULL, id INTEGER NOT NULL, product_id INTEGER NOT NULL, title TEXT, available INTEGER, extra TEXT, PRIMARY KEY (tenant_id,id))');
    $pdo->exec('CREATE INDEX variants_products ON variants (tenant_id,product_id,id)');
    $product = $pdo->prepare('INSERT INTO products VALUES (?,?,?,?,?,?,?,?,?)');
    $variant = $pdo->prepare('INSERT INTO variants VALUES (?,?,?,?,?,?)');
    $pdo->beginTransaction();
    foreach ([1, 2] as $tenant) {
        for ($id = 1; $id <= $productsPerTenant; $id++) {
            $product->execute([$tenant, $id, 'Tenant '.$tenant.' bag '.$id.' & accessories', '/products/'.$id,
                '/images/'.$id.'.jpg', 'USD '.number_format((1900 + $id * 7) / 100, 2, '.', ''), $id % 7 === 0 ? 0 : 1,
                str_repeat('wide description ', 512), null]);
            for ($v = 1; $v <= 4; $v++) {
                $variant->execute([$tenant, $id * 10 + $v, $id, 'Size '.$v.' / Navy & tan', ($id + $v) % 4 === 0 ? 0 : 1,
                    str_repeat('unused variant payload ', 96)]);
            }
        }
    }
    $pdo->commit();
}
