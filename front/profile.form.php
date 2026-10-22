<?php
/**
 * ---------------------------------------------------------------------
 * Restrict Status - Plugin GLPI 10 & 11
 * Processamento do Formulário de Configuração de Perfil
 * ---------------------------------------------------------------------
 * @author    Wilter Porto
 * @copyright 2026 Wilter Porto
 * @license   GPLv3+
 * ---------------------------------------------------------------------
 */

declare(strict_types=1);

include ("../../../inc/includes.php");

global $CFG_GLPI;

// Permissão de administração de perfis ou configuração no GLPI
if (!Session::haveRight("profile", UPDATE) && !Session::haveRight("config", UPDATE)) {
    Session::checkRight("profile", UPDATE);
}

// Validação de token CSRF para requisições com token
if (isset($_POST['_glpi_csrf_token'])) {
    Session::validateCSRF($_POST);
}

$profilesId = (int)($_POST['profiles_id'] ?? 0);
if ($profilesId <= 0) {
    Html::displayErrorAndDie(__('ID de perfil inválido ou não informado.', 'restrictstatus'));
}

$restrictionMode = isset($_POST['restriction_mode']) 
    ? (int)$_POST['restriction_mode'] 
    : PluginRestrictstatusProfileMode::ApplyRestrictions->value;

$showAlert = isset($_POST['show_timeline_alert']) 
    ? (int)$_POST['show_timeline_alert'] 
    : PluginRestrictstatusTimelineAlertMode::GlobalDefault->value;

$customNotice = isset($_POST['custom_notice']) 
    ? trim((string)$_POST['custom_notice']) 
    : '';

$success = PluginRestrictstatusProfile::saveProfileConfig($profilesId, [
    'restriction_mode'    => $restrictionMode,
    'show_timeline_alert' => $showAlert,
    'custom_notice'       => $customNotice,
]);

// Salva as regras de status individuais do perfil para todos os status
if (isset($_POST['profile_statuses']) && is_array($_POST['profile_statuses'])) {
    PluginRestrictstatusProfile::saveProfileStatusConfigs($profilesId, $_POST['profile_statuses']);
}

$rootDoc = is_array($CFG_GLPI) ? ($CFG_GLPI['root_doc'] ?? '') : '';
$redirectUrl = $rootDoc . "/front/profile.form.php?id={$profilesId}&forcetab=PluginRestrictstatusProfile$1";

if ($success) {
    $msg = __('Configurações de restrição do perfil e regras de status atualizadas com sucesso!', 'restrictstatus');
    Session::addMessageAfterRedirect($msg, false, INFO);
    Html::redirect($redirectUrl . '&saved=1&msg=' . urlencode($msg));
} else {
    Session::addMessageAfterRedirect(__('Erro ao salvar configurações do perfil.', 'restrictstatus'), false, ERROR);
    Html::redirect($redirectUrl);
}
