<?php

declare(strict_types=1);

class TaskManager extends IPSModuleStrict
{
    // ─────────────────────────────────────────────────────────────────────────
    // Lifecycle
    // ─────────────────────────────────────────────────────────────────────────

    public function Create(): void
    {
        parent::Create();

        $this->RegisterAttributeString('Tasks', '[]');
        $this->RegisterAttributeInteger('NextID', 1);

        $this->RegisterPropertyInteger('MaxCompletedVisible', 10);
        $this->RegisterPropertyBoolean('ShowStats', true);
        $this->RegisterPropertyBoolean('ShowPriority', true);
        $this->RegisterPropertyBoolean('ShowDueDate', true);
        $this->RegisterPropertyBoolean('DarkMode', true);

        $this->RegisterVariableString('TaskListHtml', 'Aufgabenliste', '~HTMLBox', 1);
        $this->RegisterVariableInteger('OpenTasks', 'Offene Aufgaben', '', 2);
        $this->RegisterVariableInteger('OverdueTasks', 'Überfällig', '', 3);

        // WebHook registrieren – empfängt Aktionen aus der HTMLBox per fetch()
        $this->RegisterHook('/hook/taskmanager_' . $this->InstanceID);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->RegisterHook('/hook/taskmanager_' . $this->InstanceID);
        $this->Refresh();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WebHook – empfängt POST-Requests aus der HTMLBox
    // ─────────────────────────────────────────────────────────────────────────

    public function ProcessHookData(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            echo '';
            return;
        }

        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '', true);

        if (!is_array($data) || empty($data['action'])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Ungültige Anfrage']);
            return;
        }

