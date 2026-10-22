<?php
/**
 * ---------------------------------------------------------------------
 * Restrict Status - Plugin GLPI 10 & 11
 * Página de Configuração, Gerenciamento de Status e Painel de Auditoria
 * ---------------------------------------------------------------------
 * @author    Wilter Porto
 * @copyright 2026 Wilter Porto
 * @license   GPLv3+
 * ---------------------------------------------------------------------
 */

declare(strict_types=1);

include ("../../../inc/includes.php");
require_once __DIR__ . '/../inc/enums.class.php';
require_once __DIR__ . '/../inc/profile.class.php';

global $CFG_GLPI;

$rootDoc = is_array($CFG_GLPI) ? ($CFG_GLPI['root_doc'] ?? '') : '';
$configUrl = $rootDoc . '/plugins/restrictstatus/front/config.php';

// Verificação de permissão: administradores com direito "config" ou "profile" ou Super-Admin
if (!Session::haveRight("config", READ) && !Session::haveRight("profile", READ) && !PluginRestrictstatusProfile::isCurrentUserSuperAdmin()) {
    Session::checkRight("config", READ);
}

$currentEntity = (int)($_SESSION['glpiactive_entity'] ?? 0);
$isSuperAdmin = PluginRestrictstatusProfile::isCurrentUserSuperAdmin();
$activeTab = $_GET['tab'] ?? 'statuses';

// =========================================================================
// PROCESSAMENTO DE FORMULÁRIOS COM PROTEÇÃO CSRF E AUDITORIA
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Session::haveRight("config", UPDATE) && !Session::haveRight("profile", UPDATE) && !$isSuperAdmin) {
        Session::checkRight("config", UPDATE);
    }

    // Proteção CSRF compatível com GLPI 10 e GLPI 11:
    // No GLPI 11 o CheckCsrfListener já valida o token no Kernel antes de carregar o controller.
    // Caso o token ainda conste na sessão (GLPI 10), valida com validateCSRF sem lançar exceção indevida.
    if (isset($_POST['_glpi_csrf_token']) && isset($_SESSION['glpicsrftokens'][$_POST['_glpi_csrf_token']])) {
        Session::validateCSRF($_POST);
    }

    // 1. Atualização dos Status de Chamados
    if (isset($_POST['save_statuses'])) {
        $statuses = PluginRestrictstatusConfig::getAllTicketStatuses();
        $updatedCount = 0;

        foreach ($statuses as $statusId => $statusLabel) {
            $statusData = [
                'is_active'          => isset($_POST["status_{$statusId}_active"]) ? 1 : 0,
                'restrict_requester' => 1,
                'restrict_observer'  => 1,
                'restrict_assigned'  => 1,
                'restrict_followups' => isset($_POST["status_{$statusId}_followups"]) ? 1 : 0,
                'restrict_solutions' => isset($_POST["status_{$statusId}_solutions"]) ? 1 : 0,
                'restrict_documents' => isset($_POST["status_{$statusId}_documents"]) ? 1 : 0,
                'restrict_tasks'     => isset($_POST["status_{$statusId}_tasks"]) ? 1 : 0,
                'custom_message'     => isset($_POST["status_{$statusId}_message"]) ? trim($_POST["status_{$statusId}_message"]) : '',
            ];

            PluginRestrictstatusConfig::saveStatusConfig($currentEntity, (int)$statusId, $statusData);
            $updatedCount++;
        }

        // Registra evento na auditoria
        PluginRestrictstatusAudit::logAction([
            'action'      => PluginRestrictstatusAuditAction::StatusChanged->value,
            'entities_id' => $currentEntity,
            'details'     => sprintf('Configurações de restrição de status atualizadas para %d status na entidade ID %d', $updatedCount, $currentEntity)
        ]);

        Session::addMessageAfterRedirect(__('Configurações de status salvas com sucesso!', 'restrictstatus'), false, INFO);
        Html::redirect($configUrl . '?tab=statuses&saved=1&msg=' . urlencode('Configurações de status salvas com sucesso!'));
    }

    // 2. Atualização dos Parâmetros Globais
    if (isset($_POST['save_global'])) {
        $defaultMessage = trim($_POST['default_message'] ?? '');

        // Validação de campo obrigatório sinalizado com asterisco vermelho
        if (empty($defaultMessage)) {
            Session::addMessageAfterRedirect(__('O campo Mensagem Padrão de Bloqueio é obrigatório!', 'restrictstatus'), false, ERROR);
            Html::redirect($configUrl . '?tab=global');
        }

        $exemptProfiles = isset($_POST['exempt_profiles']) && is_array($_POST['exempt_profiles'])
            ? array_map('intval', $_POST['exempt_profiles'])
            : [];

        // Salva configurações globais
        Config::setConfigurationValues('plugin:restrictstatus', [
            'plugin_enabled'            => isset($_POST['plugin_enabled']) ? 1 : 0,
            'default_message'           => $defaultMessage,
            'exempt_profiles'           => json_encode($exemptProfiles),
            'apply_to_mailgate'         => isset($_POST['apply_to_mailgate']) ? 1 : 0,
            'show_timeline_alert'       => isset($_POST['show_timeline_alert']) ? 1 : 0
        ]);

        // Registra evento na auditoria
        PluginRestrictstatusAudit::logAction([
            'action'      => PluginRestrictstatusAuditAction::ConfigUpdate->value,
            'entities_id' => $currentEntity,
            'details'     => 'Parâmetros globais do plugin Restrict Status atualizados com sucesso.'
        ]);

        Session::addMessageAfterRedirect(__('Parâmetros globais atualizados com sucesso!', 'restrictstatus'), false, INFO);
        Html::redirect($configUrl . '?tab=global&saved=1&msg=' . urlencode('Parâmetros globais atualizados com sucesso!'));
    }

    // 3. Atualização Rápida de Perfil de Acesso a partir da aba Perfis
    if (isset($_POST['save_profile_quick'])) {
        $pId = (int)($_POST['profile_id'] ?? 0);
        if ($pId > 0) {
            $rMode = isset($_POST['restriction_mode']) ? (int)$_POST['restriction_mode'] : PluginRestrictstatusProfileMode::ApplyRestrictions->value;
            $tAlert = isset($_POST['show_timeline_alert']) ? (int)$_POST['show_timeline_alert'] : PluginRestrictstatusTimelineAlertMode::GlobalDefault->value;
            $cNotice = trim($_POST['custom_notice'] ?? '');

            PluginRestrictstatusProfile::saveProfileConfig($pId, [
                'restriction_mode'    => $rMode,
                'show_timeline_alert' => $tAlert,
                'custom_notice'       => $cNotice
            ]);

            Session::addMessageAfterRedirect(__('Configuração do perfil atualizada com sucesso!', 'restrictstatus'), false, INFO);
            Html::redirect($configUrl . '?tab=profiles&saved=1&msg=' . urlencode('Configuração do perfil atualizada com sucesso!'));
        }
    }
}

