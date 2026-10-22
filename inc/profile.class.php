<?php
/**
 * ---------------------------------------------------------------------
 * Restrict Status - Plugin GLPI 10 & 11
 * Integração e Configuração por Perfil de Acesso
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

class PluginRestrictstatusProfile extends CommonDBTM {

    public static $rightname = 'profile';

    public static function getTypeName($nb = 0): string {
        return __('Restringir Status - Perfis', 'restrictstatus');
    }

    /**
     * Define o nome da aba exibida na tela de perfil do GLPI
     *
     * @param CommonGLPI $item
     * @param int $withblanks
     * @return string|array
     */
    public function getTabNameForItem(CommonGLPI $item, $withblanks = 0) {
        if ($item->getType() === 'Profile') {
            return self::createTabEntry(__('Restringir Status', 'restrictstatus'));
        }
        return '';
    }

    /**
     * Renderiza o conteúdo da aba no perfil do GLPI
     *
     * @param CommonGLPI $item
     * @param int $tabnum
     * @param int $withblanks
     * @return boolean
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withblanks = 0): bool {
        if ($item->getType() === 'Profile') {
            self::showForProfile($item);
        }
        return true;
    }

    /**
     * Exibe o formulário de configurações do plugin dentro do perfil selecionado
     *
     * @param Profile|int $profile
     * @return void
     */
    public static function showForProfile(Profile|int $profile): void {
        global $DB, $CFG_GLPI;

        self::checkSchema();

        $profileId = $profile instanceof Profile ? (int)$profile->getID() : (int)$profile;
        if ($profileId <= 0) {
            return;
        }

        $profileObj = new Profile();
        if (!$profileObj->getFromDB($profileId)) {
            return;
        }

        $profileName = $profileObj->fields['name'] ?? __('Perfil', 'restrictstatus');
        $config = self::getProfileConfig($profileId);
        $currentMode = $config['restriction_mode'];
        $currentAlert = $config['show_timeline_alert'];
        $customNotice = $config['custom_notice'] ?? '';

        $canUpdate = Session::haveRight('profile', UPDATE) || Session::haveRight('config', UPDATE);
        $globalConfig = Config::getConfigurationValues('plugin:restrictstatus');
        $pluginEnabled = (int)($globalConfig['plugin_enabled'] ?? 1);

        // Busca regras de todos os status existentes configurados individualmente para este perfil
        $profileStatuses = self::getProfileStatusConfigs($profileId);
        ?>

        <div class="card shadow-sm border-0 mb-4">
            <!-- Cabeçalho do Card -->
            <div class="card-header bg-body-tertiary border-bottom d-flex justify-content-between align-items-center py-3">
                <div class="d-flex align-items-center gap-2">
                    <span class="avatar avatar-md bg-primary-lt text-primary rounded-circle">
                        <i class="ti ti-shield-lock fs-2"></i>
                    </span>
                    <div>
                        <h4 class="card-title fw-bold m-0 text-dark">
                            <?= __('Regras de Bloqueio de Acompanhamentos por Perfil', 'restrictstatus'); ?>
                        </h4>
                        <div class="text-muted small">
                            <?= sprintf(__('Configurações aplicáveis aos usuários com o perfil <strong>%s</strong>.', 'restrictstatus'), htmlspecialchars($profileName)); ?>
                        </div>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-outline-info btn-sm d-flex align-items-center gap-1 shadow-sm" data-bs-toggle="modal" data-bs-target="#modalInfoProfileRestrict">
                        <i class="ti ti-info-circle"></i> <?= __('Como Funciona', 'restrictstatus'); ?>
                    </button>
                    <span class="badge <?= $currentMode === PluginRestrictstatusProfileMode::ApplyRestrictions->value ? 'bg-danger-lt' : 'bg-success-lt'; ?> fs-6 py-1 px-2">
                        <i class="<?= $currentMode === PluginRestrictstatusProfileMode::ApplyRestrictions->value ? 'ti ti-lock' : 'ti ti-shield-check'; ?> me-1"></i>
                        <?= $currentMode === PluginRestrictstatusProfileMode::ApplyRestrictions->value ? __('Restrições Ativas', 'restrictstatus') : __('Perfil Isento', 'restrictstatus'); ?>
                    </span>
                </div>
            </div>

            <!-- Popup de Sucesso sem Botões para Confirmação (Desaparece automaticamente) -->
            <?php if (isset($_GET['saved']) && !empty($_GET['msg'])): 
                $popupMsg = htmlspecialchars($_GET['msg'], ENT_QUOTES, 'UTF-8');
            ?>
            <div id="profile-restrictstatus-success-popup" class="toast show position-fixed top-0 end-0 m-4 shadow-lg border-0 bg-success text-white" style="z-index: 99999; min-width: 320px;" role="alert" aria-live="assertive" aria-atomic="true">
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
                    <div id="profile-popup-progress" class="progress-bar bg-white" style="width: 100%; transition: width 3.5s linear;"></div>
                </div>
            </div>
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                const popup = document.getElementById('profile-restrictstatus-success-popup');
                const progressBar = document.getElementById('profile-popup-progress');
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

            <div class="card-body p-4">
                <?php if (!$pluginEnabled): ?>
                    <div class="alert alert-warning d-flex align-items-center gap-2 mb-4" role="alert">
                        <i class="ti ti-alert-triangle fs-2"></i>
                        <div>
                            <strong><?= __('Atenção:', 'restrictstatus'); ?></strong>
                            <?= __('O plugin Restrict Status está globalmente desativado nos Parâmetros Gerais. As restrições não surtirão efeito até que o plugin seja ativado.', 'restrictstatus'); ?>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?= ($CFG_GLPI['root_doc'] ?? ''); ?>/plugins/restrictstatus/front/profile.form.php">
                    <input type="hidden" name="_glpi_csrf_token" value="<?= Session::getNewCSRFToken(); ?>">
                    <input type="hidden" name="profiles_id" value="<?= $profileId; ?>">

                    <div class="row g-4">
                        <!-- Campo 1: Modo de Aplicação das Regras de Bloqueio (OBRIGATÓRIO COM ASTERISCO VERMELHO) -->
                        <div class="col-12">
                            <label class="form-label fw-bold fs-5">
                                <?= __('Modo de Aplicação das Regras de Bloqueio', 'restrictstatus'); ?> <span style="color: red;">*</span>
                            </label>
                            <p class="text-muted small mb-3">
                                <?= __('Defina se os usuários operando sob este perfil devem ter a inserção de acompanhamentos bloqueada conforme os status de chamados configurados.', 'restrictstatus'); ?>
                            </p>

                            <div class="row g-3">
                                <?php foreach (PluginRestrictstatusProfileMode::cases() as $modeCase): 
                                    $isSelected = ($currentMode === $modeCase->value);
                                ?>
                                <div class="col-md-6">
                                    <label class="card card-sm border <?= $isSelected ? 'border-primary bg-primary-lt' : 'bg-body'; ?> p-3 h-100 cursor-pointer d-flex flex-row align-items-start gap-3 shadow-xs">
                                        <input class="form-check-input mt-1" type="radio" name="restriction_mode" value="<?= $modeCase->value; ?>" <?= $isSelected ? 'checked' : ''; ?> <?= !$canUpdate ? 'disabled' : ''; ?> required>
                                        <div>
                                            <div class="fw-bold d-flex align-items-center gap-2 text-dark">
                                                <i class="<?= $modeCase->icon(); ?>"></i>
                                                <?= $modeCase->label(); ?>
                                            </div>
                                            <div class="text-muted small mt-1">
                                                <?= $modeCase->description(); ?>
                                            </div>
                                        </div>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Campo 2: Comportamento do Alerta na Timeline (Enumerador) -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold" for="show_timeline_alert">
                                <?= __('Exibição de Alerta Visual na Timeline', 'restrictstatus'); ?>
                            </label>
                            <select name="show_timeline_alert" id="show_timeline_alert" class="form-select" <?= !$canUpdate ? 'disabled' : ''; ?>>
                                <?php foreach (PluginRestrictstatusTimelineAlertMode::cases() as $alertCase): ?>
                                    <option value="<?= $alertCase->value; ?>" <?= $currentAlert === $alertCase->value ? 'selected' : ''; ?>>
                                        <?= $alertCase->label(); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text text-muted">
                                <?= __('Controla se o banner informativo de bloqueio é exibido na timeline para usuários deste perfil.', 'restrictstatus'); ?>
                            </div>
                        </div>

                        <!-- Campo 3: Mensagem / Aviso Personalizado do Perfil -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold" for="custom_notice">
                                <?= __('Mensagem Personalizada para este Perfil (Opcional)', 'restrictstatus'); ?>
                            </label>
                            <input type="text" class="form-control" id="custom_notice" name="custom_notice" 
                                   value="<?= htmlspecialchars($customNotice); ?>" 
                                   placeholder="<?= __('Subscreve a mensagem padrão para usuários deste perfil (use %s para status)', 'restrictstatus'); ?>"
                                   <?= !$canUpdate ? 'disabled' : ''; ?>>
                            <div class="form-text text-muted">
                                <?= __('Se preenchido, esta mensagem será exibida no lugar da mensagem padrão para usuários deste perfil.', 'restrictstatus'); ?>
                            </div>
                        </div>

                        <!-- Seção: Regras de Status Ativas no Sistema para este Perfil -->
                        <div class="col-12">
                            <div class="border rounded p-3 bg-light shadow-xs">
                                <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                                    <div>
                                        <h6 class="fw-bold text-dark d-flex align-items-center gap-2 m-0 fs-5">
                                            <i class="ti ti-list-check text-primary"></i>
                                            <?= __('Regras de Status Ativas no Sistema', 'restrictstatus'); ?>
                                        </h6>
                                        <div class="text-muted small mt-1">
                                            <?= __('Configure abaixo individualmente para este perfil quais status de chamado terão restrição e quais itens/ações serão bloqueados.', 'restrictstatus'); ?>
                                        </div>
                                    </div>
                                    <span class="badge bg-primary-lt fs-6 py-1 px-2">
                                        <i class="ti ti-adjustments me-1"></i>
                                        <?= __('Configuração Exclusiva deste Perfil', 'restrictstatus'); ?>
                                    </span>
                                </div>

                                <div class="table-responsive mt-3">
                                    <table class="table table-vcenter table-hover table-striped bg-white border rounded mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th style="min-width: 180px;"><?= __('Status do Chamado', 'restrictstatus'); ?></th>
                                                <th class="text-center" style="width: 90px;"><?= __('Ativo', 'restrictstatus'); ?></th>
                                                <th class="text-center" style="min-width: 320px;"><?= __('Itens / Ações Bloqueadas', 'restrictstatus'); ?></th>
                                                <th style="min-width: 240px;"><?= __('Mensagem Personalizada do Status (Opcional)', 'restrictstatus'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($profileStatuses as $stId => $stCfg): 
                                                $visuals = PluginRestrictstatusConfig::getStatusVisuals($stId);
                                            ?>
                                            <tr>
                                                <td>
                                                    <span class="badge <?= $visuals['badge_class']; ?> py-2 px-3 fs-6 d-inline-flex align-items-center gap-1 shadow-xs">
                                                        <i class="<?= $visuals['icon']; ?>"></i>
                                                        <?= htmlspecialchars($stCfg['status_name']); ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <label class="form-check form-switch d-inline-block m-0">
                                                        <input class="form-check-input" type="checkbox" 
                                                               name="profile_statuses[<?= $stId; ?>][is_active]" 
                                                               value="1" 
                                                               <?= !empty($stCfg['is_active']) ? 'checked' : ''; ?> 
                                                               <?= !$canUpdate ? 'disabled' : ''; ?>>
                                                    </label>
                                                </td>
                                                <td>
                                                    <div class="d-flex flex-wrap gap-2 justify-content-center">
                                                        <label class="form-check form-check-inline m-0" title="<?= __('Bloquear Acompanhamentos', 'restrictstatus'); ?>">
                                                            <input class="form-check-input" type="checkbox" 
                                                                   name="profile_statuses[<?= $stId; ?>][restrict_followups]" 
                                                                   value="1" 
                                                                   <?= !empty($stCfg['restrict_followups']) ? 'checked' : ''; ?> 
                                                                   <?= !$canUpdate ? 'disabled' : ''; ?>>
                                                            <span class="form-check-label small"><i class="ti ti-message-circle text-primary"></i> <?= __('Acompanhamentos', 'restrictstatus'); ?></span>
                                                        </label>
                                                        <label class="form-check form-check-inline m-0" title="<?= __('Bloquear Soluções', 'restrictstatus'); ?>">
                                                            <input class="form-check-input" type="checkbox" 
                                                                   name="profile_statuses[<?= $stId; ?>][restrict_solutions]" 
                                                                   value="1" 
                                                                   <?= !empty($stCfg['restrict_solutions']) ? 'checked' : ''; ?> 
                                                                   <?= !$canUpdate ? 'disabled' : ''; ?>>
                                                            <span class="form-check-label small"><i class="ti ti-bulb text-warning"></i> <?= __('Soluções', 'restrictstatus'); ?></span>
                                                        </label>
                                                        <label class="form-check form-check-inline m-0" title="<?= __('Bloquear Documentos', 'restrictstatus'); ?>">
                                                            <input class="form-check-input" type="checkbox" 
                                                                   name="profile_statuses[<?= $stId; ?>][restrict_documents]" 
                                                                   value="1" 
                                                                   <?= !empty($stCfg['restrict_documents']) ? 'checked' : ''; ?> 
                                                                   <?= !$canUpdate ? 'disabled' : ''; ?>>
                                                            <span class="form-check-label small"><i class="ti ti-paperclip text-danger"></i> <?= __('Documentos', 'restrictstatus'); ?></span>
                                                        </label>
                                                        <label class="form-check form-check-inline m-0" title="<?= __('Bloquear Tarefas', 'restrictstatus'); ?>">
                                                            <input class="form-check-input" type="checkbox" 
                                                                   name="profile_statuses[<?= $stId; ?>][restrict_tasks]" 
                                                                   value="1" 
                                                                   <?= !empty($stCfg['restrict_tasks']) ? 'checked' : ''; ?> 
                                                                   <?= !$canUpdate ? 'disabled' : ''; ?>>
                                                            <span class="form-check-label small"><i class="ti ti-checkup-list text-purple"></i> <?= __('Tarefas', 'restrictstatus'); ?></span>
                                                        </label>
                                                    </div>
                                                </td>
                                                <td>
                                                    <input type="text" class="form-control form-control-sm" 
                                                           name="profile_statuses[<?= $stId; ?>][custom_message]" 
                                                           value="<?= htmlspecialchars($stCfg['custom_message'] ?? ''); ?>" 
                                                           placeholder="<?= __('Mensagem específica para este status/perfil (use %s para status)...', 'restrictstatus'); ?>" 
                                                           <?= !$canUpdate ? 'disabled' : ''; ?>>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($canUpdate): ?>
                        <div class="mt-4 d-flex justify-content-end">
                            <button type="submit" name="save_profile_restriction" class="btn btn-primary d-flex align-items-center gap-2 px-4 shadow-sm">
                                <i class="ti ti-device-floppy"></i>
                                <?= __('Salvar Configurações do Perfil', 'restrictstatus'); ?>
                            </button>
                        </div>
                    <?php endif; ?>

                </form>
            </div>
        </div>

        <!-- Modal Informativo com Ícone Ilustrativo e Botões de Ação -->
        <div class="modal modal-blur fade" id="modalInfoProfileRestrict" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
                <div class="modal-content shadow-lg border-0">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title d-flex align-items-center gap-2">
                            <i class="ti ti-info-circle fs-3"></i>
                            <?= __('Controle de Bloqueio por Perfil de Acesso', 'restrictstatus'); ?>
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="text-center mb-4">
                            <div class="avatar avatar-xl bg-primary-lt text-primary mb-2 shadow-xs" style="width: 64px; height: 64px; font-size: 32px; border-radius: 50%;">
                                <i class="ti ti-user-shield"></i>
                            </div>
                            <h4 class="fw-bold text-dark"><?= __('Como Funciona a Associação por Perfil', 'restrictstatus'); ?></h4>
                            <p class="text-muted small"><?= __('Entenda como as regras do plugin Restrict Status são aplicadas individualmente a cada perfil de acesso.', 'restrictstatus'); ?></p>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="border rounded p-3 h-100 bg-light">
                                    <h6 class="fw-bold text-danger d-flex align-items-center gap-2">
                                        <i class="ti ti-lock"></i> <?= __('1. Perfil com Bloqueio Ativo', 'restrictstatus'); ?>
                                    </h6>
                                    <p class="small text-muted mb-0">
                                        <?= __('Quando definido como <strong>Aplicar Regras de Bloqueio</strong>, qualquer usuário com este perfil estará totalmente bloqueado para adicionar novos itens nos chamados com status restrito, independentemente de qual seja o seu papel no chamado (requerente, observador ou técnico).', 'restrictstatus'); ?>
                                    </p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="border rounded p-3 h-100 bg-light">
                                    <h6 class="fw-bold text-success d-flex align-items-center gap-2">
                                        <i class="ti ti-shield-check"></i> <?= __('2. Perfil Isento', 'restrictstatus'); ?>
                                    </h6>
                                    <p class="small text-muted mb-0">
                                        <?= __('Quando definido como <strong>Isento</strong>, os usuários deste perfil nunca são bloqueados, podendo acompanhar e comentar chamados em qualquer situação (ideal para supervisores, coordenadores e super-administradores).', 'restrictstatus'); ?>
                                    </p>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="border rounded p-3 bg-light">
                                    <h6 class="fw-bold text-primary d-flex align-items-center gap-2">
                                        <i class="ti ti-list-check"></i> <?= __('3. Regras de Status Individuais e Exclusivas', 'restrictstatus'); ?>
                                    </h6>
                                    <p class="small text-muted mb-0">
                                        <?= __('Na seção <strong>Regras de Status Ativas no Sistema</strong>, é possível personalizar exatamente quais status de chamado (Novo, Pendente, Fechado, etc.) e quais ações/itens (Acompanhamentos, Soluções, Documentos e Tarefas) serão bloqueados especificamente para este perfil, garantindo controle sob medida para cada função operacional.', 'restrictstatus'); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-body-tertiary">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                            <?= __('Entendido / Fechar', 'restrictstatus'); ?>
                        </button>
                        <a href="<?= ($CFG_GLPI['root_doc'] ?? ''); ?>/plugins/restrictstatus/front/config.php?tab=profiles" class="btn btn-primary d-flex align-items-center gap-2">
                            <i class="ti ti-users"></i>
                            <?= __('Ver Todos os Perfis', 'restrictstatus'); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Verifica de forma robusta se o usuário autenticado atual possui privilégios de Super-Admin no GLPI
     *
     * @return bool
     */
    public static function isCurrentUserSuperAdmin(): bool {
        if (!isset($_SESSION['glpiactiveprofile']['id'])) {
            return false;
        }

        $activeProfileId = (int)$_SESSION['glpiactiveprofile']['id'];
        if ($activeProfileId === 4) {
            return true;
        }

        if (class_exists('Profile') && method_exists('Profile', 'getSuperAdminProfilesId')) {
            try {
                if (in_array($activeProfileId, Profile::getSuperAdminProfilesId(), true)) {
                    return true;
                }
            } catch (\Throwable $e) {
                // Silencioso em caso de erro
            }
        }

        $profileName = strtolower((string)($_SESSION['glpiactiveprofile']['name'] ?? ''));
        if (str_contains($profileName, 'super-admin') || str_contains($profileName, 'super admin')) {
            return true;
        }

        return Session::haveRight('config', UPDATE) && Session::haveRight('profile', UPDATE);
    }

    /**
     * Verifica se determinado perfil está sujeito às restrições de acompanhamento
     *
     * @param int $profileId
     * @return boolean True se deve aplicar restrições, False se for isento
     */
    public static function isProfileRestricted(int $profileId): bool {
        global $DB;

        self::checkSchema();

        if ($profileId <= 0) {
            return false;
        }

        // Consulta banco com Prepared Statement
        $iterator = $DB->request([
            'FROM'   => 'glpi_plugin_restrictstatus_profiles',
            'WHERE'  => [
                'profiles_id' => $profileId,
                'is_deleted'  => 0
            ],
            'LIMIT'  => 1
        ]);

        if (count($iterator) > 0) {
            $row = $iterator->current();
            return ((int)$row['restriction_mode'] === PluginRestrictstatusProfileMode::ApplyRestrictions->value);
        }

        // Padrão inteligente se ainda não configurado:
        // Se for Super-Admin (id 4 ou perfil super-admin), padrão é ISENTO (false)
        $profile = new Profile();
        if ($profile->getFromDB($profileId)) {
            $name = strtolower((string)($profile->fields['name'] ?? ''));
            if ($profileId === 4 || str_contains($name, 'super-admin') || str_contains($name, 'super admin')) {
                // Auto-sementeia como Isento
                self::saveProfileConfig($profileId, [
                    'restriction_mode'    => PluginRestrictstatusProfileMode::Exempt->value,
                    'show_timeline_alert' => PluginRestrictstatusTimelineAlertMode::GlobalDefault->value
                ]);
                return false;
            }
        }

        // Para os demais perfis, por padrão aplica as restrições
        self::saveProfileConfig($profileId, [
            'restriction_mode'    => PluginRestrictstatusProfileMode::ApplyRestrictions->value,
            'show_timeline_alert' => PluginRestrictstatusTimelineAlertMode::GlobalDefault->value
        ]);

        return true;
    }

    /**
     * Obtém as configurações de restrição para um perfil específico
     *
     * @param int $profileId
     * @return array
     */
    public static function getProfileConfig(int $profileId): array {
        global $DB;

        self::checkSchema();

        $defaults = [
            'id'                  => 0,
            'profiles_id'         => $profileId,
            'restriction_mode'    => ($profileId === 4 ? PluginRestrictstatusProfileMode::Exempt->value : PluginRestrictstatusProfileMode::ApplyRestrictions->value),
            'show_timeline_alert' => PluginRestrictstatusTimelineAlertMode::GlobalDefault->value,
            'custom_notice'       => '',
            'is_deleted'          => 0,
        ];

        if ($profileId <= 0) {
            return $defaults;
        }

        $iterator = $DB->request([
            'FROM'   => 'glpi_plugin_restrictstatus_profiles',
            'WHERE'  => [
                'profiles_id' => $profileId,
                'is_deleted'  => 0
            ],
            'LIMIT'  => 1
        ]);

        if (count($iterator) > 0) {
            $row = $iterator->current();
            return [
                'id'                  => (int)$row['id'],
                'profiles_id'         => (int)$row['profiles_id'],
                'restriction_mode'    => (int)$row['restriction_mode'],
                'show_timeline_alert' => (int)$row['show_timeline_alert'],
                'custom_notice'       => (string)($row['custom_notice'] ?? ''),
                'is_deleted'          => (int)$row['is_deleted'],
            ];
        }

        return $defaults;
    }

    /**
     * Salva ou atualiza a configuração para um perfil específico
     *
     * @param int $profileId
     * @param array $data
     * @return boolean
     */
    public static function saveProfileConfig(int $profileId, array $data): bool {
        global $DB;

        self::checkSchema();

        if ($profileId <= 0) {
            return false;
        }

        $restrictionMode = isset($data['restriction_mode']) ? (int)$data['restriction_mode'] : PluginRestrictstatusProfileMode::ApplyRestrictions->value;
        $showAlert = isset($data['show_timeline_alert']) ? (int)$data['show_timeline_alert'] : PluginRestrictstatusTimelineAlertMode::GlobalDefault->value;
        $customNotice = isset($data['custom_notice']) ? trim((string)$data['custom_notice']) : null;

        $existing = $DB->request([
            'FROM'  => 'glpi_plugin_restrictstatus_profiles',
            'WHERE' => [
                'profiles_id' => $profileId
            ],
            'LIMIT' => 1
        ]);

        $fields = [
            'restriction_mode'    => $restrictionMode,
            'show_timeline_alert' => $showAlert,
            'custom_notice'       => !empty($customNotice) ? $customNotice : null,
            'is_deleted'          => 0,
            'date_mod'            => date('Y-m-d H:i:s')
        ];

        $success = false;
        if (count($existing) > 0) {
            $curr = $existing->current();
            $success = $DB->update('glpi_plugin_restrictstatus_profiles', $fields, [
                'id' => (int)$curr['id']
            ]);
        } else {
            $fields['profiles_id']   = $profileId;
            $fields['date_creation'] = date('Y-m-d H:i:s');
            $success = (bool)$DB->insert('glpi_plugin_restrictstatus_profiles', $fields);
        }

        if ($success) {
            $profileObj = new Profile();
            $pName = $profileObj->getFromDB($profileId) ? ($profileObj->fields['name'] ?? "ID {$profileId}") : "ID {$profileId}";
            $modeLabel = $restrictionMode === PluginRestrictstatusProfileMode::ApplyRestrictions->value ? 'Aplicar Bloqueio' : 'Isento';

            PluginRestrictstatusAudit::logAction([
                'action'  => PluginRestrictstatusAuditAction::ProfileUpdated->value,
                'details' => sprintf(
                    'Perfil "%s" (ID %d) configurado com modo de restrição: %s. Alerta na timeline: %d',
                    $pName,
                    $profileId,
                    $modeLabel,
                    $showAlert
                )
            ]);
        }

        return $success;
    }

    /**
     * Determina se o banner da timeline deve ser exibido para o perfil do usuário
     *
     * @param int $profileId
     * @param int $globalShowAlert
     * @return boolean
     */
    public static function shouldShowTimelineAlert(int $profileId, int $globalShowAlert = 1): bool {
        $cfg = self::getProfileConfig($profileId);
        $mode = $cfg['show_timeline_alert'];

        if ($mode === PluginRestrictstatusTimelineAlertMode::AlwaysShow->value) {
            return true;
        }
        if ($mode === PluginRestrictstatusTimelineAlertMode::AlwaysHide->value) {
            return false;
        }

        return $globalShowAlert === 1;
    }

    /**
     * Obtém o perfil do usuário na entidade informada (para mailgate/cron/API)
     *
     * @param int $userId
     * @param int $entitiesId
     * @return int
     */
    public static function getUserProfileForEntity(int $userId, int $entitiesId): int {
        global $DB;

        if ($userId <= 0) {
            return 0;
        }

        // Tenta buscar no vínculo direto do usuário com a entidade
        $iterator = $DB->request([
            'FROM'   => 'glpi_profiles_users',
            'WHERE'  => [
                'users_id'    => $userId,
                'entities_id' => $entitiesId
            ],
            'LIMIT'  => 1
        ]);

        if (count($iterator) > 0) {
            return (int)$iterator->current()['profiles_id'];
        }

        // Fallback: Qualquer perfil associado ao usuário
        $fallback = $DB->request([
            'FROM'   => 'glpi_profiles_users',
            'WHERE'  => [
                'users_id' => $userId
            ],
            'LIMIT'  => 1
        ]);

        if (count($fallback) > 0) {
            return (int)$fallback->current()['profiles_id'];
        }

        return 0;
    }

    /**
     * Retorna lista paginada de todos os perfis com suas configurações (Paginação a 30 por página)
     *
     * @param int $page Página atual (1-indexed)
     * @param int $perPage Limite por página (30)
     * @param string $search Filtro por nome de perfil
     * @return array [rows => array, total => int, pages => int, current_page => int]
     */
    public static function getPaginatedProfiles(int $page = 1, int $perPage = 30, string $search = ''): array {
        global $DB;

        self::checkSchema();

        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $where = [];
        if (!empty($search)) {
            $where['name LIKE'] = '%' . $search . '%';
        }

        // Total de perfis
        $countIterator = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => 'glpi_profiles',
            'WHERE' => $where
        ]);
        $total = 0;
        if (count($countIterator) > 0) {
            $total = (int)$countIterator->current()['cpt'];
        }

        // Perfis paginados
        $profilesIterator = $DB->request([
            'FROM'   => 'glpi_profiles',
            'WHERE'  => $where,
            'ORDER'  => 'name ASC',
            'START'  => $offset,
            'LIMIT'  => $perPage
        ]);

        $rows = [];
        foreach ($profilesIterator as $p) {
            $pId = (int)$p['id'];
            $cfg = self::getProfileConfig($pId);
            $rows[] = [
                'id'                  => $pId,
                'name'                => $p['name'],
                'interface'           => $p['interface'] ?? 'central',
                'restriction_mode'    => $cfg['restriction_mode'],
                'show_timeline_alert' => $cfg['show_timeline_alert'],
                'custom_notice'       => $cfg['custom_notice'],
            ];
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
     * Cria a tabela glpi_plugin_restrictstatus_profiles caso não exista
     *
     * @return boolean
     */
    public static function createTable(): bool {
        global $DB;

        if ($DB->tableExists('glpi_plugin_restrictstatus_profiles')) {
            return true;
        }

        $query = "CREATE TABLE `glpi_plugin_restrictstatus_profiles` (
            `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `profiles_id` INT(11) UNSIGNED NOT NULL,
            `restriction_mode` INT(11) NOT NULL DEFAULT 1,
            `show_timeline_alert` INT(11) NOT NULL DEFAULT 0,
            `custom_notice` VARCHAR(255) DEFAULT NULL,
            `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
            `date_mod` DATETIME DEFAULT NULL,
            `date_creation` DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`profiles_id`),
            KEY `restriction_mode` (`restriction_mode`),
            KEY `is_deleted` (`is_deleted`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        $DB->doQuery($query);

        // Migra configurações antigas de exempt_profiles se existirem
        $globalConfig = Config::getConfigurationValues('plugin:restrictstatus');
        $oldExempt = [];
        if (!empty($globalConfig['exempt_profiles'])) {
            $oldExempt = json_decode($globalConfig['exempt_profiles'], true) ?: [];
        }

        // Popula perfis existentes no GLPI
        $allProfiles = $DB->request(['FROM' => 'glpi_profiles']);
        foreach ($allProfiles as $prof) {
            $pId = (int)$prof['id'];
            $isSuperAdmin = ($pId === 4 || str_contains(strtolower((string)$prof['name']), 'super-admin'));
            $isExempt = $isSuperAdmin || in_array($pId, $oldExempt);

            $mode = $isExempt ? PluginRestrictstatusProfileMode::Exempt->value : PluginRestrictstatusProfileMode::ApplyRestrictions->value;

            $DB->insert('glpi_plugin_restrictstatus_profiles', [
                'profiles_id'         => $pId,
                'restriction_mode'    => $mode,
                'show_timeline_alert' => PluginRestrictstatusTimelineAlertMode::GlobalDefault->value,
                'custom_notice'       => null,
                'is_deleted'          => 0,
                'date_creation'       => date('Y-m-d H:i:s')
            ]);
        }

        if (!$DB->tableExists('glpi_plugin_restrictstatus_profile_statuses')) {
            $queryStatuses = "CREATE TABLE `glpi_plugin_restrictstatus_profile_statuses` (
                `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `profiles_id` INT(11) UNSIGNED NOT NULL,
                `status_id` INT(11) UNSIGNED NOT NULL,
                `is_active` TINYINT(1) NOT NULL DEFAULT 0,
                `restrict_requester` TINYINT(1) NOT NULL DEFAULT 1,
                `restrict_observer` TINYINT(1) NOT NULL DEFAULT 1,
                `restrict_assigned` TINYINT(1) NOT NULL DEFAULT 0,
                `custom_message` VARCHAR(255) DEFAULT NULL,
                `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
                `date_mod` DATETIME DEFAULT NULL,
                `date_creation` DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`profiles_id`, `status_id`),
                KEY `profiles_id` (`profiles_id`),
                KEY `status_id` (`status_id`),
                KEY `is_active` (`is_active`),
                KEY `is_deleted` (`is_deleted`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

            $DB->doQuery($queryStatuses);
        }

        return true;
    }

    /**
     * Validação segura da estrutura de tabelas
     *
     * @return void
     */
    public static function checkSchema(): void {
        global $DB;
        if (!$DB) {
            return;
        }
        if (!$DB->tableExists('glpi_plugin_restrictstatus_profiles')) {
            self::createTable();
        }
        if (!$DB->tableExists('glpi_plugin_restrictstatus_profile_statuses')) {
            $queryStatuses = "CREATE TABLE `glpi_plugin_restrictstatus_profile_statuses` (
                `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `profiles_id` INT(11) UNSIGNED NOT NULL,
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
                UNIQUE KEY `unicity` (`profiles_id`, `status_id`),
                KEY `profiles_id` (`profiles_id`),
                KEY `status_id` (`status_id`),
                KEY `is_active` (`is_active`),
                KEY `is_deleted` (`is_deleted`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

            $DB->doQuery($queryStatuses);
        } else {
            // Migração de colunas adicionais de tipos de itens se tabela já existir
            if (!$DB->fieldExists('glpi_plugin_restrictstatus_profile_statuses', 'restrict_followups')) {
                $DB->doQuery("ALTER TABLE `glpi_plugin_restrictstatus_profile_statuses` 
                    ADD COLUMN `restrict_followups` TINYINT(1) NOT NULL DEFAULT 1 AFTER `restrict_assigned`,
                    ADD COLUMN `restrict_solutions` TINYINT(1) NOT NULL DEFAULT 1 AFTER `restrict_followups`,
                    ADD COLUMN `restrict_documents` TINYINT(1) NOT NULL DEFAULT 1 AFTER `restrict_solutions`,
                    ADD COLUMN `restrict_tasks` TINYINT(1) NOT NULL DEFAULT 0 AFTER `restrict_documents`");
            }
        }

        // Migração de colunas na tabela de configurações gerais por entidade
        if ($DB->tableExists('glpi_plugin_restrictstatus_configs')) {
            if (!$DB->fieldExists('glpi_plugin_restrictstatus_configs', 'restrict_followups')) {
                $DB->doQuery("ALTER TABLE `glpi_plugin_restrictstatus_configs` 
                    ADD COLUMN `restrict_followups` TINYINT(1) NOT NULL DEFAULT 1 AFTER `restrict_assigned`,
                    ADD COLUMN `restrict_solutions` TINYINT(1) NOT NULL DEFAULT 1 AFTER `restrict_followups`,
                    ADD COLUMN `restrict_documents` TINYINT(1) NOT NULL DEFAULT 1 AFTER `restrict_solutions`,
                    ADD COLUMN `restrict_tasks` TINYINT(1) NOT NULL DEFAULT 0 AFTER `restrict_documents`");
            }
        }
    }

    /**
     * Retorna a lista de regras para todos os status existentes no GLPI para este perfil
     *
     * @param int $profileId
     * @return array [status_id => config_data]
     */
    public static function getProfileStatusConfigs(int $profileId): array {
        global $DB;

        self::checkSchema();

        $allStatuses = PluginRestrictstatusConfig::getAllTicketStatuses();
        $result = [];

        // Busca registros gravados para este perfil
        $profileRows = [];
        $iterator = $DB->request([
            'FROM'  => 'glpi_plugin_restrictstatus_profile_statuses',
            'WHERE' => [
                'profiles_id' => $profileId,
                'is_deleted'  => 0
            ]
        ]);
        foreach ($iterator as $row) {
            $profileRows[(int)$row['status_id']] = $row;
        }

        // Busca regras gerais da entidade 0 como fallback inicial se não houver registros salvos
        $fallbackConfigs = [];
        if (empty($profileRows)) {
            $fallbackConfigs = PluginRestrictstatusConfig::getStatusConfigsForEntity(0);
        }

        foreach ($allStatuses as $statusId => $label) {
            if (isset($profileRows[$statusId])) {
                $row = $profileRows[$statusId];
                $result[$statusId] = [
                    'id'                 => (int)$row['id'],
                    'profiles_id'        => $profileId,
                    'status_id'          => $statusId,
                    'status_name'        => $label,
                    'is_active'          => (int)$row['is_active'],
                    'restrict_requester' => (int)$row['restrict_requester'],
                    'restrict_observer'  => (int)$row['restrict_observer'],
                    'restrict_assigned'  => (int)$row['restrict_assigned'],
                    'restrict_followups' => (int)($row['restrict_followups'] ?? 1),
                    'restrict_solutions' => (int)($row['restrict_solutions'] ?? 1),
                    'restrict_documents' => (int)($row['restrict_documents'] ?? 1),
                    'restrict_tasks'     => (int)($row['restrict_tasks'] ?? 0),
                    'custom_message'     => (string)($row['custom_message'] ?? '')
                ];
            } else {
                // Se houver configuração na entidade raiz, aproveita os valores iniciais
                $fallback = $fallbackConfigs[$statusId] ?? null;
                $defaultActive = $fallback ? (int)$fallback['is_active'] : ($statusId === 1 ? 1 : 0);
                $req           = $fallback ? (int)$fallback['restrict_requester'] : 1;
                $obs           = $fallback ? (int)$fallback['restrict_observer'] : 1;
                $tech          = $fallback ? (int)$fallback['restrict_assigned'] : 0;
                $msg           = $fallback ? (string)($fallback['custom_message'] ?? '') : '';

                $result[$statusId] = [
                    'id'                 => 0,
                    'profiles_id'        => $profileId,
                    'status_id'          => $statusId,
                    'status_name'        => $label,
                    'is_active'          => $defaultActive,
                    'restrict_requester' => $req,
                    'restrict_observer'  => $obs,
                    'restrict_assigned'  => $tech,
                    'restrict_followups' => $fallback ? (int)($fallback['restrict_followups'] ?? 1) : 1,
                    'restrict_solutions' => $fallback ? (int)($fallback['restrict_solutions'] ?? 1) : 1,
                    'restrict_documents' => $fallback ? (int)($fallback['restrict_documents'] ?? 1) : 1,
                    'restrict_tasks'     => $fallback ? (int)($fallback['restrict_tasks'] ?? 0) : 0,
                    'custom_message'     => $msg
                ];
            }
        }

        return $result;
    }

    /**
     * Salva as configurações de restrição para todos os status de um perfil específico
     *
     * @param int $profileId
     * @param array $statusesData
     * @return boolean
     */
    public static function saveProfileStatusConfigs(int $profileId, array $statusesData): bool {
        global $DB;

        self::checkSchema();

        $allStatuses = PluginRestrictstatusConfig::getAllTicketStatuses();
        $updatedCount = 0;

        foreach ($allStatuses as $statusId => $label) {
            $data = $statusesData[$statusId] ?? [];
            $isActive   = !empty($data['is_active']) ? 1 : 0;
            $followups  = !empty($data['restrict_followups']) ? 1 : 0;
            $solutions  = !empty($data['restrict_solutions']) ? 1 : 0;
            $documents  = !empty($data['restrict_documents']) ? 1 : 0;
            $tasks      = !empty($data['restrict_tasks']) ? 1 : 0;
            $msg        = !empty($data['custom_message']) ? trim((string)$data['custom_message']) : null;

            $existing = $DB->request([
                'FROM'   => 'glpi_plugin_restrictstatus_profile_statuses',
                'WHERE'  => [
                    'profiles_id' => $profileId,
                    'status_id'   => $statusId
                ],
                'LIMIT'  => 1
            ]);

            $fields = [
                'is_active'          => $isActive,
                'restrict_requester' => 1,
                'restrict_observer'  => 1,
                'restrict_assigned'  => 1,
                'restrict_followups' => $followups,
                'restrict_solutions' => $solutions,
                'restrict_documents' => $documents,
                'restrict_tasks'     => $tasks,
                'custom_message'     => $msg,
                'is_deleted'         => 0,
                'date_mod'           => date('Y-m-d H:i:s')
            ];

            if (count($existing) > 0) {
                $existingRow = $existing->current();
                $DB->update('glpi_plugin_restrictstatus_profile_statuses', $fields, [
                    'id' => (int)$existingRow['id']
                ]);
            } else {
                $fields['profiles_id']   = $profileId;
                $fields['status_id']     = $statusId;
                $fields['date_creation'] = date('Y-m-d H:i:s');
                $DB->insert('glpi_plugin_restrictstatus_profile_statuses', $fields);
            }
            $updatedCount++;
        }

        // Registra histórico na auditoria
        PluginRestrictstatusAudit::logAction([
            'action'      => PluginRestrictstatusAuditAction::StatusChanged->value,
            'details'     => sprintf('Regras de restrição de status atualizadas para o perfil ID %d (%d status configurados).', $profileId, $updatedCount),
            'entities_id' => (int)($_SESSION['glpiactive_entity'] ?? 0)
        ]);

        return true;
    }
}
