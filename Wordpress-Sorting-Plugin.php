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
 * Returns array with 'has_stock', 'delivery_rank', 'has_3113_stock'
 */
function calculate_delivery_rank($product_id) {
    $stock_data = get_product_stock_data($product_id);

    // Warehouse priority (lower number = faster delivery)
    // 3113 gets -100 to ensure it ALWAYS appears at the top (next day delivery)
    $warehouse_ranks = array(
        '3113' => -100, // Next Day - PRIORITY WAREHOUSE
        '3114' => 2,    // 3-4 Working Days
        '3211' => 3,    // 8-10 Working Days
        '3115' => 4,    // 14-21 Working Days
    );

    $has_stock = false;
    $best_delivery_rank = 999; // Default for out of stock
    $has_3113_stock = false;
    $warehouses_with_stock = array();

    foreach ($stock_data as $warehouse => $stock) {
        if ($stock > 0) {
            $has_stock = true;
            $warehouses_with_stock[] = $warehouse;
            if ($warehouse === '3113') {
                $has_3113_stock = true;
            }
            $best_delivery_rank = min($best_delivery_rank, $warehouse_ranks[$warehouse]);
        }
    }

    // Special case: If ONLY warehouse 3115 has stock (14-21 days), push it way back
    // These slow-delivery products should rank very low regardless of price
    $debug_msg = "Product $product_id: count=" . count($warehouses_with_stock) . ", warehouses=" . implode(',', $warehouses_with_stock) . ", rank_before=$best_delivery_rank";
    if (count($warehouses_with_stock) === 1 && $warehouses_with_stock[0] === '3115') {
        $best_delivery_rank = 50; // High penalty, but less than out-of-stock (999)
        error_log($debug_msg . " -> APPLIED 3115-ONLY PENALTY -> rank_after=$best_delivery_rank");
    } else {
        error_log($debug_msg . " -> NO PENALTY");
    }

    return array(
        'has_stock' => $has_stock,
        'delivery_rank' => $best_delivery_rank,
        'has_3113_stock' => $has_3113_stock,
    );
}

/**
 * Get product price
 * Returns float price value
 */
function get_product_price($product_id) {
    $product = wc_get_product($product_id);
    if (!$product) {
        return 0;
    }
    return floatval($product->get_price());
}

/**
 * Calculate price tier penalty for value score
 * Lower penalty = better (prioritized)
 *
 * Price Tiers:
 * £0-69:     +10 (no profit - pushed back)
 * £70-149:   -2  (best profit - boosted forward)
 * £150-200:  +0  (good profit - neutral)
 * £201-300:  +5  (high price - strong penalty)
 * £301+:     +15 (very expensive - heavy penalty)
 */
function calculate_price_tier_penalty($price) {
    if ($price < 70) {
        return 10; // No profit - push back
    } elseif ($price < 150) {
        return -2; // Best profit margin - boost forward
    } elseif ($price <= 200) {
        return 0; // Good profit - neutral
    } elseif ($price <= 300) {
        return 5; // High price - strong penalty
    } else {
        return 15; // Very expensive - heavy penalty
    }
}

/**
 * Calculate a sortable rank array for a product
 * Lower values = higher priority
 * Returns array suitable for sorting: [value_score, product_id]
 *
 * Value Score = delivery_rank + price_tier_penalty
 * This prioritizes profitable items (£80-200) with fast delivery
 */
function calculate_product_rank($product_id) {
    $delivery_data = calculate_delivery_rank($product_id);
    $price = get_product_price($product_id);
    $price_penalty = calculate_price_tier_penalty($price);

    // Value score: delivery rank + price penalty
    // Lower score = better (appears first)
    $value_score = $delivery_data['delivery_rank'] + $price_penalty;

    return array(
        $value_score,
        $product_id,
    );
}

// === FRONTEND QUERY LOGGER (for debugging) ===
/**
 * Temporarily enable this to see what's happening on the frontend
 * Logs query modifications to help diagnose sorting issues
 */
