<?php
/**
 * ---------------------------------------------------------------------
 * Restrict Status - Plugin GLPI 10 & 11
 * Motor de avaliação e validação de restrições de acompanhamento
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

class PluginRestrictstatusRestriction extends CommonDBTM {

    /**
     * Avalia se um usuário está restrito de adicionar acompanhamentos a um chamado específico (wrapper de compatibilidade)
     *
     * @param Ticket|int $ticketOrId
     * @param int|null   $userId
     * @return array
     */
    public static function evaluateTicketFollowupRestriction($ticketOrId, ?int $userId = null): array {
        return self::evaluateTicketRestriction($ticketOrId, $userId, 'followup');
    }

    /**
     * Avalia restrições de acompanhamento, solução, documento ou tarefa para um chamado específico
     *
     * @param Ticket|int  $ticketOrId
     * @param int|null    $userId
     * @param string|null $itemType 'followup', 'solution', 'document', 'task' ou 'all'/null
     * @return array
     */
    public static function evaluateTicketRestriction($ticketOrId, ?int $userId = null, ?string $itemType = null): array {
        global $DB;

        $notRestricted = [
            'restricted'          => false,
            'status_id'           => 0,
            'status_name'         => '',
            'profile_name'        => '',
            'role_name'           => '',
            'message'             => '',
            'entities_id'         => 0,
            'ticket_id'           => 0,
            'show_timeline_alert' => false,
            'restricted_items'    => [
                'followup' => false,
                'solution' => false,
                'document' => false,
                'task'     => false,
            ]
        ];

        // 1. Obter configurações globais
        $globalConfig = Config::getConfigurationValues('plugin:restrictstatus');
        $pluginEnabled = (int)($globalConfig['plugin_enabled'] ?? 1);
        if ($pluginEnabled !== 1) {
            return $notRestricted;
        }

        // 2. Se for cron/CLI (ex: coletor de e-mail sem login ativo)
        if (isCommandLine()) {
            $applyMailgate = (int)($globalConfig['apply_to_mailgate'] ?? 1);
            if ($applyMailgate !== 1) {
                return $notRestricted;
            }
        }

        if ($userId === null) {
            $userId = Session::getLoginUserID();
        }

        // 3. Verificar regras configuradas no Perfil de Acesso do usuário
        $activeProfileId = (int)($_SESSION['glpiactiveprofile']['id'] ?? 0);
        if ($activeProfileId <= 0 && $userId && $userId > 0) {
            $entitiesId = 0;
            if ($ticketOrId instanceof Ticket) {
                $entitiesId = (int)($ticketOrId->fields['entities_id'] ?? 0);
            }
            $activeProfileId = PluginRestrictstatusProfile::getUserProfileForEntity($userId, $entitiesId);
        }

        // Se o perfil de acesso estiver configurado como isento (não aplicar regras de bloqueio), libera a ação
        if ($activeProfileId > 0 && !PluginRestrictstatusProfile::isProfileRestricted($activeProfileId)) {
            return $notRestricted;
        }

        // Se for Super-Admin no GLPI e o perfil não estiver forçado para bloqueio
        if (PluginRestrictstatusProfile::isCurrentUserSuperAdmin() && ($activeProfileId <= 0 || !PluginRestrictstatusProfile::isProfileRestricted($activeProfileId))) {
            return $notRestricted;
        }

        // 4. Carregar o Chamado (Ticket)
        $ticket = null;
        if ($ticketOrId instanceof Ticket) {
            $ticket = $ticketOrId;
        } elseif (is_numeric($ticketOrId) && (int)$ticketOrId > 0) {
            $ticket = new Ticket();
            if (!$ticket->getFromDB((int)$ticketOrId)) {
                return $notRestricted;
            }
        } else {
            return $notRestricted;
        }

        $ticketId   = (int)$ticket->getID();
        $statusId   = (int)($ticket->fields['status'] ?? 0);
        $entitiesId = (int)($ticket->fields['entities_id'] ?? 0);

        if ($statusId <= 0) {
            return $notRestricted;
        }

        // 5. Buscar regra configurada para o status atual do chamado (individual por perfil com fallback)
        $configRow = self::getStatusConfigForProfile($statusId, $activeProfileId, $entitiesId);
        if (!$configRow || (int)$configRow['is_active'] !== 1) {
            return $notRestricted;
        }

        // 6. Obter nome do perfil do usuário para identificação e auditoria
        $profileName = '';
        if ($activeProfileId > 0) {
            $profObj = new Profile();
            if ($profObj->getFromDB($activeProfileId)) {
                $profileName = (string)($profObj->fields['name'] ?? '');
            }
        }
        if (empty($profileName)) {
            $profileName = (string)($_SESSION['glpiactiveprofile']['name'] ?? __('Perfil Restrito', 'restrictstatus'));
        }

        // 7. Checagem dos tipos de itens bloqueados para este status
        $blockFollowups = (int)($configRow['restrict_followups'] ?? 1) === 1;
        $blockSolutions = (int)($configRow['restrict_solutions'] ?? 1) === 1;
        $blockDocuments = (int)($configRow['restrict_documents'] ?? 1) === 1;
        $blockTasks     = (int)($configRow['restrict_tasks'] ?? 0) === 1;

        $restrictedItems = [
            'followup' => $blockFollowups,
            'solution' => $blockSolutions,
            'document' => $blockDocuments,
            'task'     => $blockTasks,
        ];

        // Se uma ação/item específico foi consultado, verifica se ela está restrita
        if ($itemType && $itemType !== 'all') {
            $isSpecificRestricted = match ($itemType) {
                'followup' => $blockFollowups,
                'solution' => $blockSolutions,
                'document' => $blockDocuments,
                'task'     => $blockTasks,
                default    => false,
            };

            if (!$isSpecificRestricted) {
                return $notRestricted;
            }
        } else {
            // Se nenhuma das ações estiver bloqueada para este status, não restringe
            if (!$blockFollowups && !$blockSolutions && !$blockDocuments && !$blockTasks) {
                return $notRestricted;
            }
        }

        // 8. Monta a mensagem informativa/bloqueio considerando perfil, item e status
        $statusName = Ticket::getStatus($statusId);
        $profileCfg = PluginRestrictstatusProfile::getProfileConfig($activeProfileId);

        if (!empty($configRow['custom_message'])) {
            $messageTemplate = $configRow['custom_message'];
        } elseif (!empty($profileCfg['custom_notice'])) {
            $messageTemplate = $profileCfg['custom_notice'];
        } else {
            $messageTemplate = match ($itemType) {
                'solution' => __('Não é permitido adicionar soluções a chamados com o status atual (%s).', 'restrictstatus'),
                'document' => __('Não é permitido anexar documentos a chamados com o status atual (%s).', 'restrictstatus'),
                'task'     => __('Não é permitido adicionar tarefas a chamados com o status atual (%s).', 'restrictstatus'),
                default    => ($globalConfig['default_message'] ?? __('Não é permitido adicionar novos acompanhamentos a chamados com o status atual (%s).', 'restrictstatus')),
            };
        }

        if (strpos($messageTemplate, '%s') !== false) {
            $message = sprintf($messageTemplate, $statusName);
        } else {
            $message = $messageTemplate;
        }

        $showTimelineAlert = PluginRestrictstatusProfile::shouldShowTimelineAlert(
            $activeProfileId, 
            (int)($globalConfig['show_timeline_alert'] ?? 1)
        );

        return [
            'restricted'          => true,
            'status_id'           => $statusId,
            'status_name'         => $statusName,
            'profile_name'        => $profileName,
            'role_name'           => $profileName,
            'message'             => $message,
            'entities_id'         => $entitiesId,
            'ticket_id'           => $ticketId,
            'show_timeline_alert' => $showTimelineAlert,
            'restricted_items'    => $restrictedItems,
        ];
    }

    /**
     * Busca a configuração de status priorizando a regra individual do perfil do usuário
     *
     * @param int $statusId
     * @param int $profileId
     * @param int $entitiesId
     * @return array|null
     */
    public static function getStatusConfigForProfile(int $statusId, int $profileId, int $entitiesId = 0): ?array {
        global $DB;

        // Se houver um perfil ativo, prioriza as regras individuais gravadas para o perfil
        if ($profileId > 0 && $DB->tableExists('glpi_plugin_restrictstatus_profile_statuses')) {
            $iterator = $DB->request([
                'FROM'  => 'glpi_plugin_restrictstatus_profile_statuses',
                'WHERE' => [
                    'profiles_id' => $profileId,
                    'status_id'   => $statusId,
                    'is_deleted'  => 0
                ],
                'LIMIT' => 1
            ]);

            if (count($iterator) > 0) {
                return $iterator->current();
            }
        }

        // Caso contrário, busca na configuração geral/entidade como fallback
        return self::getStatusConfig($statusId, $entitiesId);
    }

    /**
     * Busca a configuração para um determinado status, considerando hierarquia multitenant
     *
     * @param int $statusId
     * @param int $entitiesId
     * @return array|null
     */
    public static function getStatusConfig(int $statusId, int $entitiesId = 0): ?array {
        global $DB;

        // 1. Busca específica para a entidade do chamado
        $iterator = $DB->request([
            'FROM'   => 'glpi_plugin_restrictstatus_configs',
            'WHERE'  => [
                'status_id'   => $statusId,
                'entities_id' => $entitiesId,
                'is_deleted'  => 0
            ],
            'LIMIT'  => 1
        ]);

        if (count($iterator) > 0) {
            return $iterator->current();
        }

        // 2. Se a entidade do chamado for diferente de 0, busca na entidade raiz (0) se recursiva
        if ($entitiesId > 0) {
            $rootIterator = $DB->request([
                'FROM'   => 'glpi_plugin_restrictstatus_configs',
                'WHERE'  => [
                    'status_id'    => $statusId,
                    'entities_id'  => 0,
                    'is_recursive' => 1,
                    'is_deleted'   => 0
                ],
                'LIMIT'  => 1
            ]);

            if (count($rootIterator) > 0) {
                return $rootIterator->current();
            }
        }

        return null;
    }

    /**
     * Renderiza o aviso em HTML na timeline
     *
     * @param array $restriction
     * @return string
     */
    public static function renderTimelineNoticeHTML(array $restriction): string {
        $safeMsg = htmlspecialchars($restriction['message'] ?? '', ENT_QUOTES, 'UTF-8');
        $safeProfile = htmlspecialchars($restriction['profile_name'] ?? $restriction['role_name'] ?? '', ENT_QUOTES, 'UTF-8');
        $safeStatus = htmlspecialchars($restriction['status_name'] ?? '', ENT_QUOTES, 'UTF-8');

        $rItems = $restriction['restricted_items'] ?? [];
        $flagFollowup = !empty($rItems['followup']) ? '1' : '0';
        $flagSolution = !empty($rItems['solution']) ? '1' : '0';
        $flagDocument = !empty($rItems['document']) ? '1' : '0';
        $flagTask     = !empty($rItems['task']) ? '1' : '0';

        $showAlert = !empty($restriction['show_timeline_alert']);

        $html = '';

        // Exibe o banner visual somente se a configuração permitir exibição do alerta na timeline
        if ($showAlert) {
            $html .= "<div class='restrictstatus-banner alert alert-warning d-flex align-items-center mb-3 mt-2 shadow-sm' role='alert' ";
            $html .= "data-restrictstatus='1' ";
            $html .= "data-ticket-id='{$restriction['ticket_id']}' ";
            $html .= "data-status-id='{$restriction['status_id']}' ";
            $html .= "data-restrict-followups='{$flagFollowup}' ";
            $html .= "data-restrict-solutions='{$flagSolution}' ";
            $html .= "data-restrict-documents='{$flagDocument}' ";
            $html .= "data-restrict-tasks='{$flagTask}'>";
            $html .= "  <div class='me-3 fs-2 text-warning'>";
            $html .= "    <i class='ti ti-lock'></i>";
            $html .= "  </div>";
            $html .= "  <div class='flex-grow-1'>";
            $html .= "    <h5 class='alert-heading mb-1 fw-bold'>" . __('Ações Restritas no Chamado', 'restrictstatus') . "</h5>";
            $html .= "    <p class='mb-0 small text-muted'>{$safeMsg}</p>";
            $html .= "    <div class='mt-1 d-flex flex-wrap align-items-center gap-1'>";
            $html .= "      <span class='badge bg-warning-lt text-dark me-1'>" . __('Status:', 'restrictstatus') . " {$safeStatus}</span>";
            $html .= "      <span class='badge bg-secondary-lt text-dark me-2'>" . __('Perfil:', 'restrictstatus') . " {$safeProfile}</span>";
            $html .= "    </div>";
            $html .= "  </div>";
            $html .= "</div>";
        }

        // Script de execução imediata para aplicar atributos no body e executar bloqueio visual instantâneo
        $html .= "<script>";
        $html .= "(function() {";
        $html .= "    document.body.setAttribute('data-restrictstatus', '1');";
        $html .= "    document.body.setAttribute('data-restrict-followups', '{$flagFollowup}');";
        $html .= "    document.body.setAttribute('data-restrict-solutions', '{$flagSolution}');";
        $html .= "    document.body.setAttribute('data-restrict-documents', '{$flagDocument}');";
        $html .= "    document.body.setAttribute('data-restrict-tasks', '{$flagTask}');";
        $html .= "    if ('{$flagFollowup}' === '1') { document.body.classList.add('restrict-followups'); }";
        $html .= "    if ('{$flagSolution}' === '1') { document.body.classList.add('restrict-solutions'); }";
        $html .= "    if ('{$flagDocument}' === '1') { document.body.classList.add('restrict-documents'); }";
        $html .= "    if ('{$flagTask}' === '1') { document.body.classList.add('restrict-tasks'); }";
        $html .= "    if (typeof RestrictStatus !== 'undefined' && typeof RestrictStatus.applyRestriction === 'function') {";
        $html .= "        RestrictStatus.applyRestriction();";
        $html .= "    } else {";
        $html .= "        document.addEventListener('DOMContentLoaded', function() {";
        $html .= "            if (typeof RestrictStatus !== 'undefined') { RestrictStatus.applyRestriction(); }";
        $html .= "        });";
        $html .= "    }";
        $html .= "})();";
        $html .= "</script>";

        return $html;
    }
}
