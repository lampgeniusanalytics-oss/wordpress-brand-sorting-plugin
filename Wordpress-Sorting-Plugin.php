<?php
/**
 * Plugin extension: Alternate products by brand, with first-word grouping
 * Now supports global async updates with a progress log.
 * Enhanced with stock availability, delivery speed, and newness prioritization.
 */

// === HELPER FUNCTIONS ===

/**
 * Get stock data for all warehouses for a product
 * Returns an associative array of warehouse_id => stock_quantity
 */
function get_product_stock_data($product_id) {
    return array(
        '3113' => intval(get_post_meta($product_id, '_stock_at_3113', true)),
        '3114' => intval(get_post_meta($product_id, '_stock_at_3114', true)),
        '3211' => intval(get_post_meta($product_id, '_stock_at_3211', true)),
        '3115' => intval(get_post_meta($product_id, '_stock_at_3115', true)),
    );
}

/**
 * Calculate delivery rank for a product based on warehouse stock
 * Lower rank = faster delivery
 * Returns array with 'has_stock', 'delivery_rank'
 */
function calculate_delivery_rank($product_id) {
    $stock_data = get_product_stock_data($product_id);

    // Warehouse priority (lower number = faster delivery)
    $warehouse_ranks = array(
        '3113' => 1, // 1-2 Working Days
        '3114' => 2, // 3-4 Working Days
        '3211' => 3, // 8-10 Working Days
        '3115' => 4, // 14-21 Working Days
    );

    $has_stock = false;
    $best_delivery_rank = 999; // Default for out of stock

    foreach ($stock_data as $warehouse => $stock) {
        if ($stock > 0) {
            $has_stock = true;
            $best_delivery_rank = min($best_delivery_rank, $warehouse_ranks[$warehouse]);
        }
    }

    return array(
        'has_stock' => $has_stock,
        'delivery_rank' => $best_delivery_rank,
    );
}

/**
 * Calculate a sortable rank array for a product
 * Lower values = higher priority
 * Returns array suitable for sorting: [has_stock, delivery_rank, -timestamp, product_id]
 */
function calculate_product_rank($product_id, $product_date) {
    $delivery_data = calculate_delivery_rank($product_id);

    // Convert to sortable format:
    // 1. Stock availability (0 = has stock, 1 = no stock)
    // 2. Delivery rank (1-4 for in-stock, 999 for out of stock)
    // 3. Negative timestamp (newer = higher priority, so we negate for sorting)
    // 4. Product ID (for stable sort)

    return array(
        $delivery_data['has_stock'] ? 0 : 1,
        $delivery_data['delivery_rank'],
        -strtotime($product_date),
        $product_id,
    );
}