// =========================================================================
// RENDERIZAÇÃO DA PÁGINA
// =========================================================================
Html::header(__('Restringir Acompanhamentos por Status', 'restrictstatus'), $configUrl, 'config', 'PluginRestrictstatusMenu');

$globalConfig = Config::getConfigurationValues('plugin:restrictstatus');
$pluginEnabled = (int)($globalConfig['plugin_enabled'] ?? 1);
$defaultMessage = $globalConfig['default_message'] ?? 'Não é permitido adicionar novos acompanhamentos a chamados com o status atual (%s).';
$applyMailgate = (int)($globalConfig['apply_to_mailgate'] ?? 1);
$showTimelineAlert = (int)($globalConfig['show_timeline_alert'] ?? 1);

$savedExemptProfiles = [];
if (!empty($globalConfig['exempt_profiles'])) {
    $savedExemptProfiles = json_decode($globalConfig['exempt_profiles'], true) ?: [];
}

$statusConfigs = PluginRestrictstatusConfig::getStatusConfigsForEntity($currentEntity);

// Dados do usuário para o cabeçalho personalizado
$userName = $_SESSION['glpifriendlyname'] ?? ($_SESSION['glpiname'] ?? 'Usuário');
$userProfile = $_SESSION['glpiactiveprofile']['name'] ?? 'Perfil';
$lastLogin = $_SESSION['glpi_currenttime'] ?? date('d/m/Y H:i:s');
?>

