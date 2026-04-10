<?php
namespace Opencart\Catalog\Model\Tool;

class AiEvent extends \Opencart\System\Engine\Model {
    public function logEvent(int $product_id, string $event_type): void {
        if ($product_id <= 0) {
            return;
        }

        $allowed = ['view', 'add_to_cart', 'purchase'];
        if (!in_array($event_type, $allowed, true)) {
            return;
        }

        $customer_id = 0;
        if (isset($this->customer) && $this->customer->isLogged()) {
            $customer_id = (int)$this->customer->getId();
        }

        $session_id = '';
        if (isset($this->session) && method_exists($this->session, 'getId')) {
            $session_id = (string)$this->session->getId();
        }

        $this->db->query("
            INSERT INTO `" . DB_PREFIX . "ai_event`
            SET customer_id = '" . (int)$customer_id . "',
                session_id  = '" . $this->db->escape($session_id) . "',
                product_id  = '" . (int)$product_id . "',
                event_type  = '" . $this->db->escape($event_type) . "',
                date_added  = NOW()
        ");
    }
}
