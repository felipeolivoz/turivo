<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase de compatibilidad para cuando el SDK de Transbank NO está disponible.
 * Simula el comportamiento del SDK real y evita fallos en producción.
 */
class TT_Transbank_Compatibility {

    /**
     * Crear transacción falsa (simulate create)
     */
    public static function create_transaction($buyOrder, $sessionId, $amount, $returnUrl) {
        return new class($returnUrl) {
            private $token;
            private $url;

            public function __construct($returnUrl) {
                $this->token = 'FAKE_TOKEN_' . wp_generate_uuid4();
                $this->url   = esc_url($returnUrl);
            }

            public function getToken() {
                return $this->token;
            }

            public function getUrl() {
                return $this->url;
            }
        };
    }

    /**
     * Confirmar transacción simulada (simulate commit)
     */
    public static function commit_transaction($token) {

        $simulate_failure = get_option('tt_transbank_simulate_failure', false);

        return new class($simulate_failure) {

            private $fail;

            public function __construct($fail) {
                $this->fail = $fail;
            }

            public function isApproved() {
                return !$this->fail;
            }

            public function getAmount() {
                return 10000;
            }

            public function getAuthorizationCode() {
                return $this->fail ? null : 'FAKE-AUTH-' . rand(1000, 9999);
            }

            public function getTransactionDate() {
                return date('Y-m-d H:i:s');
            }

            public function getBuyOrder() {
                return 'FAKE_ORDER_' . date('YmdHis');
            }

            public function getCardNumber() {
                return '1111';
            }

            public function getResponseCode() {
                return $this->fail ? 1 : 0;
            }

            public function getPaymentTypeCode() {
                return 'VD';
            }

            public function getInstallmentsNumber() {
                return 0;
            }

            public function getSessionId() {
                return 'FAKE_SESSION';
            }
        };
    }

    /**
     * Obtener estado falso de la transacción (status API)
     */
    public static function get_transaction_status($token) {
        return new class {
            public function isApproved() {
                return true;
            }
            public function getResponseCode() {
                return 0;
            }
            public function getAmount() {
                return 10000;
            }
        };
    }
}
