<?php

use App\Constants\ProductType;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

echo '=== ProductType::visibleValues() ==='.PHP_EOL;
var_dump(ProductType::visibleValues());
echo PHP_EOL;

echo '=== ProductType::visibleItems() ==='.PHP_EOL;
var_dump(ProductType::visibleItems());
echo PHP_EOL;

echo '=== first_product_groups ==='.PHP_EOL;
$firstGroups = DB::table('first_product_groups')->get();
echo 'Count: '.count($firstGroups).PHP_EOL;
foreach ($firstGroups as $g) {
    echo json_encode($g, JSON_UNESCAPED_UNICODE).PHP_EOL;
}
echo PHP_EOL;

echo 'second_product_groups: '.DB::table('second_product_groups')->count().PHP_EOL;
echo 'products on_sale: '.DB::table('products')->where('lifecycle_status', 'on_sale')->count().PHP_EOL;
echo 'products total: '.DB::table('products')->count().PHP_EOL;