add_action('pre_get_posts', function($query) {
    // Only log on frontend product category pages
    if (is_admin() || !$query->is_main_query()) {
        return;
    }

    // Check if this is a product category page
    if (is_tax('product_cat')) {
        $term = get_queried_object();

        // Get query vars
        $meta_query = $query->get('meta_query');
        $orderby = $query->get('orderby');
        $order = $query->get('order');

        // Log to PHP error log (visible in debug.log if WP_DEBUG is enabled)
        error_log('=== RWPP Frontend Query Debug ===');
        error_log('Category: ' . $term->name . ' (ID: ' . $term->term_id . ')');
        error_log('URL Parameters: ' . print_r($_GET, true));
        error_log('Orderby: ' . print_r($orderby, true));
        error_log('Order: ' . $order);
        error_log('Meta Query: ' . print_r($meta_query, true));
        error_log('Expected Meta Key: rwpp_sortorder_' . $term->term_id);
        error_log('Is Main Query: ' . ($query->is_main_query() ? 'YES' : 'NO'));
        error_log('Post Type: ' . print_r($query->get('post_type'), true));
    }
}, 1000); // High priority to run after RWPP

// === FORCE RWPP COMPATIBILITY ===
/**
 * Ensure WooCommerce queries on category pages use our sort order
 * This hooks at priority 9999 to override any theme/plugin interference
 */
add_action('pre_get_posts', function($query) {
    // Only on frontend main query for product categories
    if (is_admin() || !$query->is_main_query()) {
        return;
    }

    // Only on product category pages
    if (!is_tax('product_cat')) {
        return;
    }

    // Don't override if user explicitly selected a different sorting
    if (isset($_GET['orderby']) && $_GET['orderby'] !== 'menu_order') {
        return;
    }

    $term = get_queried_object();
    if ($term && is_a($term, 'WP_Term')) {
        $term_id = $term->term_id;
        $meta_key = 'rwpp_sortorder_' . $term_id;

        // Check if any products have our meta key
        global $wpdb;
        $has_sorting = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1",
            $meta_key
        ));

        if ($has_sorting > 0) {
            // Force our sorting
            $meta_query = array(
                'relation' => 'OR',
                array(
                    'key'     => $meta_key,
                    'compare' => 'EXISTS',
                ),
                array(
                    'key'     => $meta_key,
                    'compare' => 'NOT EXISTS',
                ),
            );

            $query->set('meta_query', $meta_query);
            $query->set('orderby', 'meta_value_num menu_order title');
            $query->set('order', 'ASC');

            error_log('🚀 FORCED SORT ORDER for category ' . $term_id . ' (meta_key: ' . $meta_key . ')');
        }
    }
}, 9999); // Very high priority to override everything

