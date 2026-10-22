<?php
/**
 * ---------------------------------------------------------------------
 * Restrict Status - Plugin GLPI 10 & 11
 * Modelo de Configurações do Plugin
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

class PluginRestrictstatusConfig extends CommonDBTM {

    public static $rightname = 'config';

    public static function getTypeName($nb = 0): string {
        return __('Restringir Acompanhamentos por Status', 'restrictstatus');
    }

    /**
     * Retorna lista de todos os status possíveis de chamados no GLPI com seus nomes
     *
     * @return array [status_id => label]
     */
    public static function getAllTicketStatuses(): array {
        if (class_exists('Ticket')) {
            return Ticket::getAllStatusArray();
        }

        return [
            1 => __('Novo', 'restrictstatus'),
            2 => __('Em atendimento (Atribuído)', 'restrictstatus'),
            3 => __('Em atendimento (Planejado)', 'restrictstatus'),
            4 => __('Pendente', 'restrictstatus'),
            5 => __('Solucionado', 'restrictstatus'),
            6 => __('Fechado', 'restrictstatus'),
        ];
    }

    /**
     * Retorna a cor e ícone representativo de cada status para exibição refinada
     *
     * @param int $statusId
     * @return array [badge_class => string, icon => string]
     */
    public static function getStatusVisuals(int $statusId): array {
        switch ($statusId) {
            case 1: // Novo
                return ['badge_class' => 'bg-danger text-white', 'icon' => 'ti ti-sparkles'];
            case 2: // Atribuído
                return ['badge_class' => 'bg-primary text-white', 'icon' => 'ti ti-user-check'];
            case 3: // Planejado
                return ['badge_class' => 'bg-info text-white', 'icon' => 'ti ti-calendar-time'];
            case 4: // Pendente
                return ['badge_class' => 'bg-warning text-dark', 'icon' => 'ti ti-clock'];
            case 5: // Solucionado
                return ['badge_class' => 'bg-success text-white', 'icon' => 'ti ti-circle-check'];
            case 6: // Fechado
                return ['badge_class' => 'bg-secondary text-white', 'icon' => 'ti ti-lock'];
            default:
                return ['badge_class' => 'bg-dark text-white', 'icon' => 'ti ti-tag'];
        }
    }

    /**
     * Obtém todas as configurações de status para a entidade especificada
     *
     * @param int $entitiesId
     * @return array [status_id => row_data]
     */
    public static function getStatusConfigsForEntity(int $entitiesId = 0): array {
        global $DB;

        PluginRestrictstatusProfile::checkSchema();

        $statuses = self::getAllTicketStatuses();
        $result = [];

        // Inicializa com padrões para todos os status conhecidos
        foreach ($statuses as $statusId => $label) {
            $result[$statusId] = [
                'id'                 => 0,
                'entities_id'        => $entitiesId,
                'status_id'          => $statusId,
                'status_name'        => $label,
                'is_active'          => ($statusId === 1 ? 1 : 0), // Padrão status 1 ativo
                'restrict_requester' => 1,
                'restrict_observer'  => 1,
                'restrict_assigned'  => 0,
                'restrict_followups' => 1,
                'restrict_solutions' => 1,
                'restrict_documents' => 1,
                'restrict_tasks'     => 0,
                'custom_message'     => '',
                'is_deleted'         => 0,
            ];
        }

        // Consulta banco com Prepared Statement / Query builder seguro do GLPI
        $iterator = $DB->request([
            'FROM'   => 'glpi_plugin_restrictstatus_configs',
            'WHERE'  => [
                'entities_id' => $entitiesId,
                'is_deleted'  => 0
            ]
        ]);

        foreach ($iterator as $row) {
            $sId = (int)$row['status_id'];
            if (isset($result[$sId])) {
                $row['status_name']        = $result[$sId]['status_name'];
                $row['restrict_followups'] = (int)($row['restrict_followups'] ?? 1);
                $row['restrict_solutions'] = (int)($row['restrict_solutions'] ?? 1);
                $row['restrict_documents'] = (int)($row['restrict_documents'] ?? 1);
                $row['restrict_tasks']     = (int)($row['restrict_tasks'] ?? 0);
                $result[$sId] = $row;
            }
        }

        return $result;
    }

    /**
     * Salva as configurações de um status específico para uma entidade
     *
     * @param int $entitiesId
     * @param int $statusId
     * @param array $data
     * @return boolean
     */
    public static function saveStatusConfig(int $entitiesId, int $statusId, array $data): bool {
        global $DB;

        $existing = $DB->request([
            'FROM'  => 'glpi_plugin_restrictstatus_configs',
            'WHERE' => [
                'entities_id' => $entitiesId,
                'status_id'   => $statusId
            ],
            'LIMIT' => 1
        ]);

        $fields = [
            'is_active'          => !empty($data['is_active']) ? 1 : 0,
            'restrict_requester' => 1,
            'restrict_observer'  => 1,
            'restrict_assigned'  => 1,
            'restrict_followups' => !empty($data['restrict_followups']) ? 1 : 0,
            'restrict_solutions' => !empty($data['restrict_solutions']) ? 1 : 0,
            'restrict_documents' => !empty($data['restrict_documents']) ? 1 : 0,
            'restrict_tasks'     => !empty($data['restrict_tasks']) ? 1 : 0,
            'custom_message'     => !empty($data['custom_message']) ? trim($data['custom_message']) : null,
            'is_deleted'         => 0,
            'date_mod'           => date('Y-m-d H:i:s')
        ];

        if (count($existing) > 0) {
            $current = $existing->current();
            return $DB->update('glpi_plugin_restrictstatus_configs', $fields, [
                'id' => (int)$current['id']
            ]);
        } else {
            $fields['entities_id']   = $entitiesId;
            $fields['is_recursive']  = 1;
            $fields['status_id']    = $statusId;
            $fields['date_creation'] = date('Y-m-d H:i:s');
            return (bool)$DB->insert('glpi_plugin_restrictstatus_configs', $fields);
        }
    }

    /**
     * Exclusão lógica com checagem de dependência de dados
     *
     * @param int $id
     * @return boolean
     */
    public static function safeSoftDelete(int $id): bool {
        global $DB;

        // Verifica existência
        $item = $DB->request([
            'FROM'  => 'glpi_plugin_restrictstatus_configs',
            'WHERE' => ['id' => $id, 'is_deleted' => 0]
        ]);

        if (count($item) == 0) {
            return false;
        }

        // Não permite exclusão física e aplica exclusão lógica
        return $DB->update('glpi_plugin_restrictstatus_configs', [
            'is_deleted' => 1,
            'date_mod'   => date('Y-m-d H:i:s')
        ], [
            'id' => $id
        ]);
    }
}