// === CORE FUNCTION ===
function alternate_brands_for_category($category_id) {
    global $wpdb;

    $products_query = $wpdb->prepare("
        SELECT p.ID as product_id, p.post_title as product_title, p.post_date as product_date
        FROM {$wpdb->posts} p
        JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
        JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'product_cat'
        WHERE p.post_type = 'product' AND p.post_status = 'publish'
        AND tt.term_id = %d
    ", $category_id);

    $products = $wpdb->get_results($products_query);

    if (empty($products)) {
        return "Category $category_id: No products found.";
    }

    $brand_groups = array();
    $product_data = array();

    foreach ($products as $product) {
        $product_id = $product->product_id;
        $product_title = $product->product_title;
        $product_date = $product->product_date;

        // Store product metadata for later ranking
        $product_data[$product_id] = array(
            'title' => $product_title,
            'date' => $product_date,
            'rank' => calculate_product_rank($product_id, $product_date),
        );

        $brands = wp_get_post_terms($product_id, 'pa_brand');
        $brand = !empty($brands) ? $brands[0]->name : 'no-brand';
        $first_word = strtolower(trim(strtok($product_title, ' ')));

        $brand_groups[$brand][$first_word][] = $product_id;
    }

    if (count($brand_groups) <= 1) {
        return "Category $category_id: Only one brand found, skipping.";
    }

    // Sort products within each first-word group by rank
    foreach ($brand_groups as $brand => $first_word_groups) {
        $sorted_groups = array();

        foreach ($first_word_groups as $first_word => $product_ids) {
            // Sort products in this group by their rank
            usort($product_ids, function($a, $b) use ($product_data) {
                return $product_data[$a]['rank'] <=> $product_data[$b]['rank'];
            });

            $sorted_groups[] = $product_ids;
        }

        // Sort groups by the rank of their best (first) product
        usort($sorted_groups, function($a, $b) use ($product_data) {
            return $product_data[$a[0]]['rank'] <=> $product_data[$b[0]]['rank'];
        });

        $brand_grouped_lists[$brand] = $sorted_groups;
    }

    // Rank brands by their best product (first product in first group)
    $brand_keys = array_keys($brand_grouped_lists);
    usort($brand_keys, function($a, $b) use ($brand_grouped_lists, $product_data) {
        $best_product_a = $brand_grouped_lists[$a][0][0];
        $best_product_b = $brand_grouped_lists[$b][0][0];
        return $product_data[$best_product_a]['rank'] <=> $product_data[$best_product_b]['rank'];
    });

    $sorted_products = array();
    $position = 0;
    $still_going = true;

    while ($still_going) {
        $still_going = false;
        foreach ($brand_keys as $brand) {
            if (!empty($brand_grouped_lists[$brand])) {
                $group = array_shift($brand_grouped_lists[$brand]);
                foreach ($group as $product_id) {
                    $sorted_products[$position++] = $product_id;
                }
                $still_going = true;
            }
        }
    }

    $meta_key = 'rwpp_sortorder_' . $category_id;
    $meta_key_prev = 'rwpp_sortorder_' . $category_id . '_prev';
    $updated = 0;

    foreach ($sorted_products as $position => $product_id) {
        // Backup current value before updating
        $current_value = get_post_meta($product_id, $meta_key, true);
        if ($current_value !== '') {
            update_post_meta($product_id, $meta_key_prev, $current_value);
        }

        // Update with new sort order
        update_post_meta($product_id, $meta_key, $position);
        $updated++;
    }

    // Clear any RWPP caches
    delete_transient('rwpp_sortorder_cache_' . $category_id);

    // Clear WooCommerce product query cache
    wp_cache_delete('product_cat_' . $category_id, 'terms');
    if (function_exists('wc_delete_product_transients')) {
        wc_delete_product_transients();
    }

    // Get category name for better messaging
    $term = get_term($category_id, 'product_cat');
    $cat_name = $term ? $term->name : "ID $category_id";

    return "✓ Updated $updated products in category '$cat_name' (ID: $category_id). Meta key: {$meta_key}";
}

/**
 * Reverse the sort order for a category by swapping current and previous values
 */
function reverse_category_sorting($category_id) {
    global $wpdb;

    // Get all products in this category
    $products_query = $wpdb->prepare("
        SELECT p.ID as product_id
        FROM {$wpdb->posts} p
        JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
        JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'product_cat'
        WHERE p.post_type = 'product' AND p.post_status = 'publish'
        AND tt.term_id = %d
    ", $category_id);

    $products = $wpdb->get_results($products_query);

    if (empty($products)) {
        return "Category $category_id: No products found.";
    }

    $meta_key = 'rwpp_sortorder_' . $category_id;
    $meta_key_prev = 'rwpp_sortorder_' . $category_id . '_prev';
    $swapped = 0;

    foreach ($products as $product) {
        $product_id = $product->product_id;
        $current_value = get_post_meta($product_id, $meta_key, true);
        $prev_value = get_post_meta($product_id, $meta_key_prev, true);

        if ($prev_value !== '') {
            // Swap the values
            update_post_meta($product_id, $meta_key, $prev_value);
            update_post_meta($product_id, $meta_key_prev, $current_value);
            $swapped++;
        }
    }

    // Clear any RWPP caches
    delete_transient('rwpp_sortorder_cache_' . $category_id);

    // Clear WooCommerce product query cache
    wp_cache_delete('product_cat_' . $category_id, 'terms');
    if (function_exists('wc_delete_product_transients')) {
        wc_delete_product_transients();
    }

    // Get category name for better messaging
    $term = get_term($category_id, 'product_cat');
    $cat_name = $term ? $term->name : "ID $category_id";

    return "✓ Reversed sorting for $swapped products in category '$cat_name' (ID: $category_id).";
}

// === ADMIN PAGE + AJAX SETUP ===
add_action('admin_menu', function() {
    add_submenu_page(
        'rwpp-page',
        'Alternate Brands',
        'Alternate Brands',
        'manage_woocommerce',
        'alternate-brands',
        'alternate_brands_page'
    );
});

function alternate_brands_page() {
    $categories = get_terms(array(
        'taxonomy' => 'product_cat',
        'hide_empty' => false,
    ));
    ?>
    <div class="wrap">
        <h1>Alternate Products by Brand</h1>

        <div class="notice notice-info">
            <p><strong>Important Setup Requirements:</strong></p>
            <ul style="list-style: disc; margin-left: 20px;">
                <li>This plugin requires the <strong>Rearrange WooCommerce Products Plugin (RWPP)</strong> to display the sort order on the front-end.</li>
                <li>RWPP must be configured to use <strong>custom sort order</strong> for product categories.</li>
                <li>After running the sort, check the "View Sort Order" section below to verify meta keys are saved.</li>
                <li>The meta key format is: <code>rwpp_sortorder_{category_id}</code></li>
                <li>If changes don't appear, try clearing your site cache and RWPP cache.</li>
            </ul>
        </div>

        <form method="post" id="manual-category-form">
            <h2>Manual Single Category</h2>
            <select name="category_id" id="category-selector">
                <?php foreach ($categories as $category): ?>
                    <option value="<?php echo $category->term_id; ?>"><?php echo $category->name; ?> (ID: <?php echo $category->term_id; ?>)</option>
                <?php endforeach; ?>
            </select>
            <input type="submit" name="run_alternation" class="button button-primary" value="Apply Brand Alternation">
            <input type="submit" name="reverse_alternation" class="button button-secondary" value="Reverse Previous Sorting">
        </form>

        <?php
        if (isset($_POST['run_alternation']) && isset($_POST['category_id'])) {
            $result = alternate_brands_for_category(intval($_POST['category_id']));
            echo '<div class="notice notice-success is-dismissible"><p>' . $result . '</p></div>';
        }

        if (isset($_POST['reverse_alternation']) && isset($_POST['category_id'])) {
            $result = reverse_category_sorting(intval($_POST['category_id']));
            echo '<div class="notice notice-success is-dismissible"><p>' . $result . '</p></div>';
        }
        ?>

        <hr>
        <h2>View Sort Order</h2>
        <p>View the current sorted order for the selected category with product images and titles.</p>
        <button class="button button-secondary" id="view-sort-order">View Sort Order</button>
        <div id="sort-order-viewer" style="margin-top:20px;"></div>

        <hr>
        <h2>Global Apply (Async)</h2>
        <p>This will process all categories, skipping excluded ones, without overloading the server.</p>
        <button class="button button-secondary" id="start-global-update">Run in Background</button>
        <div id="update-log" style="margin-top:15px; max-height:300px; overflow-y:auto; border:1px solid #ddd; padding:10px;"></div>
    </div>

    <script>
    // View Sort Order handler
    document.getElementById('view-sort-order').addEventListener('click', function() {
        const categoryId = document.getElementById('category-selector').value;
        const viewerBox = document.getElementById('sort-order-viewer');
        viewerBox.innerHTML = '<p>Loading...</p>';

        fetch(ajaxurl + '?action=view_sort_order&category_id=' + categoryId)
            .then(response => response.text())
            .then(html => {
                viewerBox.innerHTML = html;
            })
            .catch(() => {
                viewerBox.innerHTML = '<p>Error loading sort order.</p>';
            });
    });

    // Global update handler
    document.getElementById('start-global-update').addEventListener('click', function() {
        const logBox = document.getElementById('update-log');
        logBox.innerHTML = '<p>Preparing queue...</p>';

        fetch(ajaxurl + '?action=prepare_global_queue')
            .then(response => response.json())
            .then(data => {
                if (!data.queue || data.queue.length === 0) {
                    logBox.innerHTML += '<p>No categories to process.</p>';
                    return;
                }

                const queue = data.queue;
                let index = 0;

                function processNext() {
                    if (index >= queue.length) {
                        logBox.innerHTML += '<p><strong>✅ All done!</strong></p>';
                        return;
                    }

                    const catId = queue[index];
                    fetch(ajaxurl + '?action=process_single_category&category_id=' + catId)
                        .then(response => response.text())
                        .then(result => {
                            logBox.innerHTML += '<p>' + result + '</p>';
                            index++;
                            processNext();
                        })
                        .catch(() => {
                            logBox.innerHTML += '<p>⚠️ Error on category ' + catId + '</p>';
                            index++;
                            processNext();
                        });
                }

                processNext();
            });
    });
    </script>
    <?php
}

// === AJAX HANDLERS ===

add_action('wp_ajax_prepare_global_queue', function() {
    $excluded = array(2239, 176, 177, 175);
    $categories = get_terms(array(
        'taxonomy' => 'product_cat',
        'hide_empty' => false,
        'fields' => 'ids',
    ));

    $queue = array_filter($categories, function($id) use ($excluded) {
        return !in_array($id, $excluded);
    });

    wp_send_json(array('queue' => array_values($queue)));
});

add_action('wp_ajax_process_single_category', function() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Unauthorized', '', array('response' => 403));
    }

    $category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;
    if (!$category_id) {
        echo 'Invalid category ID.';
        wp_die();
    }

    $message = alternate_brands_for_category($category_id);
    echo esc_html($message);
    wp_die();
});

