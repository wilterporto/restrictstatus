<?php
/**
 * ---------------------------------------------------------------------
 * Restrict Status - Plugin GLPI 10 & 11
 * Restringe adição de acompanhamentos (followups) de acordo com o status
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
require_once __DIR__ . '/hook.php';

define('PLUGIN_RESTRICTSTATUS_VERSION', '1.0.1');
define('PLUGIN_RESTRICTSTATUS_MIN_GLPI', '10.0.0');
define('PLUGIN_RESTRICTSTATUS_MAX_GLPI', '11.9.99');
define('PLUGIN_RESTRICTSTATUS_MIN_PHP', '8.3.0');
define('PLUGIN_RESTRICTSTATUS_TARGET_PHP', '8.3.28');

/**
 * Inicialização dos hooks do plugin
 *
 * @return void
 */
function plugin_init_restrictstatus(): void {
    global $PLUGIN_HOOKS;

    // Conformidade com proteção CSRF do GLPI
    $PLUGIN_HOOKS['csrf_compliant']['restrictstatus'] = true;

    // Registra classes autoloaded pelo GLPI
    Plugin::registerClass('PluginRestrictstatusConfig');
    Plugin::registerClass('PluginRestrictstatusProfile', ['addtabon' => ['Profile']]);
    Plugin::registerClass('PluginRestrictstatusRestriction');
    Plugin::registerClass('PluginRestrictstatusAudit');
    Plugin::registerClass('PluginRestrictstatusMenu');

    // Página de configuração padrão nos plugins do GLPI
    $PLUGIN_HOOKS['config_page']['restrictstatus'] = 'front/config.php';

    // Entrada de menu em Configurar (Setup)
    $PLUGIN_HOOKS['menu_toadd']['restrictstatus'] = [
        'config' => 'PluginRestrictstatusMenu'
    ];

    // Hook essencial no backend: Intercepta adição de acompanhamentos, soluções, documentos e tarefas
    $PLUGIN_HOOKS['pre_item_add']['restrictstatus'] = [
        'ITILFollowup'  => 'plugin_restrictstatus_pre_item_add',
        'ITILSolution'  => 'plugin_restrictstatus_pre_item_add',
        'Document_Item' => 'plugin_restrictstatus_pre_item_add',
        'Document'      => 'plugin_restrictstatus_pre_item_add',
        'TicketTask'    => 'plugin_restrictstatus_pre_item_add',
    ];

    // Intercepta atualização direta do chamado (por exemplo, tentativa de mudar para Solucionado)
    $PLUGIN_HOOKS['pre_item_update']['restrictstatus'] = [
        'Ticket' => 'plugin_restrictstatus_pre_item_update',
    ];

    // Hook na timeline de chamados para injetar aviso e controle de exibição
    $PLUGIN_HOOKS['timeline_actions']['restrictstatus'] = 'plugin_restrictstatus_timeline_actions';

    // Hook essencial do GLPI para ocultar nativamente botões da timeline no rodapé (Responder, Solução, Documento, Tarefa)
    $PLUGIN_HOOKS['timeline_answer_actions']['restrictstatus'] = 'plugin_restrictstatus_timeline_answer_actions';

    // Hook de direitos nativo do GLPI (CommonDBTM::can): bloqueia direitos CREATE e UPDATE
    $PLUGIN_HOOKS['item_can']['restrictstatus'] = 'plugin_restrictstatus_item_can';

    // Carrega CSS e JavaScript do plugin para manipulação visual em tempo real
    $PLUGIN_HOOKS['add_javascript']['restrictstatus'] = [
        'js/restrictstatus.js'
    ];
    $PLUGIN_HOOKS['add_css']['restrictstatus'] = [
        'css/restrictstatus.css'
    ];
}

/**
 * Informações e metadados do plugin
 *
 * @return array
 */
function plugin_version_restrictstatus(): array {
    return [
        'name'           => 'Restringir Acompanhamentos por Status',
        'version'        => PLUGIN_RESTRICTSTATUS_VERSION,
        'author'         => 'Antigravity',
        'license'        => 'GPLv3+',
        'homepage'       => '',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_RESTRICTSTATUS_MIN_GLPI,
                'max' => PLUGIN_RESTRICTSTATUS_MAX_GLPI
            ],
            'php' => [
                'min' => PLUGIN_RESTRICTSTATUS_MIN_PHP
            ]
        ]
    ];
}

/**
 * Verificação de pré-requisitos antes da instalação/ativação
 *
 * @return boolean
 */
function plugin_restrictstatus_check_prerequisites(): bool {
    // Validação estrita de compatibilidade com PHP 8.3+
    if (version_compare(PHP_VERSION, PLUGIN_RESTRICTSTATUS_MIN_PHP, 'lt')) {
        echo sprintf(
            __('Este plugin requer PHP versão %1$s ou superior (versão atual: %2$s). Ambiente homologado para PHP %3$s.', 'restrictstatus'),
            PLUGIN_RESTRICTSTATUS_MIN_PHP,
            PHP_VERSION,
            PLUGIN_RESTRICTSTATUS_TARGET_PHP
        );
        return false;
    }

    if (version_compare(GLPI_VERSION, PLUGIN_RESTRICTSTATUS_MIN_GLPI, 'lt')
        || version_compare(GLPI_VERSION, PLUGIN_RESTRICTSTATUS_MAX_GLPI, 'gt')) {
        echo sprintf(
            __('Este plugin requer GLPI entre %1$s e %2$s (versão atual: %3$s)', 'restrictstatus'),
            PLUGIN_RESTRICTSTATUS_MIN_GLPI,
            PLUGIN_RESTRICTSTATUS_MAX_GLPI,
            GLPI_VERSION
        );
        return false;
    }
    return true;
}

/**
 * Verificação de integridade da configuração
 *
 * @return boolean
 */
function plugin_restrictstatus_check_config(): bool {
    return true;
}
