<?php

/** Synthetic HTML contract for products, variants and B2B pricing. */
function comparisonFixtures(): array
{
    $templates = [
        'grid' => '<section data-customer="{{ customer.id }}" data-segment="{{ customer.segment | escape }}"><h1>{{ collection.title | escape }}</h1>{% if customer.is_b2b %}<p>Business prices</p>{% endif %}<div class="grid">{% for product in products %}{% render "card", product: product, customer: customer, currency: currency %}{% endfor %}</div></section>',
        'card' => '<article data-id="{{ product.id }}"><a href="{{ product.url | escape }}"><img src="{{ product.image | escape }}" alt="{{ product.title | escape }}"><h2>{{ product.title | escape }}</h2></a>{% render "price", product: product, currency: currency %}{% if customer.wishlist contains product.id %}<span>Saved</span>{% endif %}{% if product.available %}<button>Add to cart</button>{% else %}<span>Sold out</span>{% endif %}<ul>{% for variant in product.variants %}{% if variant.available %}<li data-id="{{ variant.id }}">{{ variant.title | escape }}</li>{% endif %}{% endfor %}</ul><span>{{ product.tags | join: ", " | escape }}</span></article>',
        'price' => '<span class="price">{{ currency }}{{ product.price_formatted }}</span>{% if product.on_sale %}<del>{{ currency }}{{ product.compare_at_formatted }}</del>{% endif %}',
        'fragment' => '<aside data-customer="{{ customer.id }}">{{ customer.name | escape }}{% for product in products limit: 4 %}{% render "card", product: product, customer: customer, currency: currency %}{% endfor %}</aside>',
        'inline' => '<section data-customer="{{ customer.id }}">{% for product in products %}<article><a href="{{ product.url | escape }}">{{ product.title | escape }}</a><span>{{ currency }}{{ product.price_formatted }}</span>{% if customer.wishlist contains product.id %}<b>Saved</b>{% endif %}{% for variant in product.variants %}{% if variant.available %}<i>{{ variant.title | escape }}</i>{% endif %}{% endfor %}</article>{% endfor %}</section>',
    ];
    $workloads = [];
    foreach (['fragment_4' => ['fragment', 4], 'grid_24' => ['grid', 24], 'grid_96' => ['grid', 96], 'inline_24' => ['inline', 24]] as $name => [$template, $count]) {
        $requests = [];
        for ($customer = 0; $customer < 16; $customer++) {
            $products = [];
            for ($i = 1; $i <= $count; $i++) {
                $price = 1900 + $i * 173 - ($customer % 3) * 100;
                $products[] = [
                    'id' => $i, 'title' => 'Canvas bag '.$i.' & accessories',
                    'url' => '/products/bag-'.$i.'?variant='.($i * 10),
                    'image' => 'https://cdn.example.test/images/'.$i.'.jpg',
                    'price_formatted' => number_format($price / 100, 2, '.', ''),
                    'compare_at_formatted' => number_format(($price + 500) / 100, 2, '.', ''),
                    'on_sale' => $i % 3 === 0, 'available' => $i % 7 !== 0,
                    'tags' => ['canvas', 'travel', 'season-'.($i % 4)],
                    'variants' => array_map(fn ($v) => ['id' => $i * 10 + $v, 'title' => 'Size '.$v.' / Navy & tan', 'available' => ($i + $v) % 4 !== 0], range(1, 4)),
                ];
            }
            $requests[] = ['op' => 'render', 'template' => $template, 'data' => [
                'collection' => ['title' => 'Travel & everyday'], 'currency' => 'USD ',
                'customer' => ['id' => 1000 + $customer, 'name' => 'Customer '.$customer, 'is_b2b' => $customer % 3 === 0,
                    'segment' => $customer % 3 === 0 ? 'wholesale' : 'retail', 'wishlist' => [$customer + 1, $customer + 3]],
                'products' => $products,
            ]];
        }
        $workloads[$name] = $requests;
    }

    return ['templates' => $templates, 'workloads' => $workloads];
}