add_action('wp_ajax_view_sort_order', function() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Unauthorized', '', array('response' => 403));
    }

    $category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;
    if (!$category_id) {
        echo '<p>Invalid category ID.</p>';
        wp_die();
    }

    global $wpdb;

    // Get all products in this category
    $products_query = $wpdb->prepare("
        SELECT p.ID as product_id, p.post_title as product_title
        FROM {$wpdb->posts} p
        JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
        JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'product_cat'
        WHERE p.post_type = 'product' AND p.post_status = 'publish'
        AND tt.term_id = %d
    ", $category_id);

    $products = $wpdb->get_results($products_query);

    if (empty($products)) {
        echo '<p>No products found in this category.</p>';
        wp_die();
    }

    $meta_key = 'rwpp_sortorder_' . $category_id;

    // Build array of products with their sort order
    $sorted_products = array();
    foreach ($products as $product) {
        $product_id = $product->product_id;
        $sort_order = get_post_meta($product_id, $meta_key, true);

        if ($sort_order === '') {
            $sort_order = 999999; // Put unsorted products at the end
        }

        // Get brand info for debugging
        $brands = wp_get_post_terms($product_id, 'pa_brand');
        $brand_name = !empty($brands) ? $brands[0]->name : 'No Brand';

        $sorted_products[] = array(
            'id' => $product_id,
            'title' => $product->product_title,
            'sort_order' => intval($sort_order),
            'thumbnail' => get_the_post_thumbnail_url($product_id, 'thumbnail'),
            'brand' => $brand_name,
            'meta_value' => $sort_order, // Raw meta value
        );
    }

    // Sort by sort_order
    usort($sorted_products, function($a, $b) {
        return $a['sort_order'] - $b['sort_order'];
    });

    // Output HTML table
    echo '<style>
        .sort-order-table { width: 100%; border-collapse: collapse; }
        .sort-order-table th, .sort-order-table td { padding: 10px; border: 1px solid #ddd; text-align: left; }
        .sort-order-table th { background-color: #f1f1f1; font-weight: bold; }
        .sort-order-table img { max-width: 50px; height: auto; }
        .sort-order-table tr:nth-child(even) { background-color: #f9f9f9; }
    </style>';

    echo '<p><strong>Debug Info:</strong> Meta key used: <code>' . esc_html($meta_key) . '</code></p>';
    echo '<table class="sort-order-table">';
    echo '<thead><tr><th>Position</th><th>Image</th><th>Product Title</th><th>Brand</th><th>Product ID</th><th>Meta Value</th></tr></thead>';
    echo '<tbody>';

    foreach ($sorted_products as $index => $product) {
        $position = $index + 1;
        $thumbnail = $product['thumbnail'] ? '<img src="' . esc_url($product['thumbnail']) . '" alt="Product Image">' : 'No Image';

        echo '<tr>';
        echo '<td>' . $position . '</td>';
        echo '<td>' . $thumbnail . '</td>';
        echo '<td>' . esc_html($product['title']) . '</td>';
        echo '<td>' . esc_html($product['brand']) . '</td>';
        echo '<td>' . $product['id'] . '</td>';
        echo '<td>' . esc_html($product['meta_value']) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    wp_die();
});

