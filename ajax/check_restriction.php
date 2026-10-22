<?php
/**
 * ---------------------------------------------------------------------
 * Restrict Status - Plugin GLPI 10 & 11
 * Endpoint AJAX para verificação assíncrona de restrições em chamados
 * ---------------------------------------------------------------------
 * @author    Wilter Porto
 * @copyright 2026 Wilter Porto
 * @license   GPLv3+
 * ---------------------------------------------------------------------
 */

declare(strict_types=1);

include ("../../../inc/includes.php");

header("Content-Type: application/json; charset=UTF-8");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");

// Requer usuário autenticado
if (!Session::getLoginUserID()) {
    http_response_code(401);
    echo json_encode(['restricted' => false, 'error' => 'Não autenticado'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ticketId = (int)($_REQUEST['tickets_id'] ?? 0);
if ($ticketId <= 0) {
    http_response_code(400);
    echo json_encode(['restricted' => false, 'error' => 'ID de chamado inválido'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = Session::getLoginUserID();
$restriction = PluginRestrictstatusRestriction::evaluateTicketRestriction($ticketId, $userId, 'all');

echo json_encode([
    'success'             => true,
    'restricted'          => $restriction['restricted'],
    'status_id'           => $restriction['status_id'],
    'status_name'         => $restriction['status_name'],
    'role_name'           => $restriction['role_name'],
    'message'             => $restriction['message'],
    'ticket_id'           => $ticketId,
    'show_timeline_alert' => $restriction['show_timeline_alert'] ?? true,
    'restricted_items'    => $restriction['restricted_items'] ?? [
        'followup' => false,
        'solution' => false,
        'document' => false,
        'task'     => false,
    ]
], JSON_UNESCAPED_UNICODE);
exit;
