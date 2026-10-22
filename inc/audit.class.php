<?php
/**
 * ---------------------------------------------------------------------
 * Restrict Status - Plugin GLPI 10 & 11
 * Módulo de Auditoria (Multitenant, histórico de ações e acessos)
 * ---------------------------------------------------------------------
 * @author    Wilter Porto
 * @copyright 2026 Wilter Porto
 * @license   GPLv3+
 * ---------------------------------------------------------------------
 */

declare(strict_types=1);

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

require_once __DIR__ . '/enums.class.php';
require_once __DIR__ . '/profile.class.php';

class PluginRestrictstatusAudit extends CommonDBTM {

    public static $rightname = 'config';

    /**
     * Registra uma ação no histórico de auditoria
     *
     * @param array $data
     * @return int|bool
     */
    public static function logAction(array $data): int|bool {
        global $DB;

        $userId = Session::getLoginUserID();
        $userName = $_SESSION['glpifriendlyname'] ?? ($_SESSION['glpiname'] ?? 'Sistema/Automático');
        $userProfile = $_SESSION['glpiactiveprofile']['name'] ?? 'N/A';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        $action     = $data['action'] ?? 'GENERIC_ACTION';
        $ticketId   = isset($data['ticket_id']) ? (int)$data['ticket_id'] : null;
        $statusId   = isset($data['status_id']) ? (int)$data['status_id'] : null;
        $roleName   = $data['role_name'] ?? null;
        $details    = $data['details'] ?? '';
        $entitiesId = isset($data['entities_id']) ? (int)$data['entities_id'] : (int)($_SESSION['glpiactive_entity'] ?? 0);

        try {
            return $DB->insert('glpi_plugin_restrictstatus_audits', [
                'entities_id'   => $entitiesId,
                'users_id'      => (int)$userId,
                'user_name'     => $userName,
                'user_profile'  => $userProfile,
                'action'        => $action,
                'ticket_id'     => $ticketId,
                'status_id'     => $statusId,
                'role_name'     => $roleName,
                'details'       => $details,
                'ip_address'    => $ip,
                'is_deleted'    => 0,
                'date_creation' => date('Y-m-d H:i:s')
            ]);
        } catch (\Throwable $e) {
            error_log("Erro ao registrar auditoria restrictstatus: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Retorna a listagem de registros de auditoria com paginação limitada a 30 por página
     *
     * @param int $page Página atual (1-indexed)
     * @param int $perPage Limite por página (30)
     * @param array $filters Filtros opcionais (action, users_id, ticket_id, entities_id)
     * @return array [rows => array, total => int, pages => int, current_page => int]
     */
    public static function getPaginatedAudits(int $page = 1, int $perPage = 30, array $filters = []): array {
        global $DB;

        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $where = ['is_deleted' => 0];

        // Filtro por entidade (Multitenant)
        if (isset($filters['entities_id']) && $filters['entities_id'] !== '') {
            $where['entities_id'] = (int)$filters['entities_id'];
        } elseif (!PluginRestrictstatusProfile::isCurrentUserSuperAdmin()) {
            // Se não for superadmin, restringe à entidade ativa do usuário
            $where['entities_id'] = (int)($_SESSION['glpiactive_entity'] ?? 0);
        }

        if (!empty($filters['action'])) {
            $where['action'] = $filters['action'];
        }

        if (!empty($filters['ticket_id'])) {
            $where['ticket_id'] = (int)$filters['ticket_id'];
        }

        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $where['OR'] = [
                'user_name LIKE' => $search,
                'details LIKE'   => $search,
                'role_name LIKE' => $search
            ];
        }

        // Total de registros
        $countIterator = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => 'glpi_plugin_restrictstatus_audits',
            'WHERE' => $where
        ]);
        $total = 0;
        if (count($countIterator) > 0) {
            $total = (int)$countIterator->current()['cpt'];
        }

        // Registros paginados
        $rowsIterator = $DB->request([
            'FROM'   => 'glpi_plugin_restrictstatus_audits',
            'WHERE'  => $where,
            'ORDER'  => 'id DESC',
            'START'  => $offset,
            'LIMIT'  => $perPage
        ]);

        $rows = [];
        foreach ($rowsIterator as $row) {
            $rows[] = $row;
        }

        $totalPages = (int)ceil($total / $perPage);

        return [
            'rows'         => $rows,
            'total'        => $total,
            'pages'        => max(1, $totalPages),
            'current_page' => $page,
            'per_page'     => $perPage
        ];
    }

    /**
     * Exclusão lógica com checagem de integridade referencial
     *
     * @param int $id
     * @return boolean
     */
    public static function softDeleteAudit(int $id): bool {
        global $DB;

        // Verifica existência do registro
        $iterator = $DB->request([
            'FROM'  => 'glpi_plugin_restrictstatus_audits',
            'WHERE' => ['id' => $id, 'is_deleted' => 0]
        ]);

        if (count($iterator) == 0) {
            return false;
        }

        // Aplica exclusão lógica conforme regra do usuário
        return $DB->update('glpi_plugin_restrictstatus_audits', [
            'is_deleted' => 1
        ], [
            'id' => $id
        ]);
    }
}
