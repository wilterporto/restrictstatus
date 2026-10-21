<?php
/**
 * ---------------------------------------------------------------------
 * Restrict Status - Plugin GLPI 10 & 11
 * Enumeradores Tipados para PHP 8.3.28+
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

/**
 * Enumerador tipado para os papéis de atores de chamado passíveis de restrição
 */
enum PluginRestrictstatusActorRole: string {
    case Requester = 'requester';
    case Observer  = 'observer';
    case Assigned  = 'assigned';

    public function label(): string {
        return match ($this) {
            self::Requester => __('Requerente', 'restrictstatus'),
            self::Observer  => __('Observador', 'restrictstatus'),
            self::Assigned  => __('Técnico Atribuído', 'restrictstatus'),
        };
    }
}

/**
 * Enumerador tipado para os modos de restrição por perfil de acesso
 */
enum PluginRestrictstatusProfileMode: int {
    case ApplyRestrictions = 1;
    case Exempt            = 0;

    public function label(): string {
        return match ($this) {
            self::ApplyRestrictions => __('Aplicar Regras de Bloqueio', 'restrictstatus'),
            self::Exempt            => __('Isento (Não Bloquear Acompanhamentos)', 'restrictstatus'),
        };
    }

    public function description(): string {
        return match ($this) {
            self::ApplyRestrictions => __('Usuários com este perfil estarão sujeitos aos bloqueios de acompanhamentos nos status configurados.', 'restrictstatus'),
            self::Exempt            => __('Usuários com este perfil têm permissão para adicionar acompanhamentos livremente em qualquer status.', 'restrictstatus'),
        };
    }

    public function badgeClass(): string {
        return match ($this) {
            self::ApplyRestrictions => 'bg-danger text-white',
            self::Exempt            => 'bg-success text-white',
        };
    }

    public function icon(): string {
        return match ($this) {
            self::ApplyRestrictions => 'ti ti-lock',
            self::Exempt            => 'ti ti-shield-check',
        };
    }
}

/**
 * Enumerador tipado para o comportamento do banner de alerta na timeline por perfil
 */
enum PluginRestrictstatusTimelineAlertMode: int {
    case GlobalDefault = 0;
    case AlwaysShow    = 1;
    case AlwaysHide    = 2;

    public function label(): string {
        return match ($this) {
            self::GlobalDefault => __('Seguir Configuração Global', 'restrictstatus'),
            self::AlwaysShow    => __('Sempre Exibir Alerta na Timeline', 'restrictstatus'),
            self::AlwaysHide    => __('Ocultar Alerta na Timeline', 'restrictstatus'),
        };
    }

    public function icon(): string {
        return match ($this) {
            self::GlobalDefault => 'ti ti-adjustments',
            self::AlwaysShow    => 'ti ti-eye',
            self::AlwaysHide    => 'ti ti-eye-off',
        };
    }
}

/**
 * Enumerador tipado para os tipos de ações e itens passíveis de restrição no chamado
 */
enum PluginRestrictstatusItemType: string {
    case Followup = 'followup';
    case Solution = 'solution';
    case Document = 'document';
    case Task     = 'task';

    public function label(): string {
        return match ($this) {
            self::Followup => __('Acompanhamentos', 'restrictstatus'),
            self::Solution => __('Soluções', 'restrictstatus'),
            self::Document => __('Documentos', 'restrictstatus'),
            self::Task     => __('Tarefas', 'restrictstatus'),
        };
    }

    public function singularLabel(): string {
        return match ($this) {
            self::Followup => __('Acompanhamento', 'restrictstatus'),
            self::Solution => __('Solução', 'restrictstatus'),
            self::Document => __('Documento', 'restrictstatus'),
            self::Task     => __('Tarefa', 'restrictstatus'),
        };
    }

    public function icon(): string {
        return match ($this) {
            self::Followup => 'ti ti-message-circle',
            self::Solution => 'ti ti-bulb',
            self::Document => 'ti ti-paperclip',
            self::Task     => 'ti ti-checkup-list',
        };
    }

    public function badgeClass(): string {
        return match ($this) {
            self::Followup => 'bg-cyan-lt text-cyan',
            self::Solution => 'bg-green-lt text-green',
            self::Document => 'bg-orange-lt text-orange',
            self::Task     => 'bg-purple-lt text-purple',
        };
    }
}

/**
 * Enumerador tipado para as ações registradas no módulo de auditoria
 */
enum PluginRestrictstatusAuditAction: string {
    case FollowupBlocked        = 'FOLLOWUP_BLOCKED';
    case SolutionBlocked        = 'SOLUTION_BLOCKED';
    case DocumentBlocked        = 'DOCUMENT_BLOCKED';
    case TaskBlocked            = 'TASK_BLOCKED';
    case ConfigUpdate           = 'CONFIG_UPDATE';
    case StatusChanged          = 'RESTRICTION_STATUS_CHANGED';
    case ProfileUpdated         = 'PROFILE_UPDATED';
    case PluginInstalled        = 'PLUGIN_INSTALLED';
    case PluginUninstalled      = 'PLUGIN_UNINSTALLED';

    public function label(): string {
        return match ($this) {
            self::FollowupBlocked   => __('Acompanhamento Bloqueado', 'restrictstatus'),
            self::SolutionBlocked   => __('Solução Bloqueada', 'restrictstatus'),
            self::DocumentBlocked   => __('Documento Bloqueado', 'restrictstatus'),
            self::TaskBlocked       => __('Tarefa Bloqueada', 'restrictstatus'),
            self::ConfigUpdate      => __('Parâmetros Alterados', 'restrictstatus'),
            self::StatusChanged     => __('Regras de Status Alteradas', 'restrictstatus'),
            self::ProfileUpdated    => __('Perfil de Acesso Atualizado', 'restrictstatus'),
            self::PluginInstalled   => __('Plugin Instalado', 'restrictstatus'),
            self::PluginUninstalled => __('Plugin Desinstalado', 'restrictstatus'),
        };
    }

    public function badgeClass(): string {
        return match ($this) {
            self::FollowupBlocked,
            self::SolutionBlocked,
            self::DocumentBlocked,
            self::TaskBlocked       => 'bg-danger text-white',
            self::ConfigUpdate,
            self::StatusChanged     => 'bg-primary text-white',
            self::ProfileUpdated    => 'bg-info text-white',
            self::PluginInstalled,
            self::PluginUninstalled => 'bg-success text-white',
        };
    }

    public function icon(): string {
        return match ($this) {
            self::FollowupBlocked   => 'ti ti-message-circle-off',
            self::SolutionBlocked   => 'ti ti-bulb-off',
            self::DocumentBlocked   => 'ti ti-paperclip-off',
            self::TaskBlocked       => 'ti ti-clipboard-x',
            self::ConfigUpdate      => 'ti ti-settings',
            self::StatusChanged     => 'ti ti-list-check',
            self::ProfileUpdated    => 'ti ti-user-cog',
            self::PluginInstalled   => 'ti ti-plug',
            self::PluginUninstalled => 'ti ti-trash',
        };
    }
}

