<?php
/**
 * ---------------------------------------------------------------------
 * Restrict Status - Plugin GLPI 10 & 11
 * Menu de navegação do Plugin no GLPI
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

class PluginRestrictstatusMenu extends CommonGLPI {

    public static $rightname = 'config';

    public static function getMenuName(): string {
        return __('Restringir Status', 'restrictstatus');
    }

    public static function getMenuContent(): array {
        if (!Session::haveRight('config', READ)) {
            return [];
        }

        $menu = [
            'title'   => __('Restringir Acompanhamentos', 'restrictstatus'),
            'page'    => '/plugins/restrictstatus/front/config.php',
            'icon'    => 'ti ti-lock',
            'options' => [
                'config' => [
                    'title' => __('Configurações de Status', 'restrictstatus'),
                    'page'  => '/plugins/restrictstatus/front/config.php',
                    'icon'  => 'ti ti-settings',
                ],
                'audit' => [
                    'title' => __('Auditoria & Histórico', 'restrictstatus'),
                    'page'  => '/plugins/restrictstatus/front/config.php?tab=audit',
                    'icon'  => 'ti ti-history',
                ]
            ]
        ];

        return $menu;
    }
}