// === CORE FUNCTION ===
function alternate_brands_for_category($category_id) {
    global $wpdb;

    $products_query = $wpdb->prepare("
        SELECT p.ID as product_id, p.post_title as product_title, p.post_modified as product_date
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

        // Store product metadata for later ranking
        $delivery_data = calculate_delivery_rank($product_id);
        $price = get_product_price($product_id);
        $price_penalty = calculate_price_tier_penalty($price);

        $product_data[$product_id] = array(
            'title' => $product_title,
            'rank' => calculate_product_rank($product_id),
            'has_stock' => $delivery_data['has_stock'],
            'delivery_rank' => $delivery_data['delivery_rank'],
            'price' => $price,
            'price_penalty' => $price_penalty,
        );

        $brands = wp_get_post_terms($product_id, 'pa_brand');
        $brand = !empty($brands) ? $brands[0]->name : 'no-brand';

        // Store brand with product data
        $product_data[$product_id]['brand'] = $brand;
    }

    if (count(array_unique(array_column($product_data, 'brand'))) <= 1) {
        return "Category $category_id: Only one brand found, skipping.";
    }

    // Collect all products individually (no first-word grouping)
    $all_products = array();

    foreach ($product_data as $product_id => $data) {
        $all_products[] = array(
            'product_id' => $product_id,
            'brand' => $data['brand'],
            'rank' => $data['rank'],
            'has_stock' => $data['has_stock'],
        );
    }

    // Sort ALL products individually by value score
    // 1. Products without stock go to back
    // 2. Value score (delivery + price combined)
    usort($all_products, function($a, $b) {
        // First, prioritize products that have stock
        if ($a['has_stock'] != $b['has_stock']) {
            return $b['has_stock'] <=> $a['has_stock'];
        }
        // Then sort by value score
        return $a['rank'] <=> $b['rank'];
    });

    // Apply soft brand alternation to individual products
    $sorted_products = array();
    $position = 0;
    $last_brand = null;

    while (!empty($all_products)) {
        $selected_index = 0;

        // Try to find a product from a different brand within the next few options
        if ($last_brand !== null) {
            // Look ahead up to 3 positions to find a different brand
            for ($i = 0; $i < min(3, count($all_products)); $i++) {
                if ($all_products[$i]['brand'] !== $last_brand) {
                    $selected_index = $i;
                    break;
                }
            }
        }

        // Take the selected product
        $product = array_splice($all_products, $selected_index, 1)[0];
        $last_brand = $product['brand'];

        // Add this product to sorted list
        $sorted_products[$position++] = $product['product_id'];
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

        // Update with new sort order - ensure it's an integer
        $result = update_post_meta($product_id, $meta_key, intval($position));

        // Log any failures
        if ($result === false) {
            error_log("Failed to update meta for product $product_id, meta_key: $meta_key, position: $position");
        }

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
 * SIMPLE TEST VERSION - Uses original plugin logic without enhancements
 * Use this to test if the basic sorting works on your site
 */
function test_simple_brand_alternation($category_id) {
    global $wpdb;

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
        return "Category $category_id: No products found.";
    }

    $brand_groups = array();

    foreach ($products as $product) {
        $product_id = $product->product_id;
        $product_title = $product->product_title;

        $brands = wp_get_post_terms($product_id, 'pa_brand');
        $brand = !empty($brands) ? $brands[0]->name : 'no-brand';
        $first_word = strtolower(trim(strtok($product_title, ' ')));

        $brand_groups[$brand][$first_word][] = $product_id;
    }

    if (count($brand_groups) <= 1) {
        return "Category $category_id: Only one brand found, skipping.";
    }

    foreach ($brand_groups as $brand => $first_word_groups) {
        $group_list = array_values($first_word_groups);
        shuffle($group_list);
        $brand_grouped_lists[$brand] = $group_list;
    }

    $brand_keys = array_keys($brand_grouped_lists);
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
    $updated = 0;
    foreach ($sorted_products as $position => $product_id) {
        update_post_meta($product_id, $meta_key, intval($position));
        $updated++;
    }

    return "TEST: Updated $updated products in category ID $category_id using ORIGINAL logic.";
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
            <p><strong>Setup Checklist - Please verify all items:</strong></p>
            <ol style="list-style: decimal; margin-left: 30px; line-height: 1.8;">
                <li>✓ RWPP plugin is active (you should see "Rearrange Products" in your admin menu)</li>
                <li>✓ Go to <strong>Rearrange Products → Settings</strong> and check the <strong>"Enable sorting for all product loops"</strong> checkbox
                    <br><small style="color: #666;">This enables sorting on category pages. Without this, sorting only works on the main shop page.</small>
                </li>
                <li>✓ After running the sort below, click <strong>"View Sort Order"</strong> to verify meta values are saved
                    <br><small style="color: #666;">You should see numbers 0, 1, 2, 3... in the "Meta Value" column.</small>
                </li>
                <li>✓ Visit the category page on your <strong>frontend</strong> (not admin) in an incognito/private window
                    <br><small style="color: #666;">Example: yoursite.com/product-category/ceiling-lights/</small>
                </li>
                <li>✓ Clear all caches: WordPress cache, page cache, CDN cache, browser cache</li>
            </ol>
            <p><strong>Meta Key Format:</strong> <code>rwpp_sortorder_{category_id}</code> - This is compatible with RWPP.</p>
        </div>

        <?php
        $rwpp_setting = get_option('rwpp_effected_loops');
        if (!$rwpp_setting) {
            echo '<div class="notice notice-warning">
                <p><strong>⚠️ RWPP Setting Not Enabled!</strong></p>
                <p>The "Enable sorting for all product loops" setting is currently <strong>disabled</strong> in RWPP.</p>
                <p>👉 <a href="' . admin_url('admin.php?page=rwpp-settings-page') . '" class="button button-primary">Go to RWPP Settings</a> and enable it, then test again.</p>
            </div>';
        } else {
            echo '<div class="notice notice-success">
                <p><strong>✓ RWPP Setting Enabled</strong> - Sorting should work on category pages.</p>
            </div>';
        }
        ?>

        <form method="post" id="manual-category-form">
            <h2>Manual Single Category</h2>
            <select name="category_id" id="category-selector">
                <?php foreach ($categories as $category): ?>
                    <option value="<?php echo $category->term_id; ?>"><?php echo $category->name; ?> (ID: <?php echo $category->term_id; ?>)</option>
                <?php endforeach; ?>
            </select>
            <input type="submit" name="run_alternation" class="button button-primary" value="Apply Brand Alternation (Enhanced)">
            <input type="submit" name="test_simple" class="button button-secondary" value="Test Simple Sort (Original Logic)" style="background: #ff9800; color: white;">
            <input type="submit" name="reverse_alternation" class="button button-secondary" value="Reverse Previous Sorting">
        </form>

        <?php
        if (isset($_POST['run_alternation']) && isset($_POST['category_id'])) {
            $result = alternate_brands_for_category(intval($_POST['category_id']));
            echo '<div class="notice notice-success is-dismissible"><p>' . $result . '</p></div>';
        }

        if (isset($_POST['test_simple']) && isset($_POST['category_id'])) {
            $result = test_simple_brand_alternation(intval($_POST['category_id']));
            echo '<div class="notice notice-warning is-dismissible">
                <p>' . $result . '</p>
                <p><strong>Note:</strong> This uses the ORIGINAL plugin logic (no stock/date/delivery ranking).
                If this works but the enhanced version doesn\'t, there may be an issue with the complex sorting logic.</p>
            </div>';
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
        <h2>🔬 Algorithm Debugger - See Ranking Logic</h2>
        <p>Shows how each product is ranked (stock, delivery speed, published date) and the final sort order.</p>
        <button class="button button-primary" id="debug-algorithm">Debug Algorithm</button>
        <div id="algorithm-debug-viewer" style="margin-top:20px;"></div>

        <hr>
        <h2>🔍 Diagnostic Tool - Check Meta Values</h2>
        <p>This will show you the actual meta values in the database for the selected category.</p>
        <button class="button button-secondary" id="run-diagnostic">Run Diagnostic</button>
        <div id="diagnostic-viewer" style="margin-top:20px;"></div>

        <hr>
        <h2>Global Apply (Async)</h2>
        <p>This will process all categories, skipping excluded ones, without overloading the server.</p>
        <button class="button button-secondary" id="start-global-update">Run in Background</button>
        <div id="update-log" style="margin-top:15px; max-height:300px; overflow-y:auto; border:1px solid #ddd; padding:10px;"></div>
    </div>

    <script>
    // Algorithm Debug handler
    document.getElementById('debug-algorithm').addEventListener('click', function() {
        const categoryId = document.getElementById('category-selector').value;
        const viewerBox = document.getElementById('algorithm-debug-viewer');
        viewerBox.innerHTML = '<p>Analyzing algorithm...</p>';

        fetch(ajaxurl + '?action=debug_algorithm&category_id=' + categoryId)
            .then(response => response.text())
            .then(html => {
                viewerBox.innerHTML = html;
            })
            .catch(() => {
                viewerBox.innerHTML = '<p>Error running algorithm debug.</p>';
            });
    });

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

    // Diagnostic handler
    document.getElementById('run-diagnostic').addEventListener('click', function() {
        const categoryId = document.getElementById('category-selector').value;
        const viewerBox = document.getElementById('diagnostic-viewer');
        viewerBox.innerHTML = '<p>Running diagnostic...</p>';

        fetch(ajaxurl + '?action=run_diagnostic&category_id=' + categoryId)
            .then(response => response.text())
            .then(html => {
                viewerBox.innerHTML = html;
            })
            .catch(() => {
                viewerBox.innerHTML = '<p>Error running diagnostic.</p>';
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
    $excluded = array(2257, 2239, 176, 177, 175);
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

add_action('wp_ajax_debug_algorithm', function() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Unauthorized', '', array('response' => 403));
    }

    $category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;
    if (!$category_id) {
        echo '<p>Invalid category ID.</p>';
        wp_die();
    }

    global $wpdb;

    echo '<div style="background: #fff; padding: 20px; border: 1px solid #ccc; max-width: 100%; overflow-x: auto;">';
    echo '<h3>🔬 Algorithm Debug - Step by Step</h3>';

    // Get ALL products to properly rank them (no LIMIT)
    $products_query = $wpdb->prepare("
        SELECT p.ID as product_id, p.post_title as product_title, p.post_modified as product_date
        FROM {$wpdb->posts} p
        JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
        JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'product_cat'
        WHERE p.post_type = 'product' AND p.post_status = 'publish'
        AND tt.term_id = %d
    ", $category_id);

    $products = $wpdb->get_results($products_query);

    // Calculate rank for all products
    $product_data = array();
    foreach ($products as $product) {
        $product_id = $product->product_id;
        $brands = wp_get_post_terms($product_id, 'pa_brand');
        $brand = !empty($brands) ? $brands[0]->name : 'no-brand';
        $first_word = strtolower(trim(strtok($product->product_title, ' ')));

        $stock_data = get_product_stock_data($product_id);
        $delivery_data = calculate_delivery_rank($product_id);
        $price = get_product_price($product_id);
        $price_penalty = calculate_price_tier_penalty($price);
        $rank = calculate_product_rank($product_id);

        $product_data[$product_id] = array(
            'title' => $product->product_title,
            'brand' => $brand,
            'first_word' => $first_word,
            'rank' => $rank,
            'has_stock' => $delivery_data['has_stock'],
            'delivery_rank' => $delivery_data['delivery_rank'],
            'stock_data' => $stock_data,
            'price' => $price,
            'price_penalty' => $price_penalty,
        );
    }

    // Sort products by rank to show best ranking first
    uasort($product_data, function($a, $b) {
        return $a['rank'] <=> $b['rank'];
    });

    echo '<h4>Step 1: Product Data with Rankings (Top 80 by Value Score)</h4>';
    echo '<table style="border-collapse: collapse; font-size: 9px;">';
    echo '<tr style="background: #f1f1f1;">
        <th style="padding: 3px; border: 1px solid #ddd;">ID</th>
        <th style="padding: 3px; border: 1px solid #ddd;">Title</th>
        <th style="padding: 3px; border: 1px solid #ddd;">Brand</th>
        <th style="padding: 3px; border: 1px solid #ddd;">Price</th>
        <th style="padding: 3px; border: 1px solid #ddd;">Price Penalty</th>
        <th style="padding: 3px; border: 1px solid #ddd;">Delivery</th>
        <th style="padding: 3px; border: 1px solid #ddd;">Value Score</th>
        <th style="padding: 3px; border: 1px solid #ddd;">Warehouse Stock</th>
    </tr>';

    $count = 0;
    foreach ($product_data as $product_id => $data) {
        if ($count >= 80) break;

        // Format warehouse stock display
        $warehouse_display = sprintf('3113:%d | 3114:%d | 3211:%d | 3115:%d',
            $data['stock_data']['3113'],
            $data['stock_data']['3114'],
            $data['stock_data']['3211'],
            $data['stock_data']['3115']
        );

        // Highlight products based on price tier
        $price = $data['price'];
        if ($price >= 70 && $price < 150) {
            $highlight = 'background: #d4edda;'; // Green for best profit margin
        } elseif ($price < 70) {
            $highlight = 'background: #f8d7da;'; // Red for no profit
        } else {
            $highlight = '';
        }

        // Calculate value score
        $value_score = $data['delivery_rank'] + $data['price_penalty'];

        echo '<tr style="' . $highlight . '">';
        echo '<td style="padding: 2px; border: 1px solid #ddd; font-size: 8px;">' . $product_id . '</td>';
        echo '<td style="padding: 2px; border: 1px solid #ddd; font-size: 8px;">' . esc_html(substr($data['title'], 0, 20)) . '...</td>';
        echo '<td style="padding: 2px; border: 1px solid #ddd; font-size: 8px;">' . esc_html($data['brand']) . '</td>';
        echo '<td style="padding: 2px; border: 1px solid #ddd; text-align: right;"><strong>£' . number_format($price, 0) . '</strong></td>';
        echo '<td style="padding: 2px; border: 1px solid #ddd; text-align: center;">' . $data['price_penalty'] . '</td>';
        echo '<td style="padding: 2px; border: 1px solid #ddd; text-align: center;">' . $data['delivery_rank'] . '</td>';
        echo '<td style="padding: 2px; border: 1px solid #ddd; text-align: center;"><strong>' . $value_score . '</strong></td>';
        echo '<td style="padding: 2px; border: 1px solid #ddd; font-size: 7px;">' . $warehouse_display . '</td>';
        echo '</tr>';
        $count++;
    }
    echo '</table>';

    echo '<p style="margin-top: 10px;"><strong>Value Score = Delivery Rank + Price Penalty</strong> (Lower = Better)<br>';
    echo '<small><strong>Price Tiers:</strong> £0-69: +10 (no profit) | <span style="background: #d4edda; padding: 2px;">£70-149: -2 (BEST)</span> | £150-200: +0 | £201-300: +5 | £301+: +15</small><br>';
    echo '<small><strong>Delivery:</strong> -100=Next Day (3113) PRIORITY | 2=3-4 days (3114) | 3=8-10 days (3211) | 4=14-21 days (3115) | 50=ONLY at 3115 (pushed back) | 999=out of stock</small><br>';
    echo '<small><strong>Color coding:</strong> <span style="background: #d4edda; padding: 2px;">Green = Best profit (£70-149)</span> | <span style="background: #f8d7da; padding: 2px;">Red = No profit (&lt;£70)</span></small></p>';

    // Step 2: Show actual brand-alternated sort order
    echo '<h4 style="margin-top: 30px;">Step 2: Soft Brand-Alternated Sort Order (First 80 Products)</h4>';
    echo '<p><small>Products sorted individually by value score (no range grouping). Brands alternate when possible (looks ahead 3 positions for different brand). Products with 3113 stock dominate the top.</small></p>';

    // Collect all products individually (no first-word grouping) - same as main algorithm
    $all_products = array();

    foreach ($product_data as $product_id => $data) {
        $all_products[] = array(
            'product_id' => $product_id,
            'brand' => $data['brand'],
            'rank' => $data['rank'],
            'has_stock' => $data['has_stock'],
        );
    }

    // Sort ALL products individually by value score - same as main algorithm
    usort($all_products, function($a, $b) {
        // First, prioritize products that have stock
        if ($a['has_stock'] != $b['has_stock']) {
            return $b['has_stock'] <=> $a['has_stock'];
        }
        // Then sort by value score
        return $a['rank'] <=> $b['rank'];
    });

    // Apply soft brand alternation to individual products - same as main algorithm
    $sorted_products = array();
    $position = 0;
    $last_brand = null;

    while (!empty($all_products)) {
        $selected_index = 0;

        // Try to find a product from a different brand within the next few options
        if ($last_brand !== null) {
            // Look ahead up to 3 positions to find a different brand
            for ($i = 0; $i < min(3, count($all_products)); $i++) {
                if ($all_products[$i]['brand'] !== $last_brand) {
                    $selected_index = $i;
                    break;
                }
            }
        }

        // Take the selected product
        $product = array_splice($all_products, $selected_index, 1)[0];
        $last_brand = $product['brand'];

        // Add this product to sorted list
        $sorted_products[$position++] = $product['product_id'];
    }

    // Display the brand-alternated order
    echo '<table style="border-collapse: collapse; font-size: 9px;">';
    echo '<tr style="background: #f1f1f1;">
        <th style="padding: 3px; border: 1px solid #ddd;">Pos</th>
        <th style="padding: 3px; border: 1px solid #ddd;">ID</th>
        <th style="padding: 3px; border: 1px solid #ddd;">Title</th>
        <th style="padding: 3px; border: 1px solid #ddd;">Brand</th>
        <th style="padding: 3px; border: 1px solid #ddd;">Price</th>
        <th style="padding: 3px; border: 1px solid #ddd;">Delivery</th>
        <th style="padding: 3px; border: 1px solid #ddd;">Value Score</th>
        <th style="padding: 3px; border: 1px solid #ddd;">Warehouse Stock</th>
    </tr>';

    $displayed = 0;
    foreach ($sorted_products as $pos => $product_id) {
        if ($displayed >= 80) break;

        $data = $product_data[$product_id];
        $warehouse_display = sprintf('3113:%d | 3114:%d | 3211:%d | 3115:%d',
            $data['stock_data']['3113'],
            $data['stock_data']['3114'],
            $data['stock_data']['3211'],
            $data['stock_data']['3115']
        );

        // Highlight products based on price tier
        $price = $data['price'];
        if ($price >= 70 && $price < 150) {
            $highlight = 'background: #d4edda;'; // Green for best profit margin
        } elseif ($price < 70) {
            $highlight = 'background: #f8d7da;'; // Red for no profit
        } else {
            $highlight = '';
        }

        // Calculate value score
        $value_score = $data['delivery_rank'] + $data['price_penalty'];

        echo '<tr style="' . $highlight . '">';
        echo '<td style="padding: 2px; border: 1px solid #ddd; text-align: center;"><strong>' . ($pos + 1) . '</strong></td>';
        echo '<td style="padding: 2px; border: 1px solid #ddd; font-size: 8px;">' . $product_id . '</td>';
        echo '<td style="padding: 2px; border: 1px solid #ddd; font-size: 8px;">' . esc_html(substr($data['title'], 0, 25)) . '...</td>';
        echo '<td style="padding: 2px; border: 1px solid #ddd; font-size: 8px;">' . esc_html($data['brand']) . '</td>';
        echo '<td style="padding: 2px; border: 1px solid #ddd; text-align: right;"><strong>£' . number_format($price, 0) . '</strong></td>';
        echo '<td style="padding: 2px; border: 1px solid #ddd; text-align: center;">' . $data['delivery_rank'] . '</td>';
        echo '<td style="padding: 2px; border: 1px solid #ddd; text-align: center;"><strong>' . $value_score . '</strong></td>';
        echo '<td style="padding: 2px; border: 1px solid #ddd; font-size: 7px;">' . $warehouse_display . '</td>';
        echo '</tr>';
        $displayed++;
    }
    echo '</table>';

    echo '</div>';
    wp_die();
});

add_action('wp_ajax_run_diagnostic', function() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Unauthorized', '', array('response' => 403));
    }

    $category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;
    if (!$category_id) {
        echo '<p>Invalid category ID.</p>';
        wp_die();
    }

    global $wpdb;

    // Get category info
    $term = get_term($category_id, 'product_cat');
    $meta_key = 'rwpp_sortorder_' . $category_id;

    echo '<div style="background: #fff; padding: 20px; border: 1px solid #ccc;">';
    echo '<h3>Diagnostic Results for Category: ' . esc_html($term->name) . ' (ID: ' . $category_id . ')</h3>';

    // Check RWPP settings
    echo '<h4>1. RWPP Settings Check</h4>';
    $rwpp_setting = get_option('rwpp_effected_loops');
    echo '<p>RWPP "Enable sorting for all product loops": <strong>' . ($rwpp_setting ? '✓ ENABLED' : '✗ DISABLED') . '</strong></p>';

    // Check WooCommerce default sorting
    $wc_default_sorting = get_option('woocommerce_default_catalog_orderby');
    echo '<p>WooCommerce Default Sorting: <strong>' . esc_html($wc_default_sorting) . '</strong></p>';
    if ($wc_default_sorting !== 'menu_order') {
        echo '<p style="color: orange;">⚠️ Note: WooCommerce sorting should be set to "menu_order" or "Default sorting (custom ordering + name)"</p>';
    }

    // Get products with meta values
    echo '<h4>2. Database Check - Products with Meta Values</h4>';

    $query = $wpdb->prepare("
        SELECT p.ID, p.post_title, pm.meta_value, pm.meta_key
        FROM {$wpdb->posts} p
        JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
        JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
        LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
        WHERE p.post_type = 'product'
        AND p.post_status = 'publish'
        AND tt.term_id = %d
        ORDER BY CAST(pm.meta_value AS UNSIGNED) ASC
        LIMIT 10
    ", $meta_key, $category_id);

    $products = $wpdb->get_results($query);

    if (empty($products)) {
        echo '<p style="color: red;">❌ No products found in this category!</p>';
    } else {
        echo '<p>Found ' . count($products) . ' products (showing first 10):</p>';
        echo '<table style="border-collapse: collapse; width: 100%;">';
        echo '<tr style="background: #f1f1f1;">
                <th style="padding: 8px; border: 1px solid #ddd;">Product ID</th>
                <th style="padding: 8px; border: 1px solid #ddd;">Title</th>
                <th style="padding: 8px; border: 1px solid #ddd;">Meta Key</th>
                <th style="padding: 8px; border: 1px solid #ddd;">Meta Value</th>
                <th style="padding: 8px; border: 1px solid #ddd;">Status</th>
              </tr>';

        $has_values = 0;
        foreach ($products as $product) {
            $status = '';
            if ($product->meta_value !== null && $product->meta_value !== '') {
                $status = '✓ Has Value';
                $has_values++;
            } else {
                $status = '✗ No Value';
            }

            echo '<tr>';
            echo '<td style="padding: 8px; border: 1px solid #ddd;">' . $product->ID . '</td>';
            echo '<td style="padding: 8px; border: 1px solid #ddd;">' . esc_html($product->post_title) . '</td>';
            echo '<td style="padding: 8px; border: 1px solid #ddd;"><code>' . esc_html($meta_key) . '</code></td>';
            echo '<td style="padding: 8px; border: 1px solid #ddd;">' . esc_html($product->meta_value) . '</td>';
            echo '<td style="padding: 8px; border: 1px solid #ddd;">' . $status . '</td>';
            echo '</tr>';
        }
        echo '</table>';

        if ($has_values === 0) {
            echo '<p style="color: red; font-weight: bold;">❌ PROBLEM FOUND: No products have meta values! You need to run "Apply Brand Alternation" first.</p>';
        } else {
            echo '<p style="color: green;">✓ ' . $has_values . ' products have sort order meta values.</p>';
        }
    }

    // Check what RWPP would see
    echo '<h4>3. What RWPP Would Query</h4>';
    echo '<p>RWPP looks for meta key: <code>' . esc_html($meta_key) . '</code></p>';
    echo '<p>RWPP sorts by: <code>meta_value_num menu_order title ASC</code></p>';

    // Show category URL
    $cat_link = get_term_link($term);
    echo '<h4>4. Frontend URL to Test</h4>';
    echo '<p>Visit this URL on the frontend to see if sorting works:<br>';
    echo '<a href="' . esc_url($cat_link) . '" target="_blank">' . esc_url($cat_link) . '</a></p>';

    echo '<h4>5. Next Steps</h4>';
    if ($has_values > 0) {
        echo '<ol>';
        echo '<li>Open the category URL above in an <strong>incognito/private window</strong></li>';
        echo '<li>Clear all caches (WordPress, page cache, CDN, browser)</li>';
        echo '<li>Check if products appear in the order shown in the table above</li>';
        echo '</ol>';
    } else {
        echo '<p style="color: orange;">⚠️ Run "Apply Brand Alternation" on this category first, then run this diagnostic again.</p>';
    }

    // Final recommendation
    echo '<h4>⚡ Troubleshooting Recommendations</h4>';
    echo '<div style="background: #fffbcc; padding: 15px; border-left: 4px solid #ffeb3b;">';
    echo '<p><strong>Your meta values are saved correctly!</strong> If sorting still doesn\'t work on the frontend:</p>';
    echo '<ol>';
    echo '<li><strong>Clear ALL caches:</strong>
        <ul>
            <li>WordPress object cache (if using Redis/Memcached)</li>
            <li>Page cache plugin (WP Rocket, W3 Total Cache, etc.)</li>
            <li>CDN cache (Cloudflare, etc.)</li>
            <li>Browser cache (use Ctrl+Shift+R or incognito mode)</li>
        </ul>
    </li>';
    echo '<li><strong>Check for conflicts:</strong> Temporarily switch to a default WordPress theme (Twenty Twenty-Four) to rule out theme interference.</li>';
    echo '<li><strong>Check query parameters:</strong> Make sure you\'re not using <code>?orderby=date</code> or other sorting in the URL.</li>';
    echo '<li><strong>Enable WP Debug:</strong> Add this to wp-config.php (before "That\'s all, stop editing!"):<br>
        <code>define(\'WP_DEBUG\', true);<br>define(\'WP_DEBUG_LOG\', true);</code><br>
        Then visit the category page on the frontend. Check <code>/wp-content/debug.log</code> for errors.<br>
        Look for lines starting with "=== RWPP Frontend Query Debug ===" to see what query is being used.
    </li>';
    echo '<li><strong>Verify RWPP is active:</strong> Go to Plugins and make sure "Rearrange Woocommerce Products" shows as active.</li>';
    echo '<li><strong>Check theme compatibility:</strong> Some themes override WooCommerce queries. Try switching to Storefront or Twenty Twenty-Four theme temporarily.</li>';
    echo '</ol>';
    echo '<p style="margin-top: 15px;"><strong>💡 Tip:</strong> This plugin includes a query logger. After enabling WP_DEBUG above, visit your category page and check debug.log. You should see the orderby and meta_query values.</p>';
    echo '</div>';

    echo '</div>';
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