        $action  = (string)$data['action'];
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];

        try {
            switch ($action) {
                case 'AddTask':
                    $this->AddTask($payload);
                    break;
                case 'UpdateTask':
                    $this->UpdateTask($payload);
                    break;
                case 'ToggleDone':
                    $this->ToggleDone($payload);
                    break;
                case 'DeleteTask':
                    $this->DeleteTask($payload);
                    break;
                case 'DeleteAllCompleted':
                    $this->DeleteAllCompleted();
                    break;
                default:
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'error' => 'Unbekannte Aktion: ' . $action]);
                    return;
            }
            $this->Refresh();
            echo json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            IPS_LogMessage('TaskManager', 'Hook-Fehler: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Öffentliche Skript-Methoden
    // ─────────────────────────────────────────────────────────────────────────

    public function TM_AddTask(string $Title, string $Info = '', string $Priority = 'normal', int $Due = 0): int
    {
        $id = $this->AddTask(['title' => $Title, 'info' => $Info, 'priority' => $Priority, 'due' => $Due]);
        $this->Refresh();
        return $id;
    }

    public function TM_DeleteAllCompleted(): void
    {
        $this->DeleteAllCompleted();
        $this->Refresh();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Task CRUD
    // ─────────────────────────────────────────────────────────────────────────

    private function AddTask(array $Data): int
    {
        $tasks = $this->LoadTasks();
        $id    = $this->ReadAttributeInteger('NextID');
        $this->WriteAttributeInteger('NextID', $id + 1);

        $tasks[] = [
            'id'        => $id,
            'title'     => trim((string)($Data['title'] ?? '')),
            'info'      => trim((string)($Data['info'] ?? '')),
            'priority'  => $this->ValidatePriority((string)($Data['priority'] ?? 'normal')),
            'due'       => (int)($Data['due'] ?? 0),
            'done'      => false,
            'createdAt' => time(),
        ];

        $this->SaveTasks($tasks);
        return $id;
    }

    private function UpdateTask(array $Data): void
    {
        $id    = (int)($Data['id'] ?? 0);
        $tasks = $this->LoadTasks();
        foreach ($tasks as &$t) {
            if ((int)$t['id'] === $id) {
                $t['title']    = trim((string)($Data['title'] ?? $t['title']));
                $t['info']     = trim((string)($Data['info'] ?? $t['info']));
                $t['priority'] = $this->ValidatePriority((string)($Data['priority'] ?? $t['priority']));
                $t['due']      = (int)($Data['due'] ?? $t['due']);
                break;
            }
        }
        $this->SaveTasks($tasks);
    }

    private function ToggleDone(array $Data): void
    {
        $id    = (int)($Data['id'] ?? 0);
        $done  = (bool)($Data['done'] ?? false);
        $tasks = $this->LoadTasks();
        foreach ($tasks as &$t) {
            if ((int)$t['id'] === $id) {
                $t['done'] = $done;
                if ($done) {
                    $t['completedAt'] = time();
                } else {
                    unset($t['completedAt']);
                }
                break;
            }
        }
        $this->SaveTasks($tasks);
    }

    private function DeleteTask(array $Data): void
    {
        $id    = (int)($Data['id'] ?? 0);
        $tasks = array_values(array_filter($this->LoadTasks(), fn($t) => (int)$t['id'] !== $id));
        $this->SaveTasks($tasks);
    }

    private function DeleteAllCompleted(): void
    {
        $tasks = array_values(array_filter($this->LoadTasks(), fn($t) => empty($t['done'])));
        $this->SaveTasks($tasks);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Refresh – Variable aktualisieren
    // ─────────────────────────────────────────────────────────────────────────

    private function Refresh(): void
    {
        $tasks   = $this->LoadTasks();
        $now     = time();
        $open    = count(array_filter($tasks, fn($t) => empty($t['done'])));
        $overdue = count(array_filter($tasks, fn($t) => empty($t['done']) && (int)($t['due'] ?? 0) > 0 && (int)$t['due'] < $now));

        $this->SetValue('OpenTasks', $open);
        $this->SetValue('OverdueTasks', $overdue);
        $this->SetValue('TaskListHtml', $this->BuildHtml($tasks));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HTML aufbauen
    // ─────────────────────────────────────────────────────────────────────────

    private function BuildHtml(array $Tasks): string
    {
        $iid       = $this->InstanceID;
        $hookUrl   = '/hook/taskmanager_' . $iid;
        $dark      = $this->ReadPropertyBoolean('DarkMode');
        $showStats = $this->ReadPropertyBoolean('ShowStats');
        $showPrio  = $this->ReadPropertyBoolean('ShowPriority');
        $showDue   = $this->ReadPropertyBoolean('ShowDueDate');
        $maxDone   = $this->ReadPropertyInteger('MaxCompletedVisible');

        $open = array_values(array_filter($Tasks, fn($t) => empty($t['done'])));
        $done = array_values(array_filter($Tasks, fn($t) => !empty($t['done'])));

        usort($open, fn($a, $b) => $this->SortScore($a) <=> $this->SortScore($b));
        usort($done, fn($a, $b) => (int)($b['completedAt'] ?? 0) <=> (int)($a['completedAt'] ?? 0));

        if ($maxDone > 0) {
            $done = array_slice($done, 0, $maxDone);
        }

        $now    = time();
        $todayS = mktime(0, 0, 0);
        $todayE = mktime(23, 59, 59);

        $totalOpen    = count(array_filter($Tasks, fn($t) => empty($t['done'])));
        $totalOverdue = count(array_filter($Tasks, fn($t) => empty($t['done']) && (int)($t['due'] ?? 0) > 0 && (int)$t['due'] < $now));
        $totalToday   = count(array_filter($Tasks, fn($t) => empty($t['done']) && (int)($t['due'] ?? 0) >= $todayS && (int)($t['due'] ?? 0) <= $todayE));

        $body = '';

        if ($showStats) {
            $body .= '<div class="tm-stats">';
            $body .= '<div class="tm-stat tm-stat-open"><div class="tm-stat-val">' . $totalOpen . '</div><div class="tm-stat-lbl">Offen</div></div>';
            $body .= '<div class="tm-stat tm-stat-overdue"><div class="tm-stat-val">' . $totalOverdue . '</div><div class="tm-stat-lbl">Überfällig</div></div>';
            $body .= '<div class="tm-stat tm-stat-today"><div class="tm-stat-val">' . $totalToday . '</div><div class="tm-stat-lbl">Heute</div></div>';
            $body .= '</div>';
        }

        $body .= $this->BuildFormHtml();

        foreach ($open as $t) {
            $body .= $this->BuildTaskRow($t, $showPrio, $showDue, $now, $todayS, $todayE);
        }

        if (!empty($done)) {
            $body .= '<div class="tm-section-header">Erledigt</div>';
            foreach ($done as $t) {
                $body .= $this->BuildTaskRow($t, $showPrio, $showDue, $now, $todayS, $todayE);
            }
        }

        if (empty($open) && empty($done)) {
            $body .= '<div class="tm-empty">Keine Aufgaben – leg eine neue an!</div>';
        }

        $css = $this->BuildCss($dark);
        $js  = $this->BuildJs($hookUrl);

        return '<style>' . $css . '</style>' . $js . '<div class="tm-wrap">' . $body . '</div>';
    }

    private function BuildFormHtml(): string
    {
        return '
<div class="tm-add-bar">
  <button class="tm-btn tm-btn-primary tm-btn-full" onclick="tmOpenAdd()">＋ Neue Aufgabe</button>
</div>
<div class="tm-modal-backdrop" id="tm-backdrop" onclick="tmCloseModal()"></div>
<div class="tm-modal" id="tm-modal">
  <div class="tm-modal-header">
    <span id="tm-modal-title">Neue Aufgabe</span>
    <button class="tm-modal-close" onclick="tmCloseModal()">✕</button>
  </div>
  <div class="tm-modal-body">
    <input type="hidden" id="tm-edit-id" value="" />
    <div class="tm-field">
      <label class="tm-label">Titel *</label>
      <input class="tm-input" id="tm-f-title" type="text" placeholder="Aufgabentitel ..." autocomplete="off" />
    </div>
    <div class="tm-field">
      <label class="tm-label">Beschreibung</label>
      <textarea class="tm-input tm-textarea" id="tm-f-info" placeholder="Notiz (optional) ..."></textarea>
    </div>
    <div class="tm-field-row">
      <div class="tm-field">
        <label class="tm-label">Priorität</label>
        <select class="tm-input tm-select" id="tm-f-prio">
          <option value="low">Niedrig</option>
          <option value="normal" selected>Normal</option>
          <option value="high">Hoch</option>
        </select>
      </div>
      <div class="tm-field">
        <label class="tm-label">Fällig am</label>
        <input class="tm-input" id="tm-f-due" type="datetime-local" />
      </div>
    </div>
    <div id="tm-status" style="font-size:12px;min-height:18px;"></div>
  </div>
  <div class="tm-modal-footer">
    <button class="tm-btn tm-btn-danger" id="tm-del-btn" onclick="tmDeleteFromModal()" style="display:none">🗑 Löschen</button>
    <button class="tm-btn" onclick="tmCloseModal()">Abbrechen</button>
    <button class="tm-btn tm-btn-primary" id="tm-save-btn" onclick="tmSaveModal()">Speichern</button>
  </div>
</div>';
    }

    private function BuildTaskRow(array $T, bool $ShowPrio, bool $ShowDue, int $Now, int $TodayS, int $TodayE): string
    {
        $id    = (int)$T['id'];
        $title = htmlspecialchars((string)($T['title'] ?? ''), ENT_QUOTES, 'UTF-8');
        $info  = htmlspecialchars((string)($T['info'] ?? ''), ENT_QUOTES, 'UTF-8');
        $done  = !empty($T['done']);
        $prio  = (string)($T['priority'] ?? 'normal');
        $dueTs = (int)($T['due'] ?? 0);

        $chk = '<input type="checkbox" class="tm-chk" '
             . ($done ? 'checked' : '') . ' onchange="tmToggle(' . $id . ', this.checked)" />';

        $infoHtml = $info !== '' ? '<div class="tm-info">' . $info . '</div>' : '';

        $badges = '';
        if ($ShowPrio) {
            $pl = ['low' => 'Niedrig', 'normal' => 'Normal', 'high' => 'Hoch'];
            $badges .= '<span class="tm-badge tm-badge-' . $prio . '">' . ($pl[$prio] ?? 'Normal') . '</span>';
        }
        if ($ShowDue && $dueTs > 0) {
            $dc = $dueTs < $Now ? ' tm-due-overdue' : ($dueTs <= $TodayE ? ' tm-due-today' : '');
            $badges .= '<span class="tm-badge tm-due-badge' . $dc . '">📅 ' . date('d.m.Y H:i', $dueTs) . '</span>';
        }

        $editJson = htmlspecialchars(json_encode([
            'id' => $id, 'title' => (string)($T['title'] ?? ''),
            'info' => (string)($T['info'] ?? ''), 'priority' => $prio, 'due' => $dueTs,
        ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

        $rowClass = 'tm-item' . ($done ? ' tm-done' : '');
        return '
<div class="' . $rowClass . '">
  <div class="tm-item-left">
    ' . $chk . '
    <span class="tm-prio-dot tm-prio-' . $prio . '"></span>
  </div>
  <div class="tm-item-body" onclick="tmOpenEdit(\'' . $editJson . '\')">
    <div class="tm-title">' . $title . '</div>
    ' . $infoHtml . '
    ' . ($badges !== '' ? '<div class="tm-badges">' . $badges . '</div>' : '') . '
  </div>
  <button class="tm-item-del" onclick="event.stopPropagation();tmDelete(' . $id . ')">✕</button>
</div>';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // JavaScript – kommuniziert über WebHook (kein Port-Problem)
    // ─────────────────────────────────────────────────────────────────────────

    private function BuildJs(string $HookUrl): string
    {
        $hookUrlEsc = addslashes($HookUrl);
        return '<script>
(function() {
  var HOOK = "' . $hookUrlEsc . '";

  function tmAction(action, payload, btn) {
    if (btn) { btn.disabled = true; }
    var status = document.getElementById("tm-status");
    if (status) { status.textContent = ""; status.style.color = ""; }

    fetch(HOOK, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: action, payload: payload || {} })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (btn) { btn.disabled = false; }
      if (!data.ok) {
        if (status) { status.textContent = "Fehler: " + (data.error || "unbekannt"); status.style.color = "red"; }
      }
    })
    .catch(function(e) {
      if (btn) { btn.disabled = false; }
      if (status) { status.textContent = "Verbindungsfehler: " + e; status.style.color = "red"; }
    });
  }

  window.tmToggle = function(id, done) {
    tmAction("ToggleDone", { id: id, done: done });
  };

  window.tmDelete = function(id) {
    tmAction("DeleteTask", { id: id });
  };

  window.tmOpenAdd = function() {
    document.getElementById("tm-edit-id").value = "";
    document.getElementById("tm-f-title").value = "";
    document.getElementById("tm-f-info").value = "";
    document.getElementById("tm-f-prio").value = "normal";
    document.getElementById("tm-f-due").value = "";
    document.getElementById("tm-del-btn").style.display = "none";
    document.getElementById("tm-modal-title").textContent = "Neue Aufgabe";
    var s = document.getElementById("tm-status");
    if (s) { s.textContent = ""; }
    document.getElementById("tm-modal").classList.add("open");
    document.getElementById("tm-backdrop").classList.add("open");
    setTimeout(function() { document.getElementById("tm-f-title").focus(); }, 80);
  };

  window.tmOpenEdit = function(jsonStr) {
    var d = {};
    try { d = JSON.parse(jsonStr); } catch(e) { return; }
    document.getElementById("tm-edit-id").value = d.id || "";
    document.getElementById("tm-f-title").value = d.title || "";
    document.getElementById("tm-f-info").value = d.info || "";
    document.getElementById("tm-f-prio").value = d.priority || "normal";
    if (d.due && d.due > 0) {
      var dt  = new Date(d.due * 1000);
      var pad = function(n) { return String(n).padStart(2, "0"); };
      document.getElementById("tm-f-due").value =
        dt.getFullYear() + "-" + pad(dt.getMonth()+1) + "-" + pad(dt.getDate()) +
        "T" + pad(dt.getHours()) + ":" + pad(dt.getMinutes());
    } else {
      document.getElementById("tm-f-due").value = "";
    }
    document.getElementById("tm-del-btn").style.display = "";
    document.getElementById("tm-modal-title").textContent = "Aufgabe bearbeiten";
    var s = document.getElementById("tm-status");
    if (s) { s.textContent = ""; }
    document.getElementById("tm-modal").classList.add("open");
    document.getElementById("tm-backdrop").classList.add("open");
    setTimeout(function() { document.getElementById("tm-f-title").focus(); }, 80);
  };

  window.tmCloseModal = function() {
    document.getElementById("tm-modal").classList.remove("open");
    document.getElementById("tm-backdrop").classList.remove("open");
  };

  window.tmSaveModal = function() {
    var title = document.getElementById("tm-f-title").value.trim();
    if (!title) {
      document.getElementById("tm-f-title").style.borderColor = "#ff5a5a";
      document.getElementById("tm-f-title").focus();
      return;
    }
    document.getElementById("tm-f-title").style.borderColor = "";

    var info   = document.getElementById("tm-f-info").value.trim();
    var prio   = document.getElementById("tm-f-prio").value;
    var dueRaw = document.getElementById("tm-f-due").value;
    var due    = dueRaw ? Math.floor(new Date(dueRaw).getTime() / 1000) : 0;
    var editId = document.getElementById("tm-edit-id").value;
    var btn    = document.getElementById("tm-save-btn");

    if (editId) {
      tmAction("UpdateTask", { id: parseInt(editId, 10), title: title, info: info, priority: prio, due: due }, btn);
    } else {
      tmAction("AddTask", { title: title, info: info, priority: prio, due: due }, btn);
    }
    tmCloseModal();
  };

  window.tmDeleteFromModal = function() {
    var editId = document.getElementById("tm-edit-id").value;
    if (!editId) return;
    tmAction("DeleteTask", { id: parseInt(editId, 10) });
    tmCloseModal();
  };

  document.addEventListener("keydown", function(e) {
    var modal = document.getElementById("tm-modal");
    if (!modal || !modal.classList.contains("open")) return;
    if (e.key === "Enter" && document.activeElement && document.activeElement.tagName !== "TEXTAREA") {
      e.preventDefault();
      tmSaveModal();
    }
    if (e.key === "Escape") { tmCloseModal(); }
  });

})();
</script>';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CSS
    // ─────────────────────────────────────────────────────────────────────────

    private function BuildCss(bool $Dark): string
    {
        if ($Dark) {
            $v = '--tm-bg:#1e1f23;--tm-card:#2a2b30;--tm-text:#f0f0f0;--tm-muted:rgba(240,240,240,.45);'
               . '--tm-border:rgba(255,255,255,.10);--tm-accent:#00cdab;--tm-red:#ff5a5a;'
               . '--tm-orange:#ffaa40;--tm-shadow:rgba(0,0,0,.45);--tm-modal:#23242a;'
               . '--tm-input:#1a1b1f;--tm-overlay:rgba(0,0,0,.65);';
        } else {
            $v = '--tm-bg:#f4f5f7;--tm-card:#fff;--tm-text:#1a1a2e;--tm-muted:rgba(26,26,46,.45);'
               . '--tm-border:rgba(0,0,0,.10);--tm-accent:#00897b;--tm-red:#d32f2f;'
               . '--tm-orange:#e65100;--tm-shadow:rgba(0,0,0,.12);--tm-modal:#fff;'
               . '--tm-input:#f4f5f7;--tm-overlay:rgba(0,0,0,.40);';
        }

        return '.tm-wrap{' . $v . 'display:flex;flex-direction:column;gap:8px;'
            . 'font-family:"Segoe UI",system-ui,sans-serif;font-size:14px;color:var(--tm-text);}'

            // Stats
            . '.tm-stats{display:flex;gap:8px;margin-bottom:4px;}'
            . '.tm-stat{flex:1;background:var(--tm-card);border:1px solid var(--tm-border);border-radius:12px;'
            . 'padding:10px 6px;text-align:center;box-shadow:0 1px 4px var(--tm-shadow);}'
            . '.tm-stat-val{font-size:26px;font-weight:800;line-height:1;}'
            . '.tm-stat-lbl{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.6px;'
            . 'color:var(--tm-muted);margin-top:4px;}'
            . '.tm-stat-open .tm-stat-val{color:var(--tm-accent);}'
            . '.tm-stat-overdue .tm-stat-val{color:var(--tm-red);}'
            . '.tm-stat-today .tm-stat-val{color:var(--tm-orange);}'

            // Buttons
            . '.tm-add-bar{margin-bottom:4px;}'
            . '.tm-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;'
            . 'border-radius:10px;border:1px solid var(--tm-border);padding:9px 16px;font-size:13px;'
            . 'font-weight:600;background:var(--tm-card);color:var(--tm-text);cursor:pointer;'
            . 'transition:opacity .15s,transform .1s;white-space:nowrap;}'
            . '.tm-btn:active{transform:scale(.97);}'
            . '.tm-btn:disabled{opacity:.5;cursor:not-allowed;}'
            . '.tm-btn-primary{background:var(--tm-accent);border-color:var(--tm-accent);color:#fff;}'
            . '.tm-btn-danger{background:var(--tm-red);border-color:var(--tm-red);color:#fff;}'
            . '.tm-btn-full{width:100%;box-sizing:border-box;}'

            // Task items
            . '.tm-item{display:flex;align-items:flex-start;gap:10px;background:var(--tm-card);'
            . 'border:1px solid var(--tm-border);border-radius:12px;padding:12px;'
            . 'box-shadow:0 1px 3px var(--tm-shadow);}'
            . '.tm-item.tm-done{opacity:.5;}'
            . '.tm-item-left{display:flex;flex-direction:column;align-items:center;gap:6px;'
            . 'padding-top:2px;flex-shrink:0;}'
            . '.tm-chk{width:18px;height:18px;cursor:pointer;accent-color:var(--tm-accent);flex-shrink:0;}'
            . '.tm-prio-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;}'
            . '.tm-prio-dot.tm-prio-high{background:var(--tm-red);}'
            . '.tm-prio-dot.tm-prio-normal{background:var(--tm-accent);}'
            . '.tm-prio-dot.tm-prio-low{background:var(--tm-muted);}'
            . '.tm-item-body{flex:1;min-width:0;cursor:pointer;}'
            . '.tm-title{font-weight:700;line-height:1.3;word-break:break-word;}'
            . '.tm-done .tm-title{text-decoration:line-through;}'
            . '.tm-info{font-size:12px;color:var(--tm-muted);margin-top:3px;line-height:1.4;word-break:break-word;}'
            . '.tm-badges{display:flex;flex-wrap:wrap;gap:5px;margin-top:6px;}'
            . '.tm-badge{display:inline-flex;align-items:center;gap:4px;font-size:11px;padding:2px 8px;'
            . 'border-radius:999px;border:1px solid var(--tm-border);color:var(--tm-muted);white-space:nowrap;}'
            . '.tm-badge.tm-badge-high{border-color:var(--tm-red);color:var(--tm-red);background:rgba(255,90,90,.15);}'
            . '.tm-badge.tm-badge-normal{border-color:var(--tm-accent);color:var(--tm-accent);background:rgba(0,205,171,.15);}'
            . '.tm-badge.tm-badge-low{border-color:var(--tm-muted);color:var(--tm-muted);}'
            . '.tm-due-badge.tm-due-overdue{border-color:var(--tm-red);color:var(--tm-red);background:rgba(255,90,90,.15);}'
            . '.tm-due-badge.tm-due-today{border-color:var(--tm-orange);color:var(--tm-orange);background:rgba(255,170,64,.15);}'
            . '.tm-item-del{flex-shrink:0;background:transparent;border:none;color:var(--tm-muted);'
            . 'cursor:pointer;font-size:14px;padding:2px 4px;border-radius:6px;'
            . 'transition:color .15s,background .15s;line-height:1;}'
            . '.tm-item-del:hover{color:var(--tm-red);background:rgba(255,90,90,.12);}'
            . '.tm-section-header{font-size:11px;font-weight:700;text-transform:uppercase;'
            . 'letter-spacing:.8px;color:var(--tm-muted);padding:8px 4px 2px;}'
            . '.tm-empty{text-align:center;color:var(--tm-muted);padding:24px 16px;font-size:13px;}'

            // Modal
            . '.tm-modal-backdrop{display:none;position:fixed;inset:0;background:var(--tm-overlay);z-index:9000;}'
            . '.tm-modal-backdrop.open{display:block;}'
            . '.tm-modal{display:none;position:fixed;left:50%;top:50%;'
            . 'transform:translate(-50%,-50%);width:min(420px,calc(100vw - 32px));'
            . 'max-height:calc(100vh - 48px);background:var(--tm-modal);'
            . 'border:1px solid var(--tm-border);border-radius:16px;'
            . 'box-shadow:0 8px 40px var(--tm-shadow);z-index:9100;flex-direction:column;overflow:hidden;}'
            . '.tm-modal.open{display:flex;}'
            . '.tm-modal-header{display:flex;align-items:center;justify-content:space-between;'
            . 'padding:16px 18px 12px;border-bottom:1px solid var(--tm-border);font-weight:700;font-size:15px;}'
            . '.tm-modal-close{background:transparent;border:none;color:var(--tm-muted);'
            . 'cursor:pointer;font-size:16px;padding:0 2px;line-height:1;}'
            . '.tm-modal-body{padding:16px 18px;display:flex;flex-direction:column;gap:12px;overflow-y:auto;}'
            . '.tm-modal-footer{display:flex;gap:8px;padding:12px 18px 16px;'
            . 'border-top:1px solid var(--tm-border);justify-content:flex-end;}'
            . '.tm-field{display:flex;flex-direction:column;gap:5px;}'
            . '.tm-field-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;}'
            . '.tm-label{font-size:11px;font-weight:700;text-transform:uppercase;'
            . 'letter-spacing:.5px;color:var(--tm-muted);}'
            . '.tm-input{background:var(--tm-input);border:1px solid var(--tm-border);border-radius:8px;'
            . 'padding:9px 11px;font-size:13px;color:var(--tm-text);outline:none;width:100%;'
            . 'box-sizing:border-box;transition:border-color .15s;font-family:inherit;}'
            . '.tm-input:focus{border-color:var(--tm-accent);}'
            . '.tm-textarea{resize:vertical;min-height:64px;line-height:1.4;}'
            . '.tm-select{appearance:none;cursor:pointer;}';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function LoadTasks(): array
    {
        $data = json_decode($this->ReadAttributeString('Tasks'), true);
        return is_array($data) ? $data : [];
    }

    private function SaveTasks(array $Tasks): void
    {
        $this->WriteAttributeString('Tasks', json_encode(array_values($Tasks), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function ValidatePriority(string $P): string
    {
        return in_array($P, ['low', 'normal', 'high'], true) ? $P : 'normal';
    }

    private function SortScore(array $T): int
    {
        $p   = ['high' => 0, 'normal' => 1000, 'low' => 2000][(string)($T['priority'] ?? 'normal')] ?? 1000;
        $due = (int)($T['due'] ?? 0);
        return $due > 0 ? $p + ($due % 100000) : $p + 99999;
    }

    private function RegisterHook(string $WebHookURI): void
    {
        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        foreach ($ids as $id) {
            $hooks = json_decode(IPS_GetProperty($id, 'Hooks'), true);
            if (!is_array($hooks)) {
                continue;
            }
            foreach ($hooks as $hook) {
                if ($hook['Hook'] === $WebHookURI) {
                    if ((int)$hook['TargetID'] === $this->InstanceID) {
                        return; // bereits registriert
                    }
                }
            }
            // Neuen Hook eintragen
            $hooks[] = ['Hook' => $WebHookURI, 'TargetID' => $this->InstanceID];
            IPS_SetProperty($id, 'Hooks', json_encode($hooks));
            IPS_ApplyChanges($id);
            return;
        }
    }
}
