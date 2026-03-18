<?php
namespace Opencart\Catalog\Model\Module;

class Suggestion extends \Opencart\System\Engine\Model {
    /**
     * Get suggested products for a customer based on most purchased top-level category,
     * excluding the last product bought.
     *
     * @param int|null $customer_id If null, uses logged-in customer.
     * @param bool $debug If true, returns array with 'products' and 'debug' info (for troubleshooting).
     * @return array
     */
    public function getSuggestedProducts(int $customer_id = null, bool $debug = false): array {
        if ($customer_id === null) {
            if (!isset($this->customer) || !$this->customer->isLogged()) {
                return [];
            }
            $customer_id = (int)$this->customer->getId();
        }

        // Determine "completed" statuses
        $completed_condition = "o.order_status_id > 0";

        // 1) Get most purchased top-level category
        $query = $this->db->query("
            SELECT 
                IF(pc.parent_id > 0, pc.parent_id, p2c.category_id) AS main_category_id,
                cd.name AS category_name,
                SUM(op.quantity) AS total_quantity
            FROM `" . DB_PREFIX . "order_product` op
            JOIN `" . DB_PREFIX . "order` o ON (op.order_id = o.order_id)
            JOIN `" . DB_PREFIX . "product_to_category` p2c ON (op.product_id = p2c.product_id)
            LEFT JOIN `" . DB_PREFIX . "category` pc ON (p2c.category_id = pc.category_id)
            LEFT JOIN `" . DB_PREFIX . "category_description` cd 
                ON (IF(pc.parent_id > 0, pc.parent_id, p2c.category_id) = cd.category_id 
                AND cd.language_id = '" . (int)$this->config->get('config_language_id') . "')
            WHERE o.customer_id = '" . (int)$customer_id . "'
              AND o.order_status_id > 0
            GROUP BY main_category_id
            ORDER BY total_quantity DESC
            LIMIT 1
        ");

        // Fallback to latest products if no purchase history
        if (!$query->num_rows) {
            $fallback = $this->db->query("
                SELECT p.product_id, pd.name, p.image, p.price
                FROM `" . DB_PREFIX . "product` p
                JOIN `" . DB_PREFIX . "product_description` pd ON (p.product_id = pd.product_id)
                WHERE p.status = 1
                  AND pd.language_id = " . (int)$this->config->get('config_language_id') . "
                ORDER BY p.date_added DESC
                LIMIT 8
            ");

            $this->load->model('tool/image');
            $products = [];
            foreach ($fallback->rows as $row) {
                $products[] = [
                    'product_id' => (int)$row['product_id'],
                    'name'       => $row['name'],
                    'thumb'      => $row['image'] ? $this->model_tool_image->resize($row['image'], 200, 200) : '',
                    'price'      => $this->currency->format($row['price'], $this->session->data['currency'] ?? $this->config->get('config_currency')),
                    'href'       => $this->url->link('product/product', 'product_id=' . (int)$row['product_id'])
                ];
            }

            if ($debug) {
                return ['products' => $products, 'debug' => ['reason' => 'no_purchase_history']];
            }

            return $products;
        }

        // Top category found
        $top_category_id   = (int)$query->row['main_category_id'];
        $top_category_name = $query->row['category_name'] ?? '';

        // 1b) Get last purchased product ID to exclude
        $last_product_query = $this->db->query("
            SELECT op.product_id
            FROM `" . DB_PREFIX . "order_product` op
            JOIN `" . DB_PREFIX . "order` o ON op.order_id = o.order_id
            WHERE o.customer_id = " . (int)$customer_id . "
              AND o.order_status_id > 0
            ORDER BY o.date_added DESC
            LIMIT 1
        ");
        $last_product_id = $last_product_query->num_rows ? (int)$last_product_query->row['product_id'] : 0;

        // 2) Get products from that top-level category excluding last purchased product
        $product_sql = "
            SELECT p.product_id, pd.name, p.image, p.price
            FROM `" . DB_PREFIX . "product` p
            JOIN `" . DB_PREFIX . "product_description` pd ON (p.product_id = pd.product_id)
            JOIN `" . DB_PREFIX . "product_to_category` p2c ON (p.product_id = p2c.product_id)
            WHERE p2c.category_id = " . $top_category_id . "
              AND p.status = 1
              AND pd.language_id = " . (int)$this->config->get('config_language_id') . "
              AND p.product_id != " . $last_product_id . "
            GROUP BY p.product_id
            ORDER BY p.date_added DESC
            LIMIT 8
        ";

        $product_query = $this->db->query($product_sql);

        $this->load->model('tool/image');
        $products = [];

        foreach ($product_query->rows as $row) {
            $products[] = [
                'product_id' => (int)$row['product_id'],
                'name'       => $row['name'],
                'thumb'      => $row['image'] ? $this->model_tool_image->resize($row['image'], 200, 200) : '',
                'price'      => $this->currency->format($row['price'], $this->session->data['currency'] ?? $this->config->get('config_currency')),
                'href'       => $this->url->link('product/product', 'product_id=' . (int)$row['product_id'])
            ];
        }

        if ($debug) {
            return [
                'products' => $products,
                'debug' => [
                    'top_category_id'   => $top_category_id,
                    'top_category_name' => $top_category_name,
                    'category_row'      => $query->row,
                    'last_product_id'   => $last_product_id,
                    'product_sql'       => $product_sql
                ]
            ];
        }

        return $products;
    }
}
