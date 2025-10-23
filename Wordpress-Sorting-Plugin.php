/**
 * Plugin extension: Alternate products by brand, with first-word grouping
 * Now supports global async updates with a progress log.
 */

// === CORE FUNCTION ===
function alternate_brands_for_category($category_id) {
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
    $product_names = array();

    foreach ($products as $product) {
        $product_id = $product->product_id;
        $product_title = $product->product_title;
        $product_names[$product_id] = $product_title;

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
        update_post_meta($product_id, $meta_key, $position);
        $updated++;
    }

    return "Updated $updated products in category ID $category_id.";
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

        <form method="post" id="manual-category-form">
            <h2>Manual Single Category</h2>
            <select name="category_id">
                <?php foreach ($categories as $category): ?>
                    <option value="<?php echo $category->term_id; ?>"><?php echo $category->name; ?> (ID: <?php echo $category->term_id; ?>)</option>
                <?php endforeach; ?>
            </select>
            <input type="submit" name="run_alternation" class="button button-primary" value="Apply Brand Alternation">
        </form>

        <?php
        if (isset($_POST['run_alternation']) && isset($_POST['category_id'])) {
            $result = alternate_brands_for_category(intval($_POST['category_id']));
            echo '<div class="notice notice-success is-dismissible"><p>' . $result . '</p></div>';
        }
        ?>

        <hr>
        <h2>Global Apply (Async)</h2>
        <p>This will process all categories, skipping excluded ones, without overloading the server.</p>
        <button class="button button-secondary" id="start-global-update">Run in Background</button>
        <div id="update-log" style="margin-top:15px; max-height:300px; overflow-y:auto; border:1px solid #ddd; padding:10px;"></div>
    </div>

    <script>
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