<div class="container-xl my-3">
    <!-- Cabeçalho do Plugin -->
    <div class="row align-items-center mb-4">
        <div class="col">
            <div class="page-pretitle text-muted text-uppercase fw-bold">
                <?= __('Plugin GLPI', 'restrictstatus'); ?> &bull; <?= __('Segurança & Regras de Acompanhamento', 'restrictstatus'); ?>
            </div>
            <h2 class="page-title d-flex align-items-center gap-2">
                <i class="ti ti-lock text-primary fs-1"></i>
                <?= __('Restringir Acompanhamentos por Status', 'restrictstatus'); ?>
                <span class="badge bg-blue-lt ms-2">v<?= PLUGIN_RESTRICTSTATUS_VERSION; ?></span>
                <?php if ($pluginEnabled): ?>
                    <span class="badge bg-success-lt"><i class="ti ti-check me-1"></i><?= __('Ativo', 'restrictstatus'); ?></span>
                <?php else: ?>
                    <span class="badge bg-danger-lt"><i class="ti ti-ban me-1"></i><?= __('Inativo', 'restrictstatus'); ?></span>
                <?php endif; ?>
            </h2>
        </div>
        <div class="col-auto d-flex align-items-center gap-2">
            <button type="button" class="btn btn-outline-info d-flex align-items-center gap-1 shadow-sm" data-bs-toggle="modal" data-bs-target="#modalInfoPlugin">
                <i class="ti ti-info-circle fs-4"></i> <?= __('Como Funciona', 'restrictstatus'); ?>
            </button>
            <div class="card card-sm bg-body-tertiary border-0 shadow-sm px-3 py-2 text-end">
                <div class="fw-bold text-dark d-flex align-items-center justify-content-end gap-1">
                    <i class="ti ti-user text-primary"></i> <?= htmlspecialchars($userName); ?> (<?= htmlspecialchars($userProfile); ?>)
                </div>
                <div class="text-muted small">
                    <?= __('Último acesso:', 'restrictstatus'); ?> <?= htmlspecialchars($lastLogin); ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Informativo com Ícone Ilustrativo e Botões de Ação -->
    <div class="modal modal-blur fade" id="modalInfoPlugin" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
            <div class="modal-content shadow-lg border-0">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title d-flex align-items-center gap-2">
                        <i class="ti ti-info-circle fs-3"></i>
                        <?= __('Como Funciona o Plugin Restrict Status', 'restrictstatus'); ?>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="text-center mb-4">
                        <div class="avatar avatar-xl bg-primary-lt text-primary mb-2 shadow-xs" style="width: 64px; height: 64px; font-size: 32px; border-radius: 50%;">
                            <i class="ti ti-shield-lock"></i>
                        </div>
                        <h4 class="fw-bold text-dark"><?= __('Regras e Funcionamento do Bloqueio de Acompanhamentos', 'restrictstatus'); ?></h4>
                        <p class="text-muted small"><?= __('Visão completa da arquitetura em duas camadas e comportamento multitenant.', 'restrictstatus'); ?></p>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="border rounded p-3 h-100 bg-light">
                                <h6 class="fw-bold text-primary d-flex align-items-center gap-2">
                                    <i class="ti ti-lock"></i> <?= __('1. Bloqueio no Backend', 'restrictstatus'); ?>
                                </h6>
                                <p class="small text-muted mb-0">
                                    <?= __('O hook <code>pre_item_add</code> intercepta todas as inclusões de <code>ITILFollowup</code>. Se o chamado estiver em status restrito para o perfil do usuário, a inclusão é bloqueada no nível do banco de dados.', 'restrictstatus'); ?>
                                </p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="border rounded p-3 h-100 bg-light">
                                <h6 class="fw-bold text-primary d-flex align-items-center gap-2">
                                    <i class="ti ti-eye-off"></i> <?= __('2. Interface Dinâmica', 'restrictstatus'); ?>
                                </h6>
                                <p class="small text-muted mb-0">
                                    <?= __('No frontend, os botões "Responder" e caixas de texto são ocultados e bloqueados via JavaScript com <code>MutationObserver</code>, exibindo um banner informativo.', 'restrictstatus'); ?>
                                </p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="border rounded p-3 h-100 bg-light">
                                <h6 class="fw-bold text-primary d-flex align-items-center gap-2">
                                    <i class="ti ti-user-shield"></i> <?= __('3. Perfis Isentos', 'restrictstatus'); ?>
                                </h6>
                                <p class="small text-muted mb-0">
                                    <?= __('Perfis configurados como isentos (ex: Super-Admin) sempre podem responder a chamados, garantindo a gestão contínua pelo suporte técnico.', 'restrictstatus'); ?>
                                </p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="border rounded p-3 h-100 bg-light">
                                <h6 class="fw-bold text-primary d-flex align-items-center gap-2">
                                    <i class="ti ti-history"></i> <?= __('4. Auditoria Pagina a 30', 'restrictstatus'); ?>
                                </h6>
                                <p class="small text-muted mb-0">
                                    <?= __('Tentativas de respostas bloqueadas e alterações de parâmetros são registradas na auditoria com paginação limitada a 30 por página.', 'restrictstatus'); ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-body-tertiary">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        <?= __('Fechar', 'restrictstatus'); ?>
                    </button>
                    <a href="?tab=statuses" class="btn btn-primary d-flex align-items-center gap-2">
                        <i class="ti ti-settings"></i>
                        <?= __('Ir para Regras de Status', 'restrictstatus'); ?>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php if (isset($_GET['saved']) && !empty($_GET['msg'])): 
        $popupMsg = htmlspecialchars($_GET['msg'], ENT_QUOTES, 'UTF-8');
    ?>
    <!-- Popup de Sucesso sem Botões para Confirmação (Desaparece automaticamente) -->
    <div id="restrictstatus-success-popup" class="toast show position-fixed top-0 end-0 m-4 shadow-lg border-0 bg-success text-white" style="z-index: 99999; min-width: 320px;" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="toast-body d-flex align-items-center gap-3 py-3 px-3">
            <div class="fs-2 text-white">
                <i class="ti ti-circle-check"></i>
            </div>
            <div class="flex-grow-1">
                <strong class="d-block mb-1 fs-5"><?= __('Sucesso!', 'restrictstatus'); ?></strong>
                <div class="small opacity-90"><?= $popupMsg; ?></div>
            </div>
        </div>
        <div class="progress" style="height: 3px; background: rgba(255,255,255,0.2);">
            <div id="popup-progress" class="progress-bar bg-white" style="width: 100%; transition: width 3.5s linear;"></div>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const popup = document.getElementById('restrictstatus-success-popup');
        const progressBar = document.getElementById('popup-progress');
        if (popup) {
            setTimeout(() => { if (progressBar) progressBar.style.width = '0%'; }, 50);
            setTimeout(() => {
                popup.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
                popup.style.opacity = '0';
                popup.style.transform = 'translateY(-20px)';
                setTimeout(() => popup.remove(), 600);
            }, 3500);
        }
    });
    </script>
    <?php endif; ?>

    <!-- Navegação por Abas -->
    <div class="card shadow-sm border-0">
        <div class="card-header border-bottom">
            <ul class="nav nav-tabs card-header-tabs" role="tablist">
                <li class="nav-item">
                    <a class="nav-link <?= $activeTab === 'statuses' ? 'active' : ''; ?>" href="?tab=statuses">
                        <i class="ti ti-list-check me-2"></i><?= __('Configuração por Status', 'restrictstatus'); ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $activeTab === 'profiles' ? 'active' : ''; ?>" href="?tab=profiles">
                        <i class="ti ti-user-shield me-2"></i><?= __('Perfis de Acesso', 'restrictstatus'); ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $activeTab === 'global' ? 'active' : ''; ?>" href="?tab=global">
                        <i class="ti ti-adjustments me-2"></i><?= __('Parâmetros Gerais', 'restrictstatus'); ?>
                    </a>
                </li>
                <?php if ($isSuperAdmin || Session::haveRight('config', READ)): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $activeTab === 'audit' ? 'active' : ''; ?>" href="?tab=audit">
                        <i class="ti ti-shield-check me-2"></i><?= __('Auditoria & Histórico', 'restrictstatus'); ?>
                        <span class="badge bg-secondary-lt ms-1"><?= __('Superadmin', 'restrictstatus'); ?></span>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </div>

        <div class="card-body p-4">
            <!-- ============================================================== -->
            <!-- ABA 1: CONFIGURAÇÃO POR STATUS DE CHAMADO                      -->
            <!-- ============================================================== -->
            <?php if ($activeTab === 'statuses'): ?>
                <div class="mb-3">
                    <h4 class="card-title fw-bold text-dark"><?= __('Regras de Bloqueio por Status de Chamado', 'restrictstatus'); ?></h4>
                    <p class="text-muted small">
                        <?= __('Selecione quais status do chamado devem impedir a inclusão de novos comentários/acompanhamentos e quais itens serão bloqueados.', 'restrictstatus'); ?>
                    </p>
                </div>

                <form method="post" action="<?= $configUrl; ?>?tab=statuses">
                    <input type="hidden" name="_glpi_csrf_token" value="<?= Session::getNewCSRFToken(); ?>" />
                    <div class="table-responsive">
                        <table class="table table-vcenter table-hover table-striped border rounded">
                            <thead class="table-light">
                                <tr>
                                    <th style="min-width: 180px;"><?= __('Status do Chamado', 'restrictstatus'); ?></th>
                                    <th class="text-center" style="width: 90px;"><?= __('Ativo', 'restrictstatus'); ?></th>
                                    <th class="text-center" style="min-width: 320px;"><?= __('Itens / Ações Bloqueadas', 'restrictstatus'); ?></th>
                                    <th style="min-width: 240px;"><?= __('Mensagem Personalizada (Opcional)', 'restrictstatus'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($statusConfigs as $statusId => $cfg): 
                                    $visuals = PluginRestrictstatusConfig::getStatusVisuals($statusId);
                                ?>
                                <tr>
                                    <td>
                                        <span class="badge <?= $visuals['badge_class']; ?> py-2 px-3 fs-6 d-inline-flex align-items-center gap-1 shadow-xs">
                                            <i class="<?= $visuals['icon']; ?>"></i>
                                            <?= htmlspecialchars($cfg['status_name']); ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <label class="form-check form-switch d-inline-block m-0">
                                            <input class="form-check-input" type="checkbox" name="status_<?= $statusId; ?>_active" value="1" <?= !empty($cfg['is_active']) ? 'checked' : ''; ?>>
                                        </label>
                                    </td>
                                    <td>
                                        <div class="d-flex flex-wrap gap-2 justify-content-center">
                                            <label class="form-check form-check-inline m-0" title="<?= __('Bloquear Acompanhamentos', 'restrictstatus'); ?>">
                                                <input class="form-check-input" type="checkbox" name="status_<?= $statusId; ?>_followups" value="1" <?= !empty($cfg['restrict_followups']) ? 'checked' : ''; ?>>
                                                <span class="form-check-label small"><i class="ti ti-message-circle text-primary"></i> <?= __('Acompanhamentos', 'restrictstatus'); ?></span>
                                            </label>
                                            <label class="form-check form-check-inline m-0" title="<?= __('Bloquear Soluções', 'restrictstatus'); ?>">
                                                <input class="form-check-input" type="checkbox" name="status_<?= $statusId; ?>_solutions" value="1" <?= !empty($cfg['restrict_solutions']) ? 'checked' : ''; ?>>
                                                <span class="form-check-label small"><i class="ti ti-bulb text-warning"></i> <?= __('Soluções', 'restrictstatus'); ?></span>
                                            </label>
                                            <label class="form-check form-check-inline m-0" title="<?= __('Bloquear Documentos', 'restrictstatus'); ?>">
                                                <input class="form-check-input" type="checkbox" name="status_<?= $statusId; ?>_documents" value="1" <?= !empty($cfg['restrict_documents']) ? 'checked' : ''; ?>>
                                                <span class="form-check-label small"><i class="ti ti-paperclip text-danger"></i> <?= __('Documentos', 'restrictstatus'); ?></span>
                                            </label>
                                            <label class="form-check form-check-inline m-0" title="<?= __('Bloquear Tarefas', 'restrictstatus'); ?>">
                                                <input class="form-check-input" type="checkbox" name="status_<?= $statusId; ?>_tasks" value="1" <?= !empty($cfg['restrict_tasks']) ? 'checked' : ''; ?>>
                                                <span class="form-check-label small"><i class="ti ti-checkup-list text-purple"></i> <?= __('Tarefas', 'restrictstatus'); ?></span>
                                            </label>
                                        </div>
                                    </td>
                                    <td>
                                        <input type="text" class="form-control form-control-sm" name="status_<?= $statusId; ?>_message" 
                                               value="<?= htmlspecialchars($cfg['custom_message'] ?? ''); ?>" 
                                               placeholder="<?= sprintf(__('Padrão: %s', 'restrictstatus'), htmlspecialchars($defaultMessage)); ?>">
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4 d-flex justify-content-end">
                        <button type="submit" name="save_statuses" class="btn btn-primary d-flex align-items-center gap-2 px-4 shadow-sm">
                            <i class="ti ti-device-floppy"></i>
                            <?= __('Salvar Regras de Status', 'restrictstatus'); ?>
                        </button>
                    </div>
                    <?php Html::closeForm(); ?>

            <!-- ============================================================== -->
            <!-- ABA 2: PARÂMETROS GERAIS DO PLUGIN                            -->
            <!-- ============================================================== -->
            <?php elseif ($activeTab === 'global'): ?>
                <div class="mb-4">
                    <h4 class="card-title fw-bold text-dark"><?= __('Configurações Gerais e Segurança', 'restrictstatus'); ?></h4>
                    <p class="text-muted small">
                        <?= __('Ajuste os parâmetros operacionais, perfis com permissão de exceção e comportamento da interface.', 'restrictstatus'); ?>
                    </p>
                </div>

                <form method="post" action="<?= $configUrl; ?>?tab=global">
                    <input type="hidden" name="_glpi_csrf_token" value="<?= Session::getNewCSRFToken(); ?>" />
                    <div class="row g-3">
                        <!-- Habilitar Plugin -->
                        <div class="col-12">
                            <div class="card card-sm bg-body-tertiary border p-3">
                                <div class="form-check form-switch m-0 d-flex align-items-center gap-3">
                                    <input class="form-check-input fs-3" type="checkbox" id="plugin_enabled" name="plugin_enabled" value="1" <?= $pluginEnabled ? 'checked' : ''; ?>>
                                    <div>
                                        <label class="form-check-label fw-bold text-dark fs-5" for="plugin_enabled">
                                            <?= __('Ativar Plugin Restrict Status', 'restrictstatus'); ?>
                                        </label>
                                        <div class="text-muted small"><?= __('Controla globalmente se as restrições configuradas estão ativas no GLPI.', 'restrictstatus'); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Mensagem Padrão de Bloqueio (CAMPO OBRIGATÓRIO COM ASTERISCO VERMELHO) -->
                        <div class="col-12">
                            <label class="form-label fw-bold">
                                <?= __('Mensagem Padrão de Bloqueio', 'restrictstatus'); ?> <span style="color: red;">*</span>
                            </label>
                            <input type="text" class="form-control" name="default_message" value="<?= htmlspecialchars($defaultMessage); ?>" required>
                            <div class="form-text text-muted">
                                <?= __('Use <code>%s</code> no texto para que o nome do status seja inserido dinamicamente (Ex: "Não é permitido adicionar comentários no status %s").', 'restrictstatus'); ?>
                            </div>
                        </div>

                        <!-- Opção Coletor de E-mail / Mailgate -->
                        <div class="col-md-6">
                            <div class="card card-sm border p-3 h-100">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="apply_to_mailgate" name="apply_to_mailgate" value="1" <?= $applyMailgate ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-bold" for="apply_to_mailgate">
                                        <?= __('Aplicar Restrição a E-mails (Mailgate)', 'restrictstatus'); ?>
                                    </label>
                                    <div class="text-muted small mt-1">
                                        <?= __('Se ativado, rejeita respostas via coletor de e-mail quando o chamado estiver em status restrito para o remetente.', 'restrictstatus'); ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Exibir Alerta na Timeline -->
                        <div class="col-md-6">
                            <div class="card card-sm border p-3 h-100">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="show_timeline_alert" name="show_timeline_alert" value="1" <?= $showTimelineAlert ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-bold" for="show_timeline_alert">
                                        <?= __('Exibir Banner de Alerta Visual na Timeline do Chamado', 'restrictstatus'); ?>
                                    </label>
                                    <div class="text-muted small mt-1">
                                        <?= __('Exibe um aviso elegante informando o motivo do bloqueio para o usuário quando visualizar o chamado.', 'restrictstatus'); ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Configuração Integrada nos Perfis de Acesso -->
                        <div class="col-12">
                            <div class="card card-sm bg-body-tertiary border p-3">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <label class="form-label fw-bold m-0 d-flex align-items-center gap-2 fs-5">
                                        <i class="ti ti-user-shield text-primary"></i>
                                        <?= __('Aplicação de Bloqueios por Perfil de Acesso', 'restrictstatus'); ?>
                                    </label>
                                    <a href="?tab=profiles" class="btn btn-outline-primary btn-sm d-flex align-items-center gap-1">
                                        <i class="ti ti-settings"></i>
                                        <?= __('Gerenciar Perfis de Acesso', 'restrictstatus'); ?>
                                    </a>
                                </div>
                                <p class="text-muted small mb-2">
                                    <?= __('O plugin é configurado no perfil de acesso (em <strong>Administração &gt; Perfis</strong> na aba <strong>Restringir Status</strong> ou na aba <strong>Perfis de Acesso</strong> acima), determinando individualmente para quais perfis as regras de bloqueio de acompanhamentos deverão ser aplicadas.', 'restrictstatus'); ?>
                                </p>
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <?php
                                    $allProfStats = PluginRestrictstatusProfile::getPaginatedProfiles(1, 100);
                                    $cntApply = 0;
                                    $cntExempt = 0;
                                    foreach ($allProfStats['rows'] as $pr) {
                                        if ($pr['restriction_mode'] === PluginRestrictstatusProfileMode::ApplyRestrictions->value) {
                                            $cntApply++;
                                        } else {
                                            $cntExempt++;
                                        }
                                    }
                                    ?>
                                    <span class="badge bg-danger-lt fs-6 py-1 px-2">
                                        <i class="ti ti-lock me-1"></i>
                                        <?= sprintf(__('%d com Bloqueio Ativo', 'restrictstatus'), $cntApply); ?>
                                    </span>
                                    <span class="badge bg-success-lt fs-6 py-1 px-2">
                                        <i class="ti ti-shield-check me-1"></i>
                                        <?= sprintf(__('%d Perfis Isentos', 'restrictstatus'), $cntExempt); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 d-flex justify-content-end">
                        <button type="submit" name="save_global" class="btn btn-primary d-flex align-items-center gap-2 px-4 shadow-sm">
                            <i class="ti ti-device-floppy"></i>
                            <?= __('Salvar Parâmetros Globais', 'restrictstatus'); ?>
                        </button>
                    </div>
                    <?php Html::closeForm(); ?>

            <!-- ============================================================== -->
            <!-- ABA 3: PAINEL DE AUDITORIA E HISTÓRICO (EXCLUSIVO ADMIN)       -->
            <!-- ============================================================== -->
            <?php elseif ($activeTab === 'audit' && ($isSuperAdmin || Session::haveRight('config', READ))): ?>
                <?php
                $page = max(1, (int)($_GET['page'] ?? 1));
                $filters = [
                    'action'    => $_GET['filter_action'] ?? '',
                    'ticket_id' => $_GET['filter_ticket'] ?? '',
                    'search'    => $_GET['filter_search'] ?? '',
                ];

                $auditData = PluginRestrictstatusAudit::getPaginatedAudits($page, 30, $filters);
                ?>

                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h4 class="card-title fw-bold text-dark m-0"><?= __('Módulo de Auditoria & Histórico de Operações', 'restrictstatus'); ?></h4>
                        <p class="text-muted small m-0"><?= __('Registro completo de bloqueios de comentários e alterações de configuração (Exclusivo Superadmin).', 'restrictstatus'); ?></p>
                    </div>
                    <span class="badge bg-primary-lt fs-6">
                        <?= sprintf(__('Total de Registros: %d', 'restrictstatus'), $auditData['total']); ?>
                    </span>
                </div>

                <!-- Formulário de Filtros -->
                <form method="get" action="<?= $configUrl; ?>" class="card card-sm bg-light border p-3 mb-3">
                    <input type="hidden" name="tab" value="audit">
                    <div class="row g-2 align-items-center">
                        <div class="col-md-3">
                            <select name="filter_action" class="form-select form-select-sm">
                                <option value=""><?= __('Todos os Tipos de Ação', 'restrictstatus'); ?></option>
                                <?php foreach (PluginRestrictstatusAuditAction::cases() as $actionCase): ?>
                                    <option value="<?= $actionCase->value; ?>" <?= $filters['action'] === $actionCase->value ? 'selected' : ''; ?>>
                                        <?= $actionCase->label(); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <input type="number" name="filter_ticket" class="form-control form-control-sm" placeholder="<?= __('ID Chamado', 'restrictstatus'); ?>" value="<?= htmlspecialchars($filters['ticket_id']); ?>">
                        </div>
                        <div class="col-md-4">
                            <input type="text" name="filter_search" class="form-control form-control-sm" placeholder="<?= __('Buscar por usuário, papel ou detalhes...', 'restrictstatus'); ?>" value="<?= htmlspecialchars($filters['search']); ?>">
                        </div>
                        <div class="col-md-3 d-flex gap-2">
                            <button type="submit" class="btn btn-sm btn-primary flex-fill">
                                <i class="ti ti-search me-1"></i><?= __('Filtrar', 'restrictstatus'); ?>
                            </button>
                            <a href="<?= $configUrl; ?>?tab=audit" class="btn btn-sm btn-outline-secondary">
                                <?= __('Limpar', 'restrictstatus'); ?>
                            </a>
                        </div>
                    </div>
                </form>

                <!-- Tabela de Auditoria Pagina a 30 registros -->
                <div class="table-responsive">
                    <table class="table table-vcenter table-hover table-striped border rounded">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 150px;"><?= __('Data/Hora', 'restrictstatus'); ?></th>
                                <th style="width: 170px;"><?= __('Usuário & Perfil', 'restrictstatus'); ?></th>
                                <th style="width: 150px;"><?= __('Ação', 'restrictstatus'); ?></th>
                                <th style="width: 110px;"><?= __('Chamado', 'restrictstatus'); ?></th>
                                <th style="width: 130px;"><?= __('Perfil Restrito', 'restrictstatus'); ?></th>
                                <th><?= __('Detalhes da Ação', 'restrictstatus'); ?></th>
                                <th style="width: 110px;"><?= __('IP', 'restrictstatus'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($auditData['rows'])): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">
                                        <i class="ti ti-inbox fs-2 d-block mb-2"></i>
                                        <?= __('Nenhum registro de auditoria encontrado com os filtros atuais.', 'restrictstatus'); ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($auditData['rows'] as $audit): 
                                    $actionEnum = PluginRestrictstatusAuditAction::tryFrom($audit['action']);
                                    $actionBadge = $actionEnum ? $actionEnum->badgeClass() : 'bg-secondary text-white';
                                    $actionIcon = $actionEnum ? $actionEnum->icon() : 'ti ti-activity';
                                    $actionLabel = $actionEnum ? $actionEnum->label() : htmlspecialchars($audit['action']);
                                ?>
                                <tr>
                                    <td class="small text-muted"><?= htmlspecialchars(date('d/m/Y H:i:s', strtotime($audit['date_creation']))); ?></td>
                                    <td>
                                        <div class="fw-bold"><?= htmlspecialchars($audit['user_name']); ?></div>
                                        <div class="text-muted small"><?= htmlspecialchars($audit['user_profile']); ?></div>
                                    </td>
                                    <td>
                                        <span class="badge <?= $actionBadge; ?> py-1 px-2 d-inline-flex align-items-center gap-1">
                                            <i class="<?= $actionIcon; ?>"></i>
                                            <?= $actionLabel; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($audit['ticket_id'])): ?>
                                            <a href="<?= ($CFG_GLPI['root_doc'] ?? ''); ?>/front/ticket.form.php?id=<?= (int)$audit['ticket_id']; ?>" target="_blank" class="fw-bold">
                                                #<?= (int)$audit['ticket_id']; ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($audit['role_name'])): ?>
                                            <span class="badge bg-warning-lt text-dark"><?= htmlspecialchars($audit['role_name']); ?></span>
                                        <?php elseif (!empty($audit['user_profile'])): ?>
                                            <span class="badge bg-warning-lt text-dark"><?= htmlspecialchars($audit['user_profile']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small"><?= htmlspecialchars($audit['details']); ?></td>
                                    <td class="small text-muted"><?= htmlspecialchars($audit['ip_address']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Paginação (Regra: Limitada a 30 por página) -->
                <?php if ($auditData['pages'] > 1): ?>
                <div class="d-flex justify-content-between align-items-center mt-3">
                    <div class="text-muted small">
                        <?= sprintf(__('Página %1$d de %2$d (Mostrando até 30 registros por página)', 'restrictstatus'), $auditData['current_page'], $auditData['pages']); ?>
                    </div>
                    <ul class="pagination pagination-sm m-0">
                        <?php if ($auditData['current_page'] > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?tab=audit&page=<?= ($auditData['current_page'] - 1); ?>&filter_action=<?= urlencode($filters['action']); ?>&filter_ticket=<?= urlencode($filters['ticket_id']); ?>&filter_search=<?= urlencode($filters['search']); ?>">
                                    <i class="ti ti-chevron-left"></i>
                                </a>
                            </li>
                        <?php endif; ?>

                        <?php 
                        $startP = max(1, $auditData['current_page'] - 2);
                        $endP   = min($auditData['pages'], $auditData['current_page'] + 2);
                        for ($p = $startP; $p <= $endP; $p++): 
                        ?>
                            <li class="page-item <?= $p === $auditData['current_page'] ? 'active' : ''; ?>">
                                <a class="page-link" href="?tab=audit&page=<?= $p; ?>&filter_action=<?= urlencode($filters['action']); ?>&filter_ticket=<?= urlencode($filters['ticket_id']); ?>&filter_search=<?= urlencode($filters['search']); ?>">
                                    <?= $p; ?>
                                </a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($auditData['current_page'] < $auditData['pages']): ?>
                            <li class="page-item">
                                <a class="page-link" href="?tab=audit&page=<?= ($auditData['current_page'] + 1); ?>&filter_action=<?= urlencode($filters['action']); ?>&filter_ticket=<?= urlencode($filters['ticket_id']); ?>&filter_search=<?= urlencode($filters['search']); ?>">
                                    <i class="ti ti-chevron-right"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
                <?php endif; ?>

            <!-- ============================================================== -->
            <!-- ABA 4: PERFIS DE ACESSO (DETERMINAÇÃO DAS REGRAS POR PERFIL)    -->
            <!-- ============================================================== -->
            <?php elseif ($activeTab === 'profiles'): ?>
                <?php
                $profilePage = max(1, (int)($_GET['profile_page'] ?? 1));
                $profileSearch = trim($_GET['profile_search'] ?? '');
                $profilesData = PluginRestrictstatusProfile::getPaginatedProfiles($profilePage, 30, $profileSearch);
                global $CFG_GLPI;
                ?>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h4 class="card-title fw-bold text-dark m-0 d-flex align-items-center gap-2">
                            <i class="ti ti-user-shield text-primary"></i>
                            <?= __('Controle de Bloqueio por Perfil de Acesso', 'restrictstatus'); ?>
                        </h4>
                        <p class="text-muted small m-0">
                            <?= __('Configure para quais perfis de acesso as regras de bloqueio de acompanhamentos deverão ser aplicadas.', 'restrictstatus'); ?>
                        </p>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-info btn-sm d-flex align-items-center gap-1 shadow-sm" data-bs-toggle="modal" data-bs-target="#modalInfoProfileTab">
                            <i class="ti ti-info-circle"></i> <?= __('Como Funciona', 'restrictstatus'); ?>
                        </button>
                        <a href="<?= ($CFG_GLPI['root_doc'] ?? ''); ?>/front/profile.php" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1 shadow-sm" target="_blank">
                            <i class="ti ti-external-link"></i> <?= __('Perfis do GLPI', 'restrictstatus'); ?>
                        </a>
                    </div>
                </div>

                <!-- Formulário de Filtro de Perfis -->
                <form method="get" action="<?= $configUrl; ?>" class="card card-sm bg-light border p-3 mb-3">
                    <input type="hidden" name="tab" value="profiles">
                    <div class="row g-2 align-items-center">
                        <div class="col-md-4">
                            <input type="text" name="profile_search" class="form-control form-control-sm" 
                                   value="<?= htmlspecialchars($profileSearch); ?>" 
                                   placeholder="<?= __('Pesquisar perfil pelo nome...', 'restrictstatus'); ?>">
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-sm btn-primary d-flex align-items-center gap-1">
                                <i class="ti ti-search"></i> <?= __('Filtrar', 'restrictstatus'); ?>
                            </button>
                        </div>
                        <?php if (!empty($profileSearch)): ?>
                        <div class="col-auto">
                            <a href="?tab=profiles" class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1">
                                <i class="ti ti-x"></i> <?= __('Limpar', 'restrictstatus'); ?>
                            </a>
                        </div>
                        <?php endif; ?>
                        <div class="col text-end">
                            <span class="badge bg-primary-lt fs-6">
                                <?= sprintf(__('Total de Perfis: %d', 'restrictstatus'), $profilesData['total']); ?>
                            </span>
                        </div>
                    </div>
                </form>

                <!-- Tabela de Perfis -->
                <div class="table-responsive">
                    <table class="table table-vcenter table-hover table-striped border rounded">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 70px;">ID</th>
                                <th><?= __('Perfil de Acesso', 'restrictstatus'); ?></th>
                                <th style="width: 140px;"><?= __('Interface GLPI', 'restrictstatus'); ?></th>
                                <th style="width: 220px;" class="text-center"><?= __('Aplicação de Bloqueio', 'restrictstatus'); ?></th>
                                <th style="width: 200px;" class="text-center"><?= __('Alerta na Timeline', 'restrictstatus'); ?></th>
                                <th><?= __('Mensagem Customizada', 'restrictstatus'); ?></th>
                                <th style="width: 220px;" class="text-end"><?= __('Ações', 'restrictstatus'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($profilesData['rows'])): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">
                                        <i class="ti ti-mood-empty fs-2 d-block mb-1"></i>
                                        <?= __('Nenhum perfil de acesso encontrado.', 'restrictstatus'); ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($profilesData['rows'] as $pRow): 
                                    $modeCase = PluginRestrictstatusProfileMode::tryFrom($pRow['restriction_mode']) ?? PluginRestrictstatusProfileMode::ApplyRestrictions;
                                    $alertCase = PluginRestrictstatusTimelineAlertMode::tryFrom($pRow['show_timeline_alert']) ?? PluginRestrictstatusTimelineAlertMode::GlobalDefault;
                                ?>
                                <tr>
                                    <td><span class="text-muted fw-bold">#<?= $pRow['id']; ?></span></td>
                                    <td>
                                        <div class="fw-bold text-dark d-flex align-items-center gap-2">
                                            <i class="ti ti-user text-muted"></i>
                                            <?= htmlspecialchars($pRow['name']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary-lt">
                                            <?= htmlspecialchars($pRow['interface']); ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?= $modeCase->badgeClass(); ?> py-1 px-2 d-inline-flex align-items-center gap-1 shadow-xs">
                                            <i class="<?= $modeCase->icon(); ?>"></i>
                                            <?= $modeCase->label(); ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-body-secondary text-dark py-1 px-2 d-inline-flex align-items-center gap-1">
                                            <i class="<?= $alertCase->icon(); ?>"></i>
                                            <?= $alertCase->label(); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="small text-muted">
                                            <?= !empty($pRow['custom_notice']) ? htmlspecialchars($pRow['custom_notice']) : '<em class="text-muted opacity-50">' . __('(Padrão)', 'restrictstatus') . '</em>'; ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm">
                                            <!-- Botão de Edição Rápida (Modal) -->
                                            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalQuickEditProfile_<?= $pRow['id']; ?>" title="<?= __('Edição Rápida', 'restrictstatus'); ?>">
                                                <i class="ti ti-pencil me-1"></i> <?= __('Editar', 'restrictstatus'); ?>
                                            </button>
                                            <!-- Link para a aba do perfil no GLPI -->
                                            <a href="<?= ($CFG_GLPI['root_doc'] ?? ''); ?>/front/profile.form.php?id=<?= $pRow['id']; ?>&forcetab=PluginRestrictstatusProfile$1" 
                                               class="btn btn-outline-secondary" target="_blank" title="<?= __('Abrir no Perfil do GLPI', 'restrictstatus'); ?>">
                                                <i class="ti ti-external-link"></i>
                                            </a>
                                        </div>

                                        <!-- Modal de Edição Rápida para este perfil -->
                                        <div class="modal modal-blur fade text-start" id="modalQuickEditProfile_<?= $pRow['id']; ?>" tabindex="-1" role="dialog" aria-hidden="true">
                                            <div class="modal-dialog modal-dialog-centered" role="document">
                                                <div class="modal-content shadow-lg border-0">
                                                    <div class="modal-header bg-primary text-white">
                                                        <h5 class="modal-title d-flex align-items-center gap-2">
                                                            <i class="ti ti-user-cog fs-3"></i>
                                                            <?= sprintf(__('Configurar Perfil: %s', 'restrictstatus'), htmlspecialchars($pRow['name'])); ?>
                                                        </h5>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>
                                                    <form method="post" action="<?= $configUrl; ?>?tab=profiles">
                                                        <input type="hidden" name="_glpi_csrf_token" value="<?= Session::getNewCSRFToken(); ?>" />
                                                        <input type="hidden" name="tab" value="profiles">
                                                        <input type="hidden" name="profile_id" value="<?= $pRow['id']; ?>">
                                                        <div class="modal-body p-4">
                                                            <!-- Modo de Bloqueio (OBRIGATÓRIO COM ASTERISCO VERMELHO) -->
                                                            <div class="mb-3">
                                                                <label class="form-label fw-bold">
                                                                    <?= __('Regra de Bloqueio para este Perfil', 'restrictstatus'); ?> <span style="color: red;">*</span>
                                                                </label>
                                                                <select name="restriction_mode" class="form-select" required>
                                                                    <?php foreach (PluginRestrictstatusProfileMode::cases() as $mCase): ?>
                                                                        <option value="<?= $mCase->value; ?>" <?= $pRow['restriction_mode'] === $mCase->value ? 'selected' : ''; ?>>
                                                                            <?= $mCase->label(); ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                                <div class="form-text text-muted">
                                                                    <?= __('Defina se os usuários deste perfil devem sofrer bloqueio nos status configurados.', 'restrictstatus'); ?>
                                                                </div>
                                                            </div>

                                                            <!-- Alerta na Timeline -->
                                                            <div class="mb-3">
                                                                <label class="form-label fw-bold">
                                                                    <?= __('Exibição de Alerta na Timeline', 'restrictstatus'); ?>
                                                                </label>
                                                                <select name="show_timeline_alert" class="form-select">
                                                                    <?php foreach (PluginRestrictstatusTimelineAlertMode::cases() as $aCase): ?>
                                                                        <option value="<?= $aCase->value; ?>" <?= $pRow['show_timeline_alert'] === $aCase->value ? 'selected' : ''; ?>>
                                                                            <?= $aCase->label(); ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>

                                                            <!-- Mensagem Customizada -->
                                                            <div class="mb-3">
                                                                <label class="form-label fw-bold">
                                                                    <?= __('Mensagem Customizada para este Perfil (Opcional)', 'restrictstatus'); ?>
                                                                </label>
                                                                <input type="text" name="custom_notice" class="form-control" 
                                                                       value="<?= htmlspecialchars($pRow['custom_notice'] ?? ''); ?>" 
                                                                       placeholder="<?= __('Mensagem específica para usuários deste perfil (use %s para status)', 'restrictstatus'); ?>">
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer bg-body-tertiary">
                                                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                                                                <?= __('Cancelar', 'restrictstatus'); ?>
                                                            </button>
                                                            <button type="submit" name="save_profile_quick" class="btn btn-primary d-flex align-items-center gap-1 shadow-sm">
                                                                <i class="ti ti-device-floppy"></i>
                                                                <?= __('Salvar Configuração', 'restrictstatus'); ?>
                                                            </button>
                                                        </div>
                                                        <?php Html::closeForm(); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Paginação Limitada a 30 por página (Regra Obrigatória) -->
                <?php if ($profilesData['pages'] > 1): ?>
                <div class="d-flex justify-content-between align-items-center mt-3">
                    <div class="text-muted small">
                        <?= sprintf(__('Página %1$d de %2$d (Mostrando até 30 perfis por página)', 'restrictstatus'), $profilesData['current_page'], $profilesData['pages']); ?>
                    </div>
                    <ul class="pagination pagination-sm m-0">
                        <?php if ($profilesData['current_page'] > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?tab=profiles&profile_page=<?= ($profilesData['current_page'] - 1); ?>&profile_search=<?= urlencode($profileSearch); ?>">
                                    <i class="ti ti-chevron-left"></i>
                                </a>
                            </li>
                        <?php endif; ?>

                        <?php 
                        $startP = max(1, $profilesData['current_page'] - 2);
                        $endP   = min($profilesData['pages'], $profilesData['current_page'] + 2);
                        for ($p = $startP; $p <= $endP; $p++): 
                        ?>
                            <li class="page-item <?= $p === $profilesData['current_page'] ? 'active' : ''; ?>">
                                <a class="page-link" href="?tab=profiles&profile_page=<?= $p; ?>&profile_search=<?= urlencode($profileSearch); ?>">
                                    <?= $p; ?>
                                </a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($profilesData['current_page'] < $profilesData['pages']): ?>
                            <li class="page-item">
                                <a class="page-link" href="?tab=profiles&profile_page=<?= ($profilesData['current_page'] + 1); ?>&profile_search=<?= urlencode($profileSearch); ?>">
                                    <i class="ti ti-chevron-right"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <!-- Modal Informativo com Ícone Ilustrativo e Botões de Ação para Perfis -->
                <div class="modal modal-blur fade" id="modalInfoProfileTab" tabindex="-1" role="dialog" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
                        <div class="modal-content shadow-lg border-0">
                            <div class="modal-header bg-primary text-white">
                                <h5 class="modal-title d-flex align-items-center gap-2">
                                    <i class="ti ti-info-circle fs-3"></i>
                                    <?= __('Configuração de Restrições no Perfil de Acesso', 'restrictstatus'); ?>
                                </h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body p-4">
                                <div class="text-center mb-4">
                                    <div class="avatar avatar-xl bg-primary-lt text-primary mb-2 shadow-xs" style="width: 64px; height: 64px; font-size: 32px; border-radius: 50%;">
                                        <i class="ti ti-user-shield"></i>
                                    </div>
                                    <h4 class="fw-bold text-dark"><?= __('Regras Aplicadas por Perfil', 'restrictstatus'); ?></h4>
                                    <p class="text-muted small"><?= __('Como o plugin determina para quais perfis os bloqueios devem ser aplicados.', 'restrictstatus'); ?></p>
                                </div>

                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <div class="border rounded p-3 h-100 bg-light">
                                            <h6 class="fw-bold text-danger d-flex align-items-center gap-2">
                                                <i class="ti ti-lock"></i> <?= __('1. Aplicar Regras de Bloqueio', 'restrictstatus'); ?>
                                            </h6>
                                            <p class="small text-muted mb-0">
                                                <?= __('Usuários autenticados sob perfis configurados com bloqueio não conseguirão inserir acompanhamentos, soluções, documentos ou tarefas quando o chamado estiver nos status restritos (ex: Novo, Fechado, etc.).', 'restrictstatus'); ?>
                                            </p>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="border rounded p-3 h-100 bg-light">
                                            <h6 class="fw-bold text-success d-flex align-items-center gap-2">
                                                <i class="ti ti-shield-check"></i> <?= __('2. Perfil Isento', 'restrictstatus'); ?>
                                            </h6>
                                            <p class="small text-muted mb-0">
                                                <?= __('Perfis configurados como isentos têm passe livre e nunca são bloqueados, podendo acompanhar qualquer chamado em qualquer status.', 'restrictstatus'); ?>
                                            </p>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="border rounded p-3 bg-light">
                                            <h6 class="fw-bold text-primary d-flex align-items-center gap-2">
                                                <i class="ti ti-link"></i> <?= __('3. Acesso Direto pela Tela do Perfil', 'restrictstatus'); ?>
                                            </h6>
                                            <p class="small text-muted mb-0">
                                                <?= __('Ao editar qualquer perfil em <code>Administração &gt; Perfis</code>, a aba <strong>Restringir Status</strong> está disponível para ajuste individual e direto.', 'restrictstatus'); ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer bg-body-tertiary">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                                    <?= __('Fechar', 'restrictstatus'); ?>
                                </button>
                                <a href="?tab=statuses" class="btn btn-primary d-flex align-items-center gap-2">
                                    <i class="ti ti-settings"></i>
                                    <?= __('Ver Regras por Status', 'restrictstatus'); ?>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

            <?php endif; ?>
        </div>
    </div>
</div>

<?php
Html::footer();
