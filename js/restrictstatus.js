/**
 * ---------------------------------------------------------------------
 * Restrict Status - Plugin GLPI 10 & 11
 * Script do lado cliente para controle em tempo real da interface
 * Oculta e bloqueia botões e formulários de resposta, solução, etc.
 * ---------------------------------------------------------------------
 */

const RestrictStatus = {
    isRestricted: false,
    restrictionData: null,
    observer: null,
    debounceTimer: null,

    /**
     * Inicializa a checagem no carregamento da página
     */
    init: function() {
        const bodyRestricted = document.body.getAttribute('data-restrictstatus') === '1'
            || document.body.classList.contains('restrict-solutions')
            || document.body.classList.contains('restrict-followups');

        const banner = document.querySelector('.restrictstatus-banner');

        if (bodyRestricted || banner) {
            this.isRestricted = true;
            this.applyRestriction();
            this.startObserver();
            return;
        }

        // Caso contrário, tenta obter o ID do chamado da página atual
        const ticketId = this.detectTicketId();
        if (ticketId && ticketId > 0) {
            this.checkViaAjax(ticketId);
        }
    },

    /**
     * Obtém o ID do chamado do DOM ou da URL
     */
    detectTicketId: function() {
        const urlParams = new URLSearchParams(window.location.search);
        const idParam = urlParams.get('id');
        if (idParam && !isNaN(idParam) && parseInt(idParam) > 0) {
            return parseInt(idParam);
        }

        const inputId = document.querySelector('input[name="id"]');
        if (inputId && inputId.value && !isNaN(inputId.value) && parseInt(inputId.value) > 0) {
            return parseInt(inputId.value);
        }

        const ticketsIdInput = document.querySelector('input[name="tickets_id"]');
        if (ticketsIdInput && ticketsIdInput.value && !isNaN(ticketsIdInput.value) && parseInt(ticketsIdInput.value) > 0) {
            return parseInt(ticketsIdInput.value);
        }

        return null;
    },

    /**
     * Consulta assíncrona para verificar se o chamado atual está restrito
     */
    checkViaAjax: function(ticketId) {
        const rootDoc = (typeof CFG_GLPI !== 'undefined' && CFG_GLPI.root_doc) ? CFG_GLPI.root_doc : '';
        const url = rootDoc + '/plugins/restrictstatus/ajax/check_restriction.php?tickets_id=' + ticketId;

        fetch(url, {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => {
            if (!response.ok) return null;
            return response.json();
        })
        .then(data => {
            if (data && data.restricted) {
                this.isRestricted = true;
                this.restrictionData = data;
                if (data.show_timeline_alert !== false && data.show_timeline_alert !== '0') {
                    this.injectBanner(data);
                }
                this.applyRestriction();
                this.startObserver();
            }
        })
        .catch(err => {
            console.debug('RestrictStatus: checagem ajax concluída', err);
        });
    },

    /**
     * Injeta o banner informativo caso ainda não exista no DOM
     * A informação 'Itens Bloqueados' NÃO é exibida conforme solicitado
     */
    injectBanner: function(data) {
        if (document.querySelector('.restrictstatus-banner')) {
            return;
        }

        const rItems = data.restricted_items || {};
        const flagFollowup = rItems.followup !== false ? '1' : '0';
        const flagSolution = rItems.solution !== false ? '1' : '0';
        const flagDocument = rItems.document !== false ? '1' : '0';
        const flagTask     = rItems.task === true ? '1' : '0';

        const banner = document.createElement('div');
        banner.className = 'restrictstatus-banner alert alert-warning d-flex align-items-center mb-3 mt-2 shadow-sm';
        banner.setAttribute('role', 'alert');
        banner.setAttribute('data-restrictstatus', '1');
        banner.setAttribute('data-restrict-followups', flagFollowup);
        banner.setAttribute('data-restrict-solutions', flagSolution);
        banner.setAttribute('data-restrict-documents', flagDocument);
        banner.setAttribute('data-restrict-tasks', flagTask);

        banner.innerHTML = `
            <div class="me-3 fs-2 text-warning">
                <i class="ti ti-lock"></i>
            </div>
            <div class="flex-grow-1">
                <h5 class="alert-heading mb-1 fw-bold">Ações Restritas no Chamado</h5>
                <p class="mb-0 small text-muted">${data.message || 'Adição de novos itens bloqueada para o status atual.'}</p>
                <div class="mt-1 d-flex flex-wrap align-items-center gap-1">
                    <span class="badge bg-warning-lt text-dark me-1">Status: ${data.status_name || ''}</span>
                    <span class="badge bg-secondary-lt text-dark me-2">Seu Papel: ${data.role_name || ''}</span>
                </div>
            </div>
        `;

        const timeline = document.querySelector('.itil-timeline') || document.querySelector('#itil-timeline');
        if (timeline) {
            timeline.insertBefore(banner, timeline.firstChild);
        } else {
            const formTicket = document.querySelector('form[name="form_ticket"]') || document.querySelector('.ticket-content');
            if (formTicket) {
                formTicket.insertBefore(banner, formTicket.firstChild);
            }
        }
    },

    /**
     * Aplica o bloqueio visual nos botões e containers de resposta, solução, documento e tarefa
     */
    applyRestriction: function() {
        if (!this.isRestricted) return;

        const banner = document.querySelector('.restrictstatus-banner');
        const resItems = this.restrictionData?.restricted_items || {};

        const restrictFollowups = banner 
            ? (banner.getAttribute('data-restrict-followups') === '1')
            : (document.body.getAttribute('data-restrict-followups') === '1' || document.body.classList.contains('restrict-followups') || resItems.followup !== false);

        const restrictSolutions = banner 
            ? (banner.getAttribute('data-restrict-solutions') === '1')
            : (document.body.getAttribute('data-restrict-solutions') === '1' || document.body.classList.contains('restrict-solutions') || resItems.solution !== false);

        const restrictDocuments = banner 
            ? (banner.getAttribute('data-restrict-documents') === '1')
            : (document.body.getAttribute('data-restrict-documents') === '1' || document.body.classList.contains('restrict-documents') || resItems.document !== false);

        const restrictTasks = banner 
            ? (banner.getAttribute('data-restrict-tasks') === '1')
            : (document.body.getAttribute('data-restrict-tasks') === '1' || document.body.classList.contains('restrict-tasks') || resItems.task === true);

        // 1. Bloqueio de Soluções (ITILSolution) - Bloqueio prioritário e absoluto
        if (restrictSolutions) {
            document.body.setAttribute('data-restrict-solutions', '1');
            document.body.classList.add('restrict-solutions');

            // Oculta e desabilita containers de formulário de solução
            document.querySelectorAll('#new-ITILSolution-block, .timeline-item.ITILSolution, .itilsolution').forEach(block => {
                block.classList.remove('show');
                block.style.setProperty('display', 'none', 'important');
                block.querySelectorAll('button, input, textarea, select').forEach(el => {
                    el.disabled = true;
                    el.setAttribute('disabled', 'disabled');
                });
            });

            // Oculta botões, links e itens de menu de Solução por atributos, seletores e texto
            document.querySelectorAll(
                'button[data-bs-target="#new-ITILSolution-block"], ' +
                'a[data-bs-target="#new-ITILSolution-block"], ' +
                'button[data-bs-target*="ITILSolution"], ' +
                'a[data-bs-target*="ITILSolution"], ' +
                'button[data-bs-target*="Solution"], ' +
                'a[data-bs-target*="Solution"], ' +
                '.timeline-buttons button.ITILSolution, ' +
                '.timeline-buttons a.ITILSolution, ' +
                '.timeline-buttons .action-solution, ' +
                '#itil-footer .action-solution, ' +
                'button.action-solution, ' +
                'a.action-solution, ' +
                '[data-itemtype="ITILSolution"], ' +
                'button[name="add_solution"], input[name="add_solution"]'
            ).forEach(btn => {
                btn.style.setProperty('display', 'none', 'important');
                btn.disabled = true;
                btn.setAttribute('aria-disabled', 'true');
                const li = btn.closest('li');
                if (li) {
                    li.style.setProperty('display', 'none', 'important');
                }
            });

            // Se o botão principal do rodapé for de Solução, oculta
            const mainActionBtn = document.querySelector('#itil-footer .main-actions > button.answer-action');
            if (mainActionBtn) {
                const target = mainActionBtn.getAttribute('data-bs-target') || '';
                const text = (mainActionBtn.textContent || '').toLowerCase();
                if (target.includes('ITILSolution') || target.includes('Solution') || mainActionBtn.classList.contains('action-solution') || text.includes('solução') || text.includes('solution')) {
                    mainActionBtn.style.setProperty('display', 'none', 'important');
                    mainActionBtn.disabled = true;
                }
            }
        }

        // 2. Bloqueio de Acompanhamentos (ITILFollowup / Responder)
        if (restrictFollowups) {
            document.body.setAttribute('data-restrict-followups', '1');
            document.body.classList.add('restrict-followups');

            document.querySelectorAll('#new-ITILFollowup-block, .timeline-item.ITILFollowup').forEach(block => {
                block.classList.remove('show');
                block.style.setProperty('display', 'none', 'important');
                block.querySelectorAll('button, input, textarea, select').forEach(el => {
                    el.disabled = true;
                    el.setAttribute('disabled', 'disabled');
                });
            });

            document.querySelectorAll(
                'button[data-bs-target="#new-ITILFollowup-block"], ' +
                'a[data-bs-target="#new-ITILFollowup-block"], ' +
                'button[data-bs-target*="ITILFollowup"], ' +
                'a[data-bs-target*="ITILFollowup"], ' +
                '.timeline-buttons button.ITILFollowup, ' +
                '.timeline-buttons a.ITILFollowup, ' +
                '.timeline-buttons .action-followup, ' +
                '#itil-footer .action-answer, ' +
                '#itil-footer .action-followup, ' +
                'button.action-followup, ' +
                'a.action-followup, ' +
                '.itil-btn-answer, ' +
                'button[name="add_followup"], input[name="add_followup"]'
            ).forEach(btn => {
                btn.style.setProperty('display', 'none', 'important');
                btn.disabled = true;
                btn.setAttribute('aria-disabled', 'true');
                const li = btn.closest('li');
                if (li) {
                    li.style.setProperty('display', 'none', 'important');
                }
            });

            const mainActionBtn = document.querySelector('#itil-footer .main-actions > button.answer-action');
            if (mainActionBtn) {
                const target = mainActionBtn.getAttribute('data-bs-target') || '';
                const text = (mainActionBtn.textContent || '').toLowerCase().trim();
                if (target.includes('ITILFollowup') || target.includes('Followup') || mainActionBtn.classList.contains('action-followup') || mainActionBtn.classList.contains('action-answer') || text === 'responder' || text === 'answer') {
                    mainActionBtn.style.setProperty('display', 'none', 'important');
                    mainActionBtn.disabled = true;
                }
            }
        }

        // 3. Bloqueio de Documentos (Document / Document_Item / Adicionar documento)
        if (restrictDocuments) {
            document.body.setAttribute('data-restrict-documents', '1');
            document.body.classList.add('restrict-documents');

            document.querySelectorAll('#new-Document-block, #new-Document_Item-block, .timeline-item.Document_Item, .timeline-item.Document').forEach(block => {
                block.classList.remove('show');
                block.style.setProperty('display', 'none', 'important');
                block.querySelectorAll('button, input, textarea, select').forEach(el => {
                    el.disabled = true;
                    el.setAttribute('disabled', 'disabled');
                });
            });

            document.querySelectorAll(
                'button[data-bs-target="#new-Document-block"], ' +
                'a[data-bs-target="#new-Document-block"], ' +
                'button[data-bs-target="#new-Document_Item-block"], ' +
                'a[data-bs-target="#new-Document_Item-block"], ' +
                'button[data-bs-target*="Document"], ' +
                'a[data-bs-target*="Document"], ' +
                '.timeline-buttons button.Document, ' +
                '.timeline-buttons [data-bs-target*="Document"], ' +
                '.timeline-buttons .action-document, ' +
                '#itil-footer .action-document, ' +
                'button.action-document, ' +
                'a.action-document, ' +
                'button[name="add_document"], input[name="add_document"]'
            ).forEach(btn => {
                btn.style.setProperty('display', 'none', 'important');
                btn.disabled = true;
                btn.setAttribute('aria-disabled', 'true');
                const li = btn.closest('li');
                if (li) {
                    li.style.setProperty('display', 'none', 'important');
                }
            });
        }

        // 4. Bloqueio de Tarefas (TicketTask / ITILTask)
        if (restrictTasks) {
            document.body.setAttribute('data-restrict-tasks', '1');
            document.body.classList.add('restrict-tasks');

            document.querySelectorAll('#new-TicketTask-block, #new-ITILTask-block, .timeline-item.TicketTask, .timeline-item.ITILTask').forEach(block => {
                block.classList.remove('show');
                block.style.setProperty('display', 'none', 'important');
                block.querySelectorAll('button, input, textarea, select').forEach(el => {
                    el.disabled = true;
                    el.setAttribute('disabled', 'disabled');
                });
            });

            document.querySelectorAll(
                'button[data-bs-target="#new-TicketTask-block"], ' +
                'a[data-bs-target="#new-TicketTask-block"], ' +
                'button[data-bs-target*="TicketTask"], ' +
                'a[data-bs-target*="TicketTask"], ' +
                'button[data-bs-target*="Task"], ' +
                'a[data-bs-target*="Task"], ' +
                '.timeline-buttons button.TicketTask, ' +
                '.timeline-buttons [data-bs-target*="TicketTask"], ' +
                '.timeline-buttons .action-task, ' +
                '#itil-footer .action-task, ' +
                'button.action-task, ' +
                'a.action-task, ' +
                'button[name="add_task"], input[name="add_task"]'
            ).forEach(btn => {
                btn.style.setProperty('display', 'none', 'important');
                btn.disabled = true;
                btn.setAttribute('aria-disabled', 'true');
                const li = btn.closest('li');
                if (li) {
                    li.style.setProperty('display', 'none', 'important');
                }
            });
        }

        // Se acompanhamentos, soluções e documentos estão todos restritos, oculta totalmente o bloco .main-actions
        if (restrictFollowups && restrictSolutions && restrictDocuments) {
            document.querySelectorAll('#itil-footer .main-actions, .main-actions').forEach(el => {
                el.style.setProperty('display', 'none', 'important');
            });
        }

        // Validação adicional: oculta dropdown-toggle se todos os itens da lista suspensa estiverem ocultos
        document.querySelectorAll('#itil-footer .main-actions').forEach(mainActions => {
            const dropdownItems = Array.from(mainActions.querySelectorAll('ul.dropdown-menu li, ul.dropdown-menu a'));
            if (dropdownItems.length > 0) {
                const hasVisibleDropdown = dropdownItems.some(el => {
                    return el.style.display !== 'none' && window.getComputedStyle(el).display !== 'none';
                });
                if (!hasVisibleDropdown) {
                    mainActions.querySelectorAll('.dropdown-toggle, .dropdown-toggle-split, ul.dropdown-menu').forEach(toggle => {
                        toggle.style.setProperty('display', 'none', 'important');
                    });
                }
            }

            const actionButtons = Array.from(mainActions.querySelectorAll('button:not(.dropdown-toggle), a.dropdown-item'));
            const hasVisibleAction = actionButtons.some(el => {
                return el.style.display !== 'none' && window.getComputedStyle(el).display !== 'none' && !el.disabled;
            });
            if (!hasVisibleAction) {
                mainActions.style.setProperty('display', 'none', 'important');
            }
        });
    },

    /**
     * Observer para manter o bloqueio mesmo após recarregamentos AJAX da timeline e footer
     */
    startObserver: function() {
        if (this.observer) return;

        const targetNode = document.body;
        if (!targetNode) return;

        this.observer = new MutationObserver(() => {
            if (this.isRestricted) {
                if (this.debounceTimer) clearTimeout(this.debounceTimer);
                this.debounceTimer = setTimeout(() => {
                    this.applyRestriction();
                }, 40);
            }
        });

        this.observer.observe(targetNode, {
            childList: true,
            subtree: true
        });
    }
};

// Intercepta eventos de abertura do Bootstrap Collapse para impedir abertura indevida
if (typeof jQuery !== 'undefined') {
    jQuery(document).on('show.bs.collapse', '#new-ITILSolution-block', function(e) {
        if (document.body.classList.contains('restrict-solutions') || document.body.getAttribute('data-restrict-solutions') === '1') {
            e.preventDefault();
            e.stopPropagation();
            return false;
        }
    });

    jQuery(document).on('show.bs.collapse', '#new-ITILFollowup-block', function(e) {
        if (document.body.classList.contains('restrict-followups') || document.body.getAttribute('data-restrict-followups') === '1') {
            e.preventDefault();
            e.stopPropagation();
            return false;
        }
    });

    jQuery(document).on('submit', 'form[action*="itilsolution"]', function(e) {
        if (document.body.classList.contains('restrict-solutions') || document.body.getAttribute('data-restrict-solutions') === '1') {
            e.preventDefault();
            e.stopPropagation();
            return false;
        }
    });
}

// Inicialização imediata e no evento DOMContentLoaded
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => RestrictStatus.init());
} else {
    RestrictStatus.init();
}
