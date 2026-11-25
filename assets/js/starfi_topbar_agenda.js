/**
 * assets/js/starfi_topbar_agenda.js
 * Centro Global de Alertas en Tiempo Real y Agenda Comercial en la Barra Superior ([OMN-02] SPRINT 4).
 * 100% Vanilla JS local (Cero CDN CHK-LAN-01, Cero alert(), 100% escapeHtml DOM-XSS safe, locks anti-doble clic).
 */
(function () {
    'use strict';

    const alertedIdsSession = new Set();
    const inFlightLocks = new Set();
    let audioCtx = null;
    let pollTimer = null;

    function escHtml(val) {
        if (val === null || val === undefined) return '';
        return String(val).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function getAgendaEndpoint() {
        const wrap = document.getElementById('starfiTopbarAgendaWrap');
        if (!wrap) return '';
        return wrap.getAttribute('data-endpoint') || 'index.php';
    }

    function getBandejaBaseUrl() {
        const wrap = document.getElementById('starfiTopbarAgendaWrap');
        if (!wrap) return 'modules/bandeja/bandeja.php';
        return wrap.getAttribute('data-bandeja-url') || 'modules/bandeja/bandeja.php';
    }

    function playReminderChime() {
        try {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            if (!audioCtx) audioCtx = new Ctx();
            if (audioCtx.state === 'suspended') {
                audioCtx.resume().catch(function () {});
            }
            const now = audioCtx.currentTime;
            const tones = [
                { f: 783.99, s: 0.00, d: 0.12 },
                { f: 987.77, s: 0.14, d: 0.14 },
                { f: 1174.66, s: 0.30, d: 0.22 }
            ];
            tones.forEach(function (t) {
                const osc = audioCtx.createOscillator();
                const gain = audioCtx.createGain();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(t.f, now + t.s);
                gain.gain.setValueAtTime(0.0001, now + t.s);
                gain.gain.exponentialRampToValueAtTime(0.16, now + t.s + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.0001, now + t.s + t.d);
                osc.connect(gain);
                gain.connect(audioCtx.destination);
                osc.start(now + t.s);
                osc.stop(now + t.s + t.d + 0.02);
            });
        } catch (e) {}
    }

    function showTopbarAlertBanner(task) {
        if (!task || !task.id) return;
        let host = document.getElementById('starfiAgendaRealtimeToastHost');
        if (!host) {
            host = document.createElement('div');
            host.id = 'starfiAgendaRealtimeToastHost';
            host.style.cssText = 'position:fixed;top:68px;right:20px;z-index:2050;display:flex;flex-direction:column;gap:8px;max-width:360px;';
            document.body.appendChild(host);
        }
        const isOverdue = task.urgencia_tiempo === 'VENCIDA';
        const borderCol = isOverdue ? '#EF4444' : '#E85B14';
        const badgeBg = isOverdue ? '#FEF2F2' : '#FFF7ED';
        const badgeFg = isOverdue ? '#DC2626' : '#C2410C';
        const statusTxt = isOverdue ? 'Vencido ahora' : 'Recordatorio próximo';
        const cliTxt = task.cliente_nombre ? (' · ' + escHtml(task.cliente_nombre)) : '';

        const card = document.createElement('div');
        card.style.cssText = 'background:#ffffff;border:1px solid #E2E8F0;border-left:4px solid ' + borderCol + ';border-radius:14px;padding:12px 14px;box-shadow:0 12px 28px rgba(15,23,42,0.14);font-size:0.83rem;';
        card.innerHTML =
            '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:4px;">' +
                '<span style="background:' + badgeBg + ';color:' + badgeFg + ';font-weight:700;font-size:0.7rem;padding:2px 8px;border-radius:999px;">🔔 ' + statusTxt + '</span>' +
                '<button type="button" class="btn-close" style="font-size:0.65rem;" aria-label="Cerrar"></button>' +
            '</div>' +
            '<div style="font-weight:700;color:#0F172A;margin-bottom:2px;">[' + escHtml(task.tipo) + '] ' + escHtml(task.titulo) + '</div>' +
            '<div style="color:#64748B;font-size:0.76rem;">⏰ ' + escHtml(task.fecha_vencimiento_fmt) + cliTxt + '</div>';

        const closeBtn = card.querySelector('.btn-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', function () { card.remove(); });
        }
        host.appendChild(card);
        setTimeout(function () {
            if (card.parentNode) card.remove();
        }, 8500);
    }

    function getTaskTypeIcon(tipo) {
        const map = {
            'LLAMADA': 'fa-phone-volume',
            'COTIZACION': 'fa-file-invoice-dollar',
            'SEGUIMIENTO': 'fa-comments',
            'REUNION': 'fa-handshake',
            'COBRO': 'fa-money-bill-wave'
        };
        return map[String(tipo || '').toUpperCase()] || 'fa-calendar-check';
    }

    function renderTopbarWidget(data) {
        const badge = document.getElementById('topbarAgendaBadge');
        const bellBtn = document.getElementById('btnTopbarAgendaBell');
        const listEl = document.getElementById('topbarAgendaItemsList');
        const sumEl = document.getElementById('topbarAgendaSummaryText');
        if (!badge || !listEl) return;

        const overdueCnt = parseInt(data.overdue_count || 0, 10);
        const dueSoonCnt = parseInt(data.due_soon_count || 0, 10);
        const todayCnt = parseInt(data.today_pending_count || 0, 10);
        const totalPending = parseInt(data.total_pending || 0, 10);
        const alertCount = overdueCnt + dueSoonCnt;

        if (alertCount > 0) {
            badge.textContent = alertCount > 99 ? '99+' : String(alertCount);
            badge.style.display = 'inline-flex';
            badge.style.backgroundColor = overdueCnt > 0 ? '#DC2626' : '#E85B14';
            if (bellBtn) bellBtn.classList.add('has-urgent-tasks');
        } else if (todayCnt > 0) {
            badge.textContent = String(todayCnt);
            badge.style.display = 'inline-flex';
            badge.style.backgroundColor = '#0EA5E9';
            if (bellBtn) bellBtn.classList.remove('has-urgent-tasks');
        } else {
            badge.style.display = 'none';
            if (bellBtn) bellBtn.classList.remove('has-urgent-tasks');
        }

        if (sumEl) {
            sumEl.textContent = overdueCnt + ' vencidas · ' + dueSoonCnt + ' próximas · ' + totalPending + ' pendientes';
        }

        const tasks = Array.isArray(data.tasks) ? data.tasks : [];
        if (tasks.length === 0) {
            listEl.innerHTML = '<div class="text-center text-muted py-4 px-3 small"><i class="fa-regular fa-calendar-check fs-4 d-block mb-2 opacity-50"></i>No tienes tareas ni recordatorios pendientes.</div>';
        } else {
            const bandejaUrl = getBandejaBaseUrl();
            let html = '';
            tasks.slice(0, 12).forEach(function (t) {
                const tid = parseInt(t.id, 10) || 0;
                let pillStyle = 'background:#F1F5F9;color:#475569;';
                let pillLabel = 'Programada';
                if (t.urgencia_tiempo === 'VENCIDA') {
                    pillStyle = 'background:#FEF2F2;color:#DC2626;border:1px solid #FECACA;';
                    pillLabel = 'Vencida';
                } else if (t.urgencia_tiempo === 'PROXIMA') {
                    pillStyle = 'background:#FFF7ED;color:#C2410C;border:1px solid #FDBA74;';
                    pillLabel = 'Próxima';
                }
                const cliInfo = t.cliente_nombre
                    ? '<div class="text-truncate" style="font-size:0.73rem;color:#475569;"><i class="fa-regular fa-user me-1"></i>' + escHtml(t.cliente_nombre) + (t.numero_whatsapp ? ' (+' + escHtml(t.numero_whatsapp) + ')' : '') + '</div>'
                    : '';
                const chatBtn = t.id_conversacion
                    ? '<a href="' + escHtml(bandejaUrl) + '?chat_id=' + (parseInt(t.id_conversacion, 10) || 0) + '" class="btn btn-sm btn-light border py-0 px-2" style="font-size:0.7rem;" title="Ir al chat del cliente"><i class="fa-brands fa-whatsapp text-success"></i> Chat</a>'
                    : '';

                html +=
                    '<div class="p-2.5 border-bottom" style="padding:10px 14px;">' +
                        '<div class="d-flex align-items-center justify-content-between gap-2 mb-1">' +
                            '<span class="fw-bold text-dark text-truncate" style="font-size:0.81rem;"><i class="fa-solid ' + getTaskTypeIcon(t.tipo) + ' text-primary me-1"></i>' + escHtml(t.titulo) + '</span>' +
                            '<span class="rounded-pill px-2 py-0.5 fw-bold flex-shrink-0" style="font-size:0.65rem;' + pillStyle + '">' + pillLabel + '</span>' +
                        '</div>' +
                        cliInfo +
                        '<div class="d-flex align-items-center justify-content-between gap-2 mt-1">' +
                            '<span class="text-muted" style="font-size:0.71rem;"><i class="fa-regular fa-clock me-1"></i>' + escHtml(t.fecha_vencimiento_fmt) + '</span>' +
                            '<div class="d-inline-flex align-items-center gap-1">' +
                                chatBtn +
                                '<button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 js-topbar-task-snooze" data-id="' + tid + '" style="font-size:0.7rem;" title="Posponer 30 minutos">+30m</button>' +
                                '<button type="button" class="btn btn-sm btn-success py-0 px-2 js-topbar-task-done" data-id="' + tid + '" style="font-size:0.7rem;" title="Marcar completada"><i class="fa-solid fa-check"></i></button>' +
                            '</div>' +
                        '</div>' +
                    '</div>';
            });
            listEl.innerHTML = html;
        }

        const newly = Array.isArray(data.newly_triggered) ? data.newly_triggered : [];
        let chimePlayed = false;
        newly.forEach(function (nt) {
            const nid = parseInt(nt.id, 10) || 0;
            if (nid > 0 && !alertedIdsSession.has(nid)) {
                alertedIdsSession.add(nid);
                if (!chimePlayed) {
                    playReminderChime();
                    chimePlayed = true;
                }
                showTopbarAlertBanner(nt);
            }
        });
    }

    function pollTopbarAgenda(markDispatched) {
        const endpoint = getAgendaEndpoint();
        if (!endpoint || inFlightLocks.has('poll')) return;
        inFlightLocks.add('poll');

        const fd = new FormData();
        fd.append('ajax_agenda_topbar', '1');
        fd.append('agenda_action', 'poll_alerts');
        fd.append('mark_dispatched', markDispatched ? '1' : '0');

        fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (txt) {
                try {
                    const res = JSON.parse(txt);
                    if (res && res.status === 'success') {
                        renderTopbarWidget(res);
                    }
                } catch (e) {}
            })
            .catch(function () {})
            .finally(function () {
                inFlightLocks.delete('poll');
            });
    }

    function mutateTopbarTask(taskId, op, btnEl, extraFields) {
        const endpoint = getAgendaEndpoint();
        const lockKey = 'task_' + taskId;
        if (!endpoint || taskId <= 0 || inFlightLocks.has(lockKey)) return;
        inFlightLocks.add(lockKey);
        if (btnEl) btnEl.disabled = true;

        const fd = new FormData();
        fd.append('ajax_agenda_topbar', '1');
        fd.append('agenda_action', 'update_task');
        fd.append('task_id', String(taskId));
        fd.append('operacion', op);
        if (extraFields) {
            Object.keys(extraFields).forEach(function (k) {
                fd.append(k, String(extraFields[k]));
            });
        }

        fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (txt) {
                try {
                    const res = JSON.parse(txt);
                    if (res && res.status === 'success') {
                        pollTopbarAgenda(false);
                        if (typeof window.refreshBandejaClientAgendaIfOpen === 'function') {
                            window.refreshBandejaClientAgendaIfOpen();
                        }
                    }
                } catch (e) {}
            })
            .catch(function () {})
            .finally(function () {
                inFlightLocks.delete(lockKey);
                if (btnEl) btnEl.disabled = false;
            });
    }

    function submitQuickTopbarTask(formEl) {
        const endpoint = getAgendaEndpoint();
        const btn = document.getElementById('btnSaveTopbarQuickTask');
        if (!endpoint || !formEl || inFlightLocks.has('quick_create')) return;

        const tituloEl = document.getElementById('topbarQuickTaskTitulo');
        const vencEl = document.getElementById('topbarQuickTaskVenc');
        const tipoEl = document.getElementById('topbarQuickTaskTipo');
        const prioEl = document.getElementById('topbarQuickTaskPrio');
        const feedbackEl = document.getElementById('topbarQuickTaskFeedback');

        const titulo = tituloEl ? tituloEl.value.trim() : '';
        const venc = vencEl ? vencEl.value.trim() : '';
        if (titulo.length < 3 || !venc) {
            if (feedbackEl) {
                feedbackEl.textContent = 'Completa el título (mín. 3 letras) y la fecha/hora.';
                feedbackEl.className = 'small text-danger mt-1';
            }
            return;
        }

        inFlightLocks.add('quick_create');
        if (btn) btn.disabled = true;
        if (feedbackEl) {
            feedbackEl.textContent = 'Guardando recordatorio...';
            feedbackEl.className = 'small text-muted mt-1';
        }

        const fd = new FormData();
        fd.append('ajax_agenda_topbar', '1');
        fd.append('agenda_action', 'quick_create_task');
        fd.append('titulo', titulo);
        fd.append('fecha_vencimiento', venc);
        fd.append('tipo', tipoEl ? tipoEl.value : 'SEGUIMIENTO');
        fd.append('prioridad', prioEl ? prioEl.value : 'MEDIA');
        fd.append('recordatorio_min_antes', '15');

        fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (txt) {
                const res = JSON.parse(txt);
                if (res && res.status === 'success') {
                    if (tituloEl) tituloEl.value = '';
                    if (feedbackEl) {
                        feedbackEl.textContent = '✓ Recordatorio agendado.';
                        feedbackEl.className = 'small text-success fw-semibold mt-1';
                    }
                    pollTopbarAgenda(false);
                } else if (feedbackEl) {
                    feedbackEl.textContent = (res && res.message) ? res.message : 'No se pudo guardar.';
                    feedbackEl.className = 'small text-danger mt-1';
                }
            })
            .catch(function () {
                if (feedbackEl) {
                    feedbackEl.textContent = 'Error de red al guardar.';
                    feedbackEl.className = 'small text-danger mt-1';
                }
            })
            .finally(function () {
                inFlightLocks.delete('quick_create');
                if (btn) btn.disabled = false;
            });
    }

    window.starfiRefreshTopbarAgenda = function () {
        pollTopbarAgenda(false);
    };

    document.addEventListener('DOMContentLoaded', function () {
        const wrap = document.getElementById('starfiTopbarAgendaWrap');
        if (!wrap) return;

        const vencInput = document.getElementById('topbarQuickTaskVenc');
        if (vencInput && !vencInput.value) {
            const defDate = new Date(Date.now() + 3600 * 1000);
            const pad = function (n) { return String(n).padStart(2, '0'); };
            vencInput.value = defDate.getFullYear() + '-' + pad(defDate.getMonth() + 1) + '-' + pad(defDate.getDate()) + 'T' + pad(defDate.getHours()) + ':' + pad(defDate.getMinutes());
        }

        const toggleFormBtn = document.getElementById('btnToggleTopbarQuickForm');
        const quickFormWrap = document.getElementById('topbarQuickFormWrap');
        if (toggleFormBtn && quickFormWrap) {
            toggleFormBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                const isHidden = quickFormWrap.style.display === 'none' || !quickFormWrap.style.display;
                quickFormWrap.style.display = isHidden ? 'block' : 'none';
            });
        }

        const quickForm = document.getElementById('topbarQuickTaskForm');
        if (quickForm) {
            quickForm.addEventListener('submit', function (e) {
                e.preventDefault();
                e.stopPropagation();
                submitQuickTopbarTask(quickForm);
            });
        }

        wrap.addEventListener('click', function (e) {
            const doneBtn = e.target.closest('.js-topbar-task-done');
            if (doneBtn) {
                e.preventDefault();
                e.stopPropagation();
                mutateTopbarTask(parseInt(doneBtn.getAttribute('data-id') || '0', 10), 'COMPLETAR', doneBtn, null);
                return;
            }
            const snoozeBtn = e.target.closest('.js-topbar-task-snooze');
            if (snoozeBtn) {
                e.preventDefault();
                e.stopPropagation();
                mutateTopbarTask(parseInt(snoozeBtn.getAttribute('data-id') || '0', 10), 'POSPONER', snoozeBtn, { snooze_min: 30 });
            }
        });

        pollTopbarAgenda(true);
        pollTimer = setInterval(function () {
            pollTopbarAgenda(true);
        }, 25000);
    });
})();
