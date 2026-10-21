<?php
/**
 * ---------------------------------------------------------------------
 * Restrict Status - Plugin GLPI 10 & 11
 * Arquivo de hooks, instalação, desinstalação e regras de negócio
 * ---------------------------------------------------------------------
 * @author    Antigravity
 * @copyright 2026 Antigravity
 * @license   GPLv3+
 * ---------------------------------------------------------------------
 */

declare(strict_types=1);

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

require_once __DIR__ . '/inc/enums.class.php';
require_once __DIR__ . '/inc/profile.class.php';

/**
 * Instalação do plugin
 * Criação de tabelas, índices e configurações iniciais padrão
 *
 * @return boolean
 */
function plugin_restrictstatus_install(): bool {
    global $DB;

    // Tabela de configurações por status e entidade (Multitenant)
    if (!$DB->tableExists('glpi_plugin_restrictstatus_configs')) {
        $queryConfig = "CREATE TABLE `glpi_plugin_restrictstatus_configs` (
            `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `entities_id` INT(11) UNSIGNED NOT NULL DEFAULT 0,
            `is_recursive` TINYINT(1) NOT NULL DEFAULT 1,
            `status_id` INT(11) UNSIGNED NOT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 0,
            `restrict_requester` TINYINT(1) NOT NULL DEFAULT 1,
            `restrict_observer` TINYINT(1) NOT NULL DEFAULT 1,
            `restrict_assigned` TINYINT(1) NOT NULL DEFAULT 0,
            `restrict_followups` TINYINT(1) NOT NULL DEFAULT 1,
            `restrict_solutions` TINYINT(1) NOT NULL DEFAULT 1,
            `restrict_documents` TINYINT(1) NOT NULL DEFAULT 1,
            `restrict_tasks` TINYINT(1) NOT NULL DEFAULT 0,
            `custom_message` VARCHAR(255) DEFAULT NULL,
            `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
            `date_mod` DATETIME DEFAULT NULL,
            `date_creation` DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`entities_id`, `status_id`),
            KEY `status_id` (`status_id`),
            KEY `is_active` (`is_active`),
            KEY `is_deleted` (`is_deleted`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        $DB->doQuery($queryConfig);
    }

    // Tabela de auditoria completa: registra alterações de configuração e bloqueios efetuados
    if (!$DB->tableExists('glpi_plugin_restrictstatus_audits')) {
        $queryAudit = "CREATE TABLE `glpi_plugin_restrictstatus_audits` (
            `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `entities_id` INT(11) UNSIGNED NOT NULL DEFAULT 0,
            `users_id` INT(11) UNSIGNED NOT NULL DEFAULT 0,
            `user_name` VARCHAR(255) DEFAULT NULL,
            `user_profile` VARCHAR(255) DEFAULT NULL,
            `action` VARCHAR(50) NOT NULL,
            `ticket_id` INT(11) UNSIGNED DEFAULT NULL,
            `status_id` INT(11) UNSIGNED DEFAULT NULL,
            `role_name` VARCHAR(50) DEFAULT NULL,
            `details` TEXT DEFAULT NULL,
            `ip_address` VARCHAR(45) DEFAULT NULL,
            `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
            `date_creation` DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `entities_id` (`entities_id`),
            KEY `users_id` (`users_id`),
            KEY `action` (`action`),
            KEY `is_deleted` (`is_deleted`),
            KEY `date_creation` (`date_creation`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        $DB->doQuery($queryAudit);
    }

    // Tabela de configurações por perfil de acesso (determina aplicação de bloqueio)
    PluginRestrictstatusProfile::createTable();

    // Popula configurações padrão de status (1 = Novo ativado por padrão conforme solicitado)
    $defaultStatuses = [
        1 => ['active' => 1, 'req' => 1, 'obs' => 1, 'tech' => 0], // Novo (Restrição solicitada no exemplo)
        2 => ['active' => 0, 'req' => 1, 'obs' => 1, 'tech' => 0], // Em atendimento (Atribuído)
        3 => ['active' => 0, 'req' => 1, 'obs' => 1, 'tech' => 0], // Em atendimento (Planejado)
        4 => ['active' => 0, 'req' => 1, 'obs' => 1, 'tech' => 0], // Pendente
        5 => ['active' => 0, 'req' => 1, 'obs' => 1, 'tech' => 0], // Solucionado
        6 => ['active' => 0, 'req' => 1, 'obs' => 1, 'tech' => 0], // Fechado
    ];

    foreach ($defaultStatuses as $statusId => $cfg) {
        $check = $DB->request([
            'FROM'   => 'glpi_plugin_restrictstatus_configs',
            'WHERE'  => [
                'entities_id' => 0,
                'status_id'   => $statusId
            ]
        ]);

        if (count($check) == 0) {
            $DB->insert('glpi_plugin_restrictstatus_configs', [
                'entities_id'        => 0,
                'is_recursive'       => 1,
                'status_id'          => $statusId,
                'is_active'          => $cfg['active'],
                'restrict_requester' => $cfg['req'],
                'restrict_observer'  => $cfg['obs'],
                'restrict_assigned'  => $cfg['tech'],
                'custom_message'     => null,
                'is_deleted'         => 0,
                'date_creation'      => date('Y-m-d H:i:s')
            ]);
        }
    }

    // Configurações globais via API nativa de Config do GLPI
    $currentGlobalConfig = Config::getConfigurationValues('plugin:restrictstatus');
    if (empty($currentGlobalConfig)) {
        Config::setConfigurationValues('plugin:restrictstatus', [
            'plugin_enabled'           => 1,
            'default_message'          => 'Não é permitido adicionar novos acompanhamentos a chamados com o status atual (%s).',
            'exempt_profiles'          => json_encode([4]), // Perfil Super-Admin padrão GLPI
            'allow_assigned_technician'=> 1,
            'apply_to_mailgate'        => 1,
            'show_timeline_alert'      => 1
        ]);
    }

    // Registra evento de instalação na auditoria
    PluginRestrictstatusAudit::logAction([
        'action'       => PluginRestrictstatusAuditAction::PluginInstalled->value,
        'details'      => 'Plugin Restrict Status instalado com sucesso na versão ' . PLUGIN_RESTRICTSTATUS_VERSION,
        'entities_id'  => 0
    ]);

    return true;
}

/**
 * Desinstalação do plugin
 * Aplica exclusão segura
 *
 * @return boolean
 */
function plugin_restrictstatus_uninstall(): bool {
    global $DB;

    // Registra evento antes da remoção
    PluginRestrictstatusAudit::logAction([
        'action'       => PluginRestrictstatusAuditAction::PluginUninstalled->value,
        'details'      => 'Plugin Restrict Status desinstalado.',
        'entities_id'  => 0
    ]);

    // Remove tabelas do plugin
    $tables = [
        'glpi_plugin_restrictstatus_configs',
        'glpi_plugin_restrictstatus_audits',
        'glpi_plugin_restrictstatus_profiles',
        'glpi_plugin_restrictstatus_profile_statuses'
    ];

    foreach ($tables as $table) {
        if ($DB->tableExists($table)) {
            $DB->dropTable($table);
        }
    }

    // Remove configurações globais
    Config::deleteConfigurationValues('plugin:restrictstatus', [
        'plugin_enabled',
        'default_message',
        'exempt_profiles',
        'allow_assigned_technician',
        'apply_to_mailgate',
        'show_timeline_alert'
    ]);

    return true;
}

/**
 * Hook pre_item_add para CommonDBTM (ITILFollowup, ITILSolution, Document_Item, Document, TicketTask)
 * Intercepta a adição de acompanhamentos, soluções, documentos e tarefas
 * Se o chamado estiver em status restrito e a regra bloquear o item para o perfil/papel do usuário, cancela a operação
 *
 * @param CommonDBTM $item
 * @return void
 */
function plugin_restrictstatus_pre_item_add(CommonDBTM $item): void {
    $ticketId = 0;
    $itemType = null;

    if ($item instanceof ITILFollowup) {
        $itemtype = $item->input['itemtype'] ?? $item->fields['itemtype'] ?? null;
        if ($itemtype !== 'Ticket') {
            return;
        }
        $ticketId = (int)($item->input['items_id'] ?? $item->fields['items_id'] ?? 0);
        $itemType = 'followup';
    } elseif ($item instanceof ITILSolution) {
        $itemtype = $item->input['itemtype'] ?? $item->fields['itemtype'] ?? null;
        if ($itemtype !== null && $itemtype !== 'Ticket') {
            return;
        }
        $ticketId = (int)($item->input['items_id'] ?? $item->input['tickets_id'] ?? $item->fields['items_id'] ?? 0);
        $itemType = 'solution';
    } elseif ($item instanceof Document_Item) {
        $itemtype = $item->input['itemtype'] ?? $item->fields['itemtype'] ?? null;
        if ($itemtype !== 'Ticket') {
            return;
        }
        $ticketId = (int)($item->input['items_id'] ?? $item->fields['items_id'] ?? 0);
        $itemType = 'document';
    } elseif ($item instanceof Document) {
        $itemtype = $item->input['itemtype'] ?? $item->input['_itemtype'] ?? 'Ticket';
        if ($itemtype !== 'Ticket') {
            return;
        }
        $ticketId = (int)($item->input['items_id'] ?? $item->input['_items_id'] ?? $item->input['tickets_id'] ?? 0);
        if ($ticketId <= 0) {
            return;
        }
        $itemType = 'document';
    } elseif ($item instanceof TicketTask) {
        $ticketId = (int)($item->input['tickets_id'] ?? $item->input['items_id'] ?? $item->fields['tickets_id'] ?? 0);
        $itemType = 'task';
    } else {
        return;
    }

    if ($ticketId <= 0 || !$itemType) {
        return;
    }

    $userId = Session::getLoginUserID();

    // Avalia a restrição para o tipo de item específico
    $restriction = PluginRestrictstatusRestriction::evaluateTicketRestriction($ticketId, $userId, $itemType);

    if ($restriction['restricted']) {
        // Bloqueia a inserção definindo input como false (padrão CommonDBTM do GLPI)
        $item->input = false;

        // Adiciona notificação de erro ao usuário
        Session::addMessageAfterRedirect($restriction['message'], false, ERROR);

        $actionEnum = match ($itemType) {
            'solution' => PluginRestrictstatusAuditAction::SolutionBlocked->value,
            'document' => PluginRestrictstatusAuditAction::DocumentBlocked->value,
            'task'     => PluginRestrictstatusAuditAction::TaskBlocked->value,
            default    => PluginRestrictstatusAuditAction::FollowupBlocked->value,
        };

        $itemLabel = match ($itemType) {
            'solution' => __('solução', 'restrictstatus'),
            'document' => __('documento', 'restrictstatus'),
            'task'     => __('tarefa', 'restrictstatus'),
            default    => __('acompanhamento', 'restrictstatus'),
        };

        // Registra tentativa no log de auditoria
        PluginRestrictstatusAudit::logAction([
            'action'      => $actionEnum,
            'ticket_id'   => $ticketId,
            'status_id'   => $restriction['status_id'],
            'role_name'   => $restriction['role_name'],
            'entities_id' => $restriction['entities_id'],
            'details'     => sprintf(
                'Tentativa de adição de %s bloqueada. Chamado #%d com status "%s". Papel do usuário: %s. Mensagem: %s',
                $itemLabel,
                $ticketId,
                $restriction['status_name'],
                $restriction['role_name'],
                $restriction['message']
            )
        ]);
    }
}

/**
 * Hook pre_item_update para Ticket
 * Impede que o usuário altere o chamado diretamente para status Solucionado se a adição de soluções estiver restrita
 *
 * @param CommonDBTM $item
 * @return void
 */
function plugin_restrictstatus_pre_item_update(CommonDBTM $item): void {
    if (!($item instanceof Ticket)) {
        return;
    }

    $newStatus = (int)($item->input['status'] ?? 0);
    if ($newStatus !== Ticket::SOLVED) {
        return;
    }

    $ticketId = (int)$item->getID();
    if ($ticketId <= 0) {
        return;
    }

    $userId = Session::getLoginUserID();
    $restriction = PluginRestrictstatusRestriction::evaluateTicketRestriction($ticketId, $userId, 'solution');

    if ($restriction['restricted']) {
        unset($item->input['status']);
        Session::addMessageAfterRedirect($restriction['message'], false, ERROR);

        PluginRestrictstatusAudit::logAction([
            'action'      => PluginRestrictstatusAuditAction::SolutionBlocked->value,
            'ticket_id'   => $ticketId,
            'status_id'   => $restriction['status_id'],
            'role_name'   => $restriction['role_name'],
            'entities_id' => $restriction['entities_id'],
            'details'     => sprintf(
                'Tentativa de solucionar o chamado bloqueada. Chamado #%d com status "%s". Papel do usuário: %s. Mensagem: %s',
                $ticketId,
                $restriction['status_name'],
                $restriction['role_name'],
                $restriction['message']
            )
        ]);
    }
}

/**
 * Hook timeline_actions
 * Injeta aviso visual e atributos de restrição na interface do chamado
 *
 * @param array $params
 * @return void
 */
function plugin_restrictstatus_timeline_actions(array $params): void {
    $ticket = $params['item'] ?? null;
    if (!$ticket || !($ticket instanceof Ticket)) {
        return;
    }

    $userId = Session::getLoginUserID();
    $restriction = PluginRestrictstatusRestriction::evaluateTicketRestriction($ticket, $userId, 'all');

    if ($restriction['restricted']) {
        echo PluginRestrictstatusRestriction::renderTimelineNoticeHTML($restriction);
    }
}

